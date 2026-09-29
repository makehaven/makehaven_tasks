<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Volunteer;

use Drupal\Core\Site\Settings;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use GuzzleHttp\ClientInterface;

/**
 * Tells people what the volunteer board is doing.
 *
 * Slack (the configured recruitment channel, default #volunteers) is for
 * recruiting only: a new opportunity gathering interest, one "needs N more"
 * nudge, and the "we're on" announcement. Declines, lapses and stale-claim
 * nudges are never posted anywhere public; they go by email to the people
 * directly involved (JR, 2026-09-28: never broadcast a failure).
 *
 * No webhook configured (every non-live environment) means nothing is posted
 * and the would-be post is logged, never an exception.
 */
final class Notifier {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly MailManagerInterface $mailManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SignupStore $signups,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  // -- Slack: recruitment only ------------------------------------------------

  /**
   * A new opportunity is gathering interest.
   */
  public function recruiting(NodeInterface $node): void {
    $needed = Opportunity::minNeeded($node);
    $when = Opportunity::whenLabel($node);
    $decide = Opportunity::dateLabel(Opportunity::decideBy($node));
    $this->slack(sprintf(
      "🙋 *Volunteers wanted:* <%s|%s>\n%sNeeds *%d* %s%s. Tap \"I'm interested\" on the board if you can help.",
      $this->url($node),
      $this->escape($node->label()),
      $when !== '' ? $when . "\n" : '',
      $needed,
      $needed === 1 ? 'person' : 'people',
      $decide !== '' ? ' · decide by *' . $decide . '*' : ''
    ));
  }

  /**
   * Still short, and the decide-by date is close.
   */
  public function short(NodeInterface $node): void {
    $count = $this->signups->count($node);
    $more = max(0, Opportunity::minNeeded($node) - $count);
    $this->slack(sprintf(
      "⏳ <%s|%s> needs *%d more* %s by %s. %d interested so far.",
      $this->url($node),
      $this->escape($node->label()),
      $more,
      $more === 1 ? 'volunteer' : 'volunteers',
      Opportunity::dateLabel(Opportunity::decideBy($node)),
      $count
    ));
  }

  /**
   * Approved: announce it with the roster, and tell each volunteer.
   */
  public function approved(NodeInterface $node, string $note): void {
    $users = $this->signups->users($node);
    $names = $users ? implode(', ', array_map(fn(UserInterface $u) => $this->mention($u), $users)) : 'nobody yet, so grab it';
    $when = Opportunity::whenLabel($node);
    $this->slack(sprintf(
      "✅ *It's on:* <%s|%s>%s\nVolunteers: %s%s",
      $this->url($node),
      $this->escape($node->label()),
      $when !== '' ? ' · ' . $when : '',
      $names,
      $note !== '' ? "\n_" . $this->escape($note) . '_' : ''
    ));

    $lead_uid = $node->hasField('field_task_claimed_by') ? (int) $node->get('field_task_claimed_by')->target_id : 0;
    foreach ($users as $uid => $user) {
      if (Opportunity::isShift($node)) {
        $role = 'You are on the roster.';
      }
      elseif ($uid === $lead_uid) {
        $role = 'You are the lead: you were first to volunteer. Mark it done on the task page when it is finished, or hand it off if you cannot.';
      }
      else {
        $role = 'You are helping. Coordinate with the lead on the task page.';
      }
      $this->mailUser($user, 'volunteer_approved', sprintf('It is on: %s', $node->label()), implode("\n\n", array_filter([
        sprintf('Hi %s,', $user->getDisplayName()),
        sprintf('"%s" has been approved and is going ahead.%s', $node->label(), $when !== '' ? ' When: ' . $when . '.' : ''),
        $role,
        $note !== '' ? 'Note from staff: ' . $note : '',
        'Details: ' . $this->url($node),
        'Thank you for volunteering at MakeHaven.',
      ])));
    }
  }

  /**
   * Declined (by staff or by the decide-by rule): tell only the volunteers.
   *
   * Deliberately no Slack, no digest, no broadcast.
   */
  public function declined(NodeInterface $node, string $note): void {
    foreach ($this->signups->users($node) as $user) {
      $this->mailUser($user, 'volunteer_declined', sprintf('Not going ahead: %s', $node->label()), implode("\n\n", array_filter([
        sprintf('Hi %s,', $user->getDisplayName()),
        sprintf('Thank you for offering to help with "%s". It is not going ahead this time.', $node->label()),
        $note !== '' ? 'Why: ' . $note : '',
        'There is always something else on the volunteer board: ' . $this->absolute('makehaven_tasks.volunteer'),
      ])));
    }
  }

