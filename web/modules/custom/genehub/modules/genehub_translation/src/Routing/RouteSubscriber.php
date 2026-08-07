<?php

declare(strict_types=1);

namespace Drupal\genehub_translation\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\Routing\RouteCollection;

/**
 * Alters GeneHub product translation overview routes.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach (['product_generic', 'product_solidex'] as $entity_type_id) {
      $route_name = "entity.$entity_type_id.content_translation_overview";
      if ($route = $collection->get($route_name)) {
        $route->setDefault(
          '_controller',
          '\Drupal\genehub_translation\Controller\AiTranslationOverviewController::overview',
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = parent::getSubscribedEvents();
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', -240];
    return $events;
  }

}
