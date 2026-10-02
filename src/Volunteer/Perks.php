<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Volunteer;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Volunteer thank-yous: what each person has earned and what was handed out.
 *
 * The policy (JR, 2026-10-01; amounts are settings):
 *  - a MakeHaven t-shirt on the first day volunteered;
 *  - $15 toward lunch for a day with 3+ hours (erring generous);
 *  - a hoodie after 5 days volunteered.
 *
 * A "day volunteered" is a calendar day with at least one confirmed slot of an
 * approved, dated opportunity (a shift or tabling) that has ended. Several
 * slots on one day are one day, and their hours add up for lunch. Staff can
 * mark a day as a no-show, which takes it out of every count.
 *
 * What is owed is computed; what was handed out is recorded in
 * makehaven_volunteer_perk. Giving a t-shirt or hoodie also records a store
 * inventory adjustment (reason "Volunteer appreciation") so the shelf count
 * stays right.
 */
final class Perks {

  public const TABLE = 'makehaven_volunteer_perk';

  public const TSHIRT = 'tshirt';
  public const LUNCH = 'lunch';
  public const HOODIE = 'hoodie';
  public const NO_SHOW = 'no_show';

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * The thank-you settings.
   *
   * @return array{lunch_amount:int, lunch_hours:float, hoodie_dates:int, tshirt_material:int, hoodie_material:int}
   *   Settings with defaults.
   */
  public function policy(): array {
    $c = $this->configFactory->get('makehaven_tasks.settings');
    return [
      'lunch_amount' => (int) ($c->get('perk_lunch_amount') ?? 15),
      'lunch_hours' => (float) ($c->get('perk_lunch_hours') ?? 3),
      'hoodie_dates' => max(1, (int) ($c->get('perk_hoodie_dates') ?? 5)),
      'tshirt_material' => (int) ($c->get('perk_tshirt_material') ?? 0),
      'hoodie_material' => (int) ($c->get('perk_hoodie_material') ?? 0),
    ];
  }

  /**
   * The policy in one sentence, for the board and the How You Can Help page.
   */
  public function policyText(): string {
    $p = $this->policy();
    $hours = rtrim(rtrim(number_format($p['lunch_hours'], 1), '0'), '.');
    return (string) t('Thank you, volunteers: a MakeHaven t-shirt on your first shift, $@amount toward lunch for any day you give @hours+ hours, and a MakeHaven hoodie after @n days volunteering.', [
      '@amount' => $p['lunch_amount'],
      '@hours' => $hours,
      '@n' => $p['hoodie_dates'],
    ]);
  }

  /**
   * Days volunteered, per person.
   *
   * @param int|null $uid
   *   One person, or NULL for everyone.
   *
   * @return array<int, array<string, array{hours:float, nids:int[]}>>
   *   uid => [Y-m-d => hours and opportunities], oldest day first.
   */
  public function days(?int $uid = NULL, ?int $now = NULL): array {
    $query = $this->database->select(SignupStore::TABLE, 's')
      ->fields('s', ['uid', 'nid', 'slot'])
      ->condition('kind', SignupStore::KIND_CONFIRMED);
    if ($uid !== NULL) {
      $query->condition('uid', $uid);
    }
    $rows = $query->execute()->fetchAll();
    if (!$rows) {
      return [];
    }
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple(array_unique(array_map(fn($r) => (int) $r->nid, $rows)));
    $now ??= $this->time->getCurrentTime();
    $no_shows = $this->noShows($uid);
    $out = [];
    foreach ($rows as $row) {
      $node = $nodes[(int) $row->nid] ?? NULL;
      if (!$node instanceof NodeInterface || !Opportunity::isShift($node) || !Opportunity::isApproved($node)) {
        continue;
      }
      $slots = Opportunity::slots($node);
      $slot = (int) $row->slot;
      // A slot-0 row on a dated opportunity predates slots: it is the one slot.
      $worked = $slot && isset($slots[$slot]) ? [$slots[$slot]] : (!$slot && count($slots) === 1 ? $slots : []);
      foreach ($worked as $s) {
        if ($s['end'] > $now) {
          continue;
        }
        $day = Opportunity::day($s['start']);
        $ruid = (int) $row->uid;
        if (isset($no_shows[$ruid][$day])) {
          continue;
        }
        $out[$ruid][$day]['hours'] = ($out[$ruid][$day]['hours'] ?? 0) + ($s['end'] - $s['start']) / 3600;
        $out[$ruid][$day]['nids'][(int) $row->nid] = (int) $row->nid;
      }
    }
    foreach ($out as &$days) {
      ksort($days);
      foreach ($days as &$d) {
        $d['nids'] = array_values($d['nids']);
      }
    }
    return $out;
  }

