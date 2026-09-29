<?php

declare(strict_types=1);

namespace Drupal\Tests\makehaven_tasks\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Test\AssertMailTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\flag\Entity\Flag;
use Drupal\KernelTests\KernelTestBase;
use Drupal\makehaven_tasks\Controller\TaskActionController;
use Drupal\makehaven_tasks\Controller\TaskBankController;
use Drupal\makehaven_tasks\Form\TaskRequestForm;
use Drupal\makehaven_tasks\Form\VolunteerInterestForm;
use Drupal\makehaven_tasks\Volunteer\Decider;
use Drupal\makehaven_tasks\Volunteer\Opportunity;
use Drupal\makehaven_tasks\Volunteer\SignupStore;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * The volunteer board: stages, interest, approval, decide-by, stale claims.
 *
 * @group makehaven_tasks
 */
class VolunteerBoardKernelTest extends KernelTestBase {

  use AssertMailTrait;

  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'options', 'link',
    'datetime', 'datetime_range', 'taxonomy', 'flag', 'slack_connector',
    'slack_task_poster', 'makehaven_tasks', 'path_alias',
  ];

  protected $strictConfigSchema = FALSE;

  protected User $staff;
  protected User $facilitator;
  protected User $alice;
  protected User $bob;
  protected User $carol;

  /**
   * Every HTTP request the site tried to make (i.e. Slack posts).
   */
  protected array $httpHistory = [];

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
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('system.site')->set('mail', 'site@example.com')->save();
    // A dummy webhook, so Slack posts are attempted and can be counted; the
    // mock client below answers them and nothing leaves the test.
    $this->config('slack_connector.settings')->set('webhook_url', 'https://hooks.example.invalid/T000/B000')->save();
    $this->config('makehaven_tasks.settings')->setData([
      'code_word' => 'makers',
      'volunteer_slack_channel' => '#volunteers',
      'ready_to_approve_email' => 'approvers@example.com',
      'gathering_days' => 8,
      'short_reminder_days' => 3,
      'member_suggestion_stage' => 'gathering',
      'stale_first_days' => 14,
      'stale_second_days' => 28,
    ])->save();
    $this->installMockHttp();
    \Drupal::service('router.builder')->rebuild();

    NodeType::create(['type' => 'item', 'name' => 'Item'])->save();
    NodeType::create(['type' => 'task', 'name' => 'Task'])->save();
    $this->installTaskFields();
    \Drupal::moduleHandler()->loadInclude('makehaven_tasks', 'install');
    // Optional config may already have landed when the task type was created;
    // either way the update hook helper must leave every field in place.
    _makehaven_tasks_install_volunteer_fields();
    foreach (['stage', 'type', 'when', 'min_volunteers', 'max_volunteers', 'decide_by', 'decision_note', 'interest'] as $field) {
      $this->assertNotNull(FieldConfig::loadByName('node', 'task', 'field_task_' . $field), "field_task_$field exists.");
    }
    $this->assertTrue(\Drupal::database()->schema()->tableExists('makehaven_task_signup'));
    Flag::create([
      'id' => 'task_completed', 'label' => 'Task Completed', 'entity_type' => 'node',
      'bundles' => ['task'], 'flag_type' => 'entity:node', 'link_type' => 'reload',
      'flagTypeConfig' => [], 'linkTypeConfig' => [], 'global' => TRUE,
    ])->save();
    $this->createUsers();
  }

  // ── Stages ───────────────────────────────────────────────────────────────

  /**
   * Existing tasks (no stage, no type) read as approved tasks and claim as before.
   */
  public function testEmptyStageIsApprovedAndClaimable(): void {
    $task = $this->createTask();
    $this->assertSame(Opportunity::STAGE_APPROVED, Opportunity::stage($task));
    $this->assertSame(Opportunity::TYPE_TASK, Opportunity::type($task));
    $this->assertSame(1, Opportunity::minNeeded($task));

    $this->claimAs($this->alice, $task);
    $task = Node::load($task->id());
    $this->assertEquals($this->alice->id(), $task->get('field_task_claimed_by')->target_id);
    $this->assertSame('in_progress', $task->get('field_task_status')->value);
  }

  /**
   * Gathering: default decide-by, one recruitment post, no claiming.
   */
  public function testGatheringDefaultsAndRecruitmentPost(): void {
    $task = $this->createTask(['field_task_stage' => 'gathering', 'uid' => $this->alice->id()]);
    $expected = $task->getCreatedTime() + 8 * 86400;
    $this->assertEqualsWithDelta($expected, Opportunity::decideBy($task), 1, 'Decide-by defaults to created + 8 days.');

    $volunteer_posts = $this->slackPosts('#volunteers');
    $this->assertCount(1, $volunteer_posts, 'One recruitment post to #volunteers.');
    $this->assertStringContainsString('Volunteers wanted', $volunteer_posts[0]['text']);

    // Re-saving does not post again.
    $task->setTitle('Renamed')->save();
    $this->assertCount(1, $this->slackPosts('#volunteers'));

    // Nobody can claim it until it is approved.
    $this->claimAs($this->bob, $task);
    $this->assertTrue(Node::load($task->id())->get('field_task_claimed_by')->isEmpty());
  }

  /**
   * Approving a task: first interested leads, the rest help; all emailed.
   */
  public function testApproveTaskAssignsLeadAndHelpers(): void {
    $task = $this->createTask(['field_task_stage' => 'gathering', 'field_task_min_volunteers' => 2]);
    $this->signups()->add($task, (int) $this->alice->id(), 'Saturdays only');
    $this->signups()->add($task, (int) $this->bob->id(), 'I can bring a truck');
    $this->assertSame(2, $this->signups()->count($task));

    $this->decider()->approve($task, $this->staff, 'Thanks all');
    $task = Node::load($task->id());
    $this->assertSame('approved', $task->get('field_task_stage')->value);
    $this->assertEquals($this->alice->id(), $task->get('field_task_claimed_by')->target_id, 'First to volunteer leads.');
    $this->assertEquals([$this->bob->id()], array_column($task->get('field_task_helpers')->getValue(), 'target_id'));
    $this->assertSame('in_progress', $task->get('field_task_status')->value);
    $this->assertNotNull($this->decider()->claimedAt((int) $task->id()), 'The approval claim is timestamped (post-launch).');

    $mails = $this->getMails(['key' => 'volunteer_approved']);
    $this->assertEqualsCanonicalizing(['alice@example.com', 'bob@example.com'], array_column($mails, 'to'));
    $this->assertCount(1, $this->slackPosts('#volunteers', "It's on"));
  }

  /**
   * Approving a shift turns interest into the confirmed roster.
   */
  public function testApproveShiftConfirmsRoster(): void {
    $start = \Drupal::time()->getCurrentTime() + 20 * 86400;
    $shift = $this->createTask([
      'field_task_stage' => 'gathering',
      'field_task_type' => 'shift',
      'field_task_when' => ['value' => Opportunity::timestampToStorage($start), 'end_value' => Opportunity::timestampToStorage($start + 3 * 3600)],
    ]);
    $this->assertSame(2, Opportunity::minNeeded($shift), 'A shift needs 2 by default.');
    $this->signups()->add($shift, (int) $this->alice->id());
    $this->signups()->add($shift, (int) $this->bob->id());
    $this->decider()->approve($shift, $this->staff);

    $kinds = array_column($this->signups()->list(Node::load($shift->id())), 'kind');
    $this->assertSame(['confirmed', 'confirmed'], $kinds);
    $this->assertTrue(Node::load($shift->id())->get('field_task_claimed_by')->isEmpty(), 'Shifts use the roster, not a lead.');
  }

  /**
   * The max cap closes sign-up for anyone not already on it.
   */
  public function testMaxCapClosesSignup(): void {
    $task = $this->createTask(['field_task_stage' => 'gathering', 'field_task_max_volunteers' => 1]);
    $this->signups()->add($task, (int) $this->alice->id());
    $this->assertNull(VolunteerInterestForm::closedReason($task, (int) $this->alice->id(), $this->signups()));
    $this->assertStringContainsString('full', (string) VolunteerInterestForm::closedReason($task, (int) $this->bob->id(), $this->signups()));
  }

  // ── Decide-by rule ─────────────────────────────────────────────────────────

  /**
   * Short at decide-by: declined, the interested get mail, nothing on Slack.
   */
  public function testShortAtDecideByDeclinesQuietly(): void {
    $task = $this->createTask(['field_task_stage' => 'gathering', 'field_task_min_volunteers' => 3]);
    $this->signups()->add($task, (int) $this->alice->id());
    $this->resetOutbound();

    $log = $this->decider()->tick(Opportunity::decideBy($task) + 60);
    $this->assertCount(1, $log);

    $task = Node::load($task->id());
    $this->assertSame('declined', $task->get('field_task_stage')->value);
    $this->assertStringContainsString('Not enough volunteers', (string) $task->get('field_task_decision_note')->value);
    $mails = $this->getMails();
    $this->assertCount(1, $mails, 'Only the one interested volunteer is emailed.');
    $this->assertSame('alice@example.com', $mails[0]['to']);
    $this->assertSame('volunteer_declined', $mails[0]['key']);
    $this->assertSame([], $this->httpHistory, 'A decline is never posted to Slack.');
  }

  /**
   * Enough at decide-by: one "ready to approve" email, no auto-approve.
   */
  public function testEnoughAtDecideBySendsOneReadyEmail(): void {
    $task = $this->createTask(['field_task_stage' => 'gathering', 'field_task_min_volunteers' => 1]);
    $this->signups()->add($task, (int) $this->alice->id(), 'weekends');
    $this->resetOutbound();

    $when = Opportunity::decideBy($task) + 60;
    $this->decider()->tick($when);
    $this->decider()->tick($when + 3600);

    $mails = $this->getMails(['key' => 'ready_to_approve']);
    $this->assertCount(1, $mails, 'Exactly one ready-to-approve email, however often cron runs.');
    $this->assertSame('approvers@example.com', $mails[0]['to']);
    $this->assertStringContainsString('1 interested of 1 needed', $mails[0]['body']);
    $this->assertSame('gathering', Node::load($task->id())->get('field_task_stage')->value, 'Never auto-approved.');
  }

  /**
   * Short and close to decide-by: one "needs N more" post.
   */
  public function testNeedsMorePostOnce(): void {
    $task = $this->createTask(['field_task_stage' => 'gathering', 'field_task_min_volunteers' => 2]);
    $this->resetOutbound();
    $when = Opportunity::decideBy($task) - 2 * 86400;
    $this->decider()->tick($when);
    $this->decider()->tick($when + 3600);
    $this->assertCount(1, $this->slackPosts('#volunteers', 'needs *2 more*'));
  }

  // ── Stale claims ───────────────────────────────────────────────────────────

  /**
   * Nudges go only to claims made after launch; older ones are listed.
   */
  public function testStaleClaimNudgesOnlyPostLaunchClaims(): void {
    $new_claim = $this->createTask();
    $this->claimAs($this->alice, $new_claim);
    $old_claim = $this->createTask(['field_task_status' => 'in_progress', 'field_task_claimed_by' => $this->bob->id()]);
    // Simulate a claim from before the board launched: no recorded time.
    \Drupal::keyValue(Decider::CLAIMED_AT)->delete((string) $old_claim->id());
    $this->resetOutbound();

    $now = \Drupal::time()->getCurrentTime();
    $this->decider()->tick($now + 13 * 86400);
    $this->assertCount(0, $this->getMails(), 'Nothing before 14 idle days.');

    $this->decider()->tick($now + 15 * 86400);
    $this->decider()->tick($now + 16 * 86400);
    $mails = $this->getMails(['key' => 'stale_claim']);
    $this->assertCount(1, $mails, 'One first nudge.');
    $this->assertSame('alice@example.com', $mails[0]['to']);

    $this->decider()->tick($now + 29 * 86400);
    $this->assertCount(2, $this->getMails(['key' => 'stale_claim']), 'A second nudge at 28 days.');
    $this->assertCount(0, array_filter($this->getMails(), fn($m) => $m['to'] === 'bob@example.com'), 'The pre-launch claimant is never emailed.');
    $this->assertSame([], $this->httpHistory, 'Stale nudges are never posted to Slack.');

    $claims = $this->decider()->staleClaims($now + 29 * 86400);
    $this->assertSame([(int) $old_claim->id()], array_column($claims['pre_launch'], 'nid'), 'The old claim is on the staff cleanup list.');
    $this->assertSame([(int) $new_claim->id()], array_column($claims['post_launch'], 'nid'));
  }

  // ── Who may post ───────────────────────────────────────────────────────────

  /**
   * A member's suggestion gathers interest; the audience bug is fixed.
   */
  public function testMemberSuggestionGathersInterest(): void {
    \Drupal::currentUser()->setAccount($this->alice);
    $node = $this->submitRequest(['title' => 'Build a lumber rack', 'details' => 'Needs two people and a drill.', 'min' => 2]);
    $this->assertSame('gathering', $node->get('field_task_stage')->value);
    $this->assertSame('open_member', $node->get('field_task_audience')->value, 'The allowed value, not the old invalid "members".');
    $this->assertTrue($node->isPublished());
    $this->assertTrue($this->signups()->has($node, (int) $this->alice->id()), 'The suggester counts as the first interested.');

    // Configured to screen first: the suggestion waits as an unpublished draft.
    $this->config('makehaven_tasks.settings')->set('member_suggestion_stage', 'proposed')->save();
    $draft = $this->submitRequest(['title' => 'Paint the mural wall', 'details' => 'Blue.']);
    $this->assertSame('proposed', $draft->get('field_task_stage')->value);
    $this->assertFalse($draft->isPublished());
    $this->assertTrue($draft->access('view', $this->alice), 'The author can see their draft.');
    $this->assertTrue($draft->access('view', $this->facilitator), 'Facilitators can see drafts to release them.');
    $this->assertFalse($draft->access('view', $this->bob), 'Other members cannot.');

    $this->decider()->release($draft, $this->facilitator);
    $draft = Node::load($draft->id());
    $this->assertSame('gathering', $draft->get('field_task_stage')->value);
    $this->assertTrue($draft->isPublished());
  }

  /**
   * Staff and facilitators post straight to the board (approved).
   */
  public function testFacilitatorPostsDirectly(): void {
    \Drupal::currentUser()->setAccount($this->facilitator);
    $node = $this->submitRequest(['title' => 'Empty the shop vac', 'details' => 'It is full.']);
    $this->assertTrue($node->get('field_task_stage')->isEmpty(), 'Stored empty, which reads as approved.');
    $this->assertSame(Opportunity::STAGE_APPROVED, Opportunity::stage($node));

    $gather = $this->submitRequest(['title' => 'Open house crew', 'details' => 'Setup.', 'post_as' => 'gathering']);
    $this->assertSame('gathering', $gather->get('field_task_stage')->value);
  }

  /**
   * Members start bank tasks with no approval, and see open_member templates.
   */
  public function testMemberStartsBankTaskWithoutApproval(): void {
    $template = $this->createTask(['title' => 'Clean the dishes', 'field_task_bank' => ['task_bank'], 'field_task_audience' => 'open_member']);
    \Drupal::currentUser()->setAccount($this->alice);
    $bank = \Drupal::classResolver(TaskBankController::class);
    $list = $bank->listPage(Request::create('/tasks/bank'));
    $this->assertArrayHasKey('table', $list, 'The open_member template is listed for members.');
    $this->assertCount(1, $list['table']['#rows']);

    $token = \Drupal::csrfToken()->get('task-bank-create/' . $template->id());
    $bank->createFromBank($template, Request::create('/tasks/bank/' . $template->id() . '/create', 'GET', ['token' => $token]));
    $nids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('uid', $this->alice->id())->execute();
    $this->assertCount(1, $nids);
    $instance = Node::load(reset($nids));
    $this->assertTrue($instance->isPublished());
    $this->assertSame(Opportunity::STAGE_APPROVED, Opportunity::stage($instance), 'A bank instance needs no approval.');
  }

  // ── Helpers ─────────────────────────────────────────────────────────────────

  protected function submitRequest(array $values): NodeInterface {
    $form = TaskRequestForm::create($this->container);
    $form_state = (new FormState())->setValues($values + ['kind' => 'task', 'min' => 1, 'count_me_in' => 1]);
    $build = [];
    $form->submitForm($build, $form_state);
    $nids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('title', $values['title'])->execute();
    return Node::load(reset($nids));
  }

  protected function claimAs(User $user, NodeInterface $task): void {
    \Drupal::currentUser()->setAccount($user);
    $token = \Drupal::csrfToken()->get("task-action/{$task->id()}");
    \Drupal::classResolver(TaskActionController::class)
      ->claim($task, Request::create("/tasks/{$task->id()}/claim", 'GET', ['token' => $token]));
  }

  protected function createTask(array $overrides = []): NodeInterface {
    $node = Node::create($overrides + [
      'type' => 'task',
      'title' => 'Task ' . $this->randomMachineName(),
      'status' => 1,
      'uid' => $this->staff->id(),
      'field_task_status' => 'open',
    ]);
    $node->save();
    return $node;
  }

  protected function signups(): SignupStore {
    return \Drupal::service('makehaven_tasks.volunteer_signups');
  }

  protected function decider(): Decider {
    return \Drupal::service('makehaven_tasks.volunteer_decider');
  }

  /**
   * Slack posts to a channel, optionally containing some text.
   */
  protected function slackPosts(string $channel, string $contains = ''): array {
    $out = [];
    foreach ($this->httpHistory as $entry) {
      $payload = json_decode((string) $entry['request']->getBody(), TRUE);
      if (($payload['channel'] ?? '') === $channel && ($contains === '' || str_contains($payload['text'] ?? '', $contains))) {
        $out[] = $payload;
      }
    }
    return $out;
  }

  protected function resetOutbound(): void {
    $this->httpHistory = [];
    \Drupal::state()->set('system.test_mail_collector', []);
  }

  protected function installMockHttp(): void {
    $responses = array_fill(0, 200, new Response(200, [], 'ok'));
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->httpHistory));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    foreach (['makehaven_tasks.volunteer_notifier', 'makehaven_tasks.volunteer_decider'] as $id) {
      $this->container->set($id, NULL);
    }
  }

  protected function createUsers(): void {
    User::create(['name' => 'superadmin', 'status' => 1])->save();
    Role::create(['id' => 'member', 'label' => 'Member'])->save();
    Role::create(['id' => 'facilitator', 'label' => 'Facilitator'])->save();
    Role::create(['id' => 'manager', 'label' => 'Manager'])->save();
    Role::load('authenticated')->grantPermission('makehaven_tasks.create_from_bank')->grantPermission('access content')->save();
    Role::load('facilitator')->grantPermission('makehaven_tasks.create_task')->save();
    Role::load('manager')
      ->grantPermission('makehaven_tasks.create_task')
      ->grantPermission('makehaven_tasks.manage_bank')
      ->grantPermission('makehaven_tasks.manage_tasks')
      ->save();
    $make = function (string $name, array $roles): User {
      $user = User::create(['name' => $name, 'mail' => "$name@example.com", 'status' => 1, 'roles' => $roles]);
      $user->save();
      return $user;
    };
    $this->staff = $make('staff', ['manager']);
    $this->facilitator = $make('facil', ['facilitator']);
    $this->alice = $make('alice', ['member']);
    $this->bob = $make('bob', ['member']);
    $this->carol = $make('carol', ['member']);
  }

  protected function installTaskFields(): void {
    $lists = [
      'field_task_frequency' => ['once' => 'Once', 'weekly' => 'Weekly'],
      'field_task_status' => ['open' => 'Open', 'in_progress' => 'In Progress', 'incomplete' => 'Incomplete'],
      'field_task_audience' => ['open_member' => 'Open to members', 'badge_holders' => 'Requires badge', 'staff_only' => 'Staff only'],
      'field_task_category' => ['cleaning' => 'Cleaning', 'maintenance' => 'Maintenance'],
      'field_task_priority' => ['1' => 'High', '2' => 'Medium'],
    ];
    foreach ($lists as $name => $allowed) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => 'list_string', 'settings' => ['allowed_values' => $allowed]])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'task', 'label' => $name])->save();
    }
    FieldStorageConfig::create(['field_name' => 'field_task_bank', 'entity_type' => 'node', 'type' => 'list_string', 'settings' => ['allowed_values' => ['task_bank' => 'Bank']], 'cardinality' => -1])->save();
    FieldConfig::create(['field_name' => 'field_task_bank', 'entity_type' => 'node', 'bundle' => 'task', 'label' => 'Bank'])->save();
    foreach (['field_task_allow_multiple' => 'boolean', 'field_task_series_paused' => 'boolean', 'field_task_next_due' => 'datetime', 'field_task_estimated_hours' => 'float'] as $name => $type) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => $type])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'task', 'label' => $name])->save();
    }
    foreach (['field_task_series_source' => 'node', 'field_task_lead' => 'user', 'field_task_claimed_by' => 'user', 'field_task_equipment' => 'node'] as $name => $target) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => 'entity_reference', 'settings' => ['target_type' => $target]])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'task', 'label' => $name])->save();
    }
    FieldStorageConfig::create(['field_name' => 'field_task_helpers', 'entity_type' => 'node', 'type' => 'entity_reference', 'settings' => ['target_type' => 'user'], 'cardinality' => -1])->save();
    FieldConfig::create(['field_name' => 'field_task_helpers', 'entity_type' => 'node', 'bundle' => 'task', 'label' => 'Helpers'])->save();
  }

}
