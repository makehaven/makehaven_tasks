<?php

namespace Drupal\makehaven_tasks\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\flag\Entity\Flag;
use Drupal\node\Entity\Node;

/**
 * Shows tasks targeted at facilitators and badged members.
 *
 * Displays badge_holders tasks (open to badged members) and, for staff/admin,
 * staff_only tasks. Excludes already-completed tasks.
 */
class TaskFacilitatorController extends ControllerBase {

  /**
   * Renders the facilitator-targeted task list.
   */
  public function page(): array {
    $account = $this->currentUser();
    $staff_roles = ['administrator', 'manager', 'content_editor'];
    $is_staff = (bool) array_intersect($staff_roles, $account->getRoles());

    $audiences = ['badge_holders'];
    if ($is_staff) {
      $audiences[] = 'staff_only';
    }

    $nids = \Drupal::entityQuery('node')
      ->condition('type', 'task')
      ->condition('status', 1)
      ->condition('field_task_audience', $audiences, 'IN')
      ->accessCheck(FALSE)
      ->execute();

    // Filter out tasks that are already flagged complete.
    $flag_service = \Drupal::service('flag');
    $flag = Flag::load('task_completed');

    $open_nids = [];
    foreach ($nids as $nid) {
      $node = Node::load($nid);
      if ($node && $flag && empty($flag_service->getEntityFlaggings($flag, $node))) {
        $open_nids[] = $nid;
      }
    }

    $tasks_url = Url::fromUserInput('/tasks')->toString();
    $build['nav'] = [
      '#markup' => '<div class="task-back-link"><a href="' . $tasks_url . '">← Back to all tasks</a></div>',
      '#attached' => ['library' => ['makehaven_tasks/task_actions']],
    ];
    $build['intro'] = [
      '#markup' => '<p>Tasks that need <strong>specific expertise</strong> — badge-required maintenance and staff-only procedures.</p>',
    ];

    if (empty($open_nids)) {
      $build['empty'] = ['#markup' => '<p>No open specialist tasks right now. Nice work!</p>'];
      return $build;
    }

    $rows = [];
    foreach ($open_nids as $nid) {
      $node = Node::load($nid);

      $audience = $node->hasField('field_task_audience') ? $node->get('field_task_audience')->value : '';
      $audience_label = match($audience) {
        'badge_holders' => '🔑 Badge required',
        'staff_only'    => '🔒 Staff only',
        default         => '',
      };

      $equipment = '';
      if ($node->hasField('field_task_equipment') && !$node->get('field_task_equipment')->isEmpty()) {
        $item = $node->get('field_task_equipment')->entity;
        $equipment = $item ? $item->label() : '';
      }

      $category = $node->hasField('field_task_category') && !$node->get('field_task_category')->isEmpty()
        ? ucfirst($node->get('field_task_category')->value)
        : '—';

      $status = $node->hasField('field_task_status') ? $node->get('field_task_status')->value : 'open';
      $status_label = match($status) {
        'in_progress' => '🔧 In progress',
        'incomplete'  => '⚠ Incomplete',
        default       => '✓ Open',
      };

      $rows[] = [
        'data' => [
          Link::fromTextAndUrl($node->label(), Url::fromRoute('entity.node.canonical', ['node' => $node->id()]))->toString(),
          $equipment,
          $category,
          $audience_label,
          $status_label,
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => ['Task', 'Tool / Area', 'Category', 'Access', 'Status'],
      '#rows' => $rows,
      '#caption' => $this->t('@count specialist task(s) open', ['@count' => count($rows)]),
    ];

    return $build;
  }

}
