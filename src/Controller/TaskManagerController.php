<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Staff task manager — richer alternative to the basic Views task manager.
 */
class TaskManagerController extends ControllerBase {

  public function __construct(
    protected Connection $database,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
    );
  }

  public function page(Request $request): array {
    $filter_status = $request->query->get('filter_status', 'active');
    $filter_type = $request->query->get('filter_type', 'all');

    $build = [];
    $build['#attached']['library'][] = 'makehaven_tasks/task_actions';

    // Toolbar.
    $tasks_url = Url::fromUserInput('/tasks')->toString();
    $create_url = Url::fromRoute('node.add', ['node_type' => 'task'])->toString();
    $bank_url = Url::fromRoute('makehaven_tasks.bank')->toString();

    $build['toolbar'] = [
      '#markup' => Markup::create(
        '<div class="task-back-link"><a href="' . $tasks_url . '">← Back to all tasks</a></div>'
        . '<div class="task-staff-bar">'
        . '<a href="' . $create_url . '" class="task-staff-btn task-staff-btn--primary">+ Create task</a>'
        . '<a href="' . $bank_url . '" class="task-staff-btn task-staff-btn--primary">📦 Task bank</a>'
        . '</div>'
      ),
    ];

    // Filters.
    $base = Url::fromRoute('makehaven_tasks.manage')->toString();
    $status_options = [
      'active' => 'Active (open / in-progress / incomplete)',
      'completed' => 'Completed',
      'all' => 'All',
    ];
    $type_options = [
      'all' => 'All types',
      'recurring' => 'Recurring only',
      'bank' => 'Bank templates only',
      'one_time' => 'One-time only',
    ];

    $filter_html = '<form class="task-manager-filters" method="get" action="' . $base . '">'
      . '<label>Status: <select name="filter_status">';
    foreach ($status_options as $val => $label) {
      $sel = $val === $filter_status ? ' selected' : '';
      $filter_html .= '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
    }
    $filter_html .= '</select></label>'
      . '<label>Type: <select name="filter_type">';
    foreach ($type_options as $val => $label) {
      $sel = $val === $filter_type ? ' selected' : '';
      $filter_html .= '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
    }
    $filter_html .= '</select></label>'
      . '<button type="submit" class="task-staff-btn">Apply</button>'
      . '</form>';

    $build['filters'] = ['#markup' => Markup::create($filter_html)];

    // Query tasks.
    $query = $this->entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'task')
      ->accessCheck(FALSE)
      ->sort('created', 'DESC')
      ->range(0, 100);

    if ($filter_type === 'recurring') {
      $query->condition('field_task_frequency', ['weekly', 'monthly', 'quarterly', 'yearly', 'biennial'], 'IN');
      $query->notExists('field_task_series_source');
    }
    elseif ($filter_type === 'bank') {
      $query->condition('field_task_bank', 'task_bank');
    }
    elseif ($filter_type === 'one_time') {
      $or = $query->orConditionGroup()
        ->condition('field_task_frequency', 'once')
        ->notExists('field_task_frequency');
      $query->condition($or);
    }

    $nids = $query->execute();

    // Load flag data for completed status.
    $flagging_table = $this->entityTypeManager()
      ->getDefinition('flagging')
      ->getBaseTable();
    $completed_nids = [];
    if (!empty($nids)) {
      $result = $this->database->select($flagging_table, 'f')
        ->fields('f', ['entity_id', 'uid', 'created'])
        ->condition('f.flag_id', 'task_completed')
        ->condition('f.entity_id', $nids, 'IN')
        ->execute();
      foreach ($result as $row) {
        $completed_nids[(int) $row->entity_id] = $row;
      }
    }

    // Filter by status after loading completion data.
    $nodes = Node::loadMultiple($nids);
    $rows = [];
    foreach ($nodes as $node) {
      $nid = (int) $node->id();
      $is_completed = isset($completed_nids[$nid]);
      $status_val = $node->hasField('field_task_status') ? ($node->get('field_task_status')->value ?? 'open') : 'open';

      if ($filter_status === 'active' && $is_completed) {
        continue;
      }
      if ($filter_status === 'completed' && !$is_completed) {
        continue;
      }

      // Title.
      $title_url = Url::fromRoute('entity.node.canonical', ['node' => $nid])->toString();
      $title = '<a href="' . $title_url . '">' . htmlspecialchars($node->label(), ENT_QUOTES) . '</a>';
      if (!$node->isPublished()) {
        $title .= ' <span class="task-mgr-badge task-mgr-badge--draft">Draft</span>';
      }

      // Equipment.
      $equipment = '';
      if ($node->hasField('field_task_equipment') && !$node->get('field_task_equipment')->isEmpty()) {
        $item = $node->get('field_task_equipment')->entity;
        $equipment = $item ? htmlspecialchars($item->label(), ENT_QUOTES) : '';
      }

      // Status badge.
      if ($is_completed) {
        $completer = \Drupal\user\Entity\User::load($completed_nids[$nid]->uid);
        $c_name = $completer ? htmlspecialchars($completer->getDisplayName(), ENT_QUOTES) : '';
        $c_date = \Drupal::service('date.formatter')->format($completed_nids[$nid]->created, 'short');
        $status_badge = '<span class="task-mgr-badge task-mgr-badge--done">✓ Done</span>'
          . '<span class="task-mgr-sub">' . $c_name . ' · ' . $c_date . '</span>';
      }
      elseif ($status_val === 'in_progress') {
        $claimer = '';
        if ($node->hasField('field_task_claimed_by') && !$node->get('field_task_claimed_by')->isEmpty()) {
          $u = $node->get('field_task_claimed_by')->entity;
          $claimer = $u ? ' · ' . htmlspecialchars($u->getDisplayName(), ENT_QUOTES) : '';
        }
        $status_badge = '<span class="task-mgr-badge task-mgr-badge--progress">🔧 In progress' . $claimer . '</span>';
      }
      elseif ($status_val === 'incomplete') {
        $status_badge = '<span class="task-mgr-badge task-mgr-badge--incomplete">⚠ Incomplete</span>';
      }
      else {
        $status_badge = '<span class="task-mgr-badge task-mgr-badge--open">Open</span>';
      }

      // Type badges.
      $type_badges = '';
      $freq = $node->hasField('field_task_frequency') ? $node->get('field_task_frequency')->value : NULL;
      $is_series_source = $freq && $freq !== 'once' && ($node->hasField('field_task_series_source') && $node->get('field_task_series_source')->isEmpty());
      if ($is_series_source) {
        $type_badges .= '<span class="task-mgr-badge task-mgr-badge--recurring">⟳ ' . ucfirst($freq) . '</span>';
        if ($node->hasField('field_task_series_paused') && $node->get('field_task_series_paused')->value) {
          $type_badges .= '<span class="task-mgr-badge task-mgr-badge--paused">⏸ Paused</span>';
        }
      }
      $is_bank = $node->hasField('field_task_bank') && !$node->get('field_task_bank')->isEmpty();
      if ($is_bank) {
        $type_badges .= '<span class="task-mgr-badge task-mgr-badge--bank">📦 Bank</span>';
      }

      // Priority.
      $priority = $node->hasField('field_task_priority') && !$node->get('field_task_priority')->isEmpty()
        ? htmlspecialchars($node->get('field_task_priority')->value, ENT_QUOTES)
        : '';

      // Edit link.
      $edit_url = Url::fromRoute('entity.node.edit_form', ['node' => $nid])->toString();
      $actions = '<a href="' . $edit_url . '" class="tap-link">Edit</a>';

      $rows[] = [
        'data' => [
          ['data' => ['#markup' => $title], 'class' => ['task-mgr-title']],
          $equipment,
          ['data' => ['#markup' => $status_badge]],
          ['data' => ['#markup' => $type_badges ?: '—']],
          $priority,
          ['data' => ['#markup' => $actions]],
        ],
      ];
    }

    $build['summary'] = [
      '#markup' => '<p class="task-mgr-summary">' . count($rows) . ' task(s) matching filters</p>',
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => ['Task', 'Equipment', 'Status', 'Type', 'Priority', ''],
      '#rows' => $rows,
      '#empty' => $this->t('No tasks match the current filters.'),
      '#attributes' => ['class' => ['task-manager-table']],
    ];

    return $build;
  }

}
