<?php

namespace Drupal\format_strawberryfield_views\Plugin\views\filter;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\TypedData\FieldItemDataDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\node\NodeStorageInterface;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\ParseMode\ParseModePluginManager;
use Drupal\search_api\Plugin\views\filter\SearchApiFulltext;
use Drupal\search_api\Plugin\views\query\SearchApiQuery;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_solr\Utility\Utility;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\search_api\Plugin\views\filter\SearchApiFilterTrait;
use Drupal\views\Plugin\views\filter\InOperator;
use Drupal\views\Views;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\search_api\Utility\FieldsHelperInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\Element\EntityAutocomplete;

/**
 * Defines a filter that handles ADO/ADO UUIDs against any indexed subproperty.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("sbf_ado_filter")
 */
class StrawberryADOfilter extends InOperator /* FilterPluginBase */ {

  use SearchApiFilterTrait;

  protected $valueFormType = 'select';
  protected $alwaysMultiple = TRUE;


  /**
   * The parse mode manager.
   *
   * @var \Drupal\search_api\ParseMode\ParseModePluginManager|null
   */
  protected $parseModeManager;

  /**
   * Stores the exposed input for this filter.
   *
   * @var array|null
   */
  public $validated_exposed_input = NULL;

  /**
   * The vocabulary storage.
   *
   * @var \Drupal\node\NodeStorageInterface
   */
  protected $nodeStorage;

  /**
   * The vocabulary storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $viewStorage;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The fields helper.
   *
   * @var \Drupal\search_api\Utility\FieldsHelperInterface
   */
  protected $fieldsHelper;


  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $plugin */
    $plugin = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $plugin->setParseModeManager($container->get('plugin.manager.search_api.parse_mode'));
    $plugin->setNodeStorage($container->get('entity_type.manager')->getStorage('node'));
    $plugin->setFieldsHelper($container->get('search_api.fields_helper'));
    $plugin->setViewStorage($container->get('entity_type.manager')->getStorage('view'));
    $plugin->currentUser = $container->get('current_user');
    return $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function defineOptions() {
    $options = parent::defineOptions();
    $options['value']['default'] = [];
    $options['operator']['default'] = 'or';
    $options['views_source_ids'] = ['default' => []];
    $options['sbf_fields'] = ['default' => []];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultExposeOptions() {
    parent::defaultExposeOptions();
  }

  protected function valueSubmit($form, FormStateInterface $form_state) {
    $form_state = $form_state;
  }

  /**
   * Retrieves the parse mode manager.
   *
   * @return \Drupal\search_api\ParseMode\ParseModePluginManager
   *   The parse mode manager.
   */
  public function getParseModeManager() {
    return $this->parseModeManager ?: \Drupal::service('plugin.manager.search_api.parse_mode');
  }

  /**
   * Sets the parse mode manager.
   *
   * @param \Drupal\search_api\ParseMode\ParseModePluginManager $parse_mode_manager
   *   The new parse mode manager.
   *
   * @return $this
   */
  public function setParseModeManager(ParseModePluginManager $parse_mode_manager) {
    $this->parseModeManager = $parse_mode_manager;
    return $this;
  }


  /**
   * Sets the Node Storage.
   *
   * @param \Drupal\node\NodeStorageInterface $nodestorage
   *   The new parse mode manager.
   *
   * @return $this
   */
  public function setNodeStorage(NodeStorageInterface $nodestorage) {
    $this->nodeStorage = $nodestorage;
    return $this;
  }


  public function setFieldsHelper(FieldsHelperInterface $fieldsHelper) {
    $this->fieldsHelper = $fieldsHelper;
    return $this;
  }


  /**
   * Sets the View Storage.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $viewstorage
   *   The new parse mode manager.
   *
   * @return $this
   */
  public function setViewStorage(EntityStorageInterface $viewstorage) {
    $this->viewStorage = $viewstorage;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    /* The plan:
    - Only allow fields that have somewhere in their property path a entity reference or are
    directly tied to a NODE entity except the nid, uuid. For flavors or any other data sources, including
    datasource id  == null (processor fields/aggregated fields) we need to traverse before even allowing the field
    to be configured. Also we need to re-check the field on queries in case the field definition changed?
    We can see/test \Drupal\search_api\Utility\TrackingHelper::getForeignEntityRelationsMap since that basically does something
    similar, even if protected... mimic the basics there
    - On Field selection/saving of settings also save the property path UNTIL a entity is hit, that way
    - we can save some time on the query? This has issues in case the field changes afterwards
    but that is true for even the full text search so maybe not needed
    - On query either reprocess the property paths again, or use the already processed subpaths we want.
    - Create a field on the fly with these cut/down properties (if cut down, if not we can reuse the existing fields!)
    maybe this \Drupal\search_api\Utility\FieldsHelperInterface::createFieldFromProperty ?
    - Evaluate the fields using the loaded item (the query ADO) and get the values for each. These are already mapped
    to each target field.
    - Generate a query using AND or OR (depending on the internal settings, so we need to expose that too?)
    querying against the real indexed field with the resolved from the query ADO values against these partial paths
    - For the exposed form, allow a select box or autocomplete be generated from a View using the extra form
    we have (which i can bring into the exposed one instead (better).
    - For testing, start first with a fixed UUID (or ID, we should allow both) and then go from there.
    */

    $fields = $this->getSbfNodeFields() ?? [];
    // Note we can not use a manyToOne class as base
    // because _search_api_views_handler_mapping() does not evaluate a fields full property path
    // but just the end type. That is Ok though. I could go deeper but might affect
    // the normal behavior of comparing a value against a value
    // instead of what we will do there which is a Node resolved against a property path (a field) against a value

    $form['sbf_fields'] = [
      '#type' => 'select',
      '#title' => $this->t('Node based Entity Reference (or in its property path) Fields that need to match.'),
      '#description' => $this->t('Select the fields that will be filtered against a Node or Node list.'),
      '#options' => $fields,
      '#size' => min(4, count($fields)),
      '#multiple' => TRUE,
      '#default_value' => $this->options['sbf_fields'],
      '#required' => TRUE,
    ];
  }

  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    parent::submitOptionsForm(
      $form, $form_state
    ); // TODO: Change the autogenerated stub
    $onefield = reset($form_state->getValue(['options','sbf_fields']));
    $data = $this->calculateEntityRelationsForField($onefield);
    $data = $data;
  }

