<?php

namespace Drupal\Tests\makehaven_tasks\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\flag\Entity\Flag;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

/**
 * Tests audience-based task filtering, personal history, and facilitator view.
 *
 * These tests exercise the business logic that backs:
 *   - hook_views_query_alter (staff_only visibility)
 *   - TaskPersonalHistoryController
 *   - TaskFacilitatorController
 *
 * @group makehaven_tasks
 */
class TaskAudienceFilterKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'options',
    'link',
    'datetime',
    'flag',
    'slack_connector',
    'slack_task_poster',
    'makehaven_tasks',
  ];

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  protected User $member;
  protected User $facilitator;
  protected User $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('system', ['sequences']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('flag', ['flag_counts']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('flagging');
    $this->installConfig(['field', 'node', 'user', 'system', 'flag']);

    $this->config('slack_connector.settings')->set('webhook_url', '')->save();

    NodeType::create(['type' => 'item', 'name' => 'Item'])->save();
    NodeType::create(['type' => 'task', 'name' => 'Task'])->save();

    $this->installTaskFields();
    $this->installFlag();
    $this->installRoles();

    $this->member = $this->createUser(['member']);
    $this->facilitator = $this->createUser(['facilitator']);
    $this->manager = $this->createUser(['manager']);
  }

  // ---------------------------------------------------------------------------
  // Audience filtering — staff_only visibility
  // ---------------------------------------------------------------------------

  /**
   * Non-staff users cannot see staff_only tasks in query results.
   *
   * This mirrors the condition added by hook_views_query_alter for
   * non-staff users. We test the underlying query directly to keep tests
   * fast (no full Views stack needed).
   */
  public function testStaffOnlyTasksHiddenFromMembers(): void {
    $this->createTask('Open task', 'open_member');
    $this->createTask('Badge task', 'badge_holders');
    $this->createTask('Staff task', 'staff_only');

    $member_visible = $this->queryTasksByAudience(['open_member', 'badge_holders']);
    $this->assertCount(2, $member_visible, 'Members see open_member and badge_holders tasks.');
    $titles = array_column($member_visible, 'title');
    $this->assertNotContains('Staff task', $titles, 'staff_only not visible to members.');
  }

  /**
   * Staff users can see all audience types including staff_only.
   */
  public function testStaffCanSeeAllAudiences(): void {
    $this->createTask('Open task', 'open_member');
    $this->createTask('Badge task', 'badge_holders');
    $this->createTask('Staff task', 'staff_only');

    // Staff have no audience restriction — they see everything.
    $all = $this->queryAllOpenTasks();
    $titles = array_column($all, 'title');
    $this->assertContains('Open task', $titles);
    $this->assertContains('Badge task', $titles);
    $this->assertContains('Staff task', $titles);
  }

  /**
   * The facilitator view returns badge_holders + staff_only tasks,
   * excluding open_member tasks.
   */
  public function testFacilitatorViewReturnsSpecialistTasksOnly(): void {
    $this->createTask('Open task', 'open_member');
    $this->createTask('Badge task', 'badge_holders');
    $this->createTask('Staff task', 'staff_only');

    $specialist = $this->queryTasksByAudience(['badge_holders', 'staff_only']);
    $titles = array_column($specialist, 'title');
    $this->assertNotContains('Open task', $titles, 'open_member tasks should not appear in facilitator view.');
    $this->assertContains('Badge task', $titles);
    $this->assertContains('Staff task', $titles);
  }

  /**
   * Facilitator view excludes tasks already flagged complete.
   */
  public function testFacilitatorViewExcludesCompletedTasks(): void {
    $done = $this->createTask('Done badge task', 'badge_holders');
    $open = $this->createTask('Open badge task', 'badge_holders');

    $flag = Flag::load('task_completed');
    \Drupal::service('flag')->flag($flag, $done, $this->facilitator);

    // The controller filters out flagged tasks — replicate that logic here.
    $flag_service = \Drupal::service('flag');
    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'task')
      ->condition('status', 1)
      ->condition('field_task_audience', ['badge_holders', 'staff_only'], 'IN')
      ->accessCheck(FALSE)
      ->execute();

    $open_nids = array_filter($nids, function ($nid) use ($flag_service, $flag) {
      $node = Node::load($nid);
      return $node && empty($flag_service->getEntityFlaggings($flag, $node));
    });

    $this->assertCount(1, $open_nids, 'Completed task filtered out of facilitator view.');
    $remaining = Node::load(reset($open_nids));
    $this->assertEquals('Open badge task', $remaining->label());
  }

  // ---------------------------------------------------------------------------
  // Personal history tests
  // ---------------------------------------------------------------------------

  /**
   * Personal history shows only the requesting user's completed tasks.
   */
  public function testPersonalHistoryIsolatedPerUser(): void {
    $task_a = $this->createTask('Member task');
    $task_b = $this->createTask('Manager task');

    $flag = Flag::load('task_completed');
    $flag_service = \Drupal::service('flag');

    $flag_service->flag($flag, $task_a, $this->member);
    $flag_service->flag($flag, $task_b, $this->manager);

    $member_history = $this->loadCompletedTasksForUser($this->member->id());
    $this->assertCount(1, $member_history, 'Member sees only their own completions.');
    $this->assertEquals('Member task', Node::load($member_history[0])->label());

    $manager_history = $this->loadCompletedTasksForUser($this->manager->id());
    $this->assertCount(1, $manager_history, 'Manager sees only their own completions.');
    $this->assertEquals('Manager task', Node::load($manager_history[0])->label());
  }

  /**
   * Personal history returns multiple completions in newest-first order.
   *
   * Sets explicit created timestamps on flaggings to avoid sub-second
   * flakiness from the kernel test environment.
   */
  public function testPersonalHistoryOrderedNewestFirst(): void {
    $flag = Flag::load('task_completed');
    $flag_service = \Drupal::service('flag');

    $first = $this->createTask('First task');
    $second = $this->createTask('Second task');

    $flag_service->flag($flag, $first, $this->member);
    $flag_service->flag($flag, $second, $this->member);

    // Override created timestamps so the sort order is deterministic.
    $flaggings = \Drupal::entityTypeManager()
      ->getStorage('flagging')
      ->loadByProperties(['flag_id' => 'task_completed', 'uid' => $this->member->id()]);

    foreach ($flaggings as $flagging) {
      $nid = $flagging->getFlaggableId();
      // First task gets an older timestamp; second task gets a newer one.
      $flagging->set('created', $nid === $first->id() ? 1000000 : 2000000);
      $flagging->save();
    }

    $history_nids = $this->loadCompletedTasksForUser($this->member->id());
    $this->assertCount(2, $history_nids);
    $this->assertEquals('Second task', Node::load($history_nids[0])->label(), 'Newest completion appears first.');
    $this->assertEquals('First task', Node::load($history_nids[1])->label(), 'Oldest completion appears second.');
  }

  /**
   * A user with no completions gets an empty history.
   */
  public function testPersonalHistoryEmptyForNewMember(): void {
    $history = $this->loadCompletedTasksForUser($this->member->id());
    $this->assertEmpty($history, 'New member has no task history.');
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Creates a published task node with the given audience.
   */
  protected function createTask(string $title, string $audience = 'open_member'): Node {
    $node = Node::create([
      'type' => 'task',
      'title' => $title,
      'status' => NodeInterface::PUBLISHED,
      'uid' => $this->manager->id(),
      'field_task_audience' => $audience,
      'field_task_status' => 'open',
      'field_task_frequency' => 'once',
    ]);
    $node->save();
    return $node;
  }

  /**
   * Queries open tasks for the given audience values (mirrors controller logic).
   */
  protected function queryTasksByAudience(array $audiences): array {
    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'task')
      ->condition('status', 1)
      ->condition('field_task_audience', $audiences, 'IN')
      ->accessCheck(FALSE)
      ->execute();

    $result = [];
    foreach ($nids as $nid) {
      $node = Node::load($nid);
      if ($node) {
        $result[] = ['nid' => $nid, 'title' => $node->label()];
      }
    }
    return $result;
  }

  /**
   * Queries all published tasks (no audience filter — what staff see).
   */
  protected function queryAllOpenTasks(): array {
    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'task')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->execute();

    $result = [];
    foreach ($nids as $nid) {
      $node = Node::load($nid);
      if ($node) {
        $result[] = ['nid' => $nid, 'title' => $node->label()];
      }
    }
    return $result;
  }

  /**
   * Loads completed task node IDs for a user, newest-first (mirrors controller).
   */
  protected function loadCompletedTasksForUser(int $uid): array {
    $flaggings = \Drupal::entityTypeManager()
      ->getStorage('flagging')
      ->loadByProperties([
        'flag_id' => 'task_completed',
        'uid' => $uid,
        'entity_type' => 'node',
      ]);

    uasort($flaggings, fn($a, $b) => $b->get('created')->value <=> $a->get('created')->value);

    return array_values(array_map(fn($f) => $f->getFlaggableId(), $flaggings));
  }

  /**
   * Creates a user with the given roles.
   */
  protected function createUser(array $roles = []): User {
    static $counter = 0;
    $counter++;
    $user = User::create([
      'name' => 'test_user_' . $counter,
      'status' => 1,
      'roles' => $roles,
    ]);
    $user->save();
    return $user;
  }

  /**
   * Creates the roles used in tests.
   */
  protected function installRoles(): void {
    foreach (['member', 'facilitator', 'manager'] as $rid) {
      if (!Role::load($rid)) {
        Role::create(['id' => $rid, 'label' => ucfirst($rid)])->save();
      }
    }
  }

  /**
   * Installs the task_completed flag.
   */
  protected function installFlag(): void {
    Flag::create([
      'id' => 'task_completed',
      'label' => 'Task Completed',
      'entity_type' => 'node',
      'bundles' => ['task'],
      'flag_type' => 'entity:node',
      'link_type' => 'reload',
      'flagTypeConfig' => [],
      'linkTypeConfig' => [],
      'global' => FALSE,
    ])->save();
  }

  /**
   * Creates all field storage and instance configs needed for the task bundle.
   *
   * Must include every field touched by module hooks (node_insert, node_presave,
   * cron) to avoid "Field X is unknown" errors when nodes are saved in tests.
   */
  protected function installTaskFields(): void {
    $list_fields = [
      'field_task_frequency' => [
        'always' => 'Always', 'once' => 'Once', 'weekly' => 'Weekly',
        'monthly' => 'Monthly', 'quarterly' => 'Quarterly',
        'yearly' => 'Yearly', 'biennial' => 'Biennial', 'occasional' => 'Occasional',
      ],
      'field_task_status' => [
        'open' => 'Open', 'in_progress' => 'In Progress', 'incomplete' => 'Incomplete',
      ],
      'field_task_audience' => [
        'open_member' => 'Open', 'badge_holders' => 'Badge holders', 'staff_only' => 'Staff only',
      ],
    ];

    foreach ($list_fields as $name => $values) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => 'list_string',
        'settings' => ['allowed_values' => $values],
      ])->save();
      FieldConfig::create([
        'field_name' => $name, 'entity_type' => 'node',
        'bundle' => 'task', 'label' => $name,
      ])->save();
    }

    // Entity reference and other fields accessed by module hooks.
    $ref_fields = [
      ['name' => 'field_task_lead',         'target' => 'user'],
      ['name' => 'field_task_claimed_by',   'target' => 'user'],
      ['name' => 'field_task_series_source','target' => 'node'],
      ['name' => 'field_task_equipment',    'target' => 'node'],
    ];
    foreach ($ref_fields as $spec) {
      FieldStorageConfig::create([
        'field_name' => $spec['name'], 'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => ['target_type' => $spec['target']],
      ])->save();
      FieldConfig::create([
        'field_name' => $spec['name'], 'entity_type' => 'node',
        'bundle' => 'task', 'label' => $spec['name'],
      ])->save();
    }

    // Scalar fields accessed by hooks.
    foreach (['field_task_next_due' => 'datetime', 'field_task_series_paused' => 'boolean', 'field_task_estimated_hours' => 'float'] as $name => $type) {
      FieldStorageConfig::create([
        'field_name' => $name, 'entity_type' => 'node', 'type' => $type,
      ])->save();
      FieldConfig::create([
        'field_name' => $name, 'entity_type' => 'node',
        'bundle' => 'task', 'label' => $name,
      ])->save();
    }
  }

}
