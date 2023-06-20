<?php

namespace Drupal\format_strawberryfield\Routing;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Layout builder compat route alters for Active Display Local task route.
 *
 * @internal
 *   Tagged services are internal.
 */
class FormatStrawberryfieldLayoutBuilderRoutes implements EventSubscriberInterface {

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;


  /**
   * FormatStrawberryfieldLayoutBuilderRoutes constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Clones Layout Builder Defaults/Params overrides for Active Display if.
   *
   * @param \Drupal\Core\Routing\RouteBuildEvent $event
   *   The route build event.
   */
  public function onAlterRoutes(RouteBuildEvent $event) {
    if ($route = $event->getRouteCollection()->get('format_strawberryfield.display_settings')) {
      if (\Drupal::hasService('plugin.manager.layout_builder.section_storage') &&
        $this->entityTypeManager->getDefinition('node')->hasHandlerClass('form', 'layout_builder')
      ) {
        if ($original_route = $event->getRouteCollection()->get("entity.entity_view_display.node.view_mode")) {
          $newdefaults = $route->getDefaults() + $original_route->getDefaults();
          $route->setDefaults($newdefaults);
          $parameters['section_storage']['layout_builder_tempstore'] = TRUE;
          $parameters = NestedArray::mergeDeep($parameters, $route->getOption('parameters') ?: []);
          $route->setOption('parameters', $parameters);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // Run after \Drupal\layout_builder\Routing\LayoutBuilderRoutes.
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', -120];
    return $events;
  }
}
