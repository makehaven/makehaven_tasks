<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\makehaven_tasks\Volunteer\Opportunity;
use Drupal\makehaven_tasks\Volunteer\SignupStore;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * "I'm in": interest while gathering, or the roster of an approved shift.
 *
 * With several time slots, people tick the ones they can do (doing more than
 * one is encouraged). A note is optional ("Saturdays only", "I can bring a
 * truck"). Signing up writes rows to the sign-up table; the node itself is
 * not re-saved.
 */
final class VolunteerInterestForm extends FormBase {

  public function __construct(private readonly SignupStore $signups) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('makehaven_tasks.volunteer_signups'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makehaven_tasks_volunteer_interest';
  }

  /**
   * Whether the user may sign up for this task at all, else why not.
   */
  public static function closedReason(NodeInterface $node, int $uid, SignupStore $signups): ?string {
    if ($node->bundle() !== 'task' || !$node->isPublished()) {
      return (string) t('This opportunity is not open.');
    }
    $stage = Opportunity::stage($node);
    $open = $stage === Opportunity::STAGE_GATHERING
      || ($stage === Opportunity::STAGE_APPROVED && Opportunity::isShift($node));
    if (!$open) {
      return $stage === Opportunity::STAGE_APPROVED
        ? (string) t('This task is already approved; claim it or offer to help on the task page.')
        : (string) t('This opportunity is not taking volunteers.');
    }
    $account = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
    if ($account && ($denied = Opportunity::audienceDenied($node, $account))) {
      return $denied;
    }
    $end = Opportunity::end($node);
    if ($end && $end < \Drupal::time()->getCurrentTime()) {
      return (string) t('This date has passed.');
    }
    if (!$signups->has($node, $uid) && $signups->full($node)) {
      return (string) t('It is full. Thank you for offering!');
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $form['#attached']['library'][] = 'makehaven_tasks/task_actions';
    $uid = (int) $this->currentUser()->id();
    $form_state->set('nid', (int) $node->id());
    $already = $this->signups->has($node, $uid);
    $gathering = Opportunity::isGathering($node);
    $slots = Opportunity::slots($node);

    $summary = '<p class="vol-form-summary"><strong>' . htmlspecialchars((string) $node->label(), ENT_QUOTES) . '</strong>';
    if (count($slots) === 1 && ($when = Opportunity::whenLabel($node)) !== '') {
      $summary .= '<br>' . htmlspecialchars($when, ENT_QUOTES);
    }
    if ($gathering && ($decide = Opportunity::dateLabel(Opportunity::decideBy($node))) !== '') {
      $summary .= '<br>' . $this->t('Sign up by @date; staff confirm once enough people are in.', ['@date' => $decide]);
    }
    $summary .= '</p>';
    $form['summary'] = ['#markup' => $summary];

    if (!$already && ($reason = self::closedReason($node, $uid, $this->signups))) {
      $form['closed'] = ['#markup' => '<p class="messages messages--warning">' . htmlspecialchars($reason, ENT_QUOTES) . '</p>'];
      $form['back'] = ['#type' => 'link', '#title' => $this->t('Back'), '#url' => $node->toUrl(), '#attributes' => ['class' => ['button']]];
      return $form;
    }

    if (count($slots) > 1) {
      $counts = $this->signups->slotCounts($node);
      $mine = $this->signups->userSlots($node, $uid);
      $needed = Opportunity::minNeeded($node);
      $max = Opportunity::maxAllowed($node);
      $now = \Drupal::time()->getCurrentTime();
      $options = [];
      $disabled = [];
      foreach ($slots as $start => $slot) {
        $n = $counts[$start] ?? 0;
        $state = $max !== NULL && $n >= $max ? $this->t('full') : ($n >= $needed ? $this->t('@n in', ['@n' => $n]) : $this->t('@n of @needed', ['@n' => $n, '@needed' => $needed]));
        $options[$start] = Opportunity::slotLabel($slot['start'], $slot['end'], TRUE) . ' <span class="vol-slot-state">(' . $state . ')</span>';
        if (!in_array($start, $mine, TRUE) && (($max !== NULL && $n >= $max) || $slot['end'] < $now)) {
          $disabled[] = $start;
        }
      }
      $form['slots'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Which times can you do?'),
        '#description' => $this->t('Pick as many as you like. Doing more than one is a big help.'),
        '#options' => $options,
        '#default_value' => $mine,
      ];
      foreach ($disabled as $start) {
        $form['slots'][$start]['#disabled'] = TRUE;
      }
    }
    else {
      $form['intro'] = [
        '#markup' => '<p>' . ($gathering
          ? $this->t("Saying you're in is not a promise yet. Staff confirm it once enough people are in, and then you hear back by email.")
          : $this->t('Add your name to the roster.')) . '</p>',
      ];
    }
    $form['note'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Anything to know? (optional)'),
      '#maxlength' => 255,
      '#placeholder' => $this->t('e.g. I can bring a truck, or I will show my lamp prototypes'),
    ];
    if ($already) {
      foreach ($this->signups->list($node) as $row) {
        if ($row['uid'] === $uid) {
          $form['note']['#default_value'] = $row['note'];
        }
      }
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $already ? $this->t('Save') : $this->t("I'm in"),
      '#button_type' => 'primary',
      '#name' => 'join',
    ];
    if ($already) {
      $form['actions']['withdraw'] = [
        '#type' => 'submit',
        '#value' => $this->t('Take my name off'),
        '#name' => 'withdraw',
        '#submit' => ['::withdraw'],
        '#limit_validation_errors' => [],
      ];
    }
    $form['actions']['cancel'] = ['#type' => 'link', '#title' => $this->t('Back'), '#url' => $node->toUrl(), '#attributes' => ['class' => ['button']]];
    return $form;
  }

  /**
   * The slots ticked (or the single slot / 0 for an undated task).
   *
   * @return int[]
   *   Slot starts.
   */
  private function chosenSlots(NodeInterface $node, FormStateInterface $form_state): array {
    $slots = Opportunity::slots($node);
    if (count($slots) > 1) {
      return array_map('intval', array_keys(array_filter((array) $form_state->getValue('slots'))));
    }
    return $slots ? [(int) array_key_first($slots)] : [0];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $node = $this->loadNode($form_state);
    $uid = (int) $this->currentUser()->id();
    if (!$node) {
      $form_state->setErrorByName('note', $this->t('That opportunity no longer exists.'));
      return;
    }
    if (!$this->signups->has($node, $uid) && ($reason = self::closedReason($node, $uid, $this->signups))) {
      $form_state->setErrorByName('note', $reason);
      return;
    }
    $chosen = $this->chosenSlots($node, $form_state);
    if (!$chosen) {
      $form_state->setErrorByName('slots', $this->t('Tick at least one time, or use "Take my name off".'));
      return;
    }
    $mine = $this->signups->userSlots($node, $uid);
    foreach ($chosen as $slot) {
      if ($slot && !in_array($slot, $mine, TRUE) && $this->signups->slotFull($node, $slot)) {
        $form_state->setErrorByName('slots', $this->t('One of those times just filled up. Please pick another.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node = $this->loadNode($form_state);
    $uid = (int) $this->currentUser()->id();
    $was_in = $this->signups->has($node, $uid);
    $kind = Opportunity::isGathering($node) ? SignupStore::KIND_INTEREST : SignupStore::KIND_CONFIRMED;
    $this->signups->setSlots($node, $uid, $this->chosenSlots($node, $form_state), (string) $form_state->getValue('note'), $kind);
    if ($was_in) {
      $this->messenger()->addStatus($this->t('Saved.'));
    }
    elseif ($kind === SignupStore::KIND_CONFIRMED) {
      $this->messenger()->addStatus($this->t("You're on the roster. Thank you! You'll get a reminder two days before."));
    }
    else {
      $short = $this->signups->stillNeeded($node);
      $this->messenger()->addStatus($short === 0
        ? $this->t("Thanks! That's enough people, so staff can confirm it. You'll get an email either way.")
        : $this->t("Thanks! @short more needed. You'll get an email when staff decide.", ['@short' => $short]));
    }
    $form_state->setRedirectUrl($node->toUrl());
  }

  /**
   * Submit handler: take the current user off.
   */
  public function withdraw(array &$form, FormStateInterface $form_state): void {
    $node = $this->loadNode($form_state);
    if ($node) {
      $this->signups->remove($node, (int) $this->currentUser()->id());
      $this->messenger()->addStatus($this->t('Your name is off the list.'));
      $form_state->setRedirectUrl($node->toUrl());
    }
  }

  /**
   * Loads the task this form acts on.
   */
  private function loadNode(FormStateInterface $form_state): ?NodeInterface {
    $node = \Drupal::entityTypeManager()->getStorage('node')->load((int) $form_state->get('nid'));
    return $node instanceof NodeInterface ? $node : NULL;
  }

}
