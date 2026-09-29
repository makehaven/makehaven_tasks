<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\makehaven_tasks\Volunteer\Decider;
use Drupal\makehaven_tasks\Volunteer\Opportunity;
use Drupal\makehaven_tasks\Volunteer\SignupStore;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One-click approve / decline for an opportunity (and release for a draft).
 *
 * Rendered on its own at /tasks/{node}/decide and once per row on the
 * approvals page, so the form id carries the nid.
 *
 * - Approve / Decline: makehaven_tasks.manage_tasks (staff).
 * - Put it out (proposed -> gathering) and Decline a draft:
 *   makehaven_tasks.create_task (facilitators and staff).
 */
final class VolunteerDecisionForm extends FormBase {

  private ?int $nid = NULL;

  public function __construct(
    private readonly Decider $decider,
    private readonly SignupStore $signups,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('makehaven_tasks.volunteer_decider'),
      $container->get('makehaven_tasks.volunteer_signups'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makehaven_tasks_volunteer_decision' . ($this->nid ? '_' . $this->nid : '');
  }

  /**
   * Sets the node before the form id is read (approvals page rows).
   */
  public function setNid(int $nid): static {
    $this->nid = $nid;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL, bool $compact = FALSE): array {
    $form['#attached']['library'][] = 'makehaven_tasks/task_actions';
    $form['#attributes']['class'][] = 'vol-decide-form';
    $form_state->set('nid', (int) $node->id());
    $stage = Opportunity::stage($node);
    $account = $this->currentUser();
    $can_decide = $account->hasPermission('makehaven_tasks.manage_tasks');
    $can_release = $account->hasPermission('makehaven_tasks.create_task');

    if (!$compact) {
      $form['summary'] = ['#markup' => self::summaryHtml($node, $this->signups)];
    }

    if (!in_array($stage, [Opportunity::STAGE_PROPOSED, Opportunity::STAGE_GATHERING], TRUE)) {
      $form['done'] = ['#markup' => '<p>' . $this->t('This opportunity is already @stage.', ['@stage' => $stage]) . '</p>'];
      return $form;
    }

    $form['note'] = [
      '#type' => 'textfield',
      '#title' => $compact ? $this->t('Note (optional)') : $this->t('Note to the volunteers (optional)'),
      '#title_display' => $compact ? 'invisible' : 'before',
      '#maxlength' => 1000,
      '#placeholder' => $this->t('Sent only to the people who volunteered'),
      '#size' => $compact ? 30 : 60,
    ];
    // Not 'actions' (key or type): Gin lifts any form's $form['actions']
    // into its sticky page header, which on the approvals page
    // (one form per row) would put a stray Approve for row 1 at the top.
    $form['buttons'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['vol-decide-buttons']],
    ];
    if ($stage === Opportunity::STAGE_GATHERING && $can_decide) {
      $form['buttons']['approve'] = [
        '#type' => 'submit',
        '#value' => $this->t('Approve'),
        '#name' => 'approve_' . $node->id(),
        '#button_type' => 'primary',
        '#submit' => ['::approve'],
      ];
    }
    if ($stage === Opportunity::STAGE_PROPOSED && $can_release) {
      $form['buttons']['release'] = [
        '#type' => 'submit',
        '#value' => $this->t('Put it out to gather interest'),
        '#name' => 'release_' . $node->id(),
        '#button_type' => 'primary',
        '#submit' => ['::release'],
      ];
    }
    if ($can_decide || ($stage === Opportunity::STAGE_PROPOSED && $can_release)) {
      $form['buttons']['decline'] = [
        '#type' => 'submit',
        '#value' => $this->t('Decline'),
        '#name' => 'decline_' . $node->id(),
        '#submit' => ['::decline'],
      ];
    }
    if (!$compact) {
      $form['buttons']['back'] = ['#type' => 'link', '#title' => $this->t('Back'), '#url' => $node->toUrl(), '#attributes' => ['class' => ['button']]];
    }
    return $form;
  }

