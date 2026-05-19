<?php

namespace Drupal\makehaven_tasks\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Handles quick task status actions (claim, offer to help).
 *
 * Claim model:
 *  - Single-claim (default): the first member becomes the lead
 *    (field_task_claimed_by). Other eligible members can "offer to help",
 *    which adds them to field_task_helpers. The lead and helpers can mark
 *    the task done.
 *  - Multi-claim (field_task_allow_multiple = TRUE): every eligible member
 *    can claim independently. The first claimer is stored in
 *    field_task_claimed_by and the rest in field_task_helpers, but the UI
 *    treats them all as equal co-claimants with no single lead.
 */
class TaskActionController extends ControllerBase {

  /**
   * Claim a task (become the lead, or a co-claimant on multi-claim tasks).
   */
  public function claim(NodeInterface $node, Request $request) {
    $this->assertTaskAndToken($node, $request);

    $current_user = $this->currentUser();
    $uid = (int) $current_user->id();
    $node_url = Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString();

    // Guard 1: audience / badge restrictions.
    if ($denied = $this->audienceDenied($node, $current_user)) {
      $this->messenger()->addError($denied);
      return new RedirectResponse($node_url);
    }

    // Guard 2: already flagged complete.
    if ($this->isCompleted($node)) {
      $this->messenger()->addWarning($this->t('This task is already marked as done.'));
      return new RedirectResponse($node_url);
    }

    $allow_multiple = $this->allowsMultiple($node);
    $claimed_by = $this->claimedBy($node);
    $helpers = $this->helperUids($node);
    $status = $node->hasField('field_task_status')
      ? ($node->get('field_task_status')->value ?? 'open')
      : 'open';

    // Already on an active task — nothing to do. (Incomplete tasks fall
    // through so a prior lead/helper can re-pick a stalled hand-off.)
    if ($status !== 'incomplete'
      && ($claimed_by === $uid || in_array($uid, $helpers, TRUE))) {
      $this->messenger()->addStatus($this->t("You're already on this task."));
      return new RedirectResponse($node_url);
    }

    // Incomplete (handed off / stalled) or never claimed → become the lead.
    // A handed-off task keeps the previous lead in field_task_claimed_by, so
    // status — not claimed_by — decides whether it's grabbable.
    if ($status === 'incomplete' || !$claimed_by) {
      $helpers = array_values(array_diff($helpers, [$uid]));
      $node->set('field_task_helpers', array_map(fn($h) => ['target_id' => $h], $helpers));
      $node->set('field_task_status', 'in_progress');
      $node->set('field_task_claimed_by', ['target_id' => $uid]);
      $node->save();
      $this->messenger()->addStatus($this->t("You've claimed this task. Mark it done when finished, or use the hand-off option if you can't complete it."));
      return new RedirectResponse($node_url);
    }

    // Active task someone else already holds.
    if (!$allow_multiple) {
      // Single-claim: don't let a second person take it. The UI offers
      // "Offer to help" instead — this is the server-side safety net.
      $claimer = User::load($claimed_by);
      $name = $claimer ? $claimer->getDisplayName() : 'someone';
      $this->messenger()->addWarning($this->t(
        'This task is already claimed by @name. Use "Offer to help" to pitch in, or the hand-off option to take it over.',
        ['@name' => $name]
      ));
      return new RedirectResponse($node_url);
    }

    // Multi-claim: join as a co-claimant.
    $helpers[] = $uid;
    $node->set('field_task_helpers', array_map(fn($h) => ['target_id' => $h], $helpers));
    $node->set('field_task_status', 'in_progress');
    $node->save();
    $this->messenger()->addStatus($this->t("You've joined this task — you're now one of the people on it."));
    return new RedirectResponse($node_url);
  }

