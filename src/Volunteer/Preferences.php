<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Volunteer;

use Drupal\Core\Database\Connection;
use Drupal\node\NodeInterface;
use Drupal\user\UserDataInterface;

/**
 * How a person would like to help, and whether to email them about matches.
 *
 * Replaces the old "Sign up to be a MakeHaven outreach volunteer" webform,
 * which collected the same answers and then nothing used them. Here they
 * highlight matching opportunities on the board, email people who opted in
 * when a matching one is posted, and give staff a pool to ask from (press,
 * sponsors, flyers: the outreach work that is not a dated shift).
 *
 * Stored in user.data (module makehaven_tasks, name volunteer_prefs).
 */
final class Preferences {

  public const KEY = 'volunteer_prefs';

  public function __construct(
    private readonly UserDataInterface $userData,
    private readonly Connection $database,
  ) {}

  /**
   * The ways to help, in the order shown.
   *
   * @return array<string,string>
   *   key => label.
   */
  public static function ways(): array {
    return [
      'tours' => (string) t('Give tours of MakeHaven'),
      'tabling' => (string) t('Staff a MakeHaven table at community events'),
      'events' => (string) t('Help at events here (open houses, build days, setup)'),
      'fixing' => (string) t('Fix and maintain tools'),
      'cleaning' => (string) t('Clean and organize the shop'),
      'teaching' => (string) t('Teach, mentor or demo a skill'),
      'flyers' => (string) t('Put up flyers and posters in my neighborhood'),
      'social' => (string) t('Share MakeHaven on social media'),
      'press' => (string) t('Help get press coverage or write content'),
      'connect' => (string) t('Introduce MakeHaven to groups, businesses, sponsors or funders I know'),
      'meetup' => (string) t('Host a meetup or interest group'),
    ];
  }

  /**
   * When people are free.
   *
   * @return array<string,string>
   *   key => label.
   */
  public static function availability(): array {
    return [
      'weekdays' => (string) t('Weekdays'),
      'evenings' => (string) t('Evenings'),
      'weekends' => (string) t('Weekends'),
      'flexible' => (string) t('Flexible'),
    ];
  }

  /**
   * The old outreach webform's choices, mapped to ways.
   */
  public static function legacyWayMap(): array {
    return [
      'group_awareness' => 'connect',
      'group_tours' => 'tours',
      'event_table' => 'tabling',
      'flyer_distribution' => 'flyers',
      'host_meetup' => 'meetup',
      'news_coverage' => 'press',
      'social_media' => 'social',
      'business_partnership' => 'connect',
      'sponsor_introductions' => 'connect',
      'invite_friends' => 'connect',
    ];
  }

  /**
   * One person's answers.
   *
   * @return array{ways:string[], availability:string[], connections:string, notify:bool, updated:int}
   *   Empty answers when they have never filled it in.
   */
  public function get(int $uid): array {
    $data = $this->userData->get('makehaven_tasks', $uid, self::KEY);
    $data = is_array($data) ? $data : [];
    return [
      'ways' => array_values(array_intersect((array) ($data['ways'] ?? []), array_keys(self::ways()))),
      'availability' => array_values(array_intersect((array) ($data['availability'] ?? []), array_keys(self::availability()))),
      'connections' => (string) ($data['connections'] ?? ''),
      'notify' => !empty($data['notify']),
      'updated' => (int) ($data['updated'] ?? 0),
    ];
  }

  /**
   * Saves one person's answers.
   */
  public function set(int $uid, array $prefs): void {
    $this->userData->set('makehaven_tasks', $uid, self::KEY, [
      'ways' => array_values(array_intersect((array) ($prefs['ways'] ?? []), array_keys(self::ways()))),
      'availability' => array_values(array_intersect((array) ($prefs['availability'] ?? []), array_keys(self::availability()))),
      'connections' => mb_substr(trim((string) ($prefs['connections'] ?? '')), 0, 2000),
      'notify' => !empty($prefs['notify']),
      'updated' => \Drupal::time()->getCurrentTime(),
    ]);
  }

  /**
   * Whether the person has ever answered.
   */
  public function has(int $uid): bool {
    return $this->get($uid)['updated'] > 0;
  }

  /**
   * Everyone who has answered.
   *
   * @return array<int, array>
   *   uid => answers (as get()).
   */
  public function all(): array {
    $uids = $this->database->select('users_data', 'd')
      ->fields('d', ['uid'])
      ->condition('module', 'makehaven_tasks')
      ->condition('name', self::KEY)
      ->execute()
      ->fetchCol();
    $out = [];
    foreach ($uids as $uid) {
      $out[(int) $uid] = $this->get((int) $uid);
    }
    return $out;
  }

  /**
   * The ways an opportunity calls on, for matching.
   *
   * Only the kinds of help the board posts: tabling, tours and events here,
   * fixing, cleaning. Outreach that is never a shift (press, sponsors, flyers)
   * is for staff to ask the pool about directly.
   *
   * @return string[]
   *   Way keys.
   */
  public static function waysFor(NodeInterface $node): array {
    if (Opportunity::isTabling($node)) {
      return ['tabling'];
    }
    $title = mb_strtolower((string) $node->label());
    if (Opportunity::isShift($node)) {
      $ways = ['events'];
      if (str_contains($title, 'tour')) {
        $ways[] = 'tours';
      }
      if (preg_match('/\b(demo|teach|mentor|showcase)/', $title)) {
        $ways[] = 'teaching';
      }
      return $ways;
    }
    $category = $node->hasField('field_task_category') ? (string) $node->get('field_task_category')->value : '';
    if (in_array($category, ['cleaning', 'restocking'], TRUE)) {
      return ['cleaning'];
    }
    if (in_array($category, ['maintenance', 'inspection'], TRUE)
      || ($node->hasField('field_task_equipment') && !$node->get('field_task_equipment')->isEmpty())) {
      return ['fixing'];
    }
    return [];
  }

  /**
   * Whether an opportunity matches what this person said they like doing.
   */
  public function matches(NodeInterface $node, int $uid): bool {
    $ways = self::waysFor($node);
    return $ways && array_intersect($ways, $this->get($uid)['ways']);
  }

  /**
   * People who asked to be emailed about this kind of opportunity.
   *
   * @return int[]
   *   uids.
   */
  public function subscribersFor(NodeInterface $node): array {
    $ways = self::waysFor($node);
    if (!$ways) {
      return [];
    }
    $out = [];
    foreach ($this->all() as $uid => $prefs) {
      if ($prefs['notify'] && array_intersect($ways, $prefs['ways'])) {
        $out[] = $uid;
      }
    }
    return $out;
  }

}
