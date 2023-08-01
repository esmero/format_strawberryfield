<?php

namespace Drupal\format_strawberryfield\Plugin\Menu\LocalTask;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Menu\LocalTaskDefault;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\format_strawberryfield\ViewModeResolverInterface;
use Drupal\node\NodeInterface;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Local task plugin to calculate the current view mode.
 */
class ViewModeLocalTask extends LocalTaskDefault implements ContainerFactoryPluginInterface {

  /**
   * The SBF View Mode resolver.
   *
   * @var \Drupal\format_strawberryfield\ViewModeResolverInterface
   */
  protected $viewModeResolver;

  /**
   * The Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Construct the ViewModeLocalTask object.
   *
   * @param array                                                    $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string                                                   $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array                                                    $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\format_strawberryfield\ViewModeResolverInterface $view_mode_resolver
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface           $entitytype_manager
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, ViewModeResolverInterface $view_mode_resolver, EntityTypeManagerInterface $entitytype_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->viewModeResolver = $view_mode_resolver;
    $this->entityTypeManager = $entitytype_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('format_strawberryfield.view_mode_resolver'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getRouteParameters(RouteMatchInterface $route_match) {
    $params = parent::getRouteParameters($route_match);
    $node = $route_match->getParameter('node');
    // Users can override the Viewer manually (if DS is enabled)
    if ($node instanceof NodeInterface) {
      if ($node->hasField('ds_switch') && !empty($node->ds_switch->value)) {
        $viewmode = $node->ds_switch->value;
      }
      else {
        $viewmode = $this->viewModeResolver->get($node);
      }
      $params += ['bundle' =>  $node->bundle(), 'node' => $node->id(), 'view_mode_name' => $viewmode];
    }
    elseif ($route_match->getParameter('view_id') && is_numeric($node)) {
      /* Upcasting %node route argument to NodeInterface will not happen for
      Rputed Views (Page/Rest), when under access control because of
      https://www.drupal.org/project/drupal/issues/2528166
      @TODO Revisit this work-around on Drupal 11.x
      */
      $node = $this->entityTypeManager->getStorage('node')->load($node);
      if ($node) {
        if ($node->hasField('ds_switch')
          && !empty($node->ds_switch->value)
        ) {
          $viewmode = $node->ds_switch->value;
        }
        else {
          $viewmode = $this->viewModeResolver->get($node);
        }
        $params += [
          'bundle' => $node->bundle(),
          'node' => $node->id(),
          'view_mode_name' => $viewmode
        ];
      }
    }

    return $params;
  }

}
