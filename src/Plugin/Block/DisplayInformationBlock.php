<?php

namespace Drupal\format_strawberryfield\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\format_strawberryfield\ViewModeResolverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin block that shows additional information about the displays.
 *
 * @Block(
 *   id = "format_strawberryfield_display_information",
 *   admin_label = @Translation("Strawberry Field Block: Display information"),
 *   category = @Translation("Strawberry Field Formatter"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", label = @Translation("Node"))
 *   }
 * )
 */
class DisplayInformationBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The SBF View Mode resolver.
   *
   * @var \Drupal\format_strawberryfield\ViewModeResolverInterface
   */
  protected $viewModeResolver;

  /**
   * The entity display repository.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected $entityDisplayRepository;

  /**
   * Construct a DisplayInformationBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param string $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\format_strawberryfield\ViewModeResolverInterface $view_mode_resolver
   *   The SBF View Mode resolver.
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $entity_display_repository
   *   The entity display repository.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ViewModeResolverInterface $view_mode_resolver, EntityDisplayRepositoryInterface $entity_display_repository) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->viewModeResolver = $view_mode_resolver;
    $this->entityDisplayRepository = $entity_display_repository;
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
      $container->get('entity_display.repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];

    /** @var \Drupal\node\NodeInterface $node */
    if ($node = $this->getContextValue('node')) {
      $view_modes = $this->viewModeResolver->getCandidates($node);
      $selected_view_mode = array_shift($view_modes);
      $build['info'] = [
        '#type' => 'details',
        '#title' => 'Display information',
        '#open' => TRUE,
      ];

      // Retrieve the view modes for the entity type to display the label to
      // the user.
      $view_mode_definitions = $this->entityDisplayRepository->getViewModes('node');

      $build['info']['selected_view_mode'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $this->t('<strong>Selected view mode</strong>: @view_mode',
          [
            '@view_mode' => Link::createFromRoute(
              $view_mode_definitions[$selected_view_mode['view_mode']]['label'] ?? $selected_view_mode['view_mode'],
              'format_strawberryfield.display_settings',
              [
                'node' => $node->id(),
                'bundle' => $node->bundle(),
                'view_mode_name' => $selected_view_mode['view_mode'],
              ]
            )->toString(),
          ]
        ),
      ];
      $build['info']['disclaimer'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $this->t('<strong>Info</strong>: Changes on a View Mode will affect every ADO(Node) that is using it.'),
      ];

      if (empty($view_modes)) {
        $build['info']['other_view_modes'] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => $this->t('No other view modes were considered for this content.'),
        ];
      }
      else {
        $other_view_modes = array_column($view_modes, 'view_modes');
        $build['info']['other_view_modes'] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => $this->t('These were also considered: @view_modes', ['@view_modes' => implode(', ', $other_view_modes)]),
        ];
      }
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    /** @var \Drupal\node\NodeInterface $node */
    if ($node = $this->getContextValue('node')) {
      return $node->getCacheTags();
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    /** @var \Drupal\node\NodeInterface $node */
    if ($node = $this->getContextValue('node')) {
      return $node->getCacheMaxAge();
    }
    return 0;
  }

}
