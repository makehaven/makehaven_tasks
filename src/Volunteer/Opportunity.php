<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Volunteer;

use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Reads the volunteer-opportunity fields on a task node.
 *
 * Every field is optional. An empty stage means "approved" and an empty type
 * means "task", so the tasks that existed before the volunteer board keep
 * behaving exactly as they did, and a site whose update hook has not run yet
 * (fields missing) reads every task as an approved task.
 */
final class Opportunity {

  /**
   * Shortest time an opportunity gathers interest before it is decided.
   */
  public const MIN_WINDOW = 86400;

  /**
   * A dated shift gathering interest must start at least this far ahead.
   */
  public const MIN_LEAD = 2 * 86400;

  public const STAGE_PROPOSED = 'proposed';
  public const STAGE_GATHERING = 'gathering';
  public const STAGE_APPROVED = 'approved';
  public const STAGE_DECLINED = 'declined';
  public const STAGE_CANCELLED = 'cancelled';

  public const TYPE_TASK = 'task';
  public const TYPE_SHIFT = 'shift';
  public const TYPE_TABLING = 'tabling';

  /**
   * The stage; empty reads as approved.
   */
  public static function stage(NodeInterface $node): string {
    if (!$node->hasField('field_task_stage') || $node->get('field_task_stage')->isEmpty()) {
      return self::STAGE_APPROVED;
    }
    return (string) $node->get('field_task_stage')->value;
  }

  /**
   * The kind; empty reads as task.
   */
  public static function type(NodeInterface $node): string {
    if (!$node->hasField('field_task_type') || $node->get('field_task_type')->isEmpty()) {
      return self::TYPE_TASK;
    }
    return (string) $node->get('field_task_type')->value;
  }

  /**
   * Whether it is gathering interest.
   */
  public static function isGathering(NodeInterface $node): bool {
    return self::stage($node) === self::STAGE_GATHERING;
  }

  /**
   * Whether it is approved (empty stage counts).
   */
  public static function isApproved(NodeInterface $node): bool {
    return self::stage($node) === self::STAGE_APPROVED;
  }

  /**
   * Whether it is dated (a shift or tabling): it has a roster, not a lead.
   */
  public static function isShift(NodeInterface $node): bool {
    return in_array(self::type($node), [self::TYPE_SHIFT, self::TYPE_TABLING], TRUE);
  }

  /**
   * Whether it is tabling at an outside event.
   */
  public static function isTabling(NodeInterface $node): bool {
    return self::type($node) === self::TYPE_TABLING;
  }

  /**
   * The time slots, earliest first.
   *
   * One item of field_task_when per slot (setup, morning, afternoon). The
   * headcount applies to each slot.
   *
   * @return array<int, array{start:int, end:int}>
   *   Keyed by start time.
   */
  public static function slots(NodeInterface $node): array {
    if (!$node->hasField('field_task_when') || $node->get('field_task_when')->isEmpty()) {
      return [];
    }
    $out = [];
    foreach ($node->get('field_task_when') as $item) {
      $start = self::storageToTimestamp((string) $item->value);
      if (!$start) {
        continue;
      }
      $end = $item->end_value ? self::storageToTimestamp((string) $item->end_value) : NULL;
      $out[$start] = ['start' => $start, 'end' => ($end && $end > $start) ? $end : $start + 3 * 3600];
    }
    ksort($out);
    return $out;
  }

  /**
   * Whether it has more than one time slot.
   */
  public static function hasSlots(NodeInterface $node): bool {
    return count(self::slots($node)) > 1;
  }

  /**
   * "9:45am–12:00pm" (same day) or "Sun Oct 11, 9:45am–12:00pm".
   */
  public static function slotLabel(int $start, int $end, bool $with_day = FALSE): string {
    $tz = self::timezone();
    $s = (new \DateTime('@' . $start))->setTimezone($tz);
    $e = (new \DateTime('@' . $end))->setTimezone($tz);
    $out = ($with_day ? $s->format('D M j, ') : '') . $s->format('g:ia');
    $out .= $e->format('Y-m-d') === $s->format('Y-m-d') ? '–' . $e->format('g:ia') : ' – ' . $e->format('D M j, g:ia');
    return $out;
  }

  /**
   * The local calendar day (Y-m-d) a timestamp falls on.
   */
  public static function day(int $ts): string {
    return (new \DateTime('@' . $ts))->setTimezone(self::timezone())->format('Y-m-d');
  }

  /**
   * The site timezone.
   */
  public static function timezone(): \DateTimeZone {
    return new \DateTimeZone(\Drupal::config('system.date')->get('timezone.default') ?: date_default_timezone_get());
  }

  /**
   * Minimum headcount (per slot): the field, else 1 for a task and 2 dated.
   */
  public static function minNeeded(NodeInterface $node): int {
    if ($node->hasField('field_task_min_volunteers') && !$node->get('field_task_min_volunteers')->isEmpty()) {
      return max(1, (int) $node->get('field_task_min_volunteers')->value);
    }
    return self::isShift($node) ? 2 : 1;
  }

