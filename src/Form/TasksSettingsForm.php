<?php

namespace Drupal\makehaven_tasks\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure settings for MakeHaven Tasks.
 */
class TasksSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'makehaven_tasks_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['makehaven_tasks.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('makehaven_tasks.settings');

    $form['code_word'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Display Code Word'),
      '#description' => $this->t('Secret code word to access the public task display at /display/tasks/CODEWORD'),
      '#default_value' => $config->get('code_word'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('makehaven_tasks.settings')
      ->set('code_word', $form_state->getValue('code_word'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
