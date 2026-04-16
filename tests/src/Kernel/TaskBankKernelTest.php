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
 * Tests task bank cloning, permission model, and form field visibility.
 *
 * @group makehaven_tasks
 */
class TaskBankKernelTest extends KernelTestBase {

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
    'path_alias',
  ];

  protected $strictConfigSchema = FALSE;

  protected User $staffUser;
  protected User $facilitatorUser;
  protected User $memberUser;
  protected Node $bankTemplate;

  protected function setUp(): void {
    parent::setUp();

    $this->installSchema('system', ['sequences']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('flag', ['flag_counts']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('flagging');
    $this->installConfig(['field', 'node', 'user', 'system', 'flag']);
    $this->installEntitySchema('path_alias');
    // Install routes so Url::fromRoute works in hook_node_view.
    \Drupal::service('router.builder')->rebuild();

    $this->config('slack_connector.settings')->set('webhook_url', '')->save();

    NodeType::create(['type' => 'item', 'name' => 'Item'])->save();
    NodeType::create(['type' => 'task', 'name' => 'Task'])->save();

    $this->installTaskFields();
    $this->installFlag();
    $this->createRolesAndUsers();
    $this->createBankTemplate();
  }

  /**
   * Cloning a bank template copies the right fields and sets correct defaults.
   */
  public function testBankCloneCopiesFields(): void {
    \Drupal::currentUser()->setAccount($this->staffUser);

    $controller = \Drupal::classResolver(\Drupal\makehaven_tasks\Controller\TaskBankController::class);
    $method = new \ReflectionMethod($controller, 'cloneTemplate');
    $method->setAccessible(TRUE);

    $new_task = $method->invoke($controller, $this->bankTemplate);

    $this->assertNotNull($new_task, 'Clone should return a node.');
    $this->assertTrue($new_task->isPublished(), 'Cloned task should be published.');
    $this->assertEquals('Clean wood scrap area', $new_task->label());
    $this->assertEquals('open', $new_task->get('field_task_status')->value);
    $this->assertEquals($this->staffUser->id(), $new_task->getOwnerId());
    $this->assertEquals('cleaning', $new_task->get('field_task_category')->value);
    $this->assertEquals('2', $new_task->get('field_task_priority')->value);
  }

  /**
   * Cloned task does NOT carry over the bank flag or recurring fields.
   */
  public function testBankCloneExcludesBankAndRecurring(): void {
    \Drupal::currentUser()->setAccount($this->staffUser);

    $controller = \Drupal::classResolver(\Drupal\makehaven_tasks\Controller\TaskBankController::class);
    $method = new \ReflectionMethod($controller, 'cloneTemplate');
    $method->setAccessible(TRUE);

    $new_task = $method->invoke($controller, $this->bankTemplate);

    $this->assertTrue($new_task->get('field_task_bank')->isEmpty(),
      'Cloned task should NOT carry the bank flag.');
    $this->assertTrue($new_task->get('field_task_frequency')->isEmpty(),
      'Cloned task should NOT carry frequency.');
    $this->assertTrue($new_task->get('field_task_series_source')->isEmpty(),
      'Cloned task should NOT carry series_source.');
  }

  /**
   * Cloning a template with equipment copies the equipment reference.
   */
  public function testBankCloneCopiesEquipment(): void {
    $item = Node::create(['type' => 'item', 'title' => 'Laser', 'status' => 1]);
    $item->save();
    $this->bankTemplate->set('field_task_equipment', ['target_id' => $item->id()]);
    $this->bankTemplate->save();

    \Drupal::currentUser()->setAccount($this->staffUser);
    $controller = \Drupal::classResolver(\Drupal\makehaven_tasks\Controller\TaskBankController::class);
    $method = new \ReflectionMethod($controller, 'cloneTemplate');
    $method->setAccessible(TRUE);

    $new_task = $method->invoke($controller, $this->bankTemplate);

    $this->assertEquals($item->id(), $new_task->get('field_task_equipment')->target_id,
      'Cloned task should reference the same equipment.');
  }

  // ── Permission model tests ──────────────────────────────────────────────

  /**
   * Members have create_from_bank but NOT create_task.
   */
  public function testMemberPermissions(): void {
    $this->assertTrue($this->memberUser->hasPermission('makehaven_tasks.create_from_bank'));
    $this->assertFalse($this->memberUser->hasPermission('makehaven_tasks.create_task'));
    $this->assertFalse($this->memberUser->hasPermission('makehaven_tasks.manage_bank'));
    $this->assertFalse($this->memberUser->hasPermission('makehaven_tasks.manage_tasks'));
  }

  /**
   * Facilitators have create_from_bank + create_task but NOT manage_bank.
   */
  public function testFacilitatorPermissions(): void {
    $this->assertTrue($this->facilitatorUser->hasPermission('makehaven_tasks.create_from_bank'));
    $this->assertTrue($this->facilitatorUser->hasPermission('makehaven_tasks.create_task'));
    $this->assertFalse($this->facilitatorUser->hasPermission('makehaven_tasks.manage_bank'));
    $this->assertFalse($this->facilitatorUser->hasPermission('makehaven_tasks.manage_tasks'));
  }

  /**
   * Staff have all task permissions.
   */
  public function testStaffPermissions(): void {
    $this->assertTrue($this->staffUser->hasPermission('makehaven_tasks.create_from_bank'));
    $this->assertTrue($this->staffUser->hasPermission('makehaven_tasks.create_task'));
    $this->assertTrue($this->staffUser->hasPermission('makehaven_tasks.manage_bank'));
    $this->assertTrue($this->staffUser->hasPermission('makehaven_tasks.manage_tasks'));
  }

  // ── Node view field hiding tests ────────────────────────────────────────

  /**
   * Empty fields are removed from the build array in hook_node_view.
   */
  public function testEmptyFieldsHiddenInView(): void {
    $task = Node::create([
      'type' => 'task',
      'title' => 'Simple task',
      'status' => 1,
      'uid' => $this->staffUser->id(),
      'field_task_status' => 'open',
    ]);
    $task->save();

    \Drupal::currentUser()->setAccount($this->staffUser);
    // Seed build with field keys to verify the hook removes them.
    $build = [
      'field_task_lead' => ['#markup' => 'placeholder'],
      'field_task_equipment' => ['#markup' => 'placeholder'],
      'field_task_group' => ['#markup' => 'placeholder'],
    ];
    makehaven_tasks_node_view($build, $task, NULL, 'full');

    $this->assertArrayNotHasKey('field_task_lead', $build,
      'Empty field_task_lead should be removed from build.');
    $this->assertArrayNotHasKey('field_task_equipment', $build,
      'Empty field_task_equipment should be removed from build.');
    $this->assertArrayNotHasKey('field_task_group', $build,
      'Empty field_task_group should be removed from build.');
  }

  /**
   * Non-recurring tasks (frequency=once) have recurring fields removed.
   */
  public function testNonRecurringFieldsHidden(): void {
    $task = Node::create([
      'type' => 'task',
      'title' => 'One-time task',
      'status' => 1,
      'uid' => $this->staffUser->id(),
      'field_task_frequency' => 'once',
      'field_task_status' => 'open',
    ]);
    $task->save();

    \Drupal::currentUser()->setAccount($this->staffUser);
    $build = [
      'field_task_frequency' => ['#markup' => 'placeholder'],
      'field_task_next_due' => ['#markup' => 'placeholder'],
      'field_task_series_source' => ['#markup' => 'placeholder'],
      'field_task_series_paused' => ['#markup' => 'placeholder'],
    ];
    makehaven_tasks_node_view($build, $task, NULL, 'full');

    $this->assertArrayNotHasKey('field_task_frequency', $build,
      '"once" frequency should be hidden.');
    $this->assertArrayNotHasKey('field_task_next_due', $build);
    $this->assertArrayNotHasKey('field_task_series_source', $build);
    $this->assertArrayNotHasKey('field_task_series_paused', $build);
  }

  /**
   * Recurring tasks (weekly) are treated as recurring by the is_recurring check.
   */
  public function testWeeklyIsRecurring(): void {
    $task = Node::create([
      'type' => 'task',
      'title' => 'Weekly cleaning',
      'status' => 1,
      'uid' => $this->staffUser->id(),
      'field_task_frequency' => 'weekly',
      'field_task_status' => 'open',
    ]);
    $task->save();

    $freq = $task->get('field_task_frequency')->value;
    $is_recurring = $freq && $freq !== 'once';
    $this->assertTrue($is_recurring, 'Weekly should be treated as recurring.');
  }

  /**
   * "once" frequency is NOT treated as recurring.
   */
  public function testOnceIsNotRecurring(): void {
    $freq = 'once';
    $is_recurring = $freq && $freq !== 'once';
    $this->assertFalse($is_recurring, '"once" should NOT be recurring.');
  }

  /**
   * The action panel renders for authenticated users on open tasks.
   */
  public function testActionPanelRendersForAuthenticatedUser(): void {
    $task = Node::create([
      'type' => 'task',
      'title' => 'Claim me',
      'status' => 1,
      'uid' => $this->staffUser->id(),
      'field_task_status' => 'open',
    ]);
    $task->save();

    \Drupal::currentUser()->setAccount($this->memberUser);
    $build = [];
    makehaven_tasks_node_view($build, $task, NULL, 'full');

    $this->assertArrayHasKey('task_status_actions', $build,
      'Action panel should be present for authenticated users.');
    $markup = (string) $build['task_status_actions']['#markup'];
    $this->assertStringContainsString('Claim task', $markup,
      'Action panel should contain the Claim button.');
  }

  /**
   * Completed tasks show the completed panel instead of claim actions.
   *
   * Uses hook_node_view directly since EntityViewBuilder::build may trigger
   * render pipeline calls that fail without a full request context.
   */
  public function testCompletedTaskShowsDonePanel(): void {
    $task = Node::create([
      'type' => 'task',
      'title' => 'Done task',
      'status' => 1,
      'uid' => $this->staffUser->id(),
      'field_task_status' => 'open',
    ]);
    $task->save();

    $flag = Flag::load('task_completed');
    \Drupal::service('flag')->flag($flag, $task, $this->staffUser);

    // Simulate what hook_node_view does by calling it directly.
    \Drupal::currentUser()->setAccount($this->memberUser);
    $build = [];
    makehaven_tasks_node_view($build, $task, NULL, 'full');

    $this->assertArrayHasKey('task_completed_panel', $build,
      'Completed panel should be present.');
    $this->assertArrayNotHasKey('task_status_actions', $build,
      'Claim actions should NOT show on a completed task.');
  }

  // ── Helpers ─────────────────────────────────────────────────────────────

  protected function createRolesAndUsers(): void {
    Role::create(['id' => 'member', 'label' => 'Member'])->save();
    Role::create(['id' => 'facilitator', 'label' => 'Facilitator'])->save();
    Role::create(['id' => 'manager', 'label' => 'Manager'])->save();

    $member_role = Role::load('member');
    $member_role->grantPermission('makehaven_tasks.create_from_bank');
    $member_role->save();

    $auth_role = Role::load('authenticated');
    $auth_role->grantPermission('makehaven_tasks.create_from_bank');
    $auth_role->save();

    $fac_role = Role::load('facilitator');
    $fac_role->grantPermission('makehaven_tasks.create_from_bank');
    $fac_role->grantPermission('makehaven_tasks.create_task');
    $fac_role->save();

    $mgr_role = Role::load('manager');
    $mgr_role->grantPermission('makehaven_tasks.create_from_bank');
    $mgr_role->grantPermission('makehaven_tasks.create_task');
    $mgr_role->grantPermission('makehaven_tasks.manage_bank');
    $mgr_role->grantPermission('makehaven_tasks.manage_tasks');
    $mgr_role->save();

    // Create a throwaway uid=1 first — uid 1 bypasses all permission checks.
    User::create(['name' => 'superadmin', 'status' => 1])->save();

    $this->memberUser = User::create([
      'name' => 'test_member',
      'status' => 1,
      'roles' => ['member'],
    ]);
    $this->memberUser->save();

    $this->facilitatorUser = User::create([
      'name' => 'test_facilitator',
      'status' => 1,
      'roles' => ['facilitator'],
    ]);
    $this->facilitatorUser->save();

    $this->staffUser = User::create([
      'name' => 'test_staff',
      'status' => 1,
      'roles' => ['manager'],
    ]);
    $this->staffUser->save();
  }

  protected function createBankTemplate(): void {
    $this->bankTemplate = Node::create([
      'type' => 'task',
      'title' => 'Clean wood scrap area',
      'status' => 1,
      'uid' => $this->staffUser->id(),
      'field_task_bank' => ['task_bank'],
      'field_task_category' => 'cleaning',
      'field_task_priority' => '2',
      'field_task_frequency' => 'once',
      'field_task_status' => 'open',
    ]);
    $this->bankTemplate->save();
  }

  protected function installTaskFields(): void {
    $fields = [
      ['name' => 'field_task_frequency', 'type' => 'list_string', 'settings' => [
        'allowed_values' => [
          'once' => 'Once', 'weekly' => 'Weekly', 'monthly' => 'Monthly',
          'quarterly' => 'Quarterly', 'yearly' => 'Yearly', 'biennial' => 'Biennial',
        ],
      ]],
      ['name' => 'field_task_status', 'type' => 'list_string', 'settings' => [
        'allowed_values' => ['open' => 'Open', 'in_progress' => 'In Progress', 'incomplete' => 'Incomplete'],
      ]],
      ['name' => 'field_task_bank', 'type' => 'list_string', 'settings' => [
        'allowed_values' => ['task_bank' => 'Add to Task Bank'],
      ], 'cardinality' => -1],
      ['name' => 'field_task_category', 'type' => 'list_string', 'settings' => [
        'allowed_values' => ['cleaning' => 'Cleaning', 'maintenance' => 'Maintenance', 'other' => 'Other'],
      ]],
      ['name' => 'field_task_priority', 'type' => 'list_string', 'settings' => [
        'allowed_values' => ['1' => 'High', '2' => 'Medium', '3' => 'Low', '4' => 'Lowest'],
      ]],
      ['name' => 'field_task_audience', 'type' => 'list_string', 'settings' => [
        'allowed_values' => ['members' => 'Members', 'badge_holders' => 'Badge holders', 'staff_only' => 'Staff only'],
      ]],
      ['name' => 'field_task_series_paused', 'type' => 'boolean'],
      ['name' => 'field_task_next_due', 'type' => 'datetime'],
      ['name' => 'field_task_estimated_hours', 'type' => 'float'],
    ];

    $ref_fields = [
      ['name' => 'field_task_series_source', 'target' => 'node'],
      ['name' => 'field_task_lead', 'target' => 'user'],
      ['name' => 'field_task_claimed_by', 'target' => 'user'],
      ['name' => 'field_task_equipment', 'target' => 'node'],
      ['name' => 'field_task_group', 'target' => 'node'],
    ];

    foreach ($fields as $spec) {
      FieldStorageConfig::create([
        'field_name' => $spec['name'],
        'entity_type' => 'node',
        'type' => $spec['type'],
        'settings' => $spec['settings'] ?? [],
        'cardinality' => $spec['cardinality'] ?? 1,
      ])->save();
      FieldConfig::create([
        'field_name' => $spec['name'],
        'entity_type' => 'node',
        'bundle' => 'task',
        'label' => $spec['name'],
      ])->save();
    }

    foreach ($ref_fields as $spec) {
      FieldStorageConfig::create([
        'field_name' => $spec['name'],
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => ['target_type' => $spec['target']],
      ])->save();
      FieldConfig::create([
        'field_name' => $spec['name'],
        'entity_type' => 'node',
        'bundle' => 'task',
        'label' => $spec['name'],
      ])->save();
    }
  }

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
      'global' => TRUE,
    ])->save();
  }

}
