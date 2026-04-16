<?php

namespace Drupal\makehaven_tasks\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Shows a member's claimed (in-progress) and completed tasks.
 */
class TaskPersonalHistoryController extends ControllerBase {

  public function page(): array {
    $uid = (int) $this->currentUser()->id();
    $tasks_url = Url::fromUserInput('/tasks')->toString();
    $lb_url = Url::fromRoute('makehaven_tasks.leaderboard')->toString();
    $log_url = Url::fromRoute('makehaven_tasks.retroactive_log')->toString();

    $build = [
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['flagging_list', 'user:' . $uid, 'node_list'],
        'max-age' => 0,
      ],
    ];

    $build['nav'] = [
      '#markup' => '<div class="task-back-link">'
        . '<a href="' . $tasks_url . '">← Back to all tasks</a> · '
        . '<a href="' . $lb_url . '">Community leaderboard</a>'
        . '</div>',
      '#weight' => -10,
      '#attached' => ['library' => ['makehaven_tasks/task_actions']],
    ];

    // ── Section 1: Tasks I've claimed (in progress) ──

    $claimed_nids = $this->entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'task')
      ->condition('status', 1)
      ->condition('field_task_claimed_by', $uid)
      ->condition('field_task_status', 'in_progress')
      ->accessCheck(FALSE)
      ->sort('changed', 'DESC')
      ->execute();

    // Filter out tasks already flagged complete.
    $flag_service = \Drupal::service('flag');
    $flag = $flag_service->getFlagById('task_completed');

    $claimed_rows = [];
    if (!empty($claimed_nids)) {
      $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($claimed_nids);
      foreach ($nodes as $node) {
        if ($flag && !empty($flag_service->getEntityFlaggings($flag, $node))) {
          continue;
        }
        $claimed_rows[] = $this->buildTaskRow($node);
      }
    }

    $build['claimed_heading'] = [
      '#markup' => '<h2>🔧 In progress (' . count($claimed_rows) . ')</h2>',
      '#weight' => 0,
    ];

    if (!empty($claimed_rows)) {
      $build['claimed_table'] = [
        '#type' => 'table',
        '#header' => ['Task', 'Tool / Area', 'Category', 'Est. time'],
        '#rows' => $claimed_rows,
        '#weight' => 1,
      ];
    }
    else {
      $build['claimed_empty'] = [
        '#markup' => '<p>No tasks claimed right now. <a href="' . $tasks_url . '">Browse open tasks</a> to pick one up.</p>',
        '#weight' => 1,
      ];
    }

    // ── Section 2: Completed tasks ──

    $flaggings = $this->entityTypeManager()
      ->getStorage('flagging')
      ->loadByProperties([
        'flag_id' => 'task_completed',
        'uid' => $uid,
        'entity_type' => 'node',
      ]);

    uasort($flaggings, fn($a, $b) => $b->get('created')->value <=> $a->get('created')->value);

    $completed_rows = [];
    foreach ($flaggings as $flagging) {
      $node = $this->entityTypeManager()
        ->getStorage('node')
        ->load($flagging->getFlaggableId());
      if (!$node) {
        continue;
      }
      $row = $this->buildTaskRow($node);
      $row['data'][] = \Drupal::service('date.formatter')
        ->format($flagging->get('created')->value, 'medium');
      $completed_rows[] = $row;
    }

    $build['completed_heading'] = [
      '#markup' => '<h2>✓ Completed (' . count($completed_rows) . ')</h2>',
      '#weight' => 2,
    ];

    if (!empty($completed_rows)) {
      $build['completed_table'] = [
        '#type' => 'table',
        '#header' => ['Task', 'Tool / Area', 'Category', 'Est. time', 'Completed'],
        '#rows' => $completed_rows,
        '#weight' => 3,
      ];
    }
    else {
      $build['completed_empty'] = [
        '#markup' => '<p>No completed tasks yet. <a href="' . $log_url
          . '">Log something you already fixed</a>.</p>',
        '#weight' => 3,
      ];
    }

    $build['log_link'] = [
      '#markup' => '<p><a href="' . $log_url
        . '" class="button button--primary">+ Log something I already fixed</a></p>',
      '#weight' => 4,
    ];

    return $build;
  }

  protected function buildTaskRow($node): array {
    $category = $node->hasField('field_task_category') && !$node->get('field_task_category')->isEmpty()
      ? ucfirst($node->get('field_task_category')->value)
      : '—';

    $equipment = '';
    if ($node->hasField('field_task_equipment') && !$node->get('field_task_equipment')->isEmpty()) {
      $item = $node->get('field_task_equipment')->entity;
      $equipment = $item ? $item->label() : '';
    }

    $estimated = $node->hasField('field_task_estimated_hours') && !$node->get('field_task_estimated_hours')->isEmpty()
      ? number_format($node->get('field_task_estimated_hours')->value, 1) . ' hr'
      : '—';

    return [
      'data' => [
        Link::fromTextAndUrl($node->label(), Url::fromRoute('entity.node.canonical', ['node' => $node->id()]))->toString(),
        $equipment,
        $category,
        $estimated,
      ],
    ];
  }

}
