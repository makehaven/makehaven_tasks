<?php

namespace Drupal\makehaven_tasks\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Component\Utility\Html;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds a user-facing task dashboard.
 */
class TaskDashboardController extends ControllerBase {

  /**
   * Creates a task dashboard controller.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManagerService,
    protected Connection $database,
    protected AccountInterface $currentUserAccount,
    protected DateFormatterInterface $dateFormatterService,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('current_user'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds the task dashboard.
   */
  public function content(): array {
    $buckets = $this->loadOpenTaskBuckets(80);
    $recent = $this->loadRecentCompletions(10);

    $build = [
      '#type' => 'container',
      '#attached' => [
        'library' => ['makehaven_tasks/dashboard'],
      ],
      '#attributes' => ['class' => ['task-dashboard']],
      'intro' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['task-dashboard__intro']],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h1',
          '#value' => $this->t('Task Dashboard'),
        ],
        'text' => [
          '#markup' => '<p>' . $this->t('Open member work, badge-qualified maintenance work, staff-only items, and recent recognition all in one place.') . '</p>',
        ],
        'log_fix' => !$this->currentUserAccount->isAnonymous() ? [
          '#type' => 'link',
          '#title' => $this->t('+ Log something I already fixed'),
          '#url' => \Drupal\Core\Url::fromRoute('makehaven_tasks.log_fix'),
          '#options' => ['attributes' => ['class' => ['button', 'button--small']]],
        ] : [],
      ],
      'sections' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['task-dashboard__sections']],
      ],
    ];

    // Incomplete tasks appear first — they need pickup.
    if (!empty($buckets['incomplete'])) {
      $build['sections']['incomplete'] = $this->buildIncompleteSection($buckets['incomplete']);
    }

    $build['sections']['assigned'] = $this->buildSection(
      $this->t('Assigned To Me'),
      $this->t('Tasks assigned directly to you or one of your groups.'),
      $buckets['assigned'],
      $this->t('Nothing is currently assigned to you.')
    );
    $build['sections']['member'] = $this->buildSection(
      $this->t('Open Member Tasks'),
      $this->t('Safe, generally useful tasks that members can jump into.'),
      $buckets['member'],
      $this->t('No open member tasks right now.')
    );
    $build['sections']['qualified'] = $this->buildSection(
      $this->t('Tasks I Am Qualified For'),
      $this->t('Maintenance and specialized work that matches your current badges.'),
      $buckets['qualified'],
      $this->t('No badge-qualified tasks are currently open for you.')
    );
    $build['sections']['locked'] = $this->buildSection(
      $this->t('Training Unlocks These'),
      $this->t('Tasks waiting on additional training, so people can see what skills open up more ways to help.'),
      $buckets['locked'],
      $this->t('No locked badge-gated tasks right now.')
    );

    if (!empty($buckets['staff'])) {
      $build['sections']['staff'] = $this->buildSection(
        $this->t('Staff Queue'),
        $this->t('Tasks limited to staff and task managers.'),
        $buckets['staff'],
        $this->t('No staff-only tasks are open.')
      );
    }

    $build['sections']['recent'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['task-dashboard__section']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Recent Recognition'),
      ],
      'description' => [
        '#markup' => '<p>' . $this->t('People who recently closed the loop on real work.') . '</p>',
      ],
      'list' => $this->buildRecognitionList($recent),
    ];

    return $build;
  }

  /**
   * Builds the "Needs Help — Incomplete Tasks" section.
   */
  protected function buildIncompleteSection(array $tasks): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['task-dashboard__section', 'task-dashboard__section--incomplete']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Needs Help — Incomplete Tasks'),
      ],
      'description' => [
        '#markup' => '<p>' . Html::escape((string) $this->t('These tasks were started but not finished. Pick one up if you can.')) . '</p>',
      ],
      'content' => $this->buildTaskCards($tasks, (string) $this->t('No incomplete tasks right now.')),
    ];
  }

  /**
   * Builds a dashboard section.
   */
  protected function buildSection(string $title, string $description, array $tasks, string $empty): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['task-dashboard__section']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $title,
      ],
      'description' => [
        '#markup' => '<p>' . Html::escape($description) . '</p>',
      ],
      'content' => $this->buildTaskCards($tasks, $empty),
    ];
  }

  /**
   * Builds task cards.
   */
  protected function buildTaskCards(array $tasks, string $empty): array {
    if (!$tasks) {
      return [
        '#markup' => '<p class="task-dashboard__empty">' . Html::escape($empty) . '</p>',
      ];
    }

    $items = [];
    foreach ($tasks as $task) {
      $items[] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['task-card', 'task-card--' . Html::getClass($task['variant'])]],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#attributes' => ['class' => ['task-card__title']],
          'link' => [
            '#type' => 'link',
            '#title' => $task['title'],
            '#url' => $task['url'],
          ],
        ],
        'summary' => [
          '#markup' => '<p class="task-card__summary">' . Html::escape($task['summary']) . '</p>',
        ],
        'meta' => [
          '#markup' => '<div class="task-card__meta">' .
            '<span><strong>' . $this->t('Area') . ':</strong> ' . Html::escape($task['asset']) . '</span>' .
            '<span><strong>' . $this->t('Priority') . ':</strong> ' . Html::escape($task['priority']) . '</span>' .
            '<span><strong>' . $this->t('Frequency') . ':</strong> ' . Html::escape($task['frequency']) . '</span>' .
            '<span><strong>' . $this->t('Requirement') . ':</strong> ' . Html::escape($task['requirement']) . '</span>' .
            ($task['next_due'] !== '' ? '<span><strong>' . $this->t('Next due') . ':</strong> ' . Html::escape($task['next_due']) . '</span>' : '') .
            '</div>',
        ],
        'actions' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['task-card__actions']],
          'open' => [
            '#type' => 'link',
            '#title' => $this->t('Open task'),
            '#url' => $task['url'],
            '#options' => ['attributes' => ['class' => ['button']]],
          ],
          'complete' => $this->buildCompleteAction($task['nid']),
        ],
      ];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['task-dashboard__cards']],
      '#list_type' => 'ul',
      '#wrapper_attributes' => ['class' => ['task-dashboard__cards']],
    ];
  }

  /**
   * Builds the recent recognition list.
   */
  protected function buildRecognitionList(array $recent): array {
    if (!$recent) {
      return [
        '#markup' => '<p class="task-dashboard__empty">' . $this->t('No recent task completions yet.') . '</p>',
      ];
    }

    $items = [];
    foreach ($recent as $row) {
      $task = Link::fromTextAndUrl($row['task'], $row['url'])->toString();
      $person = Html::escape($row['person']);
      $completed = Html::escape($row['completed']);
      $items[] = [
        '#markup' => '<strong>' . $person . '</strong> ' . $this->t('completed') . ' ' . $task . ' <span class="task-card__summary">(' . $completed . ')</span>',
      ];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['task-dashboard__recognition']],
    ];
  }

  /**
   * Loads open tasks bucketed for the current user.
   */
  protected function loadOpenTaskBuckets(int $scanLimit): array {
    $buckets = [
      'incomplete' => [],
      'assigned' => [],
      'member' => [],
      'qualified' => [],
      'locked' => [],
      'staff' => [],
    ];

    $storage = $this->entityTypeManagerService->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'task')
      ->condition('status', 1)
      ->sort('sticky', 'DESC')
      ->sort('created', 'DESC')
      ->range(0, $scanLimit);

    if ($this->taskFieldExists('field_task_priority')) {
      $query->sort('field_task_priority.value', 'ASC');
    }

    $nids = $query->execute();
    if (!$nids) {
      return $buckets;
    }

    $completed = $this->loadCompletedTaskIds($nids);
    $tasks = $storage->loadMultiple($nids);

    $has_status_field = $this->taskFieldExists('field_task_status');

    foreach ($tasks as $task) {
      $nid = (int) $task->id();
      if (isset($completed[$nid])) {
        continue;
      }

      // Incomplete tasks get their own bucket regardless of audience/assignment.
      if ($has_status_field
        && $task->hasField('field_task_status')
        && (string) $task->get('field_task_status')->value === 'incomplete'
      ) {
        $buckets['incomplete'][] = $this->buildTaskRow($task, 'incomplete');
        continue;
      }

      $bucket = $this->classifyTask($task);
      if ($bucket === NULL) {
        continue;
      }

      $row = $this->buildTaskRow($task, $bucket);
      if ($this->isTaskAssignedToCurrentUser($task)) {
        $buckets['assigned'][] = $row;
      }
      else {
        $buckets[$bucket][] = $row;
      }
    }

    foreach (array_keys($buckets) as $bucket) {
      $buckets[$bucket] = array_slice($buckets[$bucket], 0, 8);
    }

    return $buckets;
  }

  /**
   * Returns the bucket a task belongs to for the current user.
   */
  protected function classifyTask($task): ?string {
    $account = $this->currentUserAccount;
    $is_staff = _makehaven_tasks_is_task_staff($account);
    $audience = _makehaven_tasks_get_task_audience($task);

    if ($audience === 'staff_only') {
      return $is_staff ? 'staff' : NULL;
    }

    if ($audience === 'badge_holders') {
      if (_makehaven_tasks_user_meets_task_badge_requirement($task, $account)) {
        return 'qualified';
      }
      return $is_staff ? 'staff' : 'locked';
    }

    return 'member';
  }

  /**
   * Builds a row payload for a task.
   */
  protected function buildTaskRow($task, string $bucket): array {
    $asset = (string) $this->t('General');
    if ($task->hasField('field_task_equipment') && !$task->get('field_task_equipment')->isEmpty()) {
      $item = $task->get('field_task_equipment')->entity;
      if ($item) {
        $asset = $item->label();
      }
    }

    $summary = '';
    if ($task->hasField('body') && !$task->get('body')->isEmpty()) {
      $summary = trim(strip_tags($task->get('body')->value));
    }
    $summary = mb_substr($summary, 0, 180);
    if ($summary !== '' && mb_strlen($summary) >= 180) {
      $summary .= '...';
    }

    $requirement = (string) $this->t('None');
    if ($task->hasField('field_task_required_badge') && !$task->get('field_task_required_badge')->isEmpty()) {
      $badge = $task->get('field_task_required_badge')->entity;
      if ($badge) {
        $requirement = $badge->label();
      }
    }
    elseif (_makehaven_tasks_get_task_audience($task) === 'staff_only') {
      $requirement = (string) $this->t('Staff only');
    }

    $next_due = '';
    if ($task->hasField('field_task_next_due') && !$task->get('field_task_next_due')->isEmpty()) {
      $timestamp = strtotime((string) $task->get('field_task_next_due')->value);
      if ($timestamp) {
        $next_due = $this->dateFormatterService->format($timestamp, 'custom', 'M j, Y g:i a');
      }
    }

    return [
      'title' => $task->label(),
      'nid' => (int) $task->id(),
      'url' => $task->toUrl(),
      'summary' => $summary !== '' ? $summary : (string) $this->t('Open the task for details.'),
      'asset' => $asset,
      'priority' => (string) _makehaven_tasks_field_label_or_fallback($task, 'field_task_priority'),
      'frequency' => (string) _makehaven_tasks_field_label_or_fallback($task, 'field_task_frequency'),
      'requirement' => $requirement,
      'next_due' => $next_due,
      'variant' => $bucket,
    ];
  }

  /**
   * Returns TRUE when the current user is directly assigned.
   */
  protected function isTaskAssignedToCurrentUser($task): bool {
    $uid = (int) $this->currentUserAccount->id();
    if ($uid <= 0) {
      return FALSE;
    }

    if ($task->hasField('field_task_lead') && !$task->get('field_task_lead')->isEmpty()) {
      foreach ($task->get('field_task_lead')->getValue() as $lead) {
        if ((int) ($lead['target_id'] ?? 0) === $uid) {
          return TRUE;
        }
      }
    }

    if ($task->hasField('field_task_group') && !$task->get('field_task_group')->isEmpty()) {
      foreach ($task->get('field_task_group')->referencedEntities() as $group_term) {
        if (!$group_term->hasField('field_group_lead') || $group_term->get('field_group_lead')->isEmpty()) {
          continue;
        }
        foreach ($group_term->get('field_group_lead')->getValue() as $lead) {
          if ((int) ($lead['target_id'] ?? 0) === $uid) {
            return TRUE;
          }
        }
      }
    }

    return FALSE;
  }

  /**
   * Loads completed task ids for a result set.
   */
  protected function loadCompletedTaskIds(array $taskNids): array {
    $results = $this->database->select('flagging', 'f')
      ->fields('f', ['entity_id'])
      ->condition('f.flag_id', 'task_completed')
      ->condition('f.entity_id', $taskNids, 'IN')
      ->distinct()
      ->execute()
      ->fetchCol();

    $map = [];
    foreach ($results as $entity_id) {
      $map[(int) $entity_id] = TRUE;
    }
    return $map;
  }

  /**
   * Loads recent task completions.
   */
  protected function loadRecentCompletions(int $limit): array {
    $query = $this->database->select('flagging', 'f');
    $query->join('node_field_data', 'n', 'n.nid = f.entity_id AND n.type = :type', [':type' => 'task']);
    $query->join('users_field_data', 'u', 'u.uid = f.uid');
    $query->fields('f', ['created']);
    $query->fields('n', ['title', 'nid']);
    $query->fields('u', ['name']);
    $query->condition('f.flag_id', 'task_completed');
    $query->orderBy('f.created', 'DESC');
    $query->range(0, $limit);

    $rows = [];
    foreach ($query->execute() as $record) {
      $rows[] = [
        'task' => $record->title,
        'url' => \Drupal\Core\Url::fromRoute('entity.node.canonical', ['node' => $record->nid]),
        'person' => $record->name,
        'completed' => $this->dateFormatterService->format((int) $record->created, 'custom', 'M j, Y g:i a'),
      ];
    }

    return $rows;
  }

  /**
   * Returns TRUE if a task field exists.
   */
  protected function taskFieldExists(string $fieldName): bool {
    return $this->entityTypeManagerService->getStorage('field_storage_config')->load('node.' . $fieldName) !== NULL;
  }

  /**
   * Builds the completion action for a task card.
   */
  protected function buildCompleteAction(int $nid): array {
    if ($this->currentUserAccount->isAnonymous()) {
      return [
        '#type' => 'link',
        '#title' => $this->t('Log in to complete'),
        '#url' => \Drupal\Core\Url::fromRoute('user.login'),
        '#options' => ['attributes' => ['class' => ['button']]],
      ];
    }

    return \Drupal::service('flag.link_builder')->build('node', $nid, 'task_completed', 'full');
  }

}
