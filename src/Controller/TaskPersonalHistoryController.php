<?php

namespace Drupal\makehaven_tasks\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Shows a member's personal task completion history.
 */
class TaskPersonalHistoryController extends ControllerBase {

  /**
   * Renders the current user's completed tasks.
   */
  public function page(): array {
    $uid = $this->currentUser()->id();

    // Load all flaggings for task_completed by this user.
    $flaggings = $this->entityTypeManager()
      ->getStorage('flagging')
      ->loadByProperties([
        'flag_id' => 'task_completed',
        'uid' => $uid,
        'entity_type' => 'node',
      ]);

    if (empty($flaggings)) {
      return [
        '#markup' => '<p>You haven\'t completed any tasks yet. <a href="' .
          Url::fromRoute('view.tasks.page_tasks_interactive')->toString() .
          '">Browse open tasks</a> or <a href="' .
          Url::fromRoute('makehaven_tasks.retroactive_log')->toString() .
          '">log something you already fixed</a>.</p>',
      ];
    }

    // Sort newest first (flagging created timestamp).
    uasort($flaggings, fn($a, $b) => $b->get('created')->value <=> $a->get('created')->value);

    $rows = [];
    foreach ($flaggings as $flagging) {
      $node = $this->entityTypeManager()
        ->getStorage('node')
        ->load($flagging->getFlaggableId());
      if (!$node) {
        continue;
      }

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

      $completed_date = \Drupal::service('date.formatter')
        ->format($flagging->get('created')->value, 'medium');

      $rows[] = [
        'data' => [
          Link::fromTextAndUrl($node->label(), Url::fromRoute('entity.node.canonical', ['node' => $node->id()]))->toString(),
          $equipment,
          $category,
          $estimated,
          $completed_date,
        ],
      ];
    }

    $build['log_link'] = [
      '#markup' => '<p><a href="' . Url::fromRoute('makehaven_tasks.retroactive_log')->toString() .
        '" class="button button--primary">+ Log something I already fixed</a></p>',
    ];

    $build['table'] = [
      '#type' => 'table',
      '#caption' => $this->t('Your completed tasks (@count total)', ['@count' => count($rows)]),
      '#header' => ['Task', 'Tool / Area', 'Category', 'Est. time', 'Completed'],
      '#rows' => $rows,
      '#empty' => $this->t('No completed tasks found.'),
    ];

    return $build;
  }

}
