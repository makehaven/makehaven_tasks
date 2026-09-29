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
 * Who said "I'm interested" in (or is on the roster of) which opportunity.
 *
 * Ported from the unshipped makerspace_tabling Roster. Sign-ups live in their
 * own table, one row per person per task, so two people signing up at once
 * never re-save the node and cannot clobber each other.
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
   * Adds the user, or updates their note if already on it.
   *
   * @return bool
   *   TRUE when a new row was added, FALSE when only the note changed.
   */
  public function add(NodeInterface $node, int $uid, string $note = '', string $kind = self::KIND_INTEREST): bool {
    $note = mb_substr(trim($note), 0, 255);
    $existing = $this->database->select(self::TABLE, 's')
      ->fields('s', ['id'])
      ->condition('nid', (int) $node->id())
      ->condition('uid', $uid)
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
   * Sign-ups do not re-save the node, so clear the board and the task page.
   */
  private function invalidate(NodeInterface $node): void {
    Cache::invalidateTags(['makehaven_tasks_signups', 'node:' . $node->id()]);
  }

  /**
   * Takes the user off.
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
   * Whether the user is on it.
   */
  public function has(NodeInterface $node, int $uid): bool {
    return (bool) $this->database->select(self::TABLE, 's')
      ->fields('s', ['id'])
      ->condition('nid', (int) $node->id())
      ->condition('uid', $uid)
      ->execute()
      ->fetchField();
  }

  /**
   * How many people are on it.
   */
  public function count(NodeInterface $node): int {
    return (int) $this->database->select(self::TABLE, 's')
      ->condition('nid', (int) $node->id())
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Counts for many tasks at once (the board).
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
    $query->addExpression('COUNT(*)', 'n');
    $query->condition('nid', array_map('intval', $nids), 'IN');
    $query->groupBy('nid');
    $out = [];
    foreach ($query->execute() as $row) {
      $out[(int) $row->nid] = (int) $row->n;
    }
    return $out;
  }

  /**
   * Everyone on it, first to sign up first.
   *
   * @return array<int, array{uid:int, name:string, note:string, kind:string, created:int, user:\Drupal\user\UserInterface|null}>
   *   The list.
   */
  public function list(NodeInterface $node): array {
    $rows = $this->database->select(self::TABLE, 's')
      ->fields('s', ['uid', 'note', 'kind', 'created'])
      ->condition('nid', (int) $node->id())
      ->orderBy('created')
      ->orderBy('id')
      ->execute()
      ->fetchAll();
    if (!$rows) {
      return [];
    }
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple(array_map(fn($r) => (int) $r->uid, $rows));
    $out = [];
    foreach ($rows as $row) {
      $user = $users[(int) $row->uid] ?? NULL;
      $out[] = [
        'uid' => (int) $row->uid,
        'name' => $user instanceof UserInterface ? $user->getDisplayName() : (string) $row->uid,
        'note' => (string) $row->note,
        'kind' => (string) $row->kind,
        'created' => (int) $row->created,
        'user' => $user instanceof UserInterface ? $user : NULL,
      ];
    }
    return $out;
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
   * Drops every row for a task (when it is deleted).
   */
  public function clear(int $nid): void {
    $this->database->delete(self::TABLE)->condition('nid', $nid)->execute();
  }

}