  /**
   * Days marked as a no-show.
   *
   * @return array<int, array<string,bool>>
   *   uid => [Y-m-d => TRUE].
   */
  private function noShows(?int $uid): array {
    $query = $this->database->select(self::TABLE, 'p')
      ->fields('p', ['uid', 'ref'])
      ->condition('perk', self::NO_SHOW);
    if ($uid !== NULL) {
      $query->condition('uid', $uid);
    }
    $out = [];
    foreach ($query->execute() as $row) {
      $out[(int) $row->uid][(string) $row->ref] = TRUE;
    }
    return $out;
  }

  /**
   * Ledger rows already recorded (given or skipped), keyed "uid:perk:ref".
   */
  private function recorded(?int $uid): array {
    $query = $this->database->select(self::TABLE, 'p')->fields('p');
    if ($uid !== NULL) {
      $query->condition('uid', $uid);
    }
    $out = [];
    foreach ($query->execute() as $row) {
      $out[$row->uid . ':' . $row->perk . ':' . $row->ref] = $row;
    }
    return $out;
  }

  /**
   * Thank-yous earned and not yet handed out (or skipped).
   *
   * @param int|null $uid
   *   One person, or NULL for everyone.
   * @param int|null $now
   *   Count slots ended by then (default: now).
   *
   * @return array<int, array{uid:int, perk:string, ref:string, nid:int, day:string, why:string}>
   *   Oldest first.
   */
  public function owed(?int $uid = NULL, ?int $now = NULL): array {
    $p = $this->policy();
    $recorded = $this->recorded($uid);
    $out = [];
    foreach ($this->days($uid, $now) as $u => $days) {
      $first = array_key_first($days);
      if (!isset($recorded["$u:" . self::TSHIRT . ':'])) {
        $out[] = ['uid' => $u, 'perk' => self::TSHIRT, 'ref' => '', 'nid' => $days[$first]['nids'][0], 'day' => $first, 'why' => (string) t('First day volunteering')];
      }
      foreach ($days as $day => $d) {
        if ($d['hours'] + 0.01 >= $p['lunch_hours'] && !isset($recorded["$u:" . self::LUNCH . ":$day"])) {
          $out[] = ['uid' => $u, 'perk' => self::LUNCH, 'ref' => $day, 'nid' => $d['nids'][0], 'day' => $day, 'why' => (string) t('@h hours that day', ['@h' => round($d['hours'], 1)])];
        }
      }
      if (count($days) >= $p['hoodie_dates'] && !isset($recorded["$u:" . self::HOODIE . ':'])) {
        $nth = array_keys($days)[$p['hoodie_dates'] - 1];
        $out[] = ['uid' => $u, 'perk' => self::HOODIE, 'ref' => '', 'nid' => $days[$nth]['nids'][0], 'day' => $nth, 'why' => (string) t('@n days volunteering', ['@n' => count($days)])];
      }
    }
    usort($out, fn($a, $b) => [$a['day'], $a['uid']] <=> [$b['day'], $b['uid']]);
    return $out;
  }

  /**
   * One person's progress, for "you have volunteered on N days" lines.
   *
   * @return array{days:int, hoodie_at:int, to_hoodie:int, has_hoodie:bool}
   *   Counts.
   */
  public function progress(int $uid): array {
    $p = $this->policy();
    $days = count($this->days($uid)[$uid] ?? []);
    $has = isset($this->recorded($uid)["$uid:" . self::HOODIE . ':']);
    return ['days' => $days, 'hoodie_at' => $p['hoodie_dates'], 'to_hoodie' => max(0, $p['hoodie_dates'] - $days), 'has_hoodie' => $has];
  }

