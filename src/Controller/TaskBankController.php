<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Task bank: browse templates and instantiate tasks from them.
 */
class TaskBankController extends ControllerBase {

  /**
   * Lists all task bank templates for staff to browse and instantiate.
   */
  public function listPage(Request $request): array {
    $equipment_filter = $request->query->get('equipment');

    $query = $this->entityTypeManager()
      ->getStorage('node')
      ->getQuery()
      ->condition('type', 'task')
      ->condition('field_task_bank', 'task_bank')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->sort('title');

    // Non-staff users cannot see staff-only or badge-holder templates.
    $can_manage = $this->currentUser()->hasPermission('makehaven_tasks.manage_bank');
    if (!$can_manage) {
      $or = $query->orConditionGroup()
        ->condition('field_task_audience', 'members')
        ->notExists('field_task_audience');
      $query->condition($or);
    }

    if ($equipment_filter && is_numeric($equipment_filter)) {
      $query->condition('field_task_equipment', (int) $equipment_filter);
    }

    $nids = $query->execute();

    $build = [];
    $tasks_url = Url::fromUserInput('/tasks')->toString();
    $build['nav'] = [
      '#markup' => '<div class="task-back-link"><a href="' . $tasks_url . '">← Back to all tasks</a></div>',
      '#attached' => ['library' => ['makehaven_tasks/task_actions']],
    ];

    if ($equipment_filter && is_numeric($equipment_filter)) {
      $item = Node::load((int) $equipment_filter);
      if ($item) {
        $all_url = Url::fromRoute('makehaven_tasks.bank')->toString();
        $build['filter_notice'] = [
          '#markup' => '<div class="task-bank-filter">'
            . 'Showing templates for <strong>' . htmlspecialchars($item->label(), ENT_QUOTES) . '</strong> · '
            . '<a href="' . $all_url . '">Show all templates</a>'
            . '</div>',
        ];
      }
    }

    $can_manage = $this->currentUser()->hasPermission('makehaven_tasks.manage_bank');
    $intro = $can_manage
      ? '<p>Task templates that can be quickly turned into active tasks. '
        . 'Click <strong>Create task</strong> to copy a template into a new open task.</p>'
      : '<p>These are pre-approved tasks you can create when you see something that needs doing. '
        . 'Please only create a task if you see it genuinely needs attention right now.</p>';
    $build['intro'] = ['#markup' => $intro];

    if (empty($nids)) {
      $build['empty'] = [
        '#markup' => '<p>No task templates in the bank yet. To add one, edit any task and check '
          . '"Add to Task Bank" in the task bank field.</p>',
      ];
      return $build;
    }

    $rows = [];
    foreach (Node::loadMultiple($nids) as $node) {
      $equipment = '';
      if ($node->hasField('field_task_equipment') && !$node->get('field_task_equipment')->isEmpty()) {
        $item = $node->get('field_task_equipment')->entity;
        $equipment = $item ? htmlspecialchars($item->label(), ENT_QUOTES) : '';
      }

      $category = $node->hasField('field_task_category') && !$node->get('field_task_category')->isEmpty()
        ? htmlspecialchars(ucfirst($node->get('field_task_category')->value), ENT_QUOTES)
        : '';

      $priority = $node->hasField('field_task_priority') && !$node->get('field_task_priority')->isEmpty()
        ? htmlspecialchars($node->get('field_task_priority')->value, ENT_QUOTES)
        : '';

      $create_url = Url::fromRoute('makehaven_tasks.bank_create', ['node' => $node->id()], [
        'query' => ['token' => \Drupal::csrfToken()->get('task-bank-create/' . $node->id())],
      ])->toString();

      $actions = '<a href="' . $create_url . '" class="tap-btn tap-btn--claim" style="padding:0.4rem 0.8rem;font-size:0.85rem;">+ Create task</a>';
      if ($can_manage) {
        $edit_url = Url::fromRoute('entity.node.edit_form', ['node' => $node->id()])->toString();
        $actions .= ' <a href="' . $edit_url . '" class="tap-link" style="font-size:0.8rem;">Edit template</a>';
      }

      $rows[] = [
        'data' => [
          $node->label(),
          $equipment,
          $category,
          $priority,
          ['data' => ['#markup' => $actions]],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => ['Template', 'Equipment / Area', 'Category', 'Priority', 'Actions'],
      '#rows' => $rows,
      '#caption' => $this->t('@count template(s) in the task bank', ['@count' => count($rows)]),
    ];

    return $build;
  }

  /**
   * Instantiates a new published task from a bank template.
   */
  public function createFromBank(NodeInterface $node, Request $request): RedirectResponse {
    $token = $request->query->get('token');
    if (!$token || !\Drupal::csrfToken()->validate($token, 'task-bank-create/' . $node->id())) {
      $this->messenger()->addError($this->t('Invalid or expired token. Please try again.'));
      return new RedirectResponse(Url::fromRoute('makehaven_tasks.bank')->toString());
    }

    if ($node->bundle() !== 'task') {
      $this->messenger()->addError($this->t('Not a task node.'));
      return new RedirectResponse(Url::fromRoute('makehaven_tasks.bank')->toString());
    }

    // Guard: template must be published and in the bank.
    if (!$node->isPublished()) {
      $this->messenger()->addError($this->t('This template is not available.'));
      return new RedirectResponse(Url::fromRoute('makehaven_tasks.bank')->toString());
    }
    if ($node->hasField('field_task_bank') && $node->get('field_task_bank')->isEmpty()) {
      $this->messenger()->addError($this->t('This is not a bank template.'));
      return new RedirectResponse(Url::fromRoute('makehaven_tasks.bank')->toString());
    }

    // Guard: non-staff cannot instantiate staff-only or badge-holder templates.
    $can_manage = $this->currentUser()->hasPermission('makehaven_tasks.manage_bank');
    if (!$can_manage) {
      $audience = $node->hasField('field_task_audience') ? $node->get('field_task_audience')->value : NULL;
      if ($audience === 'staff_only' || $audience === 'badge_holders') {
        $this->messenger()->addError($this->t('You do not have access to create this type of task.'));
        return new RedirectResponse(Url::fromRoute('makehaven_tasks.bank')->toString());
      }
    }

    $new_task = $this->cloneTemplate($node);
    if (!$new_task) {
      $this->messenger()->addError($this->t('Failed to create task from template.'));
      return new RedirectResponse(Url::fromRoute('makehaven_tasks.bank')->toString());
    }

    $creator = \Drupal\user\Entity\User::load($this->currentUser()->id());
    $creator_name = $creator ? $creator->getDisplayName() : 'Unknown';
    \Drupal::logger('makehaven_tasks')->notice(
      'Task "@title" (nid @nid) created from bank template @template by @user.',
      [
        '@title' => $new_task->label(),
        '@nid' => $new_task->id(),
        '@template' => $node->id(),
        '@user' => $creator_name,
      ]
    );

    $this->messenger()->addStatus($this->t(
      'Created task "%title" from bank template.',
      ['%title' => $new_task->label()]
    ));

    return new RedirectResponse(
      Url::fromRoute('entity.node.canonical', ['node' => $new_task->id()])->toString()
    );
  }

  /**
   * Clones a bank template into a new published task.
   */
  protected function cloneTemplate(NodeInterface $source): ?NodeInterface {
    $fields_to_copy = [
      'body',
      'field_task_audience',
      'field_task_category',
      'field_task_equipment',
      'field_task_group',
      'field_task_image',
      'field_task_instructions',
      'field_task_lead',
      'field_task_priority',
      'field_task_required_badge',
      'field_task_estimated_hours',
      'field_task_video_instruction',
      'field_task_video_url',
    ];

    $values = [
      'type' => 'task',
      'title' => $source->label(),
      'status' => NodeInterface::PUBLISHED,
      'uid' => $this->currentUser()->id(),
      'field_task_status' => 'open',
    ];

    foreach ($fields_to_copy as $field_name) {
      if ($source->hasField($field_name) && !$source->get($field_name)->isEmpty()) {
        $values[$field_name] = $source->get($field_name)->getValue();
      }
    }

    try {
      $new_task = Node::create($values);
      $new_task->save();
      return $new_task;
    }
    catch (\Exception $e) {
      \Drupal::logger('makehaven_tasks')->error(
        'Failed to create task from bank template @nid: @error',
        ['@nid' => $source->id(), '@error' => $e->getMessage()]
      );
      return NULL;
    }
  }

}