  /**
   * The cap, or NULL for no cap.
   */
  public static function maxAllowed(NodeInterface $node): ?int {
    if ($node->hasField('field_task_max_volunteers') && !$node->get('field_task_max_volunteers')->isEmpty()) {
      $max = (int) $node->get('field_task_max_volunteers')->value;
      return $max > 0 ? $max : NULL;
    }
    return NULL;
  }

  /**
   * Decide-by as a Unix timestamp, or NULL.
   */
  public static function decideBy(NodeInterface $node): ?int {
    if (!$node->hasField('field_task_decide_by') || $node->get('field_task_decide_by')->isEmpty()) {
      return NULL;
    }
    return self::storageToTimestamp((string) $node->get('field_task_decide_by')->value);
  }

  /**
   * The earliest slot's start as a Unix timestamp, or NULL when undated.
   */
  public static function start(NodeInterface $node): ?int {
    $slots = self::slots($node);
    return $slots ? (int) array_key_first($slots) : NULL;
  }

  /**
   * The latest slot's end as a Unix timestamp, or NULL when undated.
   */
  public static function end(NodeInterface $node): ?int {
    $slots = self::slots($node);
    return $slots ? max(array_column($slots, 'end')) : NULL;
  }

  /**
   * The default decide-by: created + N days, capped at the day before start.
   *
   * Never less than MIN_WINDOW after $created: a shift starting tomorrow
   * would otherwise get a decide-by already in the past, and the next cron
   * would decline it (emailing whoever signed up) or ask staff to approve it
   * before anyone had seen it.
   */
  public static function defaultDecideBy(int $created, ?int $start, int $days): int {
    $decide = $created + max(1, $days) * 86400;
    if ($start) {
      $decide = min($decide, $start - 86400);
    }
    return max($decide, $created + self::MIN_WINDOW);
  }

  /**
   * A timestamp as datetime field storage (UTC, no zone).
   */
  public static function timestampToStorage(int $ts): string {
    return gmdate('Y-m-d\TH:i:s', $ts);
  }

  /**
   * Datetime field storage (UTC, no zone) as a timestamp.
   */
  public static function storageToTimestamp(string $value): ?int {
    if ($value === '') {
      return NULL;
    }
    try {
      return (new \DateTime($value, new \DateTimeZone('UTC')))->getTimestamp();
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * "Sat Oct 3, 10:00am–1:00pm", or for several slots on one day
   * "Sun Oct 11: 9:45am–12:00pm, 12:00pm–2:15pm"; '' when undated.
   */
  public static function whenLabel(NodeInterface $node): string {
    $slots = self::slots($node);
    if (!$slots) {
      return '';
    }
    if (count($slots) === 1) {
      $slot = reset($slots);
      return self::slotLabel($slot['start'], $slot['end'], TRUE);
    }
    $days = [];
    foreach ($slots as $slot) {
      $days[self::day($slot['start'])][] = self::slotLabel($slot['start'], $slot['end']);
    }
    $parts = [];
    foreach ($days as $day => $labels) {
      $parts[] = (new \DateTime($day, self::timezone()))->format('D M j') . ': ' . implode(', ', $labels);
    }
    return implode('; ', $parts);
  }

  /**
   * "Mon Oct 5" in the site timezone.
   */
  public static function dateLabel(?int $ts): string {
    if (!$ts) {
      return '';
    }
    return (new \DateTime('@' . $ts))->setTimezone(self::timezone())->format('D M j');
  }

  /**
   * Why this account may not take part (audience / badge), or NULL.
   *
   * Same rule as claiming: staff-only needs a staff role; badge holders needs
   * facilitator/staff or the task's required badge.
   */
  public static function audienceDenied(NodeInterface $node, AccountInterface $account): ?string {
    $audience = $node->hasField('field_task_audience') ? $node->get('field_task_audience')->value : NULL;
    $roles = $account->getRoles();
    $staff = (bool) array_intersect(['administrator', 'manager', 'content_editor'], $roles);
    if ($audience === 'staff_only' && !$staff) {
      return (string) t('This one is for staff.');
    }
    if ($audience === 'badge_holders' && !$staff && !in_array('facilitator', $roles, TRUE)) {
      $tid = $node->hasField('field_task_required_badge') ? (int) $node->get('field_task_required_badge')->target_id : 0;
      if (!$tid || !function_exists('_makehaven_tasks_user_has_badge') || !_makehaven_tasks_user_has_badge((int) $account->id(), $tid)) {
        return (string) t('This one needs a badge. Please check with a facilitator.');
      }
    }
    return NULL;
  }

  /**
   * Whether the site has the volunteer fields yet (update hook has run).
   */
  public static function fieldsInstalled(): bool {
    // Only a positive answer is cached, so the update hook that adds the
    // fields takes effect within the same request.
    static $installed = FALSE;
    if (!$installed) {
      $installed = (bool) \Drupal::entityTypeManager()->getStorage('field_storage_config')->load('node.field_task_stage');
    }
    return $installed;
  }

}
