<?php

namespace Drupal\Tests\makehaven_tasks\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\flag\Entity\Flag;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;

/**
 * Tests makehaven_tasks cron occurrence generation, status defaulting,
 * open-tasks helper, and retroactive log flow.
 *
 * @group makehaven_tasks
 */
class TaskOccurrenceKernelTest extends KernelTestBase {

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
   * Disable strict config schema — test creates fields programmatically.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  /**
   * A test user who owns tasks.
   *
   * @var \Drupal\user\Entity\User
   */
  protected User $testUser;

  /**
   * An item node representing a tool/area.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected Node $itemNode;

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

    // Ensure Slack webhook is empty so notification helpers bail out early.
    $this->config('slack_connector.settings')->set('webhook_url', '')->save();

    // Create the 'item' node type (tools/areas referenced by tasks).
    NodeType::create(['type' => 'item', 'name' => 'Item'])->save();

    // Create the 'task' node type.
    NodeType::create(['type' => 'task', 'name' => 'Task'])->save();

    $this->installTaskFields();
    $this->installFlag();

    // Test user.
    $this->testUser = User::create([
      'name' => 'test_member',
      'status' => 1,
    ]);
    $this->testUser->save();

    // Test item node (a tool).
    $this->itemNode = Node::create([
      'type' => 'item',
      'title' => 'Test Laser',
      'status' => 1,
    ]);
    $this->itemNode->save();
  }

  // ---------------------------------------------------------------------------
  // Occurrence generation tests
  // ---------------------------------------------------------------------------

  /**
   * A series source with an overdue next_due spawns one occurrence.
   */
  public function testCronCreatesOccurrenceWhenDue(): void {
    $source = $this->createSeriesSource('weekly', '-2 days');
    makehaven_tasks_cron();

    $occurrences = $this->findOccurrences($source->id());
    $this->assertCount(1, $occurrences, 'Exactly one occurrence was created.');

    $occ = Node::load(reset($occurrences));
    $this->assertEquals($source->id(), $occ->get('field_task_series_source')->target_id);
    $this->assertEquals('Test weekly task', $occ->label());
  }

  /**
   * field_task_lead is copied from series source to the new occurrence.
   */
  public function testOccurrenceCopiesLead(): void {
    $source = $this->createSeriesSource('monthly', '-1 day');
    $source->set('field_task_lead', ['target_id' => $this->testUser->id()]);
    $source->save();

    makehaven_tasks_cron();

    $nids = $this->findOccurrences($source->id());
    $this->assertCount(1, $nids);
    $occ = Node::load(reset($nids));
    $this->assertEquals($this->testUser->id(), $occ->get('field_task_lead')->target_id);
  }

  /**
   * next_due on the series source is advanced by the correct number of days.
   *
   * Compares stored date strings to avoid sub-second precision issues with
   * PHP's DateTime arithmetic when constructing relative dates.
   */
  public function testNextDueAdvancedByFrequency(): void {
    $source = $this->createSeriesSource('weekly', '-3 days');

    // Record the stored next_due *before* cron so we have the exact value.
    $before = new \DateTime(
      Node::load($source->id())->get('field_task_next_due')->value,
      new \DateTimeZone('UTC')
    );

    makehaven_tasks_cron();

    $after = new \DateTime(
      Node::load($source->id())->get('field_task_next_due')->value,
      new \DateTimeZone('UTC')
    );

    $diff = $after->diff($before)->days;
    $this->assertEquals(7, $diff, 'next_due was advanced by 7 days for a weekly task.');
  }

  /**
   * Series source with a future next_due is not processed.
   */
  public function testCronSkipsFutureNextDue(): void {
    $source = $this->createSeriesSource('weekly', '+5 days');
    makehaven_tasks_cron();
    $this->assertCount(0, $this->findOccurrences($source->id()));
  }

  /**
   * A paused series is not processed.
   */
  public function testCronSkipsPausedSeries(): void {
    $source = $this->createSeriesSource('weekly', '-1 day');
    $source->set('field_task_series_paused', TRUE);
    $source->save();
    makehaven_tasks_cron();
    $this->assertCount(0, $this->findOccurrences($source->id()));
  }

