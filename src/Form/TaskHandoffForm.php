<?php

namespace Drupal\makehaven_tasks\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

/**
 * Form to hand off a task with a note and optional reassignment.
 */
class TaskHandoffForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makehaven_tasks_handoff_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $node = NULL): array {
    $form['nid'] = [
      '#type' => 'hidden',
      '#value' => $node ? (int) $node->id() : 0,
    ];

    $form['handoff_note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('What did you try? What does the next person need to know?'),
      '#required' => TRUE,
      '#rows' => 5,
    ];

    $form['reassign_to'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'user',
      '#title' => $this->t('Reassign to (optional)'),
      '#required' => FALSE,
      '#selection_settings' => [
        'include_anonymous' => FALSE,
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Hand Off Task'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $nid = (int) $form_state->getValue('nid');
    if ($nid <= 0) {
      $form_state->setErrorByName('nid', $this->t('Invalid task.'));
      return;
    }

    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== 'task') {
      $form_state->setErrorByName('nid', $this->t('This is not a valid task.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $nid = (int) $form_state->getValue('nid');
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface) {
      return;
    }

    $handoff_note = (string) $form_state->getValue('handoff_note');
    $reassign_uid = $form_state->getValue('reassign_to');

    if ($node->hasField('field_task_status')) {
      $node->set('field_task_status', 'incomplete');
    }
    if ($node->hasField('field_task_handoff_note')) {
      $node->set('field_task_handoff_note', $handoff_note);
    }
    if ($reassign_uid && $node->hasField('field_task_lead')) {
      $node->set('field_task_lead', [(int) $reassign_uid]);
    }

    $node->save();

    _makehaven_tasks_notify_slack_incomplete($node, $handoff_note);

    $this->messenger()->addStatus($this->t('Task handed off. Thanks for letting the next person know what to expect.'));

    $form_state->setRedirectUrl($node->toUrl());
  }

}