  protected function valueForm(&$form, FormStateInterface $form_state) {
    $nodes = $this->value ? $this->nodeStorage->loadByProperties(['uuid' => $this->value]) : [];
    $form['value'] = [
      '#type' => 'sbf_entity_autocomplete_uuid',
      '#title' => $this->t('ADOs'),
      '#description' => $this->t('Enter a comma separated list of Archipelago Digital Objects.'),
      '#target_type' => 'node',
      '#tags' => TRUE,
      '#default_value' => $nodes,
      '#selection_handler' => 'default:nodewithstrawberry',
      '#validate_reference' => TRUE,
    ];

    $user_input = $form_state->getUserInput();
    if ($form_state->get('exposed') && !isset($user_input[$this->options['expose']['identifier']])) {
      $user_input[$this->options['expose']['identifier']] = $default_value;
      $form_state->setUserInput($user_input);
    }
  }

  protected function valueValidate($form, FormStateInterface $form_state) {
    $node_uuids = [];
    if ($values = $form_state->getValue(['options', 'value'])) {
      foreach ($values as $value) {
        $node_uuids[] = $value;
      }
      sort($node_uuids);
    }
    $form_state->setValue(['options', 'value'], $node_uuids);
  }



  public function hasExtraOptions() {
    return TRUE;
  }

  public function buildExtraOptionsForm(&$form, FormStateInterface $form_state) {
    $options = $this->getApplicationViewsAsOptions() ?? [];
      // We only do this when the form is displayed.
      if (empty($this->definition['views'])) {
        $form['views_source_ids'] = [
          '#type' => 'radios',
          '#title' => $this->t('View'),
          '#options' => $options,
          '#description' => $this->t('Select which View to use to show/generate ADOs list in the regular options .'),
          '#default_value' => $this->options['views_source_ids'],
        ];
      }
  }

