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
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests access control on claim route, audience enforcement, and bank guards.
 *
 * @group makehaven_tasks
 */
class TaskAccessKernelTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'options', 'link',
    'datetime', 'flag', 'slack_connector', 'slack_task_poster',
    'makehaven_tasks', 'path_alias',
  ];

  protected $strictConfigSchema = FALSE;

  protected User $staffUser;
  protected User $facilitatorUser;
  protected User $memberUser;

  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('system', ['sequences']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('flag', ['flag_counts']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('flagging');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['field', 'node', 'user', 'system', 'flag']);
    $this->config('slack_connector.settings')->set('webhook_url', '')->save();
    \Drupal::service('router.builder')->rebuild();

    NodeType::create(['type' => 'item', 'name' => 'Item'])->save();
    NodeType::create(['type' => 'task', 'name' => 'Task'])->save();
    $this->installTaskFields();
    $this->installFlag();
    $this->createRolesAndUsers();
  }

  // ── Claim route: already-claimed guard ──────────────────────────────────

  /**
   * A member cannot steal a task already claimed by another user.
   */
  public function testClaimBlockedWhenAlreadyClaimedByOther(): void {
    $task = $this->createTask(['field_task_status' => 'in_progress', 'field_task_claimed_by' => ['target_id' => $this->facilitatorUser->id()]]);

    \Drupal::currentUser()->setAccount($this->memberUser);
    $controller = \Drupal::classResolver(\Drupal\makehaven_tasks\Controller\TaskActionController::class);
    $token = \Drupal::csrfToken()->get("task-action/{$task->id()}");
    $request = Request::create("/tasks/{$task->id()}/claim", 'GET', ['token' => $token]);

    $response = $controller->claim($task, $request);

    // Should redirect without changing ownership.
    $this->assertEquals(302, $response->getStatusCode());
    $reloaded = Node::load($task->id());
    $this->assertEquals($this->facilitatorUser->id(), $reloaded->get('field_task_claimed_by')->target_id,
      'Original claimer should still own the task.');
  }

  /**
   * A user CAN claim an open task.
   */
  public function testClaimSucceedsForOpenTask(): void {
    $task = $this->createTask(['field_task_status' => 'open']);

    \Drupal::currentUser()->setAccount($this->memberUser);
    $controller = \Drupal::classResolver(\Drupal\makehaven_tasks\Controller\TaskActionController::class);
    $token = \Drupal::csrfToken()->get("task-action/{$task->id()}");
    $request = Request::create("/tasks/{$task->id()}/claim", 'GET', ['token' => $token]);

    $controller->claim($task, $request);

    $reloaded = Node::load($task->id());
    $this->assertEquals($this->memberUser->id(), $reloaded->get('field_task_claimed_by')->target_id);
    $this->assertEquals('in_progress', $reloaded->get('field_task_status')->value);
  }

  /**
   * Cannot claim a task already flagged complete.
   */
  public function testClaimBlockedWhenComplete(): void {
    $task = $this->createTask(['field_task_status' => 'open']);
    $flag = Flag::load('task_completed');
    \Drupal::service('flag')->flag($flag, $task, $this->staffUser);

    \Drupal::currentUser()->setAccount($this->memberUser);
    $controller = \Drupal::classResolver(\Drupal\makehaven_tasks\Controller\TaskActionController::class);
    $token = \Drupal::csrfToken()->get("task-action/{$task->id()}");
    $request = Request::create("/tasks/{$task->id()}/claim", 'GET', ['token' => $token]);

    $controller->claim($task, $request);

    $reloaded = Node::load($task->id());
    $this->assertEquals('open', $reloaded->get('field_task_status')->value,
      'Completed task status should not change.');
  }

  // ── Claim route: audience enforcement ───────────────────────────────────

  /**
   * A regular member cannot claim a staff-only task.
   */
  public function testMemberCannotClaimStaffOnlyTask(): void {
    $task = $this->createTask(['field_task_audience' => 'staff_only']);

    \Drupal::currentUser()->setAccount($this->memberUser);
    $controller = \Drupal::classResolver(\Drupal\makehaven_tasks\Controller\TaskActionController::class);
    $token = \Drupal::csrfToken()->get("task-action/{$task->id()}");
    $request = Request::create("/tasks/{$task->id()}/claim", 'GET', ['token' => $token]);

    $controller->claim($task, $request);

    $reloaded = Node::load($task->id());
    $this->assertTrue($reloaded->get('field_task_claimed_by')->isEmpty(),
      'Member should not be able to claim staff-only task.');
  }

  /**
   * Staff CAN claim a staff-only task.
   */
  public function testStaffCanClaimStaffOnlyTask(): void {
    $task = $this->createTask(['field_task_audience' => 'staff_only']);

    \Drupal::currentUser()->setAccount($this->staffUser);
    $controller = \Drupal::classResolver(\Drupal\makehaven_tasks\Controller\TaskActionController::class);
    $token = \Drupal::csrfToken()->get("task-action/{$task->id()}");
    $request = Request::create("/tasks/{$task->id()}/claim", 'GET', ['token' => $token]);

    $controller->claim($task, $request);

    $reloaded = Node::load($task->id());
    $this->assertEquals($this->staffUser->id(), $reloaded->get('field_task_claimed_by')->target_id);
  }

  /**
   * A regular member cannot claim a badge-holder task.
   */
  public function testMemberCannotClaimBadgeHolderTask(): void {
    $task = $this->createTask(['field_task_audience' => 'badge_holders']);

    \Drupal::currentUser()->setAccount($this->memberUser);
    $controller = \Drupal::classResolver(\Drupal\makehaven_tasks\Controller\TaskActionController::class);
    $token = \Drupal::csrfToken()->get("task-action/{$task->id()}");
    $request = Request::create("/tasks/{$task->id()}/claim", 'GET', ['token' => $token]);

    $controller->claim($task, $request);

    $reloaded = Node::load($task->id());
    $this->assertTrue($reloaded->get('field_task_claimed_by')->isEmpty());
  }

  /**
   * A facilitator CAN claim a badge-holder task.
   */
  public function testFacilitatorCanClaimBadgeHolderTask(): void {
    $task = $this->createTask(['field_task_audience' => 'badge_holders']);

    \Drupal::currentUser()->setAccount($this->facilitatorUser);
    $controller = \Drupal::classResolver(\Drupal\makehaven_tasks\Controller\TaskActionController::class);
    $token = \Drupal::csrfToken()->get("task-action/{$task->id()}");
    $request = Request::create("/tasks/{$task->id()}/claim", 'GET', ['token' => $token]);

    $controller->claim($task, $request);

    $reloaded = Node::load($task->id());
    $this->assertEquals($this->facilitatorUser->id(), $reloaded->get('field_task_claimed_by')->target_id);
  }

  // ── Bank: template access guards ────────────────────────────────────────

  /**
   * Bank clone rejects unpublished templates.
   */
  public function testBankRejectsUnpublishedTemplate(): void {
    $template = $this->createTask([
      'field_task_bank' => ['task_bank'],
      'status' => 0,
    ]);

    \Drupal::currentUser()->setAccount($this->memberUser);
    $controller = \Drupal::classResolver(\Drupal\makehaven_tasks\Controller\TaskBankController::class);
    $token = \Drupal::csrfToken()->get('task-bank-create/' . $template->id());
    $request = Request::create("/tasks/bank/{$template->id()}/create", 'GET', ['token' => $token]);

    $response = $controller->createFromBank($template, $request);

    // Should redirect to bank without creating a task.
    $created = \Drupal::entityQuery('node')
      ->condition('type', 'task')
      ->condition('uid', $this->memberUser->id())
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $this->assertEquals(0, (int) $created, 'No task should be created from unpublished template.');
  }

  /**
   * Member cannot instantiate a staff-only bank template.
   */
  public function testMemberCannotCloneStaffOnlyTemplate(): void {
    $template = $this->createTask([
      'field_task_bank' => ['task_bank'],
      'field_task_audience' => 'staff_only',
    ]);

    \Drupal::currentUser()->setAccount($this->memberUser);
    $controller = \Drupal::classResolver(\Drupal\makehaven_tasks\Controller\TaskBankController::class);
    $token = \Drupal::csrfToken()->get('task-bank-create/' . $template->id());
    $request = Request::create("/tasks/bank/{$template->id()}/create", 'GET', ['token' => $token]);

    $controller->createFromBank($template, $request);

    $created = \Drupal::entityQuery('node')
      ->condition('type', 'task')
      ->condition('uid', $this->memberUser->id())
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $this->assertEquals(0, (int) $created, 'Member should not clone staff-only template.');
  }

  /**
   * Staff CAN instantiate a staff-only bank template.
   */
  public function testStaffCanCloneStaffOnlyTemplate(): void {
    $template = $this->createTask([
      'field_task_bank' => ['task_bank'],
      'field_task_audience' => 'staff_only',
    ]);

    \Drupal::currentUser()->setAccount($this->staffUser);
    $controller = \Drupal::classResolver(\Drupal\makehaven_tasks\Controller\TaskBankController::class);
    $token = \Drupal::csrfToken()->get('task-bank-create/' . $template->id());
    $request = Request::create("/tasks/bank/{$template->id()}/create", 'GET', ['token' => $token]);

    $controller->createFromBank($template, $request);

    $created = \Drupal::entityQuery('node')
      ->condition('type', 'task')
      ->condition('uid', $this->staffUser->id())
      ->accessCheck(FALSE)
      ->count()
      ->execute();
    $this->assertGreaterThan(0, (int) $created, 'Staff should be able to clone staff-only template.');
  }

  // ── Helpers ─────────────────────────────────────────────────────────────

  protected function createTask(array $overrides = []): Node {
    $defaults = [
      'type' => 'task',
      'title' => 'Test task ' . mt_rand(),
      'status' => 1,
      'uid' => $this->staffUser->id(),
      'field_task_status' => 'open',
    ];
    $node = Node::create($overrides + $defaults);
    $node->save();
    return $node;
  }

  protected function createRolesAndUsers(): void {
    User::create(['name' => 'superadmin', 'status' => 1])->save();

    Role::create(['id' => 'member', 'label' => 'Member'])->save();
    Role::create(['id' => 'facilitator', 'label' => 'Facilitator'])->save();
    Role::create(['id' => 'manager', 'label' => 'Manager'])->save();

    $auth = Role::load('authenticated');
    $auth->grantPermission('makehaven_tasks.create_from_bank')->save();

    Role::load('facilitator')
      ->grantPermission('makehaven_tasks.create_from_bank')
      ->grantPermission('makehaven_tasks.create_task')
      ->save();

    Role::load('manager')
      ->grantPermission('makehaven_tasks.create_from_bank')
      ->grantPermission('makehaven_tasks.create_task')
      ->grantPermission('makehaven_tasks.manage_bank')
      ->grantPermission('makehaven_tasks.manage_tasks')
      ->save();

    $this->memberUser = User::create(['name' => 'member', 'status' => 1, 'roles' => ['member']]);
    $this->memberUser->save();
    $this->facilitatorUser = User::create(['name' => 'facilitator', 'status' => 1, 'roles' => ['facilitator']]);
    $this->facilitatorUser->save();
    $this->staffUser = User::create(['name' => 'staff', 'status' => 1, 'roles' => ['manager']]);
    $this->staffUser->save();
  }

  protected function installTaskFields(): void {
    $fields = [
      ['name' => 'field_task_frequency', 'type' => 'list_string', 'settings' => ['allowed_values' => ['once' => 'Once', 'weekly' => 'Weekly']]],
      ['name' => 'field_task_status', 'type' => 'list_string', 'settings' => ['allowed_values' => ['open' => 'Open', 'in_progress' => 'In Progress', 'incomplete' => 'Incomplete']]],
      ['name' => 'field_task_bank', 'type' => 'list_string', 'settings' => ['allowed_values' => ['task_bank' => 'Bank']], 'cardinality' => -1],
      ['name' => 'field_task_audience', 'type' => 'list_string', 'settings' => ['allowed_values' => ['members' => 'Members', 'badge_holders' => 'Badge holders', 'staff_only' => 'Staff only']]],
      ['name' => 'field_task_category', 'type' => 'list_string', 'settings' => ['allowed_values' => ['cleaning' => 'Cleaning']]],
      ['name' => 'field_task_priority', 'type' => 'list_string', 'settings' => ['allowed_values' => ['1' => 'High', '2' => 'Medium']]],
      ['name' => 'field_task_series_paused', 'type' => 'boolean'],
      ['name' => 'field_task_next_due', 'type' => 'datetime'],
      ['name' => 'field_task_estimated_hours', 'type' => 'float'],
    ];
    $refs = [
      ['name' => 'field_task_series_source', 'target' => 'node'],
      ['name' => 'field_task_lead', 'target' => 'user'],
      ['name' => 'field_task_claimed_by', 'target' => 'user'],
      ['name' => 'field_task_equipment', 'target' => 'node'],
      ['name' => 'field_task_group', 'target' => 'node'],
    ];
    foreach ($fields as $spec) {
      FieldStorageConfig::create(['field_name' => $spec['name'], 'entity_type' => 'node', 'type' => $spec['type'], 'settings' => $spec['settings'] ?? [], 'cardinality' => $spec['cardinality'] ?? 1])->save();
      FieldConfig::create(['field_name' => $spec['name'], 'entity_type' => 'node', 'bundle' => 'task', 'label' => $spec['name']])->save();
    }
    foreach ($refs as $spec) {
      FieldStorageConfig::create(['field_name' => $spec['name'], 'entity_type' => 'node', 'type' => 'entity_reference', 'settings' => ['target_type' => $spec['target']]])->save();
      FieldConfig::create(['field_name' => $spec['name'], 'entity_type' => 'node', 'bundle' => 'task', 'label' => $spec['name']])->save();
    }
  }

  protected function installFlag(): void {
    Flag::create([
      'id' => 'task_completed', 'label' => 'Task Completed', 'entity_type' => 'node',
      'bundles' => ['task'], 'flag_type' => 'entity:node', 'link_type' => 'reload',
      'flagTypeConfig' => [], 'linkTypeConfig' => [], 'global' => TRUE,
    ])->save();
  }

}
