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
 * "I'm interested" (while gathering) or "Sign me up" (an approved shift).
 *
 * A note is optional ("Saturdays only", "I can bring a truck"). Signing up
 * writes one row to the sign-up table; the node itself is not re-saved.
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
    $start = Opportunity::start($node);
    if ($start && $start < \Drupal::time()->getCurrentTime()) {
      return (string) t('This date has passed.');
    }
    $max = Opportunity::maxAllowed($node);
    if ($max !== NULL && !$signups->has($node, $uid) && $signups->count($node) >= $max) {
      return (string) t('It is full: @max people have already signed up.', ['@max' => $max]);
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

    $count = $this->signups->count($node);
    $needed = Opportunity::minNeeded($node);
    $max = Opportunity::maxAllowed($node);
    $when = Opportunity::whenLabel($node);
    $decide = Opportunity::dateLabel(Opportunity::decideBy($node));
    $gathering = Opportunity::isGathering($node);

    $summary = '<p class="vol-form-summary"><strong>' . htmlspecialchars((string) $node->label(), ENT_QUOTES) . '</strong>';
    if ($when !== '') {
      $summary .= '<br>' . htmlspecialchars($when, ENT_QUOTES);
    }
    $summary .= '<br>' . $this->t('@count interested of @needed needed', ['@count' => $count, '@needed' => $needed]);
    if ($max) {
      $summary .= ' ' . $this->t('(room for @max)', ['@max' => $max]);
    }
    if ($gathering && $decide !== '') {
      $summary .= '<br>' . $this->t('Staff decide by @date.', ['@date' => $decide]);
    }
    $summary .= '</p>';
    $form['summary'] = ['#markup' => $summary];

    if (!$already && ($reason = self::closedReason($node, $uid, $this->signups))) {
      $form['closed'] = ['#markup' => '<p class="messages messages--warning">' . htmlspecialchars($reason, ENT_QUOTES) . '</p>'];
      $form['back'] = ['#type' => 'link', '#title' => $this->t('Back'), '#url' => $node->toUrl(), '#attributes' => ['class' => ['button']]];
      return $form;
    }

    $form['intro'] = [
      '#markup' => '<p>' . ($gathering
        ? $this->t('Saying you are interested is not a promise. Staff approve it once enough people are in, and then you hear back by email.')
        : $this->t('Add your name to the roster for this shift.')) . '</p>',
    ];
    $form['note'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Anything to know? (optional)'),
      '#maxlength' => 255,
      '#placeholder' => $this->t('e.g. Saturdays only, or I can bring a truck'),
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
      '#value' => $already ? $this->t('Update my note') : ($gathering ? $this->t("I'm interested") : $this->t('Sign me up')),
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
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node = $this->loadNode($form_state);
    $uid = (int) $this->currentUser()->id();
    $kind = Opportunity::isGathering($node) ? SignupStore::KIND_INTEREST : SignupStore::KIND_CONFIRMED;
    $added = $this->signups->add($node, $uid, (string) $form_state->getValue('note'), $kind);
    if (!$added) {
      $this->messenger()->addStatus($this->t('Note updated.'));
    }
    elseif ($kind === SignupStore::KIND_CONFIRMED) {
      $this->messenger()->addStatus($this->t("You're on the roster. Thank you!"));
    }
    else {
      $count = $this->signups->count($node);
      $needed = Opportunity::minNeeded($node);
      $this->messenger()->addStatus($count >= $needed
        ? $this->t("Thanks! That makes @count of @needed needed, so staff can approve it. You'll get an email either way.", ['@count' => $count, '@needed' => $needed])
        : $this->t("Thanks! @count of @needed so far. You'll get an email when staff decide.", ['@count' => $count, '@needed' => $needed]));
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
