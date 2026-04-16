<?php

namespace Drupal\makehaven_tasks\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Handles quick task status actions (claim).
 */
class TaskActionController extends ControllerBase {

  public function claim(NodeInterface $node, Request $request) {
    if ($node->bundle() !== 'task') {
      throw new AccessDeniedHttpException();
    }

    $token = $request->query->get('token');
    if (!\Drupal::csrfToken()->validate($token, "task-action/{$node->id()}")) {
      throw new AccessDeniedHttpException('Invalid CSRF token.');
    }

    $current_user = $this->currentUser();
    if ($current_user->isAnonymous()) {
      throw new AccessDeniedHttpException();
    }

    $uid = (int) $current_user->id();

    // ── Guard 1: audience restrictions ──
    $audience = $node->hasField('field_task_audience')
      ? $node->get('field_task_audience')->value
      : NULL;

    if ($audience === 'staff_only') {
      $staff_roles = ['administrator', 'manager', 'content_editor'];
      if (!array_intersect($staff_roles, $current_user->getRoles())) {
        $this->messenger()->addError($this->t('This task is restricted to staff.'));
        return new RedirectResponse(Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString());
      }
    }

    if ($audience === 'badge_holders') {
      // Badge-holder tasks require the facilitator role or staff.
      $allowed_roles = ['administrator', 'manager', 'content_editor', 'facilitator'];
      if (!array_intersect($allowed_roles, $current_user->getRoles())) {
        $this->messenger()->addError($this->t('This task requires a badge. Please check with a facilitator.'));
        return new RedirectResponse(Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString());
      }
    }

    // ── Guard 2: already claimed by someone else ──
    $status = $node->hasField('field_task_status')
      ? ($node->get('field_task_status')->value ?? 'open')
      : 'open';
    $claimed_by = $node->hasField('field_task_claimed_by') && !$node->get('field_task_claimed_by')->isEmpty()
      ? (int) $node->get('field_task_claimed_by')->target_id
      : 0;

    if ($status === 'in_progress' && $claimed_by && $claimed_by !== $uid) {
      $claimer = \Drupal\user\Entity\User::load($claimed_by);
      $name = $claimer ? $claimer->getDisplayName() : 'someone';
      $this->messenger()->addWarning($this->t(
        'This task is already claimed by @name. Use the handoff form if you need to take over.',
        ['@name' => $name]
      ));
      return new RedirectResponse(Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString());
    }

    // ── Guard 3: already flagged complete ──
    $flag_service = \Drupal::service('flag');
    $flag = $flag_service->getFlagById('task_completed');
    if ($flag && $flag_service->getFlagging($flag, $node)) {
      $this->messenger()->addWarning($this->t('This task is already marked as done.'));
      return new RedirectResponse(Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString());
    }

    // All guards passed — claim the task.
    $node->set('field_task_status', 'in_progress');
    $node->set('field_task_claimed_by', ['target_id' => $uid]);
    $node->save();

    $this->messenger()->addStatus($this->t("You've claimed this task. Mark it done when finished, or use the handoff option if you can't complete it."));

    return new RedirectResponse(Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString());
  }

}
