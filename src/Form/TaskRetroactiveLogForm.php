<?php

namespace Drupal\makehaven_tasks\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

/**
 * "Log something I fixed" — creates a completed task retroactively.
 *
 * Minimal form: which tool, what did you do, when. On submit the task node is
 * created already flagged as complete so it appears in the history and fires
 * the normal Slack completion notification.
 */
class TaskRetroactiveLogForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'makehaven_tasks_retroactive_log';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $preselected_item = NULL) {
    $form['#title'] = $this->t('Log something I already fixed');

    // Allow pre-selecting the tool via ?equipment=NID query parameter.
    if (!$preselected_item) {
      $equipment_nid = \Drupal::request()->query->get('equipment');
      if ($equipment_nid && is_numeric($equipment_nid)) {
        $preselected_item = \Drupal\node\Entity\Node::load($equipment_nid);
      }
    }

    $form['equipment'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Which tool or area?'),
      '#target_type' => 'node',
      '#selection_handler' => 'default:node',
      '#selection_settings' => [
        'target_bundles' => ['item'],
        'sort' => ['field' => 'title', 'direction' => 'ASC'],
      ],
      '#required' => TRUE,
      '#default_value' => $preselected_item,
      '#ajax' => [
        'callback' => '::openTasksCallback',
        'wrapper' => 'open-tasks-wrapper',
        'event' => 'autocompleteclose change',
      ],
    ];

    // Placeholder for open-task suggestions (populated via AJAX).
    $form['open_tasks_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'open-tasks-wrapper'],
    ];

    $equipment_nid = $form_state->getValue('equipment');
    if ($equipment_nid) {
      $open = _makehaven_tasks_open_tasks_for_item($equipment_nid);
      if (!empty($open)) {
        $items = [];
        foreach ($open as $nid => $title) {
          $url = Url::fromRoute('entity.node.canonical', ['node' => $nid])->toString();
          $items[] = "<a href=\"{$url}\">{$title}</a>";
        }
        $form['open_tasks_wrapper']['hint'] = [
          '#markup' => '<div class="task-log-open-hint"><strong>Open tasks for this tool:</strong> '
            . implode(', ', $items)
            . ' — if you completed one of those, mark it done there instead of logging a new entry.</div>',
        ];
      }
    }

    $form['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('What did you fix or do?'),
      '#placeholder' => $this->t('e.g. "Cleaned the laser lens" or "Replaced the sandpaper belt"'),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['completed_on'] = [
      '#type' => 'datetime',
      '#title' => $this->t('When?'),
      '#default_value' => \Drupal\Core\Datetime\DrupalDateTime::createFromTimestamp(time()),
      '#required' => TRUE,
      '#description' => $this->t('Defaults to right now. You can set it to earlier today if you just forgot to log it.'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Log it — I fixed this'),
    ];

    return $form;
  }

  /**
   * AJAX callback: returns the open-tasks hint after equipment is selected.
   */
  public function openTasksCallback(array &$form, FormStateInterface $form_state) {
    return $form['open_tasks_wrapper'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $current_user = $this->currentUser();
    $equipment_nid = $form_state->getValue('equipment');
    $description = $form_state->getValue('description');

    /** @var \Drupal\Core\Datetime\DrupalDateTime $completed_on */
    $completed_on = $form_state->getValue('completed_on');

    // Create the task node already published.
    $node = Node::create([
      'type' => 'task',
      'title' => $description,
      'status' => NodeInterface::PUBLISHED,
      'uid' => $current_user->id(),
      'field_task_equipment' => ['target_id' => $equipment_nid],
      'field_task_frequency' => 'once',
      'field_task_status' => 'open',
      'created' => $completed_on ? $completed_on->getTimestamp() : time(),
    ]);
    $node->save();

    // Immediately flag it as complete — this fires the Slack notification via
    // hook_entity_insert on the flagging entity.
    $flag_service = \Drupal::service('flag');
    $flag = $flag_service->getFlagById('task_completed');
    if ($flag) {
      /** @var \Drupal\user\UserInterface $user */
      $user = \Drupal\user\Entity\User::load($current_user->id());
      $flag_service->flag($flag, $node, $user);
    }

    $this->messenger()->addStatus($this->t(
      'Logged: "%description" — thanks for keeping the space running!',
      ['%description' => $description]
    ));

    $form_state->setRedirectUrl(Url::fromRoute('entity.node.canonical', ['node' => $node->id()]));
  }

}
