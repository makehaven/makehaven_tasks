<?php

namespace Drupal\makehaven_tasks\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles task status transitions (claim / in-progress).
 */
class TaskStatusController extends ControllerBase {

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messengerService;

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUserAccount;

  /**
   * Creates a TaskStatusController.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    MessengerInterface $messenger,
    AccountInterface $current_user,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->messengerService = $messenger;
    $this->currentUserAccount = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('messenger'),
      $container->get('current_user'),
    );
  }

  /**
   * Claims a task for the current user (sets status to in_progress).
   */
  public function claim(NodeInterface $node, Request $request): RedirectResponse {
    $canonical = $node->toUrl()->setAbsolute()->toString();

    // Validate: must be a task node that is published.
    if ($node->bundle() !== 'task' || !$node->isPublished()) {
      return new RedirectResponse($canonical);
    }

    // Must be authenticated.
    if (!$this->currentUserAccount->isAuthenticated()) {
      return new RedirectResponse($canonical);
    }

    $uid = (int) $this->currentUserAccount->id();

    // Idempotent: if already claimed by this user, do nothing.
    if ($node->hasField('field_task_status')
      && (string) $node->get('field_task_status')->value === 'in_progress'
      && $node->hasField('field_task_claimed_by')
      && !$node->get('field_task_claimed_by')->isEmpty()
      && (int) $node->get('field_task_claimed_by')->target_id === $uid
    ) {
      return new RedirectResponse($canonical);
    }

    if ($node->hasField('field_task_status')) {
      $node->set('field_task_status', 'in_progress');
    }
    if ($node->hasField('field_task_claimed_by')) {
      $node->set('field_task_claimed_by', $uid);
    }

    $node->save();

    $this->messengerService->addStatus($this->t("You're now listed as working on this task."));

    return new RedirectResponse($canonical);
  }

}
