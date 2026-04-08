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

  /**
   * Marks a task as in-progress and claims it for the current user.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The task node.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirects back to the task.
   */
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

    // Don't re-claim if already in progress by someone else (they should use
    // the handoff form). Allow re-claim if status is open or incomplete.
    $node->set('field_task_status', 'in_progress');
    $node->set('field_task_claimed_by', ['target_id' => $current_user->id()]);
    $node->save();

    $this->messenger()->addStatus($this->t("You've claimed this task. Mark it done when finished, or use the handoff option if you can't complete it."));

    return new RedirectResponse(Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString());
  }

}
