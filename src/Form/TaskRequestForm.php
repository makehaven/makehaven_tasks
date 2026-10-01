<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\makehaven_tasks\Volunteer\Decider;
use Drupal\makehaven_tasks\Volunteer\Opportunity;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

/**
 * The one form for posting (and editing) anything on the volunteer board.
 *
 * /tasks/request creates, /tasks/{node}/edit edits. Before October 2026 there
 * were two forms with different options ("Post an opportunity" and
 * "+ Create task", the node form); staff found that confusing (Kate,
 * 2026-10-01). The node form stays for bank templates and for admins
 * (?advanced=1), but everything else comes here.
 *
 * Kinds:
 * - anytime task (fix, clean, project);
 * - a dated shift here, with one or more time slots;
 * - tabling at an outside event (dated, plus organizer details);
 * - a repeating chore (staff/facilitators: a series source for cron).
 *
 * Who may post what (JR, 2026-09-28; roles only, no per-person flag):
 * - Members' suggestions go on the board to gather interest (or wait as a
 *   draft, per makehaven_tasks.settings:member_suggestion_stage).
 * - Staff and facilitators (makehaven_tasks.create_task) post ready to go, or
 *   gather interest first, and may ask for a newsletter notice.
 */
final class TaskRequestForm extends FormBase {

  public const KIND_REPEATING = 'repeating';

