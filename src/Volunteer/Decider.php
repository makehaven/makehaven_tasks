<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Volunteer;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Moves opportunities through their stages and runs the time-based rules.
 *
 * Proposed ──release──> gathering ──approve──> approved (the existing claim /
 *                           │                  help / handoff / done flow)
 *                           └──decline, or decide-by passes short──> declined.
 *
 * Rules run by cron (tick):
 *  - decide-by passed and short: declined, email to the interested only;
 *  - decide-by passed with enough: one "ready to approve" email, no auto-approve;
 *  - short and decide-by close: one "needs N more" recruitment post;
 *  - stale claims: private nudges to the lead at 14 and 28 idle days, only for
 *    claims made after launch (a claim time is recorded from launch onward;
 *    claims without one are listed for staff cleanup and never emailed).
 *
 * Nothing here ever releases a claim; a human decides.
 */
final class Decider {

  /**
   * Key-value collection: "nid:what" => when it was sent.
   */
  public const SENT = 'makehaven_tasks.volunteer_sent';

  /**
   * Key-value collection: nid => when the current claim was made.
   */
  public const CLAIMED_AT = 'makehaven_tasks.claimed_at';

  /**
   * Key-value collection: nid => TRUE when the poster asked for a newsletter
   * notice once the opportunity is public.
   */
  public const ANNOUNCE = 'makehaven_tasks.announce';