  /**
   * Offer to help (or step back from helping) a single-claim task.
   *
   * Toggles the current user in field_task_helpers.
   */
  public function help(NodeInterface $node, Request $request) {
    $this->assertTaskAndToken($node, $request);

    $current_user = $this->currentUser();
    $uid = (int) $current_user->id();
    $node_url = Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString();

    if ($denied = $this->audienceDenied($node, $current_user)) {
      $this->messenger()->addError($denied);
      return new RedirectResponse($node_url);
    }

    if ($this->isCompleted($node)) {
      $this->messenger()->addWarning($this->t('This task is already marked as done.'));
      return new RedirectResponse($node_url);
    }

    // On multi-claim tasks there is no "lead" to help — just claim.
    if ($this->allowsMultiple($node)) {
      $this->messenger()->addStatus($this->t('This task lets several people claim it — use "Claim task" to join.'));
      return new RedirectResponse($node_url);
    }

    $claimed_by = $this->claimedBy($node);

    if (!$claimed_by) {
      $this->messenger()->addStatus($this->t("No one's claimed this task yet — claim it to get started."));
      return new RedirectResponse($node_url);
    }

    if ($claimed_by === $uid) {
      $this->messenger()->addStatus($this->t("You're the lead on this task — no need to offer help to yourself."));
      return new RedirectResponse($node_url);
    }

    $helpers = $this->helperUids($node);

    if (in_array($uid, $helpers, TRUE)) {
      // Withdraw.
      $helpers = array_values(array_diff($helpers, [$uid]));
      $node->set('field_task_helpers', array_map(fn($h) => ['target_id' => $h], $helpers));
      $node->save();
      $this->messenger()->addStatus($this->t("You've stepped back from helping with this task."));
      return new RedirectResponse($node_url);
    }

    // Offer to help.
    $helpers[] = $uid;
    $node->set('field_task_helpers', array_map(fn($h) => ['target_id' => $h], $helpers));
    $node->save();

    $lead = User::load($claimed_by);
    $lead_name = $lead ? $lead->getDisplayName() : $this->t('the lead');
    $this->messenger()->addStatus($this->t(
      "Thanks for offering to help! Coordinate with @name — they've been notified.",
      ['@name' => $lead_name]
    ));

    if (function_exists('_makehaven_tasks_notify_help_offer')) {
      _makehaven_tasks_notify_help_offer($node, $uid, $claimed_by);
    }

    return new RedirectResponse($node_url);
  }

  // ── Helpers ──────────────────────────────────────────────────────────────

  /**
   * Validates the node is a task and the CSRF token is good.
   */
  protected function assertTaskAndToken(NodeInterface $node, Request $request): void {
    if ($node->bundle() !== 'task') {
      throw new AccessDeniedHttpException();
    }
    $token = $request->query->get('token');
    if (!\Drupal::csrfToken()->validate($token, "task-action/{$node->id()}")) {
      throw new AccessDeniedHttpException('Invalid CSRF token.');
    }
    if ($this->currentUser()->isAnonymous()) {
      throw new AccessDeniedHttpException();
    }
  }

  /**
   * Returns an error message if the user may not participate, else NULL.
   */
  protected function audienceDenied(NodeInterface $node, AccountInterface $user): ?string {
    $audience = $node->hasField('field_task_audience')
      ? $node->get('field_task_audience')->value
      : NULL;

    if ($audience === 'staff_only') {
      $staff_roles = ['administrator', 'manager', 'content_editor'];
      if (!array_intersect($staff_roles, $user->getRoles())) {
        return (string) $this->t('This task is restricted to staff.');
      }
    }

    if ($audience === 'badge_holders') {
      $allowed_roles = ['administrator', 'manager', 'content_editor', 'facilitator'];
      $passes = (bool) array_intersect($allowed_roles, $user->getRoles());
      if (!$passes
        && $node->hasField('field_task_required_badge')
        && !$node->get('field_task_required_badge')->isEmpty()) {
        $required_tid = (int) $node->get('field_task_required_badge')->target_id;
        if ($required_tid && _makehaven_tasks_user_has_badge((int) $user->id(), $required_tid)) {
          $passes = TRUE;
        }
      }
      if (!$passes) {
        return (string) $this->t('This task requires a badge. Please check with a facilitator.');
      }
    }

    return NULL;
  }

  /**
   * Whether the task is flagged completed.
   */
  protected function isCompleted(NodeInterface $node): bool {
    $flag_service = \Drupal::service('flag');
    $flag = $flag_service->getFlagById('task_completed');
    return $flag && $flag_service->getFlagging($flag, $node);
  }

  /**
   * Whether this task opts in to multiple independent claimants.
   */
  protected function allowsMultiple(NodeInterface $node): bool {
    return $node->hasField('field_task_allow_multiple')
      && (bool) $node->get('field_task_allow_multiple')->value;
  }

  /**
   * The lead/first-claimant uid, or 0 if unclaimed.
   */
  protected function claimedBy(NodeInterface $node): int {
    return $node->hasField('field_task_claimed_by') && !$node->get('field_task_claimed_by')->isEmpty()
      ? (int) $node->get('field_task_claimed_by')->target_id
      : 0;
  }

  /**
   * Helper/co-claimant uids as a flat int array.
   */
  protected function helperUids(NodeInterface $node): array {
    if (!$node->hasField('field_task_helpers') || $node->get('field_task_helpers')->isEmpty()) {
      return [];
    }
    $uids = [];
    foreach ($node->get('field_task_helpers') as $item) {
      if ($item->target_id) {
        $uids[] = (int) $item->target_id;
      }
    }
    return array_values(array_unique($uids));
  }

}
