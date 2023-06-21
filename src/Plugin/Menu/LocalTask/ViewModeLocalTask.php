<?php

namespace Drupal\format_strawberryfield\Plugin\Menu\LocalTask;

use Drupal\Core\Menu\LocalTaskDefault;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\format_strawberryfield\ViewModeResolverInterface;
use Drupal\node\NodeInterface;
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
   * Construct the ViewModeLocalTask object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\format_strawberryfield\ViewModeResolverInterface
   *   The SBF View Mode resolver.
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, ViewModeResolverInterface $view_mode_resolver) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->viewModeResolver = $view_mode_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('format_strawberryfield.view_mode_resolver')
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
    return $params;
  }

}
