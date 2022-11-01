<?php

namespace Drupal\format_strawberryfield\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\format_strawberryfield\MetadataConfigInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Symfony\Component\HttpFoundation\File\MimeType\ExtensionGuesser;

/**
 * Defines the MetadataAPIConfigEntity entity.*.
 *
 * This entity binds Bundle and a Metadata Display.
 * Allows direct access exposure of Twig Template generated Metadata per node.
 *
 * @ConfigEntityType(
 *   id = "metadataapi_entity",
 *   label = @Translation("Metadata API Configuration Entity"),
 *   handlers = {
 *     "list_builder" = "\Drupal\format_strawberryfield\Entity\Controller\MetadataAPIConfigEntityListBuilder",
 *     "form" = {
 *       "add" = "Drupal\format_strawberryfield\Form\MetadataAPIConfigEntityForm",
 *       "edit" = "Drupal\format_strawberryfield\Form\MetadataAPIConfigEntityForm",
 *       "delete" = "Drupal\format_strawberryfield\Form\MetadataAPIConfigEntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\format_strawberryfield\MetadataAPIConfigEntityHtmlRouteProvider",
 *     },
 *   },
 *   config_prefix = "metadataapi_entity",
 *   admin_permission = "administer site configuration",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "active" = "active",
 *   },
 *  config_export = {
 *     "id",
 *     "label",
 *     "uuid",
 *     "configuration",
 *     "views_source_ids",
 *     "cache",
 *     "active",
 *   },
 *   links = {
 *     "edit-form" = "/admin/config/archipelago/metadataapi/{metadataapi_entity}/edit",
 *     "add-form" = "/admin/config/archipelago/metadataapi/add",
 *     "delete-form" = "/admin/config/archipelago/metadataapie/{metadataapi_entity}/delete",
 *     "collection" = "/admin/config/archipelago/metadataapi",
 *   }
 * )
 */
class MetadataAPIConfigEntity extends ConfigEntityBase implements MetadataConfigInterface {

  use DependencySerializationTrait;
  /**
   * The ID of the Metadata Config Entity.
   *
   * @var string
   */

  protected $id;

  /**
   * The human-readable name of the form or view mode.
   *
   * @var string
   */
  protected $label;

  /**
   * The quite complex OpenAPI configuration that will live in configuration
   *
   * @var array
   */
  protected $configuration = [];


  /**
   * The in use MetadataDisplay Entities.
   *
   * @var \Drupal\format_strawberryfield\MetadataDisplayInterface
   */
  protected $metadataItemDisplayEntity = NULL;


  /**
   * The in use MetadataDisplay Entities.
   *
   * @var \Drupal\format_strawberryfield\MetadataDisplayInterface;
   */
  protected $metadataWrapperDisplayEntity = NULL;


  /**
   * A Named Query Config array
   *
   * @var string
   */
  protected $named_query_configs = [];

  /**
   * Whether or not the rendered output of is cached by default.
   *
   * @var bool
   */
  protected $cache = TRUE;

  /**
   * If the Config Entity is active or not.
   *
   * @var bool
   */
  protected $active = TRUE;

  /**
   * The Label for this config entity.
   *
   * @return string
   *   The label.
   */
  public function getLabel(): string {
    return $this->label;
  }

  /**
   * Label setter.
   *
   * @param string $label
   *   The config entity label.
   */
  public function setLabel(string $label): void {
    $this->label = $label;
  }

  /**
   * Gets a Metadata Display Entity for a given condition
   *
   * @param string $condition
   *
   * @return \Drupal\format_strawberryfield\MetadataDisplayInterface|Null
   *   Either a Metadata Display entity or missing reference.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getItemMetadataDisplayEntity($condition = 'default') {
    //@TODO process condition into a machinable key
    if (empty($this->metadataItemDisplayEntity)) {
      if ($this->configuration['metadataItemDisplayentity'][$condition]) {
        $metadatadisplayentities = $this->entityTypeManager()
          ->getStorage('metadatadisplay_entity')
          ->loadByProperties(['uuid' => $this->configuration['metadataItemDisplayentity'][$condition]]);
        $metadatadisplayentity = reset($metadatadisplayentities);
        if (isset($metadatadisplayentity)) {
          $this->metadataItemDisplayEntity = $metadatadisplayentity;
        }
      }
    }
    return $this->metadataItemDisplayEntity;
  }

  /**
   * @param string $condition
   *
   * @return \Drupal\format_strawberryfield\MetadataDisplayInterface
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getWrapperMetadataDisplayEntity($condition = 'default') {
    if (empty($this->metadataWrapperDisplayEntity)) {
      if ($this->configuration['metadataWrapperDisplayentity'][$condition])  {
        $metadatadisplayentities = $this->entityTypeManager()
          ->getStorage('metadatadisplay_entity')
          ->loadByProperties(['uuid' => $this->configuration['metadataWrapperDisplayentity'][$condition]]);
        $metadatadisplayentity = reset($metadatadisplayentities);
        if (isset($metadatadisplayentity)) {
          $this->metadataWrapperDisplayEntity = $metadatadisplayentity;
        }
      }
    }
    return $this->metadataWrapperDisplayEntity;
  }


  /**
   * Checks if cached.
   *
   * @return bool
   *   True If this is cached.
   */
  public function isCache(): bool {
    return $this->cache;
  }


