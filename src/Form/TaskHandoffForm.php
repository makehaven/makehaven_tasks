<?php

namespace Drupal\makehaven_tasks\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Form for handing off a task with a note and optional reassignment.
 */
class TaskHandoffForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'makehaven_tasks_handoff_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $node = NULL) {
    if (!$node || $node->bundle() !== 'task') {
      $this->messenger()->addError($this->t('Invalid task.'));
      return $form;
    }

    $form_state->set('node', $node);

    $form['#title'] = $this->t('Hand off: @title', ['@title' => $node->label()]);

    $form['note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('What did you try / what still needs to be done?'),
      '#required' => TRUE,
      '#rows' => 4,
      '#placeholder' => $this->t('e.g. "Replaced the sandpaper but the belt tensioner is still loose — needs someone with the woodshop badge."'),
    ];

    $form['reassign'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Reassign to (optional)'),
      '#description' => $this->t('Leave blank to keep the current lead. Changing this updates the task lead and notifies the new person on Slack.'),
      '#target_type' => 'user',
      '#selection_settings' => ['include_anonymous' => FALSE],
      '#required' => FALSE,
    ];

    $form['back'] = [
      '#type' => 'link',
      '#title' => $this->t('← Cancel'),
      '#url' => Url::fromRoute('entity.node.canonical', ['node' => $node->id()]),
      '#attributes' => ['class' => ['button', 'button--secondary']],
      '#weight' => 10,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t("Mark as incomplete — hand off"),
      '#weight' => 9,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $form_state->get('node');
    $note = $form_state->getValue('note');
    $reassign_uid = $form_state->getValue('reassign');
    $current_user = $this->currentUser();

    // Update status.
    $node->set('field_task_status', 'incomplete');
    $node->set('field_task_claimed_by', ['target_id' => $current_user->id()]);

    // Reassign lead if specified.
    if ($reassign_uid) {
      $node->set('field_task_lead', ['target_id' => $reassign_uid]);
    }

    $node->save();

    // Send Slack notification with the handoff note.
    _makehaven_tasks_notify_handoff($node, $note, $current_user->id(), $reassign_uid);

    $this->messenger()->addStatus($this->t('Task marked as incomplete. The team has been notified on Slack.'));
    $form_state->setRedirectUrl(Url::fromRoute('entity.node.canonical', ['node' => $node->id()]));
  }

}
