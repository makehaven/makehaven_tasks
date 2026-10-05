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
use Drupal\makehaven_tasks\SlackBot;
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
    private readonly SlackBot $slackBot,
  ) {}

  // -- Slack: recruitment only ------------------------------------------------

  /**
   * A new opportunity is gathering interest.
   *
   * Tabling also gets a one-line summary in the outreach committee's channel
   * (JR, 2026-09-28), so the committee sees every table without the recruiting.
   */
  public function recruiting(NodeInterface $node): void {
    $needed = Opportunity::minNeeded($node);
    $when = Opportunity::whenLabel($node);
    $decide = Opportunity::dateLabel(Opportunity::decideBy($node));
    $per = Opportunity::hasSlots($node) ? ' per time slot' : '';
    $this->slack(sprintf(
      "🙋 *Volunteers wanted:* <%s|%s>\n%sNeeds *%d* %s%s%s. Tap \"I'm in\" on the board if you can help%s.",
      $this->url($node),
      $this->escape($node->label()),
      $when !== '' ? $when . "\n" : '',
      $needed,
      $needed === 1 ? 'person' : 'people',
      $per,
      $decide !== '' ? ' · sign up by *' . $decide . '*' : '',
      Opportunity::hasSlots($node) ? ' (pick one slot or several)' : ''
    ));
    if (Opportunity::isTabling($node)) {
      $channel = (string) $this->configFactory->get('makehaven_tasks.settings')->get('outreach_slack_channel');
      if ($channel !== '') {
        $this->slack(sprintf('📋 New tabling opportunity on the volunteer board: <%s|%s>%s', $this->url($node), $this->escape($node->label()), $when !== '' ? ' · ' . $when : ''), $channel);
      }
    }
  }

  /**
   * Email people who asked to hear about this kind of opportunity.
   *
   * Opt-in only (the "email me" box on the volunteer preferences form), once
   * per opportunity, and never to someone already signed up.
   *
   * @param int[] $uids
   *   Subscribers to tell.
   */
  public function matching(NodeInterface $node, array $uids): int {
    $sent = 0;
    $users = $uids ? $this->entityTypeManager->getStorage('user')->loadMultiple($uids) : [];
    $when = Opportunity::whenLabel($node);
    foreach ($users as $user) {
      if (!$user instanceof UserInterface || !$user->isActive() || $this->signups->has($node, (int) $user->id())) {
        continue;
      }
      $this->mailUser($user, 'volunteer_match', sprintf('Volunteer opportunity: %s', $node->label()), implode("\n\n", array_filter([
        sprintf('Hi %s,', $user->getDisplayName()),
        sprintf('You asked to hear about volunteer opportunities like this one: "%s".%s', $node->label(), $when !== '' ? ' When: ' . $when . '.' : ''),
        'Details and sign-up: ' . $this->url($node),
        'Change what you hear about, or stop these emails: ' . $this->absolute('makehaven_tasks.preferences'),
      ])));
      $sent++;
    }
    return $sent;
  }

  /**
   * Two days out: remind the roster, with their own slots.
   */
  public function rosterReminder(NodeInterface $node): int {
    $slots = Opportunity::slots($node);
    $bring = $node->hasField('field_task_bring') ? trim((string) $node->get('field_task_bring')->value) : '';
    $sent = 0;
    foreach ($this->signups->list($node) as $row) {
      if (!$row['user']) {
        continue;
      }
      $mine = $this->slotLines($slots, $row['slots']);
      $this->mailUser($row['user'], 'volunteer_reminder', sprintf('Coming up: %s', $node->label()), implode("\n\n", array_filter([
        sprintf('Hi %s,', $row['user']->getDisplayName()),
        sprintf('A reminder that you are signed up for "%s".', $node->label()),
        $mine ? "Your time:\n" . $mine : 'When: ' . Opportunity::whenLabel($node),
        $bring !== '' ? 'Bring / know: ' . $bring : '',
        "Can't make it any more? Take your name off so staff can find someone: " . $this->absolute('makehaven_tasks.interest', ['node' => $node->id()]),
        'Details: ' . $this->url($node),
        'Thank you!',
      ])));
      $sent++;
    }
    $short = $this->signups->stillNeeded($node);
    if ($short > 0) {
      $this->slack(sprintf('⏰ <%s|%s> is in 2 days (%s) and could still use *%d more*.', $this->url($node), $this->escape($node->label()), Opportunity::whenLabel($node), $short));
    }
    return $sent;
  }

  /**
   * After the last slot ends: thank the roster, and say what they earned.
   *
   * @param array<int, string[]> $earned
   *   uid => thank-you labels now owed to them.
   */
  public function thankYou(NodeInterface $node, array $earned): int {
    $sent = 0;
    foreach ($this->signups->users($node) as $uid => $user) {
      $perks = $earned[$uid] ?? [];
      $this->mailUser($user, 'volunteer_thanks', sprintf('Thank you for volunteering: %s', $node->label()), implode("\n\n", array_filter([
        sprintf('Hi %s,', $user->getDisplayName()),
        sprintf('Thank you for helping with "%s". It makes a real difference.', $node->label()),
        $perks ? 'You have earned: ' . implode(', ', $perks) . '. Staff have been told and will get it to you.' : '',
        'Didn\'t make it after all? No problem; please let a staff member know so the thank-yous stay right.',
        'More ways to help: ' . $this->boardUrl(),
      ])));
      $sent++;
    }
    return $sent;
  }

  /**
   * "Sun Oct 11, 9:45am–12:00pm" lines for the slots a person is on.
   */
  private function slotLines(array $slots, array $mine): string {
    $lines = [];
    foreach ($mine as $start) {
      if (isset($slots[$start])) {
        $lines[] = '- ' . Opportunity::slotLabel($slots[$start]['start'], $slots[$start]['end'], TRUE);
      }
      elseif ($start === 0 && count($slots) === 1) {
        $slot = reset($slots);
        $lines[] = '- ' . Opportunity::slotLabel($slot['start'], $slot['end'], TRUE);
      }
    }
    return implode("\n", $lines);
  }

  /**
   * Still short, and the decide-by date is close.
   */
  public function short(NodeInterface $node): void {
    $count = $this->signups->count($node);
    $more = $this->signups->stillNeeded($node);
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
      $mine = Opportunity::hasSlots($node) ? $this->slotLines(Opportunity::slots($node), $this->signups->userSlots($node, (int) $uid)) : '';
      $this->mailUser($user, 'volunteer_approved', sprintf('It is on: %s', $node->label()), implode("\n\n", array_filter([
        sprintf('Hi %s,', $user->getDisplayName()),
        sprintf('"%s" has been approved and is going ahead.%s', $node->label(), $when !== '' && !$mine ? ' When: ' . $when . '.' : ''),
        $mine ? "Your time:\n" . $mine : '',
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
        'There is always something else on the volunteer board: ' . $this->boardUrl(),
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
    if (Opportunity::hasSlots($node)) {
      $slots = Opportunity::slots($node);
      foreach ($this->signups->slotCounts($node) as $start => $n) {
        $lines[] = sprintf('  %s: %d of %d', Opportunity::slotLabel($slots[$start]['start'], $slots[$start]['end'], TRUE), $n, $needed);
      }
    }
    $body = implode("\n\n", [
      Opportunity::hasSlots($node)
        ? sprintf('"%s" has %d people interested, with every time slot at its %d needed, and its sign-up deadline (%s) has arrived.', $node->label(), $count, $needed, Opportunity::dateLabel(Opportunity::decideBy($node)))
        : sprintf('"%s" has %d interested of %d needed and its sign-up deadline (%s) has arrived.', $node->label(), $count, $needed, Opportunity::dateLabel(Opportunity::decideBy($node))),
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

  /**
   * A week after a claim: a friendly check-in to the lead, staff copied.
   *
   * Unlike the stale nudges this one is not private: staff asked to see who
   * has been checked on (Kate, ledger #45792), so the configured staff
   * address (checkin_cc, default the "ready to approve" address) is on Cc.
   */
  public function checkIn(NodeInterface $node, UserInterface $lead, int $claim_days): void {
    $settings = $this->configFactory->get('makehaven_tasks.settings');
    $cc = trim((string) ($settings->get('checkin_cc') ?? $settings->get('ready_to_approve_email')));
    $handoff = $this->absolute('makehaven_tasks.handoff', ['node' => $node->id()]);
    $this->mailUser($lead, 'claim_checkin', sprintf('How\'s it going? "%s"', $node->label()), implode("\n\n", [
      sprintf('Hi %s,', $lead->getDisplayName()),
      sprintf('You picked up "%s" on the volunteer board %d days ago. Thank you! We are just checking in to see how it is going.', $node->label(), $claim_days),
      '- All done? Mark it done on the task page so it comes off the board: ' . $this->url($node),
      '- Still working on it? No need to do anything. If something is in the way (a part, a tool, a question), just reply to this email; staff are copied.',
      '- Turned out not to fit your time? Hand it off, with a note for whoever picks it up next: ' . $handoff,
      'Thanks for helping keep MakeHaven running.',
    ]), $cc !== '' ? ['cc' => $cc] : []);
  }

  // -- transports ---------------------------------------------------------------

  /**
   * Posts one mrkdwn message to the recruitment channel.
   *
   * Goes out as the Member Sync bot, not the slack_connector webhook: a
   * modern webhook is locked to the one channel it was created for and
   * silently ignores 'channel', so posts meant for #volunteer and the
   * outreach channel never reached them. The bot joins public channels on
   * its own.
   */
  public function slack(string $text, ?string $channel = NULL): bool {
    $channel = $channel ?? (string) ($this->configFactory->get('makehaven_tasks.settings')->get('volunteer_slack_channel') ?: '#volunteer');
    try {
      $this->slackBot->post($channel, $text);
      return TRUE;
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('makehaven_tasks')->error('Volunteer Slack post to @channel failed: @error', ['@channel' => $channel, '@error' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Emails one person.
   *
   * @param \Drupal\user\UserInterface $user
   *   The recipient.
   * @param string $key
   *   The mail key (makehaven_tasks_mail()).
   * @param string $subject
   *   The subject.
   * @param string $body
   *   The plain-text body.
   * @param array $extra
   *   Extra mail params; 'cc' adds a Cc header (see makehaven_tasks_mail()).
   */
  public function mailUser(UserInterface $user, string $key, string $subject, string $body, array $extra = []): void {
    $to = $user->getEmail();
    if (!$to) {
      return;
    }
    $this->mailManager->mail('makehaven_tasks', $key, $to, $user->getPreferredLangcode(), [
      'subject' => $subject,
      'body' => $body,
    ] + $extra, NULL, TRUE);
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
   * The board, by path: /volunteer is a legacy redirect on live, and the
   * board's route comes from a view that may not exist (tests, a broken view),
   * which must never stop a decline email going out.
   */
  private function boardUrl(): string {
    $url = Url::fromUserInput('/tasks', ['absolute' => TRUE])->toString();
    $host = parse_url($url, PHP_URL_HOST);
    if ($host && $host !== 'default') {
      return $url;
    }
    return rtrim((string) (Settings::get('makehaven_tasks_base_url') ?: 'https://www.makehaven.org'), '/') . '/tasks';
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
