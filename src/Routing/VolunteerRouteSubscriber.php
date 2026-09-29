<?php

declare(strict_types=1);

namespace Drupal\makehaven_tasks\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Titles the /tasks board "Volunteer" without editing the exported view.
 *
 * The page title of a views page comes from a title callback that reads the
 * view config; replace it with a fixed title so /tasks and /volunteer match.
 */
final class VolunteerRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    if ($route = $collection->get('view.tasks.page_tasks_interactive')) {
      $defaults = $route->getDefaults();
      unset($defaults['_title_callback']);
      $defaults['_title'] = 'Volunteer';
      $route->setDefaults($defaults);
    }
  }

}