  private function getApplicationViewsAsOptions() {
    $displays_entity_reference = Views::getApplicableViews('entity_reference_display');
    // Only key that allows to me get REST and FEEDS
    $displays_rest = Views::getApplicableViews('returns_response');
    $options = [];
    $displays = $displays_entity_reference + $displays_rest;
    foreach ($displays as $data) {
      [$view_id, $display_id] = $data;
      $view = $this->viewStorage->load($view_id);
      $display = $view->get('display');
      $options[$view_id . ':' . $display_id] = $view_id . ' - ' . $display[$display_id]['display_title'];
    }
    ksort($options);
    return $options;
  }



  public function query() {
    /* We need to resolve the Passed ADO against the data source/field
    @see \Drupal\search_api\Entity\Index::indexSpecificItems
    - Generate the Item using the Fields helper / I could try to do this only for the fields i need?
      - Not really, i just need to create fake fields and resolve them... bc
      - the applicable source paths might be valid values, but not already present fields.
      - See the tests e.g \Drupal\Tests\search_api\Kernel\System\FieldValuesExtractionTest::testPropertyValuesExtraction

    This looks promising!

     $object = $this->entities[3]->getTypedData();
    /** @var \Drupal\search_api\Item\FieldInterface[][] $fields */
    /*$fields = [
      'type' => [$this->fieldsHelper->createField($this->index, 'type')],
      'name' => [$this->fieldsHelper->createField($this->index, 'name')],
      'links:entity:name' => [
        $this->fieldsHelper->createField($this->index, 'links'),
        $this->fieldsHelper->createField($this->index, 'links_1'),
      ],
      'links:entity:links:entity:name' => [
        $this->fieldsHelper->createField($this->index, 'links_links'),
      ],
    ];
    $this->fieldsHelper->extractFields($object, $fields);

    - Call the preprocess alter/processors for these new fake fields
    - Ensemble the query
    - DOne.

    */
    $query = $this->getQuery();
    $nodes = $this->value ? $this->nodeStorage->loadByProperties(['uuid' => $this->value]) : [];
    $field_id = null;
    if (is_array($this->options['sbf_fields']) && !empty($this->options['sbf_fields'])) {
      $field_id = reset($this->options['sbf_fields']);
    }
    foreach ($nodes as $node) {
      if ($field_id) {
        $object = $node->getTypedData();
        $data = $this->calculateEntityRelationsForField($field_id);
        $fields = [
          $data[0]['property_path_to_foreign_entity']
          => $this->fieldsHelper->createField(
            $query->getIndex(), $node->uuid() . '-0'
          )
        ];
        $fields_values = $this->fieldsHelper->extractFields($object, $fields);
        $fields_values = $fields_values;
      }
    }


    $backend = $query->getIndex()->getServerInstance()->getBackend();
    $full_text = NULL;
    $type = NULL;
  }


