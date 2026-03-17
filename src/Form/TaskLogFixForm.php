<?php

namespace Drupal\makehaven_tasks\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for retroactively logging a task that was already completed.
 */
class TaskLogFixForm extends FormBase {

  /**
   * Creates the form.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManagerService,
    protected MessengerInterface $messengerService,
    protected AccountInterface $currentUserAccount,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('messenger'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makehaven_tasks_log_fix_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['equipment'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'node',
      '#selection_settings' => [
        'target_bundles' => ['item'],
        'sort' => ['field' => 'title', 'direction' => 'ASC'],
      ],
      '#title' => $this->t('Which tool or area?'),
      '#description' => $this->t('Start typing the name of the tool or area you worked on.'),
      '#required' => TRUE,
    ];

    $form['what_was_done'] = [
      '#type' => 'textfield',
      '#title' => $this->t('What did you fix or do?'),
      '#description' => $this->t('A short description — e.g. "Cleared a jam", "Wiped down the table", "Replaced the screen".'),
      '#maxlength' => 255,
      '#required' => TRUE,
    ];

    $form['task_date'] = [
      '#type' => 'date',
      '#title' => $this->t('When did you do this?'),
      '#description' => $this->t('Defaults to today. Use this if you are logging something you did earlier.'),
      '#default_value' => date('Y-m-d'),
      '#required' => FALSE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Log it'),
      ],
      'cancel' => [
        '#type' => 'link',
        '#title' => $this->t('Cancel'),
        '#url' => Url::fromRoute('makehaven_tasks.dashboard'),
        '#attributes' => ['class' => ['button']],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($this->currentUserAccount->isAnonymous()) {
      $form_state->setErrorByName('', $this->t('You must be logged in to log a task.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $equipment_nid = (int) $form_state->getValue('equipment');
    $description = trim((string) $form_state->getValue('what_was_done'));
    $date_value = $form_state->getValue('task_date') ?: date('Y-m-d');
    $timestamp = strtotime($date_value . ' 12:00:00') ?: \Drupal::time()->getCurrentTime();

    // Create the task node.
    $node_storage = $this->entityTypeManagerService->getStorage('node');
    $task = $node_storage->create([
      'type' => 'task',
      'title' => $description,
      'status' => 1,
      'created' => $timestamp,
      'uid' => $this->currentUserAccount->id(),
      'field_task_frequency' => 'once',
    ]);

    if ($equipment_nid) {
      $task->set('field_task_equipment', $equipment_nid);
    }

    $task->save();

    // Flag as completed immediately — this fires the Slack notification
    // and recognition logic via hook_entity_insert on the flagging.
    $flag_service = \Drupal::service('flag');
    $flag = $flag_service->getFlagById('task_completed');
    if ($flag) {
      try {
        $flag_service->flag($flag, $task, $this->currentUserAccount);
      }
      catch (\Exception $e) {
        \Drupal::logger('makehaven_tasks')->error('Could not flag retroactive task @nid: @msg', [
          '@nid' => $task->id(),
          '@msg' => $e->getMessage(),
        ]);
      }
    }

    $this->messengerService->addStatus($this->t('Logged — thanks for taking care of it! Your contribution has been recorded.'));

    // Check for any matching open tasks on the same equipment and hint.
    if ($equipment_nid) {
      $open = $this->findOpenTasksForEquipment($equipment_nid, (int) $task->id());
      if ($open) {
        $titles = array_map(fn($t) => '"' . $t->label() . '"', array_slice($open, 0, 2));
        $this->messengerService->addWarning($this->t(
          'There is also an open task for this equipment: @titles. If you completed that too, go mark it done.',
          ['@titles' => implode(', ', $titles)]
        ));
      }
    }

    $form_state->setRedirectUrl(Url::fromRoute('makehaven_tasks.dashboard'));
  }

  /**
   * Returns open (not completed) published tasks for a given equipment node.
   *
   * @param int $equipment_nid
   *   The equipment node id.
   * @param int $exclude_nid
   *   Task nid to exclude (the one just created).
   *
   * @return \Drupal\node\NodeInterface[]
   */
  protected function findOpenTasksForEquipment(int $equipment_nid, int $exclude_nid): array {
    $storage = $this->entityTypeManagerService->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'task')
      ->condition('status', 1)
      ->condition('field_task_equipment.target_id', $equipment_nid)
      ->condition('nid', $exclude_nid, '!=')
      ->range(0, 3)
      ->execute();

    if (!$nids) {
      return [];
    }

    $db = \Drupal::database();
    $completed = $db->select('flagging', 'f')
      ->fields('f', ['entity_id'])
      ->condition('f.flag_id', 'task_completed')
      ->condition('f.entity_id', array_values($nids), 'IN')
      ->execute()
      ->fetchCol();

    $completed_map = array_flip($completed);
    $open = [];
    foreach ($storage->loadMultiple($nids) as $task) {
      if (!isset($completed_map[(int) $task->id()])) {
        $open[] = $task;
      }
    }

    return $open;
  }

}
