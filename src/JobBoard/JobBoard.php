<?php

namespace Drupal\makehaven_tasks\JobBoard;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use Drupal\makehaven_tasks\SlackBot;
use Drupal\node\NodeInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 * The job board: outside requests from the Hire a Maker form (/commission).
 *
 * Unlike the volunteer board (task nodes, members helping MakeHaven), these
 * are outside people and businesses asking members for paid or volunteer
 * work. Form submissions become job_posting nodes; approved ones post to
 * Slack #jobs.
 *
 * Lifecycle, with no extra workflow state:
 * - pending  = unpublished, status Open;
 * - posted   = published (approval is publishing);
 * - declined = unpublished, status Closed.
 *
 * "Intake" postings are the ones with field_job_source set. They carry a
 * requester's contact details, so they are members-only and kept out of
 * search. MakeHaven's own hiring pages are job_posting nodes without a
 * source, and they stay public.
 */
class JobBoard {

  /**
   * Request types offered on the form, with the job_type term they map to.
   */
  const KINDS = [
    'full_time' => 'Full-time',
    'part_time' => 'Part-time',
    'contract' => 'Contract',
    'volunteer' => 'Volunteer',
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected SlackBot $slack,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Whether a node came in through the Hire a Maker form (or staff on behalf).
   */
  public function isIntake(NodeInterface $node): bool {
    return $node->bundle() === 'job_posting'
      && $node->hasField('field_job_source')
      && !$node->get('field_job_source')->isEmpty();
  }

  /**
   * Whether an intake node is waiting for a staff decision.
   */
  public function isPending(NodeInterface $node): bool {
    return $this->isIntake($node) && !$node->isPublished() && !$this->isClosed($node);
  }

  /**
   * Whether the posting's status term is Closed.
   */
  public function isClosed(NodeInterface $node): bool {
    $term = $node->get('field_job_status')->entity;
    return $term && $term->label() === 'Closed';
  }

  /**
   * Creates the posting for a completed submission.
   *
   * Staff submitting on a caller's behalf, and trusted senders signed in to
   * their own account, are published straight away (which posts to Slack);
   * everyone else waits for review.
   */
  public function createFromSubmission(WebformSubmissionInterface $submission): NodeInterface {
    $d = $submission->getData();
    $type = (string) ($d['request_type'] ?? 'commission');
    $email = trim((string) ($d['email_address'] ?? ''));

    $source = 'form';
    $owner = $submission->getOwner();
    if ($owner && $owner->isAuthenticated() && $owner->hasPermission('review job board')) {
      $source = 'staff';
    }
    // Trust the signed-in account's address, never the typed one: the form
    // is anonymous, so a typed address proves nothing (SEC-035).
    elseif ($owner && $owner->isAuthenticated()
      && JobMessage::isTrusted((string) $owner->getEmail(), (array) $this->config()->get('job_board_trusted_senders'))) {
      $source = 'trusted';
    }

    $description = trim((string) ($d['what_are_you_hoping_to_have_made'] ?? ''));
    $materials = trim((string) ($d['what_materials_do_you_imagine_it_being_made_of_wood_metal_textiles_3d_printed_pla_sign_vinyl_etc'] ?? ''));
    $more = trim((string) ($d['anything_more_to_pass_along_to_the_makers'] ?? ''));
    if ($type === 'commission' && $materials !== '') {
      $description .= "\n\nMaterials: " . $materials;
    }
    if ($more !== '') {
      $description .= "\n\n" . $more;
    }

    $title = trim((string) ($d['headline'] ?? ''));
    if ($title === '') {
      $title = JobMessage::trim(strtok($description, "\n") ?: 'Opportunity', 80);
    }

    $kind = $type === 'commission' ? 'Commission' : (self::KINDS[$d['job_kind'] ?? ''] ?? 'Contract');

    $values = [
      'type' => 'job_posting',
      'title' => mb_substr($title, 0, 255),
      'status' => 0,
      'promote' => 0,
      'uid' => ($owner && $owner->isAuthenticated()) ? $owner->id() : 0,
      'body' => ['value' => $description, 'format' => 'plain_text'],
      'field_job_type' => $this->termId('job_type', $kind),
      'field_job_status' => $this->termId('job_status', 'Open'),
      'field_job_where' => mb_substr(trim((string) ($d['location'] ?? '')), 0, 255),
      'field_job_organization' => mb_substr(trim((string) ($d['organization'] ?? '')), 0, 255),
      'field_job_pay' => mb_substr(trim((string) ($d['pay'] ?? '')), 0, 255),
      'field_job_when' => mb_substr(trim((string) ($d['when'] ?? '')), 0, 255),
      'field_job_how_to_apply' => trim((string) ($d['how_to_apply'] ?? '')),
      'field_job_contact_name' => mb_substr(trim((string) ($d['name'] ?? '')), 0, 255),
      'field_job_contact_email' => $email,
      'field_job_contact_phone' => mb_substr(trim((string) ($d['phone_number'] ?? '')), 0, 64),
      'field_job_source' => $source,
      'field_job_submission' => $submission->id(),
    ];
    if ($type !== 'commission' && !empty($d['people_needed'])) {
      $values['field_job_people_needed'] = max(1, (int) $d['people_needed']);
    }
    if (!empty($d['link'])) {
      $values['field_job_link'] = ['uri' => (string) $d['link']];
    }
    $values['field_job_disciplines'] = array_values(array_filter(array_map('intval', (array) ($d['disciplines'] ?? []))));

    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entityTypeManager->getStorage('node')->create($values);
    $node->set('field_job_attachment', $this->copyAttachments((array) ($d['attachment'] ?? [])));
    $node->setRevisionLogMessage('Created from Hire a Maker form submission ' . $submission->id());
    $node->save();

    if ($source !== 'form') {
      // Publishing posts to Slack (hook_node_presave). Done as a second save
      // so the posting has a URL for the Slack message to link to.
      $node->setPublished();
      $node->save();
    }
    else {
      $this->alertReviewers($node);
    }
    return $node;
  }

  /**
   * Posts a just-published intake node to #jobs and the trade channels.
   *
   * Called from hook_node_presave, so it only sets field values; the caller's
   * save stores them.
   *
   * @return bool
   *   TRUE if #jobs accepted the post.
   */
  public function postToSlack(NodeInterface $node): bool {
    $config = $this->config();
    if (!$config->get('job_board_post_to_slack')) {
      return FALSE;
    }
    $logger = $this->loggerFactory->get('makehaven_tasks');
    $jobs = (string) $config->get('job_board_channel');
    $o = $this->messageData($node);
    try {
      $jobsId = $this->slack->resolveChannelId($jobs);
      $ts = $this->slack->post($jobsId, JobMessage::jobsPost($o));
    }
    catch (\Throwable $e) {
      $logger->error('Job posting @nid not posted to #jobs: @error', ['@nid' => $node->id(), '@error' => $e->getMessage()]);
      return FALSE;
    }
    $node->set('field_job_slack_ts', $ts);
    $logger->info('Job posting @nid posted to #jobs (ts @ts).', ['@nid' => $node->id(), '@ts' => $ts]);

    $permalink = $this->slack->permalink($jobsId, $ts);
    foreach ($this->tradeChannels($node, (int) $config->get('job_board_trade_channels')) as $channel) {
      try {
        $this->slack->post($channel, JobMessage::tradePointer($o, $permalink));
      }
      catch (\Throwable $e) {
        // A missing trade channel must never undo the #jobs post.
        $logger->warning('Job posting @nid pointer to #@ch failed: @error', ['@nid' => $node->id(), '@ch' => $channel, '@error' => $e->getMessage()]);
      }
    }
    return TRUE;
  }

  /**
   * Tells staff a submission is waiting.
   *
   * With a review channel set, posts there mentioning the staff group (@staff).
   * Without one, DMs each person who can review and has a Slack ID on their
   * profile, so no extra channel is needed. Either way the email to info@
   * from the webform still goes out.
   */
  public function alertReviewers(NodeInterface $node): void {
    $config = $this->config();
    if (!$config->get('job_board_post_to_slack')) {
      return;
    }
    $logger = $this->loggerFactory->get('makehaven_tasks');
    $queue = Url::fromRoute('makehaven_tasks.job_board', [], ['absolute' => TRUE])->toString();
    $data = $this->messageData($node);
    $channel = trim((string) $config->get('job_board_review_channel'));
    if ($channel !== '') {
      try {
        $this->slack->post($channel, JobMessage::reviewAlert($data, $queue, trim((string) $config->get('job_board_review_group'))));
      }
      catch (\Throwable $e) {
        $logger->warning('Review alert for job posting @nid failed: @error', ['@nid' => $node->id(), '@error' => $e->getMessage()]);
      }
      return;
    }
    foreach ($this->reviewerSlackIds() as $slackId) {
      try {
        $this->slack->post($slackId, JobMessage::reviewAlert($data, $queue));
      }
      catch (\Throwable $e) {
        $logger->warning('Review DM for job posting @nid to @user failed: @error', ['@nid' => $node->id(), '@user' => $slackId, '@error' => $e->getMessage()]);
      }
    }
  }

  /**
   * Slack IDs of active people whose role grants "review job board".
   *
   * Administrators are left out (they pass every permission check).
   */
  public function reviewerSlackIds(): array {
    $roles = array_keys(array_filter(
      $this->entityTypeManager->getStorage('user_role')->loadMultiple(),
      fn($role) => !$role->isAdmin() && $role->hasPermission('review job board')
    ));
    if (!$roles) {
      return [];
    }
    $uids = $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('roles', $roles, 'IN')
      ->execute();
    $ids = [];
    foreach ($this->entityTypeManager->getStorage('user')->loadMultiple($uids) as $user) {
      $id = _makehaven_tasks_get_slack_id($user);
      if ($id) {
        $ids[$id] = $id;
      }
    }
    return array_values($ids);
  }

  /**
   * Flattens a posting into the keys JobMessage expects.
   */
  public function messageData(NodeInterface $node): array {
    $value = fn(string $field) => $node->hasField($field) && !$node->get($field)->isEmpty() ? (string) $node->get($field)->value : '';
    $type = $node->get('field_job_type')->entity;
    $link = $node->get('field_job_link')->isEmpty() ? '' : $node->get('field_job_link')->first()->getUrl()->toString();
    return [
      'title' => $node->label(),
      'type' => $type ? $type->label() : '',
      'organization' => $value('field_job_organization'),
      'description' => $node->get('body')->isEmpty() ? '' : strip_tags((string) $node->get('body')->value),
      'pay' => $value('field_job_pay'),
      'when' => $value('field_job_when'),
      'where' => $value('field_job_where'),
      'people_needed' => $value('field_job_people_needed'),
      'how_to_apply' => $value('field_job_how_to_apply'),
      'link' => $link,
      'contact_name' => $value('field_job_contact_name'),
      'contact_email' => $value('field_job_contact_email'),
      'contact_phone' => $value('field_job_contact_phone'),
      'url' => $node->id() ? $node->toUrl('canonical', ['absolute' => TRUE])->toString() : '',
    ];
  }

  /**
   * Slack channels for the posting's trades, first channel per trade.
   *
   * @return string[]
   *   Channel names without '#', de-duplicated, at most $max.
   */
  public function tradeChannels(NodeInterface $node, int $max): array {
    $channels = [];
    foreach ($node->get('field_job_disciplines')->referencedEntities() as $term) {
      if (!$term->hasField('field_interest_slack_channel') || $term->get('field_interest_slack_channel')->isEmpty()) {
        continue;
      }
      $channel = ltrim(trim((string) $term->get('field_interest_slack_channel')->value), '#');
      if ($channel !== '' && !in_array($channel, $channels, TRUE)) {
        $channels[] = $channel;
      }
    }
    return array_slice($channels, 0, max(0, $max));
  }

  /**
   * Copies uploaded files next to the posting so webform purges can't orphan it.
   */
  protected function copyAttachments(array $fids): array {
    $fids = array_filter(array_map('intval', $fids));
    if (!$fids) {
      return [];
    }
    // Looked up here rather than injected: makehaven_tasks does not depend on
    // the file module, and only submissions with an upload need it.
    /** @var \Drupal\file\FileRepositoryInterface $repository */
    $repository = \Drupal::service('file.repository');
    $items = [];
    $directory = 'private://job-attachments';
    \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    // The copy bypasses the destination field's validators, so apply its
    // extension list here. Anything else (e.g. an SVG, which a private
    // download would serve inline) stays only in the webform submission
    // (SEC-026).
    $allowed = $this->allowedAttachmentExtensions();
    foreach ($this->entityTypeManager->getStorage('file')->loadMultiple($fids) as $file) {
      $extension = strtolower(pathinfo((string) $file->getFilename(), PATHINFO_EXTENSION));
      if (!in_array($extension, $allowed, TRUE)) {
        $this->loggerFactory->get('makehaven_tasks')->warning('Attachment @fid not copied to the job posting: .@ext is not allowed on field_job_attachment.', ['@fid' => $file->id(), '@ext' => $extension]);
        continue;
      }
      try {
        $copy = $repository->copy($file, $directory . '/' . $file->getFilename(), FileExists::Rename);
        $items[] = ['target_id' => $copy->id(), 'display' => 1];
      }
      catch (\Throwable $e) {
        $this->loggerFactory->get('makehaven_tasks')->warning('Attachment @fid not copied: @error', ['@fid' => $file->id(), '@error' => $e->getMessage()]);
      }
    }
    return $items;
  }

  /**
   * Extensions field_job_attachment accepts, never including svg or xml.
   *
   * @return string[]
   *   Lower-case extensions without dots.
   */
  public function allowedAttachmentExtensions(): array {
    $definition = $this->entityTypeManager->getStorage('field_config')->load('node.job_posting.field_job_attachment');
    $list = $definition ? (string) $definition->getSetting('file_extensions') : 'pdf';
    $extensions = preg_split('/[\s,]+/', strtolower($list), -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_diff($extensions, ['svg', 'svgz', 'xml', 'html', 'htm', 'xhtml']));
  }

  /**
   * Looks a term up by name, so tids never need to match across environments.
   */
  protected function termId(string $vid, string $name): ?int {
    $ids = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vid)
      ->condition('name', $name)
      ->range(0, 1)
      ->execute();
    return $ids ? (int) reset($ids) : NULL;
  }

  /**
   * The module settings (job_board_* keys).
   */
  protected function config() {
    return $this->configFactory->get('makehaven_tasks.settings');
  }

}
