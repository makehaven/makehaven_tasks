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
      '#description' => $this->t('Gets one email when an opportunity reaches its sign-up deadline with enough people in. Leave empty to send none.'),
      '#default_value' => $config->get('ready_to_approve_email') ?? 'staff@makehaven.org',
    ];
    $form['volunteer']['gathering_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days to gather interest'),
      '#description' => $this->t('Default sign-up deadline, counted from posting (capped at the day before a dated shift). 8 lands in one weekly digest.'),
      '#min' => 1,
      '#max' => 60,
      '#default_value' => $config->get('gathering_days') ?? 8,
    ];
    $form['volunteer']['short_reminder_days'] = [
      '#type' => 'number',
      '#title' => $this->t('"Needs N more" post, days before the sign-up deadline'),
      '#min' => 0,
      '#max' => 30,
      '#default_value' => $config->get('short_reminder_days') ?? 3,
    ];
    $form['volunteer']['member_suggestion_stage'] = [
      '#type' => 'radios',
      '#title' => $this->t('A member\'s new suggestion'),
      '#options' => [
        'gathering' => $this->t('Goes on the board to gather interest straight away (staff approve once enough people are in)'),
        'proposed' => $this->t('Waits as a draft until a facilitator or staff member publishes it'),
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

    $form['volunteer']['outreach_slack_channel'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tabling summary Slack channel'),
      '#description' => $this->t('Gets a one-line summary of each new tabling opportunity (the recruiting post still goes to the channel above). Leave empty for none.'),
      '#default_value' => $config->get('outreach_slack_channel') ?? '#outreach-retention-committee',
    ];

    $form['perks'] = [
      '#type' => 'details',
      '#title' => $this->t('Volunteer thank-yous'),
      '#open' => TRUE,
      '#description' => $this->t('A t-shirt on the first day volunteered; lunch for a long day; a hoodie after a number of days. Counted from shifts and tabling. Staff hand them out from /tasks/thank-yous.'),
    ];
    $form['perks']['perk_lunch_amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Lunch thank-you ($)'),
      '#min' => 0,
      '#default_value' => $config->get('perk_lunch_amount') ?? 15,
    ];
    $form['perks']['perk_lunch_hours'] = [
      '#type' => 'number',
      '#title' => $this->t('Hours in one day that earn lunch'),
      '#min' => 0.5,
      '#step' => 0.5,
      '#default_value' => $config->get('perk_lunch_hours') ?? 3,
    ];
    $form['perks']['perk_hoodie_dates'] = [
      '#type' => 'number',
      '#title' => $this->t('Days volunteered that earn a hoodie'),
      '#min' => 1,
      '#default_value' => $config->get('perk_hoodie_dates') ?? 5,
    ];
    $form['perks']['perk_tshirt_material'] = [
      '#type' => 'number',
      '#title' => $this->t('T-shirt store item (node ID)'),
      '#description' => $this->t('Giving one takes it off this item\'s shelf count.'),
      '#default_value' => $config->get('perk_tshirt_material') ?? 19384,
    ];
    $form['perks']['perk_hoodie_material'] = [
      '#type' => 'number',
      '#title' => $this->t('Hoodie store item (node ID)'),
      '#default_value' => $config->get('perk_hoodie_material') ?? 41386,
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
      ->set('outreach_slack_channel', ($c = trim((string) $form_state->getValue('outreach_slack_channel'))) !== '' ? '#' . ltrim($c, '#') : '')
      ->set('perk_lunch_amount', (int) $form_state->getValue('perk_lunch_amount'))
      ->set('perk_lunch_hours', (float) $form_state->getValue('perk_lunch_hours'))
      ->set('perk_hoodie_dates', (int) $form_state->getValue('perk_hoodie_dates'))
      ->set('perk_tshirt_material', (int) $form_state->getValue('perk_tshirt_material'))
      ->set('perk_hoodie_material', (int) $form_state->getValue('perk_hoodie_material'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