  /**
   * Retrieves a list of all fields that contain in its path a Node Entity.
   *
   * @return string[]
   *   An options list of field identifiers mapped to their prefixed
   *   labels.
   */
  protected function getSbfNodeFields() {
    $fields = [];
    /** @var \Drupal\search_api\IndexInterface $index */
    $index = Index::load(substr($this->table, 17));

    $fields_info = $index->getFields();
    foreach ($fields_info as $field_id => $field) {
      //if (($field->getDatasourceId() == 'strawberryfield_flavor_datasource') && ($field->getType() == "integer")) {
      // Anything except text, fulltext or any solr_text variations. Also skip direct node id and UUIDs which would
      // basically return the same ADO as input filtered, given that those are unique.
      $property_path = $field->getPropertyPath();
      $datasource_id = $field->getDatasourceId();
      if(strpos($field->getType(), 'text') === false && ($property_path !== "nid" || $property_path !== "uuid")) {
        $field->getDataDefinition();
        // Now the hard part.
        // We need to know if the $field->getDatasourceId() == 'entity:node' and/or
        // one of the properties, from right to left resolves to an entity reference and stop there.
        // At this point, to be honest we can really only do that last part IF the
        // $field->getDatasourceId() != 'entity:node' given that on the opposite eventually
        // any field will resolve to a NODE.
        if ($field->getDatasourceId() !== 'entity:node') {
          // Also check whether the underlying property actually (still) exists.
          $property = NULL;
          if ($datasource_id === NULL
            || $index->isValidDatasource(
              $datasource_id
            )
          ) {
            $field->getLabel();
            $properties = $index->getPropertyDefinitions($datasource_id);

            $property = \Drupal::getContainer()
              ->get('search_api.fields_helper')
              ->retrieveNestedProperty($properties, $field->getPropertyPath());
            $property;
          }
        }
        else {
          if ($datasource_id === NULL
            || $index->isValidDatasource(
              $datasource_id
            )
          ) {
            $field->getLabel();
            $properties = $index->getPropertyDefinitions($datasource_id);

            $property = \Drupal::getContainer()
              ->get('search_api.fields_helper')
              ->retrieveNestedProperty($properties, $field->getPropertyPath());
            $property;
          }

          $fields[$field_id] = $field->getPrefixedLabel() . '('
            . $field->getFieldIdentifier() . ' ' . $property_path . ')';
        }
        $property_path_parts = explode(":", $property_path);
        if (end($property_path_parts) == "nid"
          || $property_path == 'parent_id'
        ) {
          $fields[$field_id] = $field->getPrefixedLabel() . '('
            . $field->getFieldIdentifier() . ')';
        }
      }
    }
    return $fields;
  }