  /**
   * Records a thank-you as handed out (or skipped), and takes stock off.
   *
   * @return string
   *   What happened, for a status message.
   */
  public function record(int $uid, string $perk, string $ref, int $nid, AccountInterface $by, string $status = 'given', string $note = ''): string {
    $adjustment = 0;
    $result = '';
    if ($status === 'given' && in_array($perk, [self::TSHIRT, self::HOODIE], TRUE)) {
      [$adjustment, $result] = $this->takeFromStock($perk, $uid, $nid, $note);
    }
    try {
      $this->database->insert(self::TABLE)->fields([
        'uid' => $uid,
        'perk' => $perk,
        'ref' => $ref,
        'nid' => $nid,
        'status' => $status,
        'note' => mb_substr(trim($note), 0, 255),
        'adjustment_id' => $adjustment,
        'by_uid' => (int) $by->id(),
        'created' => $this->time->getCurrentTime(),
      ])->execute();
    }
    catch (IntegrityConstraintViolationException $e) {
      return (string) t('That was already recorded.');
    }
    $this->loggerFactory->get('makehaven_tasks')->notice('Volunteer thank-you @perk (@ref) @status for uid @uid by @by. @note', [
      '@perk' => $perk,
      '@ref' => $ref ?: '-',
      '@status' => $status,
      '@uid' => $uid,
      '@by' => $by->getAccountName(),
      '@note' => $note,
    ]);
    return $result;
  }

  /**
   * Marks a day as a no-show, which takes it out of every count.
   */
  public function noShow(int $uid, string $day, int $nid, AccountInterface $by): void {
    $this->record($uid, self::NO_SHOW, $day, $nid, $by, 'given', 'No-show');
  }

  /**
   * Takes one t-shirt or hoodie off the store's shelf count.
   *
   * @return array{0:int, 1:string}
   *   The adjustment id (0 when none) and a message.
   */
  private function takeFromStock(string $perk, int $uid, int $nid, string $note): array {
    $p = $this->policy();
    $material_nid = $perk === self::HOODIE ? $p['hoodie_material'] : $p['tshirt_material'];
    if (!$material_nid || !$this->entityTypeManager->hasDefinition('material_inventory')) {
      return [0, (string) t('No store item is set for this, so the shelf count was not changed.')];
    }
    $material = $this->entityTypeManager->getStorage('node')->load($material_nid);
    if (!$material instanceof NodeInterface) {
      return [0, (string) t('Store item @nid not found, so the shelf count was not changed.', ['@nid' => $material_nid])];
    }
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    $memo = sprintf('Volunteer thank-you for %s%s%s',
      $user instanceof UserInterface ? $user->getDisplayName() : "uid $uid",
      $nid ? " (task $nid)" : '',
      trim($note) !== '' ? ': ' . trim($note) : '');
    try {
      $adjustment = $this->entityTypeManager->getStorage('material_inventory')->create([
        'type' => 'inventory_adjustment',
        'field_inventory_ref_material' => $material_nid,
        'field_inventory_quantity_change' => -1,
        'field_inventory_change_reason' => 'volunteer_appreciation',
        'field_inventory_change_memo' => mb_substr($memo, 0, 255),
      ]);
      $adjustment->save();
      return [(int) $adjustment->id(), (string) t('One @item taken off the store count.', ['@item' => $material->label()])];
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('makehaven_tasks')->error('Volunteer thank-you: could not adjust store stock for @item: @error', ['@item' => $material_nid, '@error' => $e->getMessage()]);
      return [0, (string) t('Recorded, but the store count could not be changed: @error', ['@error' => $e->getMessage()])];
    }
  }

  /**
   * A short label for a perk.
   */
  public static function label(string $perk): string {
    return match ($perk) {
      self::TSHIRT => (string) t('T-shirt'),
      self::LUNCH => (string) t('Lunch'),
      self::HOODIE => (string) t('Hoodie'),
      self::NO_SHOW => (string) t('No-show'),
      default => $perk,
    };
  }

}
