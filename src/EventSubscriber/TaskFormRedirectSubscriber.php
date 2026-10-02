<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\makehaven_tasks\Form\TaskRequestForm;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sends task create and edit to the one Post form.
 *
 * Staff found two forms with different options confusing (Kate, 2026-10-01).
 * node/add/task and node/N/edit for a task now go to /tasks/request and
 * /tasks/N/edit. The node form is still reachable with ?advanced=1 (admins,
 * path aliases and publishing options) and for bank templates.
 */
final class TaskFormRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After routing (32) so the route match is available.
    return [KernelEvents::REQUEST => ['onRequest', 28]];
  }

  /**
   * Redirects, when it applies.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    if ($request->query->has('advanced') || $request->isMethod('POST')) {
      return;
    }
    $route = $this->routeMatch->getRouteName();
    if ($route === 'node.add') {
      $type = $this->routeMatch->getParameter('node_type');
      $type_id = is_object($type) ? $type->id() : (string) $type;
      if ($type_id !== 'task') {
        return;
      }
      $query = [];
      if ($equipment = $request->query->get('field_task_equipment')) {
        $query['equipment'] = $equipment;
      }
      $event->setResponse(new RedirectResponse(Url::fromRoute('makehaven_tasks.request', [], ['query' => $query])->toString()));
      return;
    }
    if ($route === 'entity.node.edit_form') {
      $node = $this->routeMatch->getParameter('node');
      if (!$node instanceof NodeInterface || $node->bundle() !== 'task' || TaskRequestForm::isTemplate($node)) {
        return;
      }
      if (!TaskRequestForm::editAccess($node, $this->currentUser)->isAllowed()) {
        return;
      }
      $event->setResponse(new RedirectResponse(Url::fromRoute('makehaven_tasks.edit', ['node' => $node->id()])->toString()));
    }
  }

}
