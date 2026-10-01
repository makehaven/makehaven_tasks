<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Volunteer;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Who said "I'm in" for (or is on the roster of) which opportunity and slot.
 *
 * Ported from the unshipped makerspace_tabling Roster. Sign-ups live in their
 * own table, one row per person per time slot, so two people signing up at
 * once never re-save the node and cannot clobber each other.
 *
 * slot is the start (Unix time) of the slot signed up for, or 0 for an
 * undated task. A dated opportunity with a single slot may also hold slot-0
 * rows from before slots existed; those count for its one slot.
 *
 * kind = 'interest' while the opportunity gathers interest; approval turns
 * every row into 'confirmed', which is then the roster of record.
 */
final class SignupStore {

  public const TABLE = 'makehaven_task_signup';

  public const KIND_INTEREST = 'interest';
  public const KIND_CONFIRMED = 'confirmed';

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Adds the user to a slot, or updates their note if already on it.
   *
   * @return bool
   *   TRUE when a new row was added, FALSE when only the note changed.
   */
  public function add(NodeInterface $node, int $uid, string $note = '', string $kind = self::KIND_INTEREST, int $slot = 0): bool {
    $note = mb_substr(trim($note), 0, 255);
    $existing = $this->database->select(self::TABLE, 's')
      ->fields('s', ['id'])
      ->condition('nid', (int) $node->id())
      ->condition('uid', $uid)
      ->condition('slot', $slot)
      ->execute()
      ->fetchField();
    if ($existing) {
      $this->database->update(self::TABLE)
        ->fields(['note' => $note])
        ->condition('id', (int) $existing)
        ->execute();
      $this->invalidate($node);
      return FALSE;
    }
    try {
      $this->database->insert(self::TABLE)
        ->fields([
          'nid' => (int) $node->id(),
          'uid' => $uid,
          'note' => $note,
          'kind' => $kind,
          'slot' => $slot,
          'created' => $this->time->getCurrentTime(),
        ])
        ->execute();
    }
    catch (IntegrityConstraintViolationException $e) {
      // A double-submit raced the check above: the row is already there.
      return FALSE;
    }
    $this->invalidate($node);
    return TRUE;
  }

  /**
   * Puts the user on exactly these slots (adding and removing as needed).
   *
   * @param int[] $slots
   *   Slot starts; [0] for an undated task.
   *
   * @return int[]
   *   The slots newly added.
   */
  public function setSlots(NodeInterface $node, int $uid, array $slots, string $note, string $kind): array {
    $slots = array_values(array_unique(array_map('intval', $slots)));
    $current = $this->userSlots($node, $uid);
    $added = [];
    foreach ($slots as $slot) {
      if ($this->add($node, $uid, $note, in_array($slot, $current, TRUE) ? $this->kindOf($node, $uid, $slot) : $kind, $slot)) {
        $added[] = $slot;
      }
    }
    $drop = array_diff($current, $slots);
    if ($drop) {
      $this->database->delete(self::TABLE)
        ->condition('nid', (int) $node->id())
        ->condition('uid', $uid)
        ->condition('slot', array_values($drop), 'IN')
        ->execute();
      $this->invalidate($node);
    }
    return $added;
  }

  /**
   * The kind of an existing row (so editing a note keeps a confirmation).
   */
  private function kindOf(NodeInterface $node, int $uid, int $slot): string {
    return (string) ($this->database->select(self::TABLE, 's')
      ->fields('s', ['kind'])
      ->condition('nid', (int) $node->id())
      ->condition('uid', $uid)
      ->condition('slot', $slot)
      ->execute()
      ->fetchField() ?: self::KIND_INTEREST);
  }

  /**
   * Sign-ups do not re-save the node, so clear the board and the task page.
   */
  private function invalidate(NodeInterface $node): void {
    Cache::invalidateTags(['makehaven_tasks_signups', 'node:' . $node->id()]);
  }

  /**
   * Takes the user off every slot.
   */
  public function remove(NodeInterface $node, int $uid): bool {
    $removed = $this->database->delete(self::TABLE)
      ->condition('nid', (int) $node->id())
      ->condition('uid', $uid)
      ->execute() > 0;
    $this->invalidate($node);
    return $removed;
  }

  /**
   * Whether the user is on it (any slot).
   */
  public function has(NodeInterface $node, int $uid): bool {
    return (bool) $this->database->select(self::TABLE, 's')
      ->fields('s', ['id'])
      ->condition('nid', (int) $node->id())
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }

  /**
   * The slots the user is on.
   *
   * @return int[]
   *   Slot starts (0 for undated).
   */
  public function userSlots(NodeInterface $node, int $uid): array {
    return array_map('intval', $this->database->select(self::TABLE, 's')
      ->fields('s', ['slot'])
      ->condition('nid', (int) $node->id())
      ->condition('uid', $uid)
      ->orderBy('slot')
      ->execute()
      ->fetchCol());
  }

  /**
   * How many different people are on it.
   */
  public function count(NodeInterface $node): int {
    $query = $this->database->select(self::TABLE, 's')->condition('nid', (int) $node->id());
    $query->addExpression('COUNT(DISTINCT uid)', 'n');
    return (int) $query->execute()->fetchField();
  }