  /**
   * Days before the first slot that the roster reminder goes out.
   */
  public const REMIND_DAYS = 2;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SignupStore $signups,
    private readonly Notifier $notifier,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly TimeInterface $time,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly Connection $database,
    private readonly Perks $perks,
    private readonly Preferences $preferences,
  ) {}

  // -- decisions ------------------------------------------------------------------

  /**
   * Approve: interested people become the lead and helpers, or the roster.
   */
  public function approve(NodeInterface $node, ?AccountInterface $by, string $note = ''): void {
    $node->set('field_task_stage', Opportunity::STAGE_APPROVED);
    $node->set('field_task_decision_note', mb_substr(trim($note), 0, 1000) ?: NULL);
    $node->setPublished();

    $uids = array_keys($this->signups->users($node));
    if (!Opportunity::isShift($node)) {
      if ($uids) {
        // First to volunteer leads; the rest help. On allow-multiple tasks the
        // same storage reads as equal co-claimants, so nothing differs here.
        $node->set('field_task_claimed_by', ['target_id' => array_shift($uids)]);
        $node->set('field_task_helpers', array_map(fn($uid) => ['target_id' => $uid], $uids));
        $node->set('field_task_status', 'in_progress');
      }
      elseif ($node->get('field_task_status')->isEmpty()) {
        $node->set('field_task_status', 'open');
      }
    }
    $this->signups->confirmAll($node);
    $node->save();
    $this->notifier->approved($node, trim($note));
    $this->log('approved', $node, $by, $note);
  }

  /**
   * Decline: shown on the site, emailed only to the people who volunteered.
   */
  public function decline(NodeInterface $node, ?AccountInterface $by, string $note = ''): void {
    $note = trim($note) !== '' ? trim($note) : 'Not going ahead this time.';
    $node->set('field_task_stage', Opportunity::STAGE_DECLINED);
    $node->set('field_task_decision_note', mb_substr($note, 0, 1000));
    $node->save();
    $this->notifier->declined($node, $note);
    $this->log('declined', $node, $by, $note);
  }

  /**
   * Release a proposed draft to gather interest (posts the recruitment call).
   */
  public function release(NodeInterface $node, ?AccountInterface $by): void {
    $now = $this->time->getCurrentTime();
    $node->set('field_task_stage', Opportunity::STAGE_GATHERING);
    // The window starts now, not when the draft was written.
    $days = (int) ($this->settings()->get('gathering_days') ?: 8);
    $node->set('field_task_decide_by', Opportunity::timestampToStorage(Opportunity::defaultDecideBy($now, Opportunity::start($node), $days)));
    $node->setPublished();
    $node->save();
    $this->log('released', $node, $by, '');
  }

  // -- save hooks -------------------------------------------------------------------

  /**
   * Presave: fill the decide-by default for an opportunity gathering interest.
   */
  public function presave(NodeInterface $node): void {
    if (!$node->hasField('field_task_stage')) {
      return;
    }
    // A proposed draft is visible only to staff, facilitators and the poster:
    // keep it unpublished so neither the board nor slack_task_poster sees it.
    if (Opportunity::stage($node) === Opportunity::STAGE_PROPOSED && $node->isPublished()) {
      $node->setUnpublished();
    }
    if (!Opportunity::isGathering($node)) {
      return;
    }
    $now = $this->time->getCurrentTime();
    $original = method_exists($node, 'getOriginal') ? $node->getOriginal() : ($node->original ?? NULL);
    $entering = !$original || !Opportunity::isGathering($original);
    $decide_by = Opportunity::decideBy($node);
    // The window starts when the opportunity starts gathering, not when the
    // node was written: an old task switched to "Gathering interest" on the
    // node form would otherwise get a decide-by already in the past.
    if ($decide_by === NULL || ($entering && $decide_by < $now + Opportunity::MIN_WINDOW)) {
      $days = (int) ($this->settings()->get('gathering_days') ?: 8);
      $node->set('field_task_decide_by', Opportunity::timestampToStorage(Opportunity::defaultDecideBy($now, Opportunity::start($node), $days)));
    }
  }

  /**
   * Insert/update: record claim times, follow moved slots, and announce once.
   *
   * Announcing = the #volunteers recruitment call, an email to people who
   * asked to hear about this kind of opportunity, and (when the poster ticked
   * it) a newsletter notice. It happens once, when the opportunity first
   * becomes public: gathering interest, or a dated one posted ready to go.
   */
  public function afterSave(NodeInterface $node, ?NodeInterface $original): void {
    $nid = (int) $node->id();

    // Claim time: from launch onward, every new claim is timestamped. Only a
    // timestamped claim is ever nudged (JR 2026-09-28: do not email the
    // claimants who predate the board).
    $status = $node->hasField('field_task_status') ? (string) $node->get('field_task_status')->value : '';
    $lead = $node->hasField('field_task_claimed_by') ? (int) $node->get('field_task_claimed_by')->target_id : 0;
    if ($status === 'in_progress' && $lead) {
      $was_status = $original && $original->hasField('field_task_status') ? (string) $original->get('field_task_status')->value : '';
      $was_lead = $original && $original->hasField('field_task_claimed_by') ? (int) $original->get('field_task_claimed_by')->target_id : 0;
      if (!$original || $was_status !== 'in_progress' || $was_lead !== $lead) {
        $this->keyValue->get(self::CLAIMED_AT)->set((string) $nid, $this->time->getCurrentTime());
      }
    }

    if (!$node->hasField('field_task_stage')) {
      return;
    }

    // A slot's time edited in place (same position): its sign-ups follow it.
    if ($original) {
      $old = array_keys(Opportunity::slots($original));
      $new = array_keys(Opportunity::slots($node));
      if ($old && count($old) === count($new) && $old !== $new) {
        $this->signups->moveSlots($node, array_combine($old, $new));
      }
    }

    if ($this->isPublic($node) && !($original && $this->isPublic($original))) {
      $sent = $this->keyValue->get(self::SENT);
      if (!$sent->has($nid . ':recruit')) {
        $this->notifier->recruiting($node);
        $sent->set($nid . ':recruit', $this->time->getCurrentTime());
      }
      if (!$sent->has($nid . ':match')) {
        $this->notifier->matching($node, $this->preferences->subscribersFor($node));
        $sent->set($nid . ':match', $this->time->getCurrentTime());
      }
      if ($this->keyValue->get(self::ANNOUNCE)->get((string) $nid)) {
        $this->announce($node);
      }
    }
  }

  /**
   * Whether an opportunity is out recruiting: gathering interest, or a dated
   * one posted ready to go that still has room.
   */
  private function isPublic(NodeInterface $node): bool {
    if (!$node->isPublished()) {
      return FALSE;
    }
    if (Opportunity::isGathering($node)) {
      return TRUE;
    }
    if (!Opportunity::isShift($node) || !Opportunity::isApproved($node) || $node->get('field_task_stage')->isEmpty()) {
      // An approved task with an empty stage is an ordinary task: the
      // existing #tasks post (slack_task_poster) covers it.
      return FALSE;
    }
    $start = Opportunity::start($node);
    return $start && $start > $this->time->getCurrentTime() && !$this->signups->full($node);
  }

  /**
   * Raises a newsletter notice for the opportunity (once).
   *
   * A notice goes to Slack #members at once, the next weekly digest and the
   * monthly newsletter while it is live (makerspace_digest_scheduler).
   */
  public function announce(NodeInterface $node): ?NodeInterface {
    if (!\Drupal::hasService('makerspace_digest_scheduler.notice_writer')) {
      return NULL;
    }
    $until_ts = Opportunity::start($node) ?: (Opportunity::decideBy($node) ?: $this->time->getCurrentTime() + 14 * 86400);
    $when = Opportunity::whenLabel($node);
    $summary = trim(strip_tags((string) $node->get('body')->value));
    if (mb_strlen($summary) > 280) {
      $summary = rtrim(mb_substr($summary, 0, 277)) . '…';
    }
    $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
    $body = '<p>' . htmlspecialchars($summary, ENT_QUOTES) . '</p>'
      . ($when !== '' ? '<p><strong>' . htmlspecialchars($when, ENT_QUOTES) . '</strong></p>' : '')
      . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . t('Sign up on the volunteer board') . '</a>. ' . htmlspecialchars($this->perks->policyText(), ENT_QUOTES) . '</p>';
    $notice = \Drupal::service('makerspace_digest_scheduler.notice_writer')->create(
      (string) t('Volunteers wanted: @title', ['@title' => $node->label()]),
      $body,
      ['source' => 'volunteer_board', 'key' => 'task:' . $node->id(), 'until' => Opportunity::day($until_ts)]
    );
    $this->keyValue->get(self::ANNOUNCE)->delete((string) $node->id());
    return $notice;
  }

  /**
   * When a claim was made, or NULL for a claim that predates the board.
   */
  public function claimedAt(int $nid): ?int {
    $value = $this->keyValue->get(self::CLAIMED_AT)->get((string) $nid);
    return $value ? (int) $value : NULL;
  }

  // -- cron ---------------------------------------------------------------------------

  /**
   * Runs every rule that is due. Called from cron and from Drush.
   *
   * @return string[]
   *   One line per action taken (or, in a dry run, that would be taken).
   */
  public function tick(?int $now = NULL, bool $dry_run = FALSE): array {
    $now ??= $this->time->getCurrentTime();
    $log = [];
    $sent = $this->keyValue->get(self::SENT);
    $short_days = (int) ($this->settings()->get('short_reminder_days') ?? 3);
    $storage = $this->entityTypeManager->getStorage('node');

    // 1. Opportunities gathering interest.
    $nids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'task')
      ->condition('status', 1)
      ->condition('field_task_stage', Opportunity::STAGE_GATHERING)
      ->execute();
    foreach ($storage->loadMultiple($nids) as $node) {
      /** @var \Drupal\node\NodeInterface $node */
      $nid = (int) $node->id();
      $count = $this->signups->count($node);
      $needed = Opportunity::minNeeded($node);
      $short = $this->signups->stillNeeded($node);
      $enough = $short === 0;
      $decide_by = Opportunity::decideBy($node);
      $start = Opportunity::start($node);
      $label = sprintf('#%d "%s" (%d in, %d more needed)', $nid, $node->label(), $count, $short);

      if ($start && $start <= $now) {
        $log[] = "decline (date passed undecided): $label";
        if (!$dry_run) {
          $this->decline($node, NULL, sprintf('The date (%s) passed before it was approved.', Opportunity::dateLabel($start)));
        }
        continue;
      }
      if (!$decide_by) {
        continue;
      }
      if ($decide_by <= $now) {
        if (!$enough) {
          $log[] = "decline (short at decide-by): $label";
          if (!$dry_run) {
            $this->decline($node, NULL, Opportunity::hasSlots($node)
              ? sprintf('Not enough volunteers by %s: %d more were needed to fill every time slot.', Opportunity::dateLabel($decide_by), $short)
              : sprintf('Not enough volunteers by %s: %d of %d needed.', Opportunity::dateLabel($decide_by), $count, $needed));
          }
        }
        elseif (!$sent->has($nid . ':ready')) {
          $log[] = "ready-to-approve email: $label";
          if (!$dry_run) {
            $this->notifier->readyToApprove($node);
            $sent->set($nid . ':ready', $now);
          }
        }
        continue;
      }
      // Not within a day of the recruitment call: a short window would
      // otherwise post "needs N more" minutes after the first post.
      $recruited = (int) ($sent->get($nid . ':recruit') ?? 0);
      if (!$enough && $decide_by - $now <= $short_days * 86400 && $now - $recruited >= 86400 && !$sent->has($nid . ':short')) {
        $log[] = "needs-more post: $label";
        if (!$dry_run) {
          $this->notifier->short($node);
          $sent->set($nid . ':short', $now);
        }
      }
    }

    // 2. Approved dated opportunities: the roster reminder two days out, and
    // the thank-you once the last slot has ended.
    $query = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'task')
      ->condition('field_task_type', [Opportunity::TYPE_SHIFT, Opportunity::TYPE_TABLING], 'IN')
      ->condition('field_task_when.end_value', Opportunity::timestampToStorage($now - 3 * 86400), '>=');
    $or = $query->orConditionGroup()
      ->notExists('field_task_stage')
      ->condition('field_task_stage', Opportunity::STAGE_APPROVED);
    $query->condition($or);
    foreach ($storage->loadMultiple($query->execute()) as $node) {
      /** @var \Drupal\node\NodeInterface $node */
      $nid = (int) $node->id();
      $start = Opportunity::start($node);
      $end = Opportunity::end($node);
      if (!$start || !$this->signups->count($node)) {
        continue;
      }
      $label = sprintf('#%d "%s"', $nid, $node->label());
      // Keyed on the start, so moving the date re-arms the reminder.
      $key = $nid . ':remind:' . $start;
      if ($start > $now && $start - $now <= self::REMIND_DAYS * 86400 && !$sent->has($key)) {
        $log[] = "roster reminder: $label";
        if (!$dry_run) {
          $this->notifier->rosterReminder($node);
          $sent->set($key, $now);
        }
      }
      if ($end && $end <= $now && !$sent->has($nid . ':thanks')) {
        $log[] = "thank-you: $label";
        if (!$dry_run) {
          $earned = [];
          $users = array_keys($this->signups->users($node));
          foreach ($this->perks->owed(NULL, $now) as $row) {
            if (in_array($row['uid'], $users, TRUE)) {
              $earned[$row['uid']][] = Perks::label($row['perk']);
            }
          }
          $this->notifier->thankYou($node, $earned);
          $sent->set($nid . ':thanks', $now);
        }
      }
    }

    // 3. Stale claims: private nudges for post-launch claims only.
    $first = (int) ($this->settings()->get('stale_first_days') ?: 14);
    $second = (int) ($this->settings()->get('stale_second_days') ?: 28);
    foreach ($this->staleClaims($now)['post_launch'] as $row) {
      $level = $row['idle_days'] >= $second ? 2 : ($row['idle_days'] >= $first ? 1 : 0);
      if (!$level) {
        continue;
      }
      $key = sprintf('%d:stale%d:%d', $row['nid'], $level, $row['claimed_at']);
      if ($sent->has($key) || !$row['lead']) {
        continue;
      }
      $log[] = sprintf('stale-claim nudge %d to %s: #%d "%s" (%d days idle)', $level, $row['lead']->getDisplayName(), $row['nid'], $row['node']->label(), $row['idle_days']);
      if (!$dry_run) {
        $this->notifier->staleNudge($row['node'], $row['lead'], $level, $row['idle_days']);
        $sent->set($key, $now);
      }
    }

    if ($log && !$dry_run) {
      $this->loggerFactory->get('makehaven_tasks')->notice('Volunteer board tick: @lines', ['@lines' => implode('; ', $log)]);
    }
    return $log;
  }

  /**
   * Every open claim, split by whether it was made after launch.
   *
   * @return array{post_launch: array, pre_launch: array}
   *   Rows of: nid, node, lead (user|null), claimed_at (int|null),
   *   last_activity, idle_days. Sorted most idle first.
   */
  public function staleClaims(?int $now = NULL): array {
    $now ??= $this->time->getCurrentTime();
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()->accessCheck(FALSE)
      ->condition('type', 'task')
      ->condition('status', 1)
      ->condition('field_task_status', 'in_progress')
      ->exists('field_task_claimed_by');
    $nids = $query->execute();
    $out = ['post_launch' => [], 'pre_launch' => []];
    if (!$nids) {
      return $out;
    }
    // Completion is the task_completed flag, not the status field.
    $done = [];
    if ($this->database->schema()->tableExists('flagging')) {
      $done = $this->database->select('flagging', 'f')
        ->fields('f', ['entity_id'])
        ->condition('flag_id', 'task_completed')
        ->condition('entity_type', 'node')
        ->condition('entity_id', array_values($nids), 'IN')
        ->execute()
        ->fetchCol();
    }
    $done = array_flip(array_map('intval', $done));
    foreach ($storage->loadMultiple($nids) as $node) {
      /** @var \Drupal\node\NodeInterface $node */
      $nid = (int) $node->id();
      if (isset($done[$nid]) || !Opportunity::isApproved($node)) {
        continue;
      }
      $claimed_at = $this->claimedAt($nid);
      $last = max((int) $node->getChangedTime(), (int) $claimed_at);
      $lead = $node->get('field_task_claimed_by')->entity;
      $row = [
        'nid' => $nid,
        'node' => $node,
        'lead' => $lead instanceof UserInterface ? $lead : NULL,
        'claimed_at' => $claimed_at,
        'last_activity' => $last,
        'idle_days' => (int) floor(max(0, $now - $last) / 86400),
      ];
      $out[$claimed_at ? 'post_launch' : 'pre_launch'][] = $row;
    }
    foreach ($out as &$rows) {
      usort($rows, fn($a, $b) => $b['idle_days'] <=> $a['idle_days']);
    }
    return $out;
  }

  /**
   * -- helpers ------------------------------------------------------------------------
   */
  private function settings() {
    return $this->configFactory->get('makehaven_tasks.settings');
  }

  /**
   * Logs a stage change.
   */
  private function log(string $what, NodeInterface $node, ?AccountInterface $by, string $note): void {
    $this->loggerFactory->get('makehaven_tasks')->notice('Volunteer opportunity @nid "@title" @what by @who. @note', [
      '@nid' => $node->id(),
      '@title' => $node->label(),
      '@what' => $what,
      '@who' => $by ? $by->getAccountName() : 'the decide-by rule',
      '@note' => $note,
    ]);
  }

}