  /**
   * Non-recurring frequencies (once, occasional) are not processed.
   */
  public function testCronSkipsNonRecurringFrequencies(): void {
    foreach (['once', 'occasional'] as $freq) {
      $source = $this->createSeriesSource($freq, '-1 day');
      makehaven_tasks_cron();
      $this->assertCount(0, $this->findOccurrences($source->id()),
        "Frequency '{$freq}' should not generate occurrences.");
    }
  }

  /**
   * Cron does not generate a new occurrence when an incomplete one exists.
   */
  public function testCronSkipsIncompleteActiveSeries(): void {
    $source = $this->createSeriesSource('weekly', '-1 day');

    // Create a pre-existing incomplete occurrence.
    $existing = Node::create([
      'type' => 'task',
      'title' => 'Existing incomplete occurrence',
      'status' => 1,
      'field_task_series_source' => ['target_id' => $source->id()],
      'field_task_status' => 'incomplete',
      'field_task_frequency' => 'weekly',
    ]);
    $existing->save();

    makehaven_tasks_cron();

    // Only the pre-existing one should remain; no new one created.
    $this->assertCount(1, $this->findOccurrences($source->id()),
      'Cron must not create a new occurrence when an incomplete one exists.');
  }

  // ---------------------------------------------------------------------------
  // Status default test
  // ---------------------------------------------------------------------------

  /**
   * hook_node_presave defaults field_task_status to 'open' on first publish.
   */
  public function testStatusDefaultsToOpenOnPublish(): void {
    $node = Node::create([
      'type' => 'task',
      'title' => 'New task',
      'status' => 1,
      'uid' => $this->testUser->id(),
    ]);
    $node->save();

    $node = Node::load($node->id());
    $this->assertEquals('open', $node->get('field_task_status')->value);
  }

  /**
   * hook_node_presave does not overwrite an explicitly set status.
   */
  public function testStatusNotOverwrittenWhenSet(): void {
    $node = Node::create([
      'type' => 'task',
      'title' => 'In progress task',
      'status' => 1,
      'uid' => $this->testUser->id(),
      'field_task_status' => 'in_progress',
    ]);
    $node->save();

    $node = Node::load($node->id());
    $this->assertEquals('in_progress', $node->get('field_task_status')->value,
      'Explicit status should not be overwritten by presave hook.');
  }

  // ---------------------------------------------------------------------------
  // Open-tasks helper test
  // ---------------------------------------------------------------------------

  /**
   * _makehaven_tasks_open_tasks_for_item returns open unflagged tasks only.
   */
  public function testOpenTasksHelperFiltersCorrectly(): void {
    // Create two open tasks for the item.
    $open1 = $this->createTaskForItem('Clean lens');
    $open2 = $this->createTaskForItem('Check rails');

    // Create a third task and flag it complete.
    $done = $this->createTaskForItem('Already cleaned');
    $flag = Flag::load('task_completed');
    \Drupal::service('flag')->flag($flag, $done, $this->testUser);

    // Create a task for a *different* item — should not appear.
    $other_item = Node::create(['type' => 'item', 'title' => 'Other tool', 'status' => 1]);
    $other_item->save();
    $this->createTaskForItem('Unrelated task', $other_item->id());

    $result = _makehaven_tasks_open_tasks_for_item($this->itemNode->id());

    $this->assertArrayHasKey($open1->id(), $result, 'Open task 1 should appear.');
    $this->assertArrayHasKey($open2->id(), $result, 'Open task 2 should appear.');
    $this->assertArrayNotHasKey($done->id(), $result, 'Flagged-complete task should not appear.');
    $this->assertCount(2, $result, 'Exactly 2 open tasks for this item.');
  }

  // ---------------------------------------------------------------------------
  // Retroactive log flow test
  // ---------------------------------------------------------------------------

