<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Form;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\makehaven_tasks\Volunteer\Opportunity;
use Drupal\makehaven_tasks\Volunteer\SignupStore;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

/**
 * "Suggest a volunteer opportunity" (/tasks/request).
 *
 * Who may post what (JR, 2026-09-28; roles only, no per-person flag):
 * - Members inventing something new: it goes on the board to gather interest
 *   (or, if makehaven_tasks.settings:member_suggestion_stage is "proposed",
 *   waits as a draft for a facilitator or staff member to put it out). Staff
 *   approve it once enough people are in. The suggester counts as the first
 *   person interested unless they untick the box.
 * - Staff and facilitators (makehaven_tasks.create_task) may post it straight
 *   to the board as an approved task, or choose to gather interest first.
 * - Repeating chores (the task bank) need no approval at all; see /tasks/bank.
 */
final class TaskRequestForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makehaven_tasks_request';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'makehaven_tasks/task_actions';
    $equipment = NULL;
    $equipment_nid = $this->getRequest()->query->get('equipment');
    if (is_numeric($equipment_nid)) {
      $candidate = Node::load((int) $equipment_nid);
      if ($candidate instanceof NodeInterface && $candidate->bundle() === 'item') {
        $equipment = $candidate;
      }
    }
    $elevated = $this->currentUser()->hasPermission('makehaven_tasks.create_task');
    $fields = Opportunity::fieldsInstalled();

    $bank_link = Url::fromRoute('makehaven_tasks.bank')->toString();
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Suggest something volunteers could do: fix or clean something, run a build day, help at an event. It goes on the <a href=":board">volunteer board</a> so people can say they are interested, and staff approve it once enough people are in. For a regular chore that is already in the <a href=":bank">task bank</a>, just start it from there; no approval needed. For damage or a safety concern, use the issue report on the tool page instead.', [
        ':board' => Url::fromRoute('makehaven_tasks.volunteer')->toString(),
        ':bank' => $bank_link,
      ]) . '</p>',
    ];
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('What needs doing?'),
      '#maxlength' => 255,
      '#required' => TRUE,
      '#placeholder' => $this->t('e.g. Clean the table-saw dust collector, or Help set up the open house'),
    ];
    $form['details'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Details'),
      '#required' => TRUE,
      '#rows' => 5,
      '#description' => $this->t('Describe the work and anything a volunteer should know.'),
    ];
    if ($fields) {
      $form['kind'] = [
        '#type' => 'radios',
        '#title' => $this->t('Kind'),
        '#options' => [
          Opportunity::TYPE_TASK => $this->t('A task: can be done any time'),
          Opportunity::TYPE_SHIFT => $this->t('A dated shift: needs people on a particular date'),
        ],
        '#default_value' => Opportunity::TYPE_TASK,
      ];
      $form['when'] = [
        '#type' => 'container',
        '#states' => ['visible' => [':input[name="kind"]' => ['value' => Opportunity::TYPE_SHIFT]]],
      ];
      $form['when']['start'] = [
        '#type' => 'datetime',
        '#title' => $this->t('Starts'),
      ];
      $form['when']['end'] = [
        '#type' => 'datetime',
        '#title' => $this->t('Ends'),
      ];
      $form['min'] = [
        '#type' => 'number',
        '#title' => $this->t('How many people does it need?'),
        '#min' => 1,
        '#max' => 50,
        '#default_value' => 1,
      ];
      $form['max'] = [
        '#type' => 'number',
        '#title' => $this->t('Most people (optional)'),
        '#min' => 1,
        '#max' => 200,
        '#description' => $this->t('Sign-up closes when this many have joined.'),
      ];
      $form['interest'] = [
        '#type' => 'select',
        '#title' => $this->t('Interest area (optional)'),
        '#options' => $this->interestOptions(),
        '#empty_option' => $this->t('- General -'),
      ];
    }
    $form['equipment'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Tool (optional)'),
      '#target_type' => 'node',
      '#selection_handler' => 'default:node',
      '#selection_settings' => [
        'target_bundles' => ['item'],
        'sort' => ['field' => 'title', 'direction' => 'ASC'],
      ],
      '#default_value' => $equipment,
      '#required' => FALSE,
    ];
    if ($fields) {
      $form['count_me_in'] = [
        '#type' => 'checkbox',
        '#title' => $this->t("I'm interested in doing it myself"),
        '#default_value' => 1,
      ];
      if ($elevated) {
        $form['post_as'] = [
          '#type' => 'radios',
          '#title' => $this->t('Post it'),
          '#options' => [
            Opportunity::STAGE_APPROVED => $this->t('Straight to the board, ready to claim (approved)'),
            Opportunity::STAGE_GATHERING => $this->t('Gather interest first, then approve'),
          ],
          '#default_value' => Opportunity::STAGE_APPROVED,
        ];
      }
    }
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Post it'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getValue('kind') === Opportunity::TYPE_SHIFT) {
      $start = $form_state->getValue('start');
      if (!$start instanceof DrupalDateTime) {
        $form_state->setErrorByName('start', $this->t('A dated shift needs a start date and time.'));
      }
      elseif ($start->getTimestamp() < \Drupal::time()->getCurrentTime() + Opportunity::MIN_LEAD) {
        $form_state->setErrorByName('start', $this->t('Suggest a shift at least two days ahead, so people have time to sign up and staff to approve it.'));
      }
      $end = $form_state->getValue('end');
      if ($start instanceof DrupalDateTime && $end instanceof DrupalDateTime && $end->getTimestamp() <= $start->getTimestamp()) {
        $form_state->setErrorByName('end', $this->t('The end must be after the start.'));
      }
    }
    $min = (int) $form_state->getValue('min');
    $max = $form_state->getValue('max');
    if ($max !== NULL && $max !== '' && (int) $max < $min) {
      $form_state->setErrorByName('max', $this->t('"Most people" cannot be fewer than the number it needs.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $elevated = $this->currentUser()->hasPermission('makehaven_tasks.create_task');
    $fields = Opportunity::fieldsInstalled();
    $stage = $this->stageFor($elevated, (string) $form_state->getValue('post_as'));

    $values = [
      'type' => 'task',
      'title' => trim((string) $form_state->getValue('title')),
      'body' => [
        'value' => trim((string) $form_state->getValue('details')),
        'format' => 'plain_text',
      ],
      'status' => $stage === Opportunity::STAGE_PROPOSED ? NodeInterface::NOT_PUBLISHED : NodeInterface::PUBLISHED,
      'uid' => $this->currentUser()->id(),
      'field_task_frequency' => 'once',
      'field_task_status' => 'open',
      // 'open_member' is the allowed value; the old 'members' was invalid.
      'field_task_audience' => 'open_member',
    ];
    if ($equipment = $form_state->getValue('equipment')) {
      $values['field_task_equipment'] = ['target_id' => (int) $equipment];
    }
    if ($fields) {
      $kind = (string) ($form_state->getValue('kind') ?: Opportunity::TYPE_TASK);
      $values['field_task_type'] = $kind;
      // Approved posts keep the stage empty, which reads as approved.
      $values['field_task_stage'] = $stage === Opportunity::STAGE_APPROVED ? NULL : $stage;
      $values['field_task_min_volunteers'] = max(1, (int) $form_state->getValue('min'));
      if ((string) $form_state->getValue('max') !== '') {
        $values['field_task_max_volunteers'] = (int) $form_state->getValue('max');
      }
      if ($tid = $form_state->getValue('interest')) {
        $values['field_task_interest'] = [['target_id' => (int) $tid]];
      }
      $start = $form_state->getValue('start');
      if ($kind === Opportunity::TYPE_SHIFT && $start instanceof DrupalDateTime) {
        $end = $form_state->getValue('end');
        $end_ts = $end instanceof DrupalDateTime ? $end->getTimestamp() : $start->getTimestamp() + 3 * 3600;
        $values['field_task_when'] = [
          'value' => Opportunity::timestampToStorage($start->getTimestamp()),
          'end_value' => Opportunity::timestampToStorage($end_ts),
        ];
      }
    }

    // A proposed draft is unpublished, so creating it trips no Slack post; a
    // gathering post fires the #volunteers recruitment call on insert.
    $node = Node::create($values);
    $node->save();
    if ($fields && $form_state->getValue('count_me_in') && $stage !== Opportunity::STAGE_APPROVED) {
      \Drupal::service('makehaven_tasks.volunteer_signups')->add($node, (int) $this->currentUser()->id(), (string) $this->t('Suggested this'));
    }

    if ($stage === Opportunity::STAGE_GATHERING) {
      $this->messenger()->addStatus($this->t('"@title" is on the volunteer board gathering interest until @date. Staff approve it once enough people are in, and everyone who signed up hears back by email.', [
        '@title' => $node->label(),
        '@date' => Opportunity::dateLabel(Opportunity::decideBy($node)),
      ]));
    }
    elseif ($stage === Opportunity::STAGE_PROPOSED) {
      $this->messenger()->addStatus($this->t('Thanks! "@title" is waiting for a facilitator or staff member to put it on the board.', ['@title' => $node->label()]));
    }
    else {
      $this->messenger()->addStatus($this->t('Posted "@title" to the board.', ['@title' => $node->label()]));
    }
    $form_state->setRedirectUrl(Url::fromRoute('entity.node.canonical', ['node' => $node->id()]));
  }

  /**
   * The stage a new post starts in.
   */
  public function stageFor(bool $elevated, string $choice): string {
    if (!Opportunity::fieldsInstalled()) {
      return Opportunity::STAGE_APPROVED;
    }
    if ($elevated) {
      return $choice === Opportunity::STAGE_GATHERING ? Opportunity::STAGE_GATHERING : Opportunity::STAGE_APPROVED;
    }
    $configured = (string) $this->config('makehaven_tasks.settings')->get('member_suggestion_stage');
    return $configured === Opportunity::STAGE_PROPOSED ? Opportunity::STAGE_PROPOSED : Opportunity::STAGE_GATHERING;
  }

  /**
   * Area-of-interest terms, by name.
   */
  private function interestOptions(): array {
    $options = [];
    if (!\Drupal::entityTypeManager()->hasDefinition('taxonomy_term')) {
      return $options;
    }
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $tids = $storage->getQuery()->accessCheck(TRUE)->condition('vid', 'area_of_interest')->condition('status', 1)->sort('name')->execute();
    foreach ($storage->loadMultiple($tids) as $term) {
      $options[$term->id()] = $term->label();
    }
    return $options;
  }

}
