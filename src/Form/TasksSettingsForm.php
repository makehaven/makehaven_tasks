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

    $form['volunteer'] = [
      '#type' => 'details',
      '#title' => $this->t('Volunteer board'),
      '#open' => TRUE,
    ];
    $form['volunteer']['volunteer_slack_channel'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Recruitment Slack channel'),
      '#description' => $this->t('New opportunities gathering interest, the one "needs N more" nudge and "it\'s on" announcements post here, through the Slack Connector webhook. Declines and stale-claim nudges are never posted. The #tasks posts are unchanged.'),
      '#default_value' => $config->get('volunteer_slack_channel') ?? '#volunteers',
      '#required' => TRUE,
    ];
    $form['volunteer']['ready_to_approve_email'] = [
      '#type' => 'email',
      '#title' => $this->t('"Ready to approve" email address'),
      '#description' => $this->t('Gets one email when an opportunity reaches its decide-by date with enough people interested. Leave empty to send none.'),
      '#default_value' => $config->get('ready_to_approve_email') ?? 'staff@makehaven.org',
    ];
    $form['volunteer']['gathering_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days to gather interest'),
      '#description' => $this->t('Default decide-by date, counted from posting (capped at the day before a dated shift). 8 lands in one weekly digest.'),
      '#min' => 1,
      '#max' => 60,
      '#default_value' => $config->get('gathering_days') ?? 8,
    ];
    $form['volunteer']['short_reminder_days'] = [
      '#type' => 'number',
      '#title' => $this->t('"Needs N more" post, days before decide-by'),
      '#min' => 0,
      '#max' => 30,
      '#default_value' => $config->get('short_reminder_days') ?? 3,
    ];
    $form['volunteer']['member_suggestion_stage'] = [
      '#type' => 'radios',
      '#title' => $this->t('A member\'s new suggestion'),
      '#options' => [
        'gathering' => $this->t('Goes on the board to gather interest straight away (staff approve once enough people are in)'),
        'proposed' => $this->t('Waits as a draft until a facilitator or staff member puts it out'),
      ],
      '#default_value' => $config->get('member_suggestion_stage') ?? 'gathering',
    ];
    $form['volunteer']['stale_first_days'] = [
      '#type' => 'number',
      '#title' => $this->t('First stale-claim email to the lead after (idle days)'),
      '#description' => $this->t('Only claims made since the volunteer board launched are ever emailed. Older claims are listed for staff at /tasks/approvals.'),
      '#min' => 1,
      '#default_value' => $config->get('stale_first_days') ?? 14,
    ];
    $form['volunteer']['stale_second_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Second stale-claim email after (idle days)'),
      '#min' => 1,
      '#default_value' => $config->get('stale_second_days') ?? 28,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('makehaven_tasks.settings')
      ->set('code_word', $form_state->getValue('code_word'))
      ->set('volunteer_slack_channel', '#' . ltrim(trim((string) $form_state->getValue('volunteer_slack_channel')), '#'))
      ->set('ready_to_approve_email', trim((string) $form_state->getValue('ready_to_approve_email')))
      ->set('gathering_days', (int) $form_state->getValue('gathering_days'))
      ->set('short_reminder_days', (int) $form_state->getValue('short_reminder_days'))
      ->set('member_suggestion_stage', (string) $form_state->getValue('member_suggestion_stage'))
      ->set('stale_first_days', (int) $form_state->getValue('stale_first_days'))
      ->set('stale_second_days', (int) $form_state->getValue('stale_second_days'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