  /**
   * A retroactively logged task is immediately flagged complete.
   */
  public function testRetroactiveLogCreatesCompletedTask(): void {
    $task = Node::create([
      'type' => 'task',
      'title' => 'Cleaned the laser lens',
      'status' => NodeInterface::PUBLISHED,
      'uid' => $this->testUser->id(),
      'field_task_equipment' => ['target_id' => $this->itemNode->id()],
      'field_task_frequency' => 'once',
      'field_task_status' => 'open',
    ]);
    $task->save();

    $flag_service = \Drupal::service('flag');
    $flag = Flag::load('task_completed');
    $flag_service->flag($flag, $task, $this->testUser);

    $flagging = $flag_service->getFlagging($flag, $task, $this->testUser);
    $this->assertNotNull($flagging, 'Task should be flagged complete immediately after log.');

    // Verify the task does NOT appear in open tasks (it's done).
    $open = _makehaven_tasks_open_tasks_for_item($this->itemNode->id());
    $this->assertArrayNotHasKey($task->id(), $open,
      'A completed task should not appear in open tasks for the item.');
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Creates a published series source node with the given frequency and due date.
   */
  protected function createSeriesSource(string $frequency, string $next_due_offset): Node {
    $due = new \DateTime($next_due_offset, new \DateTimeZone('UTC'));
    $node = Node::create([
      'type' => 'task',
      'title' => "Test {$frequency} task",
      'status' => 1,
      'uid' => $this->testUser->id(),
      'field_task_frequency' => $frequency,
      'field_task_next_due' => $due->format('Y-m-d\TH:i:s'),
      'field_task_series_paused' => FALSE,
      'field_task_status' => 'open',
    ]);
    $node->save();
    return $node;
  }

  /**
   * Returns NIDs of task occurrences that reference the given series source.
   */
  protected function findOccurrences(int $source_nid): array {
    return \Drupal::entityQuery('node')
      ->condition('type', 'task')
      ->condition('field_task_series_source', $source_nid)
      ->accessCheck(FALSE)
      ->execute();
  }

  /**
   * Creates an open published task linked to the test item (or given item NID).
   */
  protected function createTaskForItem(string $title, int $item_nid = NULL): Node {
    $node = Node::create([
      'type' => 'task',
      'title' => $title,
      'status' => 1,
      'uid' => $this->testUser->id(),
      'field_task_equipment' => ['target_id' => $item_nid ?? $this->itemNode->id()],
      'field_task_status' => 'open',
      'field_task_frequency' => 'weekly',
    ]);
    $node->save();
    return $node;
  }

  /**
   * Creates all field storage and instance configs needed for the task bundle.
   */
  protected function installTaskFields(): void {
    $fields = [
      ['name' => 'field_task_frequency', 'type' => 'list_string', 'settings' => [
        'allowed_values' => [
          'always' => 'Always', 'once' => 'Once', 'weekly' => 'Weekly',
          'monthly' => 'Monthly', 'quarterly' => 'Quarterly',
          'yearly' => 'Yearly', 'biennial' => 'Biennial', 'occasional' => 'Occasional',
        ],
      ]],
      ['name' => 'field_task_status', 'type' => 'list_string', 'settings' => [
        'allowed_values' => ['open' => 'Open', 'in_progress' => 'In Progress', 'incomplete' => 'Incomplete'],
      ]],
      ['name' => 'field_task_series_paused', 'type' => 'boolean'],
      ['name' => 'field_task_next_due', 'type' => 'datetime'],
      ['name' => 'field_task_estimated_hours', 'type' => 'float'],
    ];

    // Entity reference fields.
    $ref_fields = [
      ['name' => 'field_task_series_source', 'target' => 'node'],
      ['name' => 'field_task_lead', 'target' => 'user'],
      ['name' => 'field_task_claimed_by', 'target' => 'user'],
      ['name' => 'field_task_equipment', 'target' => 'node'],
    ];

    foreach ($fields as $spec) {
      FieldStorageConfig::create([
        'field_name' => $spec['name'],
        'entity_type' => 'node',
        'type' => $spec['type'],
        'settings' => $spec['settings'] ?? [],
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

  /**
   * Creates the task_completed flag config entity programmatically.
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

}