  /**
   * @param array $configuration
   */
  public function setConfiguration(array $configuration): void {
    $this->configuration = $configuration;
  }

  /**
   * @param array $configuration
   */
  public function getConfiguration()  {
    return $this->configuration;
  }

  /**
   * Sets cached flag.
   *
   * @param bool $cache
   *   The cache Flag.
   */
  public function setCache(bool $cache): void {
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public static function sort(
    ConfigEntityInterface $a,
    ConfigEntityInterface $b
  ) {
    /** @var \Drupal\format_strawberryfield\Entity\MetadataAPIConfigEntity $a */
    /** @var \Drupal\format_strawberryfield\Entity\MetadataAPIConfigEntity $b */
    // Sort by the type the source Metadata Display this entity uses.
    $a_type = $a->getLabel();
    $b_type = $b->getLabel();
    $type_order = strnatcasecmp($a_type, $b_type);
    return $type_order != 0 ? $type_order : parent::sort($a, $b);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    // @TODO add Views used as a dependency
    $this->addDependency('module', \Drupal::entityTypeManager()->getDefinition(
      'node')->getProvider());
    $this->addDependency('module', \Drupal::entityTypeManager()->getDefinition(
      'metadatadisplay_entity')->getProvider());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
    \Drupal::entityTypeManager()->clearCachedDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public static function preDelete(
    EntityStorageInterface $storage,
    array $entities
  ) {
    parent::preDelete($storage, $entities);
    \Drupal::entityTypeManager()->clearCachedDefinitions();
  }

  /**
   * Checks if this Config is active.
   *
   * @return bool
   *   True if active.
   */
  public function isActive(): bool {
    return $this->active;
  }

  /**
   * Sets the active flag.
   *
   * @param bool $active
   *   True to set Active.
   */
  public function setActive(bool $active): void {
    $this->active = $active;
  }

  /**
   * @return string
   */
  public function getViewsSourceId(): string {
    return $this->views_source_id;
  }

  public function getNamedQuery($named_query): string {
    return $this->query_config[$named_query] ?? [];
  }

  public function searchApiQuery($named_query): array {
  /* string $index, string $term, array $fulltext, array $filters, array $facets, array $sort = ['search_api_relevance' => 'ASC'], int $limit = 1, int $offset = 0 */

    /** @var \Drupal\search_api\IndexInterface[] $indexes */
    $indexes = \Drupal::entityTypeManager()
      ->getStorage('search_api_index')
      ->loadMultiple([$index]);

    // We can check if $fulltext, $filters and $facets are inside $indexes['theindex']->field_settings["afield"]?
    foreach ($indexes as $search_api_index) {

      // Create the query.
      // How many?
      $query = $search_api_index->query([
        'limit' => $limit,
        'offset' => $offset,
      ]);

      $parse_mode = $this->parseModeManager->createInstance('terms');
      $query->setParseMode($parse_mode);
      foreach ($sort as $field => $order) {
        $query->sort($field, $order);
      }
      $query->keys($term);
      if (!empty($fulltext)) {
        $query->setFulltextFields($fulltext);
      }

      $allfields_translated_to_solr = $search_api_index->getServerInstance()
        ->getBackend()
        ->getSolrFieldNames($query->getIndex());

      $query->setOption('search_api_retrieved_field_values', ['id']);
      foreach ($filters as $field => $condition) {
        $query->addCondition($field, $condition);
      }
      // Facets, does this search api index supports them?
      if ($search_api_index->getServerInstance()->supportsFeature('search_api_facets')) {
        // My real goal!
        //https://solarium.readthedocs.io/en/stable/queries/select-query/building-a-select-query/components/facetset-component/facet-pivot/
        $facet_options = [];
        foreach ($facets as $facet_field) {
          $facet_options['facet:' . $facet_field] = [
            'field'     => $facet_field,
            'limit'     => 10,
            'operator'  => 'or',
            'min_count' => 1,
            'missing'   => TRUE,
          ];
        }

        if (!empty($facet_options)) {
          $query->setOption('search_api_facets', $facet_options);
        }
      }
      /* Basic is good enough for facets */
      $query->setProcessingLevel(QueryInterface::PROCESSING_BASIC);
      // see also \Drupal\search_api_autocomplete\Plugin\search_api_autocomplete\suggester\LiveResults::getAutocompleteSuggestions
      $results = $query->execute();
      $extradata = $results->getAllExtraData();
      // We return here
      $return = [];
      foreach( $results->getResultItems() as $resultItem) {
        $return['results'][$resultItem->getOriginalObject()->getValue()->id()]['entity'] = $resultItem->getOriginalObject()->getValue();
        foreach ($resultItem->getFields() as $field) {
          $return['results'][$resultItem->getOriginalObject()->getValue()->id()]['fields'][$field->getFieldIdentifier()] = $field->getValues();
        }
      }
      $return['total'] = $results->getResultCount();
      if (isset($extradata['search_api_facets'])) {
        foreach($extradata['search_api_facets'] as $facet_id => $facet_values) {
          [$not_used, $field_id] = explode(':', $facet_id);
          $facet = [];
          foreach ($facet_values as $entry) {
            $facet[$entry['filter']] = $entry['count'];
          }
          $return['facets'][$field_id] = $facet;
        }
      }
      return $return;
    }
    return [];
  }


}