  /**
   * "3 interested of 2 needed" plus names and notes.
   */
  public static function summaryHtml(NodeInterface $node, SignupStore $signups): string {
    $count = $signups->count($node);
    $needed = Opportunity::minNeeded($node);
    $max = Opportunity::maxAllowed($node);
    $cls = $count >= $needed ? 'vol-count vol-count--ok' : 'vol-count vol-count--short';
    $html = '<div class="vol-summary"><span class="' . $cls . '">'
      . t('@count interested of @needed needed', ['@count' => $count, '@needed' => $needed])
      . ($max ? ' ' . t('(max @max)', ['@max' => $max]) : '') . '</span>';
    $bits = [];
    if (($when = Opportunity::whenLabel($node)) !== '') {
      $bits[] = htmlspecialchars($when, ENT_QUOTES);
    }
    if ($decide = Opportunity::decideBy($node)) {
      $bits[] = t('decide by @d', ['@d' => Opportunity::dateLabel($decide)]);
    }
    if ($bits) {
      $html .= ' <span class="vol-meta">' . implode(' · ', $bits) . '</span>';
    }
    $rows = $signups->list($node);
    if ($rows) {
      $html .= '<ul class="vol-people">';
      foreach ($rows as $row) {
        $html .= '<li>' . htmlspecialchars($row['name'], ENT_QUOTES)
          . ($row['note'] !== '' ? ': <em>' . htmlspecialchars($row['note'], ENT_QUOTES) . '</em>' : '') . '</li>';
      }
      $html .= '</ul>';
    }
    return $html . '</div>';
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Approve.
   */
  public function approve(array &$form, FormStateInterface $form_state): void {
    $node = $this->loadNode($form_state);
    if (!$node || !Opportunity::isGathering($node) || !$this->currentUser()->hasPermission('makehaven_tasks.manage_tasks')) {
      $this->messenger()->addError($this->t('That opportunity cannot be approved now.'));
      return;
    }
    $this->decider->approve($node, $this->currentUser(), (string) $form_state->getValue('note'));
    $this->messenger()->addStatus($this->t('Approved "@title". The volunteers have been emailed.', ['@title' => $node->label()]));
    $this->redirectBack($form_state, $node);
  }

  /**
   * Decline.
   */
  public function decline(array &$form, FormStateInterface $form_state): void {
    $node = $this->loadNode($form_state);
    $account = $this->currentUser();
    $allowed = $node && (
      (Opportunity::isGathering($node) && $account->hasPermission('makehaven_tasks.manage_tasks'))
      || (Opportunity::stage($node) === Opportunity::STAGE_PROPOSED && ($account->hasPermission('makehaven_tasks.create_task')))
    );
    if (!$allowed) {
      $this->messenger()->addError($this->t('That opportunity cannot be declined now.'));
      return;
    }
    $this->decider->decline($node, $account, (string) $form_state->getValue('note'));
    $this->messenger()->addStatus($this->t('Declined "@title". Only the people who volunteered were told.', ['@title' => $node->label()]));
    $this->redirectBack($form_state, $node);
  }

  /**
   * Release a proposed draft to gather interest.
   */
  public function release(array &$form, FormStateInterface $form_state): void {
    $node = $this->loadNode($form_state);
    if (!$node || Opportunity::stage($node) !== Opportunity::STAGE_PROPOSED || !$this->currentUser()->hasPermission('makehaven_tasks.create_task')) {
      $this->messenger()->addError($this->t('That draft cannot be released now.'));
      return;
    }
    $this->decider->release($node, $this->currentUser());
    $this->messenger()->addStatus($this->t('"@title" is on the board gathering interest.', ['@title' => $node->label()]));
    $this->redirectBack($form_state, $node);
  }

  /**
   * Sends the user back to the approvals page or the task.
   */
  private function redirectBack(FormStateInterface $form_state, NodeInterface $node): void {
    $route = \Drupal::routeMatch()->getRouteName();
    $form_state->setRedirectUrl($route === 'makehaven_tasks.approvals' ? Url::fromRoute('makehaven_tasks.approvals') : $node->toUrl());
  }

  /**
   * Loads the task this form acts on.
   */
  private function loadNode(FormStateInterface $form_state): ?NodeInterface {
    $node = \Drupal::entityTypeManager()->getStorage('node')->load((int) $form_state->get('nid'));
    return $node instanceof NodeInterface && $node->bundle() === 'task' ? $node : NULL;
  }

}