  protected function calculateEntityRelationsForField($field_id) {
    /* We need to cache this folks. Too much energy to extract each
    time we need to query  */
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($index);
    /** @var \Drupal\search_api\IndexInterface $index */
    $index = Index::load(substr($this->table, 17));
    $field = $index->getField($field_id);
    $data = [];
    try {
      $datasource = $field->getDatasource();
    }
    catch (SearchApiException $e) {
      return [];
    }
    if (!$datasource) {
      return [];
    }

    $relation_info = [
      'datasource' => $datasource->getPluginId(),
      'entity_type' => NULL,
      'bundles' => NULL,
      'property_path_to_foreign_entity' => NULL,
    ];
    $seen_path_chunks = [];
    $property_definitions = $datasource->getPropertyDefinitions();
    $field_property = \Drupal\search_api\Utility\Utility::splitPropertyPath($field->getPropertyPath(), FALSE);
    for (; $field_property[0]; $field_property = \Drupal\search_api\Utility\Utility::splitPropertyPath($field_property[1] ?? '', FALSE)) {
      $property_definition = $this->fieldsHelper->retrieveNestedProperty($property_definitions, $field_property[0]);
      if (!$property_definition) {
        // Seems like we could not map it from the property path to some Typed
        // Data definition. In the absence of a better alternative, let's
        // simply disregard this field.
        break;
      }

      $seen_path_chunks[] = $field_property[0];

      if ($property_definition instanceof FieldItemDataDefinitionInterface
        && $property_definition->getFieldDefinition()->isComputed()) {
        // We cannot really deal with computed fields since we have no
        // knowledge about their internal logic. Thus we cannot process
        // this field any further.
        break;
      }

      if ($relation_info['entity_type'] && $property_definition instanceof FieldItemDataDefinitionInterface) {
        // Parent is an entity. Hence this level is fields of the entity.
        $cacheability->addCacheableDependency($property_definition->getFieldDefinition());

        $data[] = $relation_info + [
            'path_to_field' => $property_definition->getFieldDefinition()
              ->getName(),
          ];
        // We want only the last piece of the chunks?
        $seen_path_chunks = [];
      }

      $entity_reference = $this->isEntityReferenceDataDefinition($property_definition, $cacheability);
      if ($entity_reference) {
        // Rethinking this:
        // Once we touch an $entity_reference, only then i need to start tracking $seen_path_chunks
        // In other words. I only want the property chunk piece that comes AFTER a $relation_info['entity_type']
        // or inbetween $relation_info['entity_type']s (since i can not look forward.
        // Unfortunately, the nested "entity" property for entity reference
        // fields comes without a bundles restriction, so we need to copy the
        // bundles information from the level above (on the field itself), if
        // any.
        if ($relation_info['entity_type'] === $entity_reference['entity_type']
          && empty($entity_reference['bundles'])
          && !empty($relation_info['bundles'])
          && $field_property[0] === 'entity') {
          $entity_reference['bundles'] = $relation_info['bundles'];
        }
        $relation_info = $entity_reference;
        $relation_info['property_path_to_foreign_entity'] = implode(IndexInterface::PROPERTY_PATH_SEPARATOR, $seen_path_chunks);
        $relation_info['datasource'] = $datasource->getPluginId();
      }


      if ($property_definition instanceof ComplexDataDefinitionInterface) {
        /// Not even sure i need this?
        $property_definitions = $this->fieldsHelper->getNestedProperties($property_definition);
      }
      else {

        if (empty($data) && count($seen_path_chunks) > 0) {
          // Means we reached the root of the properties and there were no Entities/references inbetween
          // But means also the property path is already connected at the base (so invisible to this) to an entity
          // So we can use it directly
          $relation_info['property_path_to_foreign_entity'] = implode(IndexInterface::PROPERTY_PATH_SEPARATOR, $seen_path_chunks);
          $relation_info['datasource'] = $datasource->getPluginId();
          $data[] = $relation_info + [
              'path_to_field' => $seen_path_chunks[0]
            ];
        }
        // This item no longer has "nested" properties in its Typed Data
        // definition. Thus we cannot examine it any further than the current
        // point.
        break;
      }
    }
    /* Data will contain something like this in case of two properties.
    Ordered by occurrence which is great bc we want the last one only.
    Note. We can also here check if there is a bundle. Given that if there is NOT bundle
    any queried node will serve as input, but if there is one, we need to limit or
    even discard.
     * result =
    0 = {array} [5]
       entity_type = "node"
       bundles = {array} [0]
       property_path_to_foreign_entity = "field_descriptive_metadata:sbf_entity_reference_ispartof"
       datasource = "entity:node"
       path_to_field = "field_descriptive_metadata"
   1 = {array} [5]
      entity_type = "node"
      bundles = {array} [0]
      property_path_to_foreign_entity = "field_descriptive_metadata:sbf_entity_reference_ispartof:field_descriptive_metadata:sbf_entity_reference_ismemberof"
      datasource = "entity:node"
      path_to_field = "title"
     */

    return $data;
  }

  /**
   * Determines whether the given property is a reference to an entity.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $property_definition
   *   The property to test.
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface $cacheability
   *   A cache metadata object to track any caching information necessary in
   *   this method call.
   *
   * @return array
   *   This method will return an empty array if $property is not an entity
   *   reference. Otherwise it will return an associative array with the
   *   following structure:
   *   - entity_type: (string) The entity type to which $property refers.
   *   - bundles: (array) A list of bundles to which $property refers. In case
   *     specific bundles cannot be determined or the $property points to all
   *     the bundles, this key will contain an empty array.
   */
  protected function isEntityReferenceDataDefinition(DataDefinitionInterface $property_definition, RefinableCacheableDependencyInterface $cacheability): array {
    $return = [];

    if ($property_definition instanceof FieldItemDataDefinitionInterface
      && $property_definition->getFieldDefinition()->getType() === 'entity_reference') {
      $field = $property_definition->getFieldDefinition();
      $cacheability->addCacheableDependency($field);

      $return['entity_type'] = $field->getSetting('target_type');
      $field_settings = $field->getSetting('handler_settings');
      $return['bundles'] = $field_settings['target_bundles'] ?? [];
    }
    elseif ($property_definition instanceof EntityDataDefinitionInterface) {
      $return['entity_type'] = $property_definition->getEntityTypeId();
      $return['bundles'] = $property_definition->getBundles() ?: [];
    }
    return $return;
  }

}