  /**
   * Time slots shown when the form first opens for a new dated post.
   */
  private const DEFAULT_SLOTS = 1;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makehaven_tasks_request';
  }

  /**
   * Access to the edit route: staff/facilitators, or the poster while it is
   * still a draft or gathering interest. Bank templates use the node form.
   */
  public static function editAccess(NodeInterface $node, AccountInterface $account) {
    if ($node->bundle() !== 'task' || self::isTemplate($node)) {
      return AccessResult::forbidden()->addCacheableDependency($node);
    }
    if ($account->hasPermission('makehaven_tasks.create_task')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    $own = (int) $node->getOwnerId() === (int) $account->id()
      && in_array(Opportunity::stage($node), [Opportunity::STAGE_PROPOSED, Opportunity::STAGE_GATHERING], TRUE);
    return AccessResult::allowedIf($own)->cachePerUser()->addCacheableDependency($node);
  }

  /**
   * Route access callback for /tasks/{node}/edit.
   */
  public static function editAccessRoute(NodeInterface $node, AccountInterface $account) {
    return self::editAccess($node, $account);
  }

  /**
   * Whether a task is a bank template (edited on the node form).
   */
  public static function isTemplate(NodeInterface $node): bool {
    return $node->hasField('field_task_bank') && (bool) $node->get('field_task_bank')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $form['#attached']['library'][] = 'makehaven_tasks/task_actions';
    $form['#attributes']['class'][] = 'vol-post-form';
    $account = $this->currentUser();
    $elevated = $account->hasPermission('makehaven_tasks.create_task');
    $fields = Opportunity::fieldsInstalled();
    $editing = $node instanceof NodeInterface && !$node->isNew();
    $form_state->set('nid', $editing ? (int) $node->id() : 0);

    $equipment = NULL;
    if ($editing && !$node->get('field_task_equipment')->isEmpty()) {
      $equipment = $node->get('field_task_equipment')->entity;
    }
    elseif (is_numeric($nid = $this->getRequest()->query->get('equipment') ?? $this->getRequest()->query->get('field_task_equipment'))) {
      $candidate = Node::load((int) $nid);
      if ($candidate instanceof NodeInterface && $candidate->bundle() === 'item') {
        $equipment = $candidate;
      }
    }

    $kind = $editing ? $this->kindOf($node) : (string) ($this->getRequest()->query->get('kind') ?: Opportunity::TYPE_TASK);
    $val = fn(string $field, $default = NULL) => ($editing && $node->hasField($field) && !$node->get($field)->isEmpty()) ? $node->get($field)->value : $default;

    if (!$editing) {
      $form['intro'] = [
        '#markup' => '<p class="vol-help">' . ($elevated
          ? $this->t('Post anything people can help with. For a regular chore already in the <a href=":bank">task bank</a>, start it from there instead. For damage or a safety problem, use the issue report on the tool page.', [':bank' => Url::fromRoute('makehaven_tasks.bank')->toString()])
          : $this->t('Suggest something volunteers could do. It goes on the board so people can say they are in, and staff confirm it once enough people are. For damage or a safety problem, use the issue report on the tool page.')) . '</p>',
      ];
    }
    else {
      $form['intro'] = [
        '#markup' => '<p class="vol-help">' . $this->t('Editing "@title" (@stage).', ['@title' => $node->label(), '@stage' => $this->stageLabel(Opportunity::stage($node))])
          . ($account->hasPermission('administer nodes') ? ' <a href="' . $node->toUrl('edit-form', ['query' => ['advanced' => 1]])->toString() . '">' . $this->t('Advanced editor') . '</a>' : '') . '</p>',
      ];
    }

    // 1. Kind.
    $kinds = [
      Opportunity::TYPE_TASK => $this->t('<strong>Something to do anytime</strong>: a fix, a clean-up, a project'),
    ];
    if ($fields) {
      $kinds[Opportunity::TYPE_SHIFT] = $this->t('<strong>A dated shift here</strong>: an open house, tours, a build day, setup crew');
      $kinds[Opportunity::TYPE_TABLING] = $this->t('<strong>Tabling at an outside event</strong>: representing MakeHaven at a fair, festival or market');
    }
    if ($elevated) {
      $kinds[self::KIND_REPEATING] = $this->t('<strong>A repeating chore</strong>: comes back every week, month or year');
    }
    $form['kind'] = [
      '#type' => 'radios',
      '#title' => $this->t('What kind of help?'),
      '#options' => $kinds,
      '#default_value' => isset($kinds[$kind]) ? $kind : Opportunity::TYPE_TASK,
      '#required' => TRUE,
    ];
    $dated = ['visible' => [
      [':input[name="kind"]' => ['value' => Opportunity::TYPE_SHIFT]],
      'or',
      [':input[name="kind"]' => ['value' => Opportunity::TYPE_TABLING]],
    ]];
    $tabling_only = ['visible' => [':input[name="kind"]' => ['value' => Opportunity::TYPE_TABLING]]];
    $repeating_only = ['visible' => [':input[name="kind"]' => ['value' => self::KIND_REPEATING]]];

    // 2. What.
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('What needs doing?'),
      '#maxlength' => 255,
      '#required' => TRUE,
      '#default_value' => $editing ? $node->label() : '',
      '#placeholder' => $this->t('e.g. Tour guide for the open house, or Clean the table-saw dust collector'),
    ];
    $form['details'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Details'),
      '#required' => TRUE,
      '#rows' => 5,
      '#default_value' => $editing ? trim(strip_tags((string) $node->get('body')->value)) : '',
      '#description' => $this->t('What the work is and anything a volunteer should know.'),
    ];

    // 3. When.
    if ($fields) {
      $existing = $editing ? array_values(Opportunity::slots($node)) : [];
      $count = $form_state->get('slot_count') ?? max(self::DEFAULT_SLOTS, count($existing));
      $form_state->set('slot_count', $count);
      $form['when'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('When'),
        '#description' => $this->t('Add a time slot for each shift (setup, morning, afternoon). People can sign up for one slot or several.'),
        '#states' => $dated,
        '#attributes' => ['id' => 'vol-slots'],
        '#tree' => TRUE,
      ];
      $tz = Opportunity::timezone();
      for ($i = 0; $i < $count; $i++) {
        $slot = $existing[$i] ?? NULL;
        $s = $slot ? (new \DateTime('@' . $slot['start']))->setTimezone($tz) : NULL;
        $e = $slot ? (new \DateTime('@' . $slot['end']))->setTimezone($tz) : NULL;
        $form['when'][$i] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['vol-slot-row']],
          'date' => ['#type' => 'date', '#title' => $this->t('Date'), '#default_value' => $s ? $s->format('Y-m-d') : ($i > 0 ? NULL : NULL)],
          'start' => ['#type' => 'textfield', '#title' => $this->t('From'), '#size' => 8, '#attributes' => ['type' => 'time'], '#default_value' => $s ? $s->format('H:i') : ''],
          'end' => ['#type' => 'textfield', '#title' => $this->t('To'), '#size' => 8, '#attributes' => ['type' => 'time'], '#default_value' => $e ? $e->format('H:i') : ''],
        ];
      }
      $form['when']['add'] = [
        '#type' => 'submit',
        '#value' => $this->t('+ Add another time slot'),
        '#name' => 'add_slot',
        '#submit' => ['::addSlot'],
        '#limit_validation_errors' => [],
        '#ajax' => ['callback' => '::slotsCallback', 'wrapper' => 'vol-slots'],
        '#attributes' => ['class' => ['button--small']],
      ];
    }
    if ($elevated) {
      $form['repeat'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('How often'),
        '#states' => $repeating_only,
        'frequency' => [
          '#type' => 'select',
          '#title' => $this->t('Repeats'),
          '#options' => ['weekly' => $this->t('Every week'), 'monthly' => $this->t('Every month'), 'quarterly' => $this->t('Every 3 months'), 'yearly' => $this->t('Every year'), 'biennial' => $this->t('Every 2 years')],
          '#default_value' => in_array($val('field_task_frequency'), ['weekly', 'monthly', 'quarterly', 'yearly', 'biennial'], TRUE) ? $val('field_task_frequency') : 'monthly',
        ],
        'first_due' => [
          '#type' => 'date',
          '#title' => $this->t('First due'),
          '#default_value' => $val('field_task_next_due') ? substr((string) $val('field_task_next_due'), 0, 10) : date('Y-m-d'),
        ],
      ];
    }

    // 4. People.
    if ($fields) {
      $form['people'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['vol-people-row']],
        'min' => [
          '#type' => 'number',
          '#title' => $this->t('People needed'),
          '#description' => $this->t('Per time slot, for a dated shift.'),
          '#min' => 1,
          '#max' => 50,
          '#default_value' => (int) $val('field_task_min_volunteers', 1),
        ],
        'max' => [
          '#type' => 'number',
          '#title' => $this->t('Most people (optional)'),
          '#description' => $this->t('Sign-up closes at this many.'),
          '#min' => 1,
          '#max' => 200,
          '#default_value' => $val('field_task_max_volunteers'),
        ],
      ];

      // Tabling details.
      $form['tabling'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('About the event'),
        '#states' => $tabling_only,
        'event_url' => [
          '#type' => 'url',
          '#title' => $this->t('Event link or RSVP form'),
          '#default_value' => $editing && $node->hasField('field_task_event_url') && !$node->get('field_task_event_url')->isEmpty() ? $node->get('field_task_event_url')->uri : '',
        ],
        'rsvp_by' => [
          '#type' => 'date',
          '#title' => $this->t('Organizer needs our RSVP by (optional)'),
          '#description' => $this->t('Sign-ups close the day before.'),
          '#default_value' => $val('field_task_rsvp_by'),
        ],
        'bring' => [
          '#type' => 'textarea',
          '#rows' => 2,
          '#title' => $this->t('What to bring, activity, code to mention'),
          '#placeholder' => $this->t('e.g. Bring table, chairs and tent. Button making. Mention code FLIGHTS26.'),
          '#default_value' => $val('field_task_bring'),
        ],
        'selling_ok' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Volunteers may sell their own work at our table'),
          '#default_value' => (bool) $val('field_task_selling_ok', FALSE),
        ],
      ];
      if ($elevated) {
        $form['tabling']['organizer'] = [
          '#type' => 'textarea',
          '#rows' => 2,
          '#title' => $this->t('Organizer and contact (staff and facilitators only)'),
          '#default_value' => $val('field_task_organizer'),
        ];
        $form['tabling']['org_status'] = [
          '#type' => 'select',
          '#title' => $this->t('Where we stand with the organizer'),
          '#options' => $this->allowedValues('field_task_org_status'),
          '#default_value' => $val('field_task_org_status', 'need_rsvp'),
        ];
        $form['tabling']['fee'] = [
          '#type' => 'number',
          '#title' => $this->t('Fee ($)'),
          '#min' => 0,
          '#step' => '0.01',
          '#default_value' => $val('field_task_fee'),
        ];
      }
    }

    // 5. Who and where.
    $form['who'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['vol-who-row']],
    ];
    if ($elevated) {
      $form['who']['audience'] = [
        '#type' => 'select',
        '#title' => $this->t('Who can do it'),
        '#options' => [
          'open_member' => $this->t('Anyone'),
          'badge_holders' => $this->t('People with a badge'),
          'staff_only' => $this->t('Staff only'),
        ],
        '#default_value' => $val('field_task_audience', 'open_member'),
      ];
      $form['who']['required_badge'] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('Badge needed'),
        '#target_type' => 'taxonomy_term',
        '#selection_settings' => ['target_bundles' => ['badges' => 'badges']],
        '#default_value' => $editing && !$node->get('field_task_required_badge')->isEmpty() ? $node->get('field_task_required_badge')->entity : NULL,
        '#states' => ['visible' => [':input[name="audience"]' => ['value' => 'badge_holders']]],
      ];
    }
    if ($fields) {
      $form['who']['interest'] = [
        '#type' => 'select',
        '#title' => $this->t('Interest area (optional)'),
        '#options' => $this->interestOptions(),
        '#empty_option' => $this->t('- General -'),
        '#default_value' => $editing && !$node->get('field_task_interest')->isEmpty() ? $node->get('field_task_interest')->target_id : NULL,
      ];
    }
    $form['who']['equipment'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Tool (optional)'),
      '#target_type' => 'node',
      '#selection_handler' => 'default:node',
      '#selection_settings' => [
        'target_bundles' => ['item'],
        'sort' => ['field' => 'title', 'direction' => 'ASC'],
      ],
      '#default_value' => $equipment,
    ];

    // 6. More options (staff).
    if ($elevated) {
      $form['more'] = [
        '#type' => 'details',
        '#title' => $this->t('More options'),
        '#open' => FALSE,
        'priority' => [
          '#type' => 'select',
          '#title' => $this->t('Priority'),
          '#options' => $this->allowedValues('field_task_priority'),
          '#empty_option' => $this->t('- None -'),
          '#default_value' => $val('field_task_priority'),
        ],
        'estimated_hours' => [
          '#type' => 'number',
          '#title' => $this->t('About how many hours?'),
          '#min' => 0,
          '#step' => '0.25',
          '#default_value' => $val('field_task_estimated_hours'),
        ],
        'instructions' => [
          '#type' => 'textarea',
          '#title' => $this->t('Step-by-step instructions'),
          '#rows' => 4,
          '#default_value' => $editing && $node->hasField('field_task_instructions') ? trim(strip_tags((string) $node->get('field_task_instructions')->value)) : '',
        ],
        'video_url' => [
          '#type' => 'url',
          '#title' => $this->t('How-to video link'),
          '#default_value' => $editing && $node->hasField('field_task_video_url') && !$node->get('field_task_video_url')->isEmpty() ? $node->get('field_task_video_url')->uri : '',
        ],
      ];
    }

    // 7. Posting.
    if ($fields && !$editing) {
      if ($elevated) {
        $form['post_as'] = [
          '#type' => 'radios',
          '#title' => $this->t('Post it'),
          '#options' => [
            Opportunity::STAGE_GATHERING => $this->t('<strong>Gather interest first</strong>: goes ahead once enough people are in and staff approve'),
            Opportunity::STAGE_APPROVED => $this->t('<strong>Ready now</strong>: it is happening; people sign up or claim it straight away'),
            Opportunity::STAGE_PROPOSED => $this->t('<strong>Save as a draft</strong>: staff and facilitators only, until someone puts it out'),
          ],
          '#default_value' => Opportunity::STAGE_GATHERING,
          '#states' => ['invisible' => [':input[name="kind"]' => ['value' => self::KIND_REPEATING]]],
        ];
        if (\Drupal::hasService('makerspace_digest_scheduler.notice_writer')) {
          $form['announce'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Also announce it in member news'),
            '#description' => $this->t('Creates a notice when it goes public: Slack #members right away, then the next weekly digest.'),
            '#states' => ['invisible' => [':input[name="kind"]' => ['value' => self::KIND_REPEATING]]],
          ];
        }
      }
      else {
        $form['count_me_in'] = [
          '#type' => 'checkbox',
          '#title' => $this->t("I'm in: count me as the first volunteer"),
          '#default_value' => 1,
        ];
      }
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $editing ? $this->t('Save') : ($elevated ? $this->t('Post it') : $this->t('Suggest it')),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $editing ? $node->toUrl() : Url::fromRoute('view.tasks.page_tasks_interactive'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  /**
   * Submit handler for "+ Add another time slot".
   */
  public function addSlot(array &$form, FormStateInterface $form_state): void {
    $form_state->set('slot_count', ($form_state->get('slot_count') ?? 1) + 1);
    $form_state->setRebuild();
  }

  /**
   * AJAX callback: the time slots fieldset.
   */
  public function slotsCallback(array &$form, FormStateInterface $form_state): array {
    return $form['when'];
  }

  /**
   * The slots entered, as [start, end] timestamps, earliest first.
   *
   * @return array<int, array{start:int, end:int, row:int}>
   *   The slots; rows left blank are skipped.
   */
  private function slotsFromInput(FormStateInterface $form_state): array {
    $out = [];
    $tz = Opportunity::timezone();
    foreach ((array) $form_state->getValue('when') as $i => $row) {
      if (!is_array($row) || empty($row['date'])) {
        continue;
      }
      $start_time = trim((string) ($row['start'] ?? '')) ?: '09:00';
      $end_time = trim((string) ($row['end'] ?? ''));
      try {
        $start = (new \DateTime($row['date'] . ' ' . $start_time, $tz))->getTimestamp();
        $end = $end_time !== '' ? (new \DateTime($row['date'] . ' ' . $end_time, $tz))->getTimestamp() : $start + 3 * 3600;
      }
      catch (\Exception $e) {
        continue;
      }
      $out[] = ['start' => $start, 'end' => $end, 'row' => (int) $i];
    }
    usort($out, fn($a, $b) => $a['start'] <=> $b['start']);
    return $out;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (($form_state->getTriggeringElement()['#name'] ?? '') === 'add_slot') {
      return;
    }
    $kind = (string) $form_state->getValue('kind');
    $node = $this->loadNode($form_state);
    if (in_array($kind, [Opportunity::TYPE_SHIFT, Opportunity::TYPE_TABLING], TRUE)) {
      $slots = $this->slotsFromInput($form_state);
      if (!$slots) {
        $form_state->setErrorByName('when][0][date', $this->t('Give at least one date and time.'));
      }
      foreach ($slots as $slot) {
        if ($slot['end'] <= $slot['start']) {
          $form_state->setErrorByName('when][' . $slot['row'] . '][end', $this->t('Each time slot must end after it starts.'));
        }
      }
      // A new post that gathers interest needs time for people to sign up.
      $gathering = !$node && ($form_state->getValue('post_as') ?? Opportunity::STAGE_GATHERING) === Opportunity::STAGE_GATHERING;
      if ($slots && $gathering && $slots[0]['start'] < \Drupal::time()->getCurrentTime() + Opportunity::MIN_LEAD) {
        $form_state->setErrorByName('when][' . $slots[0]['row'] . '][date', $this->t('To gather interest, the first slot must be at least two days away so people have time to sign up. Choose "Ready now" if the crew is already known.'));
      }
    }
    $min = (int) $form_state->getValue('min');
    $max = $form_state->getValue('max');
    if ($max !== NULL && $max !== '' && (int) $max < $min) {
      $form_state->setErrorByName('max', $this->t('"Most people" cannot be fewer than the people needed.'));
    }
    if ($form_state->getValue('audience') === 'badge_holders' && !$form_state->getValue('required_badge')) {
      $form_state->setErrorByName('required_badge', $this->t('Choose the badge it needs.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $account = $this->currentUser();
    $elevated = $account->hasPermission('makehaven_tasks.create_task');
    $fields = Opportunity::fieldsInstalled();
    $node = $this->loadNode($form_state);
    $editing = (bool) $node;
    $kind = (string) ($form_state->getValue('kind') ?: Opportunity::TYPE_TASK);
    $dated = in_array($kind, [Opportunity::TYPE_SHIFT, Opportunity::TYPE_TABLING], TRUE);

    if (!$node) {
      $stage = $this->stageFor($elevated, (string) $form_state->getValue('post_as'), $kind);
      $node = Node::create([
        'type' => 'task',
        'uid' => $account->id(),
        'field_task_status' => 'open',
        'field_task_audience' => 'open_member',
      ]);
      if ($fields) {
        // Approved undated tasks keep the stage empty, which reads as approved
        // and keeps the long-standing claim flow; dated ones say so, which
        // lets the board post their recruitment call.
        $node->set('field_task_stage', $stage === Opportunity::STAGE_APPROVED && !$dated ? NULL : $stage);
      }
      $node->setPublished($stage !== Opportunity::STAGE_PROPOSED);
    }

    $node->setTitle(trim((string) $form_state->getValue('title')));
    $node->set('body', ['value' => trim((string) $form_state->getValue('details')), 'format' => 'plain_text']);
    $node->set('field_task_equipment', ($equipment = $form_state->getValue('equipment')) ? ['target_id' => (int) $equipment] : NULL);

    if ($kind === self::KIND_REPEATING && $elevated) {
      $node->set('field_task_frequency', (string) $form_state->getValue('frequency'));
      $first = (string) $form_state->getValue('first_due');
      $node->set('field_task_next_due', $first !== '' ? $first . 'T12:00:00' : NULL);
    }
    elseif (!$editing || $node->get('field_task_frequency')->isEmpty() || in_array($node->get('field_task_frequency')->value, ['weekly', 'monthly', 'quarterly', 'yearly', 'biennial'], TRUE)) {
      $node->set('field_task_frequency', 'once');
    }

    if ($fields) {
      $node->set('field_task_type', $dated ? $kind : Opportunity::TYPE_TASK);
      $node->set('field_task_min_volunteers', max(1, (int) $form_state->getValue('min')));
      $max = (string) $form_state->getValue('max');
      $node->set('field_task_max_volunteers', $max !== '' ? (int) $max : NULL);
      $node->set('field_task_interest', ($tid = $form_state->getValue('interest')) ? [['target_id' => (int) $tid]] : []);
      if ($dated) {
        $node->set('field_task_when', array_map(fn($s) => [
          'value' => Opportunity::timestampToStorage($s['start']),
          'end_value' => Opportunity::timestampToStorage($s['end']),
        ], $this->slotsFromInput($form_state)));
      }
      else {
        $node->set('field_task_when', []);
      }
      if ($node->hasField('field_task_event_url')) {
        $tabling = $kind === Opportunity::TYPE_TABLING;
        $url = $tabling ? trim((string) $form_state->getValue('event_url')) : '';
        $node->set('field_task_event_url', $url !== '' ? ['uri' => $url] : NULL);
        $node->set('field_task_rsvp_by', $tabling && $form_state->getValue('rsvp_by') ? (string) $form_state->getValue('rsvp_by') : NULL);
        $node->set('field_task_bring', $tabling ? (trim((string) $form_state->getValue('bring')) ?: NULL) : NULL);
        $node->set('field_task_selling_ok', $tabling ? (int) (bool) $form_state->getValue('selling_ok') : NULL);
        if ($elevated) {
          $node->set('field_task_organizer', $tabling ? (trim((string) $form_state->getValue('organizer')) ?: NULL) : NULL);
          $node->set('field_task_org_status', $tabling ? ($form_state->getValue('org_status') ?: NULL) : NULL);
          $fee = (string) $form_state->getValue('fee');
          $node->set('field_task_fee', $tabling && $fee !== '' ? $fee : NULL);
        }
      }
      // Tabling: sign-ups close the day before the organizer's deadline when
      // that is sooner than the usual window.
      if (!$editing && $kind === Opportunity::TYPE_TABLING && Opportunity::isGathering($node) && ($rsvp = $form_state->getValue('rsvp_by'))) {
        $deadline = (new \DateTime($rsvp . ' 23:59', Opportunity::timezone()))->getTimestamp() - 86400;
        $now = \Drupal::time()->getCurrentTime();
        $days = (int) ($this->config('makehaven_tasks.settings')->get('gathering_days') ?: 8);
        $default = Opportunity::defaultDecideBy($now, $this->slotsFromInput($form_state)[0]['start'] ?? NULL, $days);
        if ($deadline < $default) {
          $node->set('field_task_decide_by', Opportunity::timestampToStorage(max($deadline, $now + Opportunity::MIN_WINDOW)));
        }
      }
    }

    if ($elevated) {
      $node->set('field_task_audience', (string) ($form_state->getValue('audience') ?: 'open_member'));
      $node->set('field_task_required_badge', $form_state->getValue('audience') === 'badge_holders' && $form_state->getValue('required_badge') ? ['target_id' => (int) $form_state->getValue('required_badge')] : NULL);
      $node->set('field_task_priority', $form_state->getValue('priority') ?: NULL);
      $hours = (string) $form_state->getValue('estimated_hours');
      $node->set('field_task_estimated_hours', $hours !== '' ? $hours : NULL);
      if ($node->hasField('field_task_instructions')) {
        $text = trim((string) $form_state->getValue('instructions'));
        $node->set('field_task_instructions', $text !== '' ? ['value' => $text, 'format' => 'plain_text'] : NULL);
      }
      if ($node->hasField('field_task_video_url')) {
        $video = trim((string) $form_state->getValue('video_url'));
        $node->set('field_task_video_url', $video !== '' ? ['uri' => $video] : NULL);
      }
    }

    // Ask for the newsletter notice before saving: the save that makes it
    // public is the one that raises it.
    if (!$editing && $form_state->getValue('announce')) {
      $node->save();
      \Drupal::keyValue(Decider::ANNOUNCE)->set((string) $node->id(), TRUE);
      // Re-run the announce step now that the flag is set.
      if ($node->isPublished()) {
        \Drupal::service('makehaven_tasks.volunteer_decider')->announce($node);
      }
    }
    else {
      $node->save();
    }

    if (!$editing && $fields && !$elevated && $form_state->getValue('count_me_in') && !Opportunity::isApproved($node)) {
      $slots = array_keys(Opportunity::slots($node)) ?: [0];
      \Drupal::service('makehaven_tasks.volunteer_signups')->setSlots($node, (int) $account->id(), $slots, (string) $this->t('Suggested this'), 'interest');
    }

    $stage = $fields ? Opportunity::stage($node) : Opportunity::STAGE_APPROVED;
    if ($editing) {
      $this->messenger()->addStatus($this->t('Saved "@title".', ['@title' => $node->label()]));
    }
    elseif ($stage === Opportunity::STAGE_GATHERING) {
      $this->messenger()->addStatus($this->t('"@title" is on the volunteer board until @date. Staff confirm it once enough people are in, and everyone who signs up hears back by email.', [
        '@title' => $node->label(),
        '@date' => Opportunity::dateLabel(Opportunity::decideBy($node)),
      ]));
    }
    elseif ($stage === Opportunity::STAGE_PROPOSED) {
      $this->messenger()->addStatus($this->t('"@title" is saved as a draft. Put it out from Volunteer approvals when it is ready.', ['@title' => $node->label()]));
    }
    else {
      $this->messenger()->addStatus($this->t('Posted "@title" to the board.', ['@title' => $node->label()]));
    }
    $form_state->setRedirectUrl($node->toUrl());
  }

  /**
   * The stage a new post starts in.
   */
  public function stageFor(bool $elevated, string $choice, string $kind = Opportunity::TYPE_TASK): string {
    if (!Opportunity::fieldsInstalled() || $kind === self::KIND_REPEATING) {
      return Opportunity::STAGE_APPROVED;
    }
    if ($elevated) {
      return in_array($choice, [Opportunity::STAGE_GATHERING, Opportunity::STAGE_PROPOSED], TRUE) ? $choice : Opportunity::STAGE_APPROVED;
    }
    $configured = (string) $this->config('makehaven_tasks.settings')->get('member_suggestion_stage');
    return $configured === Opportunity::STAGE_PROPOSED ? Opportunity::STAGE_PROPOSED : Opportunity::STAGE_GATHERING;
  }

  /**
   * The kind of an existing task, as the form's radios name it.
   */
  private function kindOf(NodeInterface $node): string {
    if (Opportunity::isShift($node)) {
      return Opportunity::type($node);
    }
    $frequency = $node->hasField('field_task_frequency') ? (string) $node->get('field_task_frequency')->value : '';
    return in_array($frequency, ['weekly', 'monthly', 'quarterly', 'yearly', 'biennial'], TRUE) ? self::KIND_REPEATING : Opportunity::TYPE_TASK;
  }

  /**
   * A stage, in words.
   */
  private function stageLabel(string $stage): string {
    return match ($stage) {
      Opportunity::STAGE_PROPOSED => (string) $this->t('draft'),
      Opportunity::STAGE_GATHERING => (string) $this->t('gathering interest'),
      Opportunity::STAGE_DECLINED => (string) $this->t('declined'),
      Opportunity::STAGE_CANCELLED => (string) $this->t('cancelled'),
      default => (string) $this->t('on the board'),
    };
  }

  /**
   * A list field's allowed values.
   */
  private function allowedValues(string $field): array {
    $definitions = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions('node');
    if (!isset($definitions[$field])) {
      return [];
    }
    $out = [];
    foreach ((array) $definitions[$field]->getSetting('allowed_values') as $key => $value) {
      if (is_array($value)) {
        $out[(string) $value['value']] = $value['label'];
      }
      else {
        $out[(string) $key] = $value;
      }
    }
    return $out;
  }

  /**
   * Loads the task being edited, if any.
   */
  private function loadNode(FormStateInterface $form_state): ?NodeInterface {
    $nid = (int) $form_state->get('nid');
    $node = $nid ? Node::load($nid) : NULL;
    return $node instanceof NodeInterface ? $node : NULL;
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