  /**
   * Enough people by the decide-by date: one email to the approver address.
   */
  public function readyToApprove(NodeInterface $node): bool {
    $to = trim((string) $this->configFactory->get('makehaven_tasks.settings')->get('ready_to_approve_email'));
    if ($to === '') {
      return FALSE;
    }
    $count = $this->signups->count($node);
    $needed = Opportunity::minNeeded($node);
    $lines = [];
    foreach ($this->signups->list($node) as $row) {
      $lines[] = '- ' . $row['name'] . ($row['note'] !== '' ? ': ' . $row['note'] : '');
    }
    $body = implode("\n\n", [
      sprintf('"%s" has %d interested of %d needed and its decide-by date (%s) has arrived.', $node->label(), $count, $needed, Opportunity::dateLabel(Opportunity::decideBy($node))),
      "Interested:\n" . implode("\n", $lines),
      'Approve or decline it (one click): ' . $this->absolute('makehaven_tasks.approvals'),
      'Task page: ' . $this->url($node),
      'It stays on the board gathering interest until someone decides.',
    ]);
    $this->mailManager->mail('makehaven_tasks', 'ready_to_approve', $to, $this->languageManager->getDefaultLanguage()->getId(), [
      'subject' => sprintf('Ready to approve: %s (%d of %d interested)', $node->label(), $count, $needed),
      'body' => $body,
    ], NULL, TRUE);
    return TRUE;
  }

  /**
   * A claim has sat idle: ask the lead, privately.
   */
  public function staleNudge(NodeInterface $node, UserInterface $lead, int $level, int $idle_days): void {
    $handoff = $this->absolute('makehaven_tasks.handoff', ['node' => $node->id()]);
    $subject = $level >= 2
      ? sprintf('Still on it? "%s" (%d days)', $node->label(), $idle_days)
      : sprintf('Still on it? "%s"', $node->label());
    $this->mailUser($lead, 'stale_claim', $subject, implode("\n\n", array_filter([
      sprintf('Hi %s,', $lead->getDisplayName()),
      sprintf('You claimed "%s" and it has not changed in %d days. No pressure, just checking which of these fits:', $node->label(), $idle_days),
      '- Done? Mark it done on the task page: ' . $this->url($node),
      '- Stuck or out of time? Hand it off (with a note for whoever picks it up): ' . $handoff,
      '- Still on it? Nothing to do; this is the only reminder until it has been idle a while longer.',
      $level >= 2 ? 'Staff will see it on the stale-claims list from now on and may check in.' : '',
      'Nobody will take it away from you automatically.',
    ])));
  }

  // -- transports ---------------------------------------------------------------

  /**
   * Posts one mrkdwn message to the recruitment channel.
   */
  public function slack(string $text, ?string $channel = NULL): bool {
    $webhook = (string) $this->configFactory->get('slack_connector.settings')->get('webhook_url');
    $channel = $channel ?? (string) ($this->configFactory->get('makehaven_tasks.settings')->get('volunteer_slack_channel') ?: '#volunteers');
    $channel = '#' . ltrim($channel, '#');
    if ($webhook === '') {
      $this->loggerFactory->get('makehaven_tasks')->notice('Slack not posted (no webhook configured) to @channel: @text', ['@channel' => $channel, '@text' => $text]);
      return FALSE;
    }
    try {
      $this->httpClient->request('POST', $webhook, [
        'headers' => ['Content-Type' => 'application/json'],
        'json' => [
          'channel' => $channel,
          'text' => $text,
          'blocks' => [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]]],
        ],
        'timeout' => 10,
      ]);
      return TRUE;
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('makehaven_tasks')->error('Volunteer Slack post to @channel failed: @error', ['@channel' => $channel, '@error' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Emails one person.
   */
  public function mailUser(UserInterface $user, string $key, string $subject, string $body): void {
    $to = $user->getEmail();
    if (!$to) {
      return;
    }
    $this->mailManager->mail('makehaven_tasks', $key, $to, $user->getPreferredLangcode(), [
      'subject' => $subject,
      'body' => $body,
    ], NULL, TRUE);
  }

  /**
   * -- formatting ---------------------------------------------------------------
   */
  private function url(NodeInterface $node): string {
    return $this->absolute('entity.node.canonical', ['node' => $node->id()]);
  }

  /**
   * An absolute link that still works when cron runs without a base URL.
   *
   * Drush cron with no --uri builds links on "http://default"; those go to
   * the live host instead (non-live mail is captured, so this is harmless
   * there). Override with $settings['makehaven_tasks_base_url'].
   */
  private function absolute(string $route, array $params = []): string {
    $url = Url::fromRoute($route, $params, ['absolute' => TRUE])->toString();
    $host = parse_url($url, PHP_URL_HOST);
    if ($host && $host !== 'default') {
      return $url;
    }
    $base = rtrim((string) (Settings::get('makehaven_tasks_base_url') ?: 'https://www.makehaven.org'), '/');
    return $base . Url::fromRoute($route, $params)->toString();
  }

  /**
   * <@U…> when the member's profile carries a Slack id, else their name.
   */
  private function mention(UserInterface $user): string {
    if ($this->entityTypeManager->hasDefinition('profile')) {
      $profiles = $this->entityTypeManager->getStorage('profile')->loadByProperties([
        'uid' => $user->id(),
        'type' => 'main',
        'status' => 1,
      ]);
      $profile = $profiles ? reset($profiles) : NULL;
      if ($profile && $profile->hasField('field_member_slack_id_number') && !$profile->get('field_member_slack_id_number')->isEmpty()) {
        return '<@' . $profile->get('field_member_slack_id_number')->value . '>';
      }
    }
    return $this->escape($user->getDisplayName());
  }

  /**
   * Escapes text for Slack mrkdwn.
   */
  private function escape(string $text): string {
    return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
  }

}
