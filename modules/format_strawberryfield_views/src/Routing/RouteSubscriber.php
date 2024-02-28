<?php
/**
 * @file
 * Contains \Drupal\format_strawberryfield_views\Routing\RouteSubscriber.
 */

namespace Drupal\format_strawberryfield_views\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  public function alterRoutes(RouteCollection $collection) {
    if ($route = $collection->get('views.ajax')) {
      $route->setDefaults(array(
        '_controller' => '\Drupal\format_strawberryfield_views\Controller\FormatStrawberryfieldViewAjaxController:ajaxView',
      ));
    }
  }
}