  /**
   * People per slot, for every slot the opportunity has.
   *
   * @return array<int,int>
   *   slot start => people. An undated task has the single key 0.
   */
  public function slotCounts(NodeInterface $node): array {
    $slots = Opportunity::slots($node);
    $query = $this->database->select(self::TABLE, 's');
    $query->addField('s', 'slot');
    $query->addExpression('COUNT(DISTINCT uid)', 'n');
    $query->condition('nid', (int) $node->id());
    $query->groupBy('slot');
    $raw = $query->execute()->fetchAllKeyed();
    if (!$slots) {
      return [0 => (int) array_sum($raw)];
    }
    $out = array_fill_keys(array_keys($slots), 0);
    foreach ($raw as $slot => $n) {
      if (isset($out[(int) $slot])) {
        $out[(int) $slot] = (int) $n;
      }
    }
    // Rows from before slots existed (slot 0) count for a single-slot shift.
    if (count($slots) === 1 && !empty($raw[0])) {
      $out[array_key_first($out)] += (int) $raw[0];
    }
    return $out;
  }

  /**
   * People still needed to fill every slot to its minimum.
   */
  public function stillNeeded(NodeInterface $node): int {
    $min = Opportunity::minNeeded($node);
    $short = 0;
    foreach ($this->slotCounts($node) as $n) {
      $short += max(0, $min - $n);
    }
    return $short;
  }

  /**
   * Whether every slot has its minimum.
   */
  public function enough(NodeInterface $node): bool {
    return $this->stillNeeded($node) === 0;
  }

  /**
   * Whether a slot is at its cap.
   */
  public function slotFull(NodeInterface $node, int $slot): bool {
    $max = Opportunity::maxAllowed($node);
    if ($max === NULL) {
      return FALSE;
    }
    return ($this->slotCounts($node)[$slot] ?? 0) >= $max;
  }

  /**
   * Whether every slot is at its cap.
   */
  public function full(NodeInterface $node): bool {
    $max = Opportunity::maxAllowed($node);
    if ($max === NULL) {
      return FALSE;
    }
    foreach ($this->slotCounts($node) as $n) {
      if ($n < $max) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Distinct people for many tasks at once (the board).
   *
   * @return array<int,int>
   *   nid => count. Tasks with nobody are absent.
   */
  public function countMany(array $nids): array {
    if (!$nids) {
      return [];
    }
    $query = $this->database->select(self::TABLE, 's');
    $query->addField('s', 'nid');
    $query->addExpression('COUNT(DISTINCT uid)', 'n');
    $query->condition('nid', array_map('intval', $nids), 'IN');
    $query->groupBy('nid');
    $out = [];
    foreach ($query->execute() as $row) {
      $out[(int) $row->nid] = (int) $row->n;
    }
    return $out;
  }

  /**
   * Everyone on it, one row per person, first to sign up first.
   *
   * @return array<int, array{uid:int, name:string, note:string, kind:string, created:int, slots:int[], user:\Drupal\user\UserInterface|null}>
   *   The list.
   */
  public function list(NodeInterface $node): array {
    $rows = $this->database->select(self::TABLE, 's')
      ->fields('s', ['uid', 'note', 'kind', 'created', 'slot'])
      ->condition('nid', (int) $node->id())
      ->orderBy('created')
      ->orderBy('id')
      ->execute()
      ->fetchAll();
    if (!$rows) {
      return [];
    }
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple(array_unique(array_map(fn($r) => (int) $r->uid, $rows)));
    $out = [];
    foreach ($rows as $row) {
      $uid = (int) $row->uid;
      if (!isset($out[$uid])) {
        $user = $users[$uid] ?? NULL;
        $out[$uid] = [
          'uid' => $uid,
          'name' => $user instanceof UserInterface ? $user->getDisplayName() : (string) $uid,
          'note' => '',
          'kind' => (string) $row->kind,
          'created' => (int) $row->created,
          'slots' => [],
          'user' => $user instanceof UserInterface ? $user : NULL,
        ];
      }
      $out[$uid]['slots'][] = (int) $row->slot;
      if ((string) $row->note !== '') {
        $out[$uid]['note'] = (string) $row->note;
      }
    }
    return array_values($out);
  }

  /**
   * The accounts on it, for mail. Keyed by uid, first to sign up first.
   *
   * @return \Drupal\user\UserInterface[]
   *   The users.
   */
  public function users(NodeInterface $node): array {
    $out = [];
    foreach ($this->list($node) as $row) {
      if ($row['user']) {
        $out[$row['uid']] = $row['user'];
      }
    }
    return $out;
  }

  /**
   * Marks every row confirmed (approval).
   */
  public function confirmAll(NodeInterface $node): void {
    $this->database->update(self::TABLE)
      ->fields(['kind' => self::KIND_CONFIRMED])
      ->condition('nid', (int) $node->id())
      ->execute();
    $this->invalidate($node);
  }

  /**
   * Moves sign-ups when a slot's start time is edited.
   *
   * @param array<int,int> $moves
   *   Old start => new start.
   */
  public function moveSlots(NodeInterface $node, array $moves): void {
    foreach ($moves as $old => $new) {
      if ((int) $old === (int) $new) {
        continue;
      }
      $ids = $this->database->select(self::TABLE, 's')
        ->fields('s', ['id'])
        ->condition('nid', (int) $node->id())
        ->condition('slot', (int) $old)
        ->execute()
        ->fetchCol();
      foreach ($ids as $id) {
        try {
          $this->database->update(self::TABLE)
            ->fields(['slot' => (int) $new])
            ->condition('id', (int) $id)
            ->execute();
        }
        catch (IntegrityConstraintViolationException $e) {
          // That person was already on the new time too: drop the duplicate.
          $this->database->delete(self::TABLE)->condition('id', (int) $id)->execute();
        }
      }
    }
    $this->invalidate($node);
  }

  /**
   * Drops every row for a task (when it is deleted).
   */
  public function clear(int $nid): void {
    $this->database->delete(self::TABLE)->condition('nid', $nid)->execute();
  }

}
