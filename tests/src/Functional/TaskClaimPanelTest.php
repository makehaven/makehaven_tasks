<?php

namespace Drupal\Tests\makehaven_tasks\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\profile\Entity\ProfileType;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Tests the rendered claim/help action panel on the task page.
 *
 * This guards the exact bug class that prompted the single-claim rework: the
 * task page must never offer "Claim task" to a bystander on a task that is
 * already led by someone else (single-claim), and must offer "Offer to help"
 * instead. The controller logic is covered by TaskAccessKernelTest; this
 * covers the view layer, which is where the original defect lived.
 *
 * @group makehaven_tasks
 */
class TaskClaimPanelTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   *
   * The makehaven_tasks module is installed manually in setUp() after its
   * shipped views.view.tasks config dependencies (node.type.task,
   * profile.type.main) exist, so it is intentionally not listed here.
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'options', 'link',
    'datetime', 'flag', 'views', 'profile', 'slack_connector',
    'slack_task_poster', 'path_alias',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * The member who claims the task (the lead).
   *
   * @var \Drupal\user\UserInterface
   */
  protected UserInterface $leadUser;

  /**
   * A member who has not joined the task.
   *
   * @var \Drupal\user\UserInterface
   */
  protected UserInterface $bystanderUser;

  /**
   * A member listed in field_task_helpers.
   *
   * @var \Drupal\user\UserInterface
   */
  protected UserInterface $helperUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Satisfy the config dependencies of the module's shipped
    // views.view.tasks before installing it, then install the module so
    // BrowserTestBase's config installer can resolve them.
    NodeType::create(['type' => 'task', 'name' => 'Task'])->save();
    ProfileType::create(['id' => 'main', 'label' => 'Main'])->save();
    \Drupal::service('module_installer')->install(['makehaven_tasks']);

    $this->config('slack_connector.settings')->set('webhook_url', '')->save();

    // The task fields are created by hook_update_N (not config/install or
    // hook_install), so the manual install above does not create them.
    $this->installTaskFields();

    $this->leadUser = $this->drupalCreateUser(['access content'], 'lead_member');
    $this->bystanderUser = $this->drupalCreateUser(['access content'], 'bystander_member');
    $this->helperUser = $this->drupalCreateUser(['access content'], 'helper_member');
  }

  /**
   * A bystander on a claimed single-claim task is offered help, not claim.
   *
   * This is the regression guard for the reported bug.
   */
  public function testBystanderSeesOfferToHelpNotClaim(): void {
    $task = $this->createTask([
      'field_task_status' => 'in_progress',
      'field_task_claimed_by' => ['target_id' => $this->leadUser->id()],
    ]);

    $this->drupalLogin($this->bystanderUser);
    $this->drupalGet('/node/' . $task->id());

    $this->assertSession()->pageTextContains('Offer to help');
    $this->assertSession()->pageTextNotContains('Claim task');
    $this->assertSession()->linkByHrefExists('/tasks/' . $task->id() . '/help');
  }

  /**
   * The lead sees the claimed state, not a claim or help button.
   */
  public function testLeadSeesClaimedState(): void {
    $task = $this->createTask([
      'field_task_status' => 'in_progress',
      'field_task_claimed_by' => ['target_id' => $this->leadUser->id()],
    ]);

    $this->drupalLogin($this->leadUser);
    $this->drupalGet('/node/' . $task->id());

    $this->assertSession()->pageTextContains("You've claimed this");
    $this->assertSession()->pageTextNotContains('Offer to help');
  }

  /**
   * A helper sees the helping state and a way to step back.
   */
  public function testHelperSeesHelpingState(): void {
    $task = $this->createTask([
      'field_task_status' => 'in_progress',
      'field_task_claimed_by' => ['target_id' => $this->leadUser->id()],
      'field_task_helpers' => [['target_id' => $this->helperUser->id()]],
    ]);

    $this->drupalLogin($this->helperUser);
    $this->drupalGet('/node/' . $task->id());

    $this->assertSession()->pageTextContains("You're helping");
    $this->assertSession()->pageTextContains('Step back from helping');
  }

  /**
   * An open task still offers the plain Claim button.
   */
  public function testOpenTaskShowsClaim(): void {
    $task = $this->createTask(['field_task_status' => 'open']);

    $this->drupalLogin($this->bystanderUser);
    $this->drupalGet('/node/' . $task->id());

    $this->assertSession()->pageTextContains('Claim task');
    $this->assertSession()->pageTextNotContains('Offer to help');
  }

  /**
   * On a multi-claim task a bystander is offered Claim, not help.
   */
  public function testMultiClaimBystanderSeesClaimNotHelp(): void {
    $task = $this->createTask([
      'field_task_status' => 'in_progress',
      'field_task_claimed_by' => ['target_id' => $this->leadUser->id()],
      'field_task_allow_multiple' => 1,
    ]);

    $this->drupalLogin($this->bystanderUser);
    $this->drupalGet('/node/' . $task->id());

    $this->assertSession()->pageTextContains('Claim task');
    $this->assertSession()->pageTextNotContains('Offer to help');
  }

  /**
   * Creates a published task node with the given field overrides.
   */
  protected function createTask(array $overrides = []): Node {
    $node = Node::create($overrides + [
      'type' => 'task',
      'title' => 'Test task ' . mt_rand(),
      'status' => 1,
      'uid' => $this->leadUser->id(),
      'field_task_status' => 'open',
    ]);
    $node->save();
    return $node;
  }

  /**
   * Creates the task fields the action panel reads (normally made by updates).
   */
  protected function installTaskFields(): void {
    $status_values = [
      'open' => 'Open',
      'in_progress' => 'In Progress',
      'incomplete' => 'Incomplete',
    ];
    $audience_values = [
      'members' => 'Members',
      'badge_holders' => 'Badge holders',
      'staff_only' => 'Staff only',
    ];
    $this->makeField('field_task_status', 'list_string', [
      'allowed_values' => $status_values,
    ]);
    $this->makeField('field_task_audience', 'list_string', [
      'allowed_values' => $audience_values,
    ]);
    $this->makeField('field_task_allow_multiple', 'boolean');
    $this->makeField('field_task_claimed_by', 'entity_reference', [
      'target_type' => 'user',
    ]);
    $this->makeField('field_task_helpers', 'entity_reference', [
      'target_type' => 'user',
    ], -1);
  }

  /**
   * Creates a single field storage + config on the task node bundle.
   */
  protected function makeField(string $name, string $type, array $settings = [], int $cardinality = 1): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => $type,
      'settings' => $settings,
      'cardinality' => $cardinality,
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => 'task',
      'label' => $name,
    ])->save();
  }

}
