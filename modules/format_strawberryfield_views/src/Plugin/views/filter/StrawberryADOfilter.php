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
use Drupal\views\Views;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\search_api\Utility\FieldsHelperInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a filter that handles ADO/ADO UUIDs against any indexed subproperty.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("sbf_ado_filter")
 */
class StrawberryADOfilter extends FilterPluginBase {

  use SearchApiFilterTrait;

  protected $valueFormType = 'select';


  /**
   * @var array
   * Stores all operations which are available on the form.
   */
  protected $valueOptions = NULL;

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
  public function operators() {
    return [
      'or' => [
        'title' => $this->t('Is one of'),
        'short' => $this->t('or'),
        'short_single' => $this->t('='),
        'method' => 'opHelper',
        'values' => 1,
        'ensure_my_table' => 'helper',
      ],
      'and' => [
        'title' => $this->t('Is all of'),
        'short' => $this->t('and'),
        'short_single' => $this->t('='),
        'method' => 'opHelper',
        'values' => 1,
        'ensure_my_table' => 'helper',
      ],
      'not' => [
        'title' => $this->t('Is none of'),
        'short' => $this->t('not'),
        'short_single' => $this->t('<>'),
        'method' => 'opHelper',
        'values' => 1,
        'ensure_my_table' => 'helper',
      ],
      'empty' => [
        'title' => $this->t('Is empty (NULL)'),
        'method' => 'opEmpty',
        'short' => $this->t('empty'),
        'values' => 0,
      ],
      'not empty' => [
        'title' => $this->t('Is not empty (NOT NULL)'),
        'method' => 'opEmpty',
        'short' => $this->t('not empty'),
        'values' => 0,
      ],
    ];
  }


  /**
   * {@inheritdoc}
   */
  public function defineOptions() {
    $options = parent::defineOptions();

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
    //$this->options['expose']['sbf_type'] = ['default' => []];
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
    $this->calculateEntityRelationsForField($onefield);
  }


  public function hasExtraOptions() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getValueOptions() {
    // This should return the list of values based on the
    //$this->options['views_source_ids'];
    return $this->valueOptions;
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
    $query = $this->getQuery();
    $backend = $query->getIndex()->getServerInstance()->getBackend();
    $full_text = NULL;
    $type = NULL;
    // We only know how to join on Solr. All rest is bad poetry
    if ($backend instanceof \Drupal\search_api_solr\SolrBackendInterface) {
      $index_fields = $query->getIndex()->getFields(TRUE);
      /* @var \Drupal\views\Plugin\views\display\DisplayPluginBase[] $filters */
      $filters = $this->view->getHandlers('filter', NULL);
      $value = "";
      foreach ($filters as $filter_name => $filter) {
        if ($filter['plugin_id'] == 'search_api_fulltext') {
          // Reuse the Full Text Field's parse mode
          $parsemode = $filter['parse_mode'] ?? 'terms' ;
          /** @var \Drupal\search_api\ParseMode\ParseModeInterface $parse_mode */
          $parse_mode = $this->getParseModeManager()
            ->createInstance($parsemode);

          if (!$filter['exposed']) {
            $op = $filter['operator'];
          }
          $full_text = $query->getKeys();
          $type = 'fulltext';
        }
        elseif ($filter['plugin_id'] == 'sbf_advanced_search_api_fulltext') {
          $full_text = $query->getKeys();
          $type = 'advanced_fulltext';
        }
        if ($this->options['id'] == $filter_name) {
          // This is myself, break out.
          break;
        }
      }
      // If value == "" do nothing, no need to JOIN SBF for that.
      if ($type == 'fulltext' && $full_text && ((is_scalar($full_text) && strlen($full_text) > 0) || (is_array($full_text) && count($full_text) > 0 ))) {

        // Never ever make it easy Solr!
        // This is the Join Subquery, sadly not useful "directly" for Flavor Highlights IF the conjunction is AND
        // Because the AND implies matches across the union/intersection of main query and the Join
        // but OCR might only contain a few of these. the Idea is that the combination of all keys + searched against fields match
        // at the end the total.
        $subquery = $this->buildFlavorSubQuery($query, $parse_mode, $this->options['sbf_fields'], $full_text);
        // check the conjunctions, remove the #negations, change the ANDs to ORs.
        // processor_id
        $negation = FALSE;
        if (strlen($subquery) > 0 ) {
          // only if we have a subquery
          foreach ($full_text as $key => &$entry) {
            if (is_array($entry)) {
              if ($entry['#negation'] ?? FALSE) {
                unset($full_text[$key]);
                $negation = TRUE;
              }
              elseif (($entry['#conjunction'] ?? FALSE) == 'AND') {
                // Make it OR. could move the search term up but that would add
                // an extra foreach loop. I'm cheap.
                $entry['#conjunction'] = 'OR';
              }
            }
            elseif ($key == "#conjunction" && $entry == 'AND') {
              $entry = 'OR';
            }
          }
          if ($this->options['negation_default'] == 'omit' && $negation) {
            // In case of negation AND decision to return on negation return;
            return;
          }
          $subquery_hl = $this->buildFlavorSubQuery($query, $parse_mode, $this->options['sbf_fields'], $full_text);

          $join_structure = [
            'from' => 'its_parent_id',
            'to'   => 'its_nid',
            'v'    => $subquery,
            'hl'   => $subquery_hl,
          ];
          // 'hl' will be used by
          // \Drupal\strawberryfield\Plugin\search_api\processor\StrawberryFieldHighlight::highlightFlavorsFromIndex
          $this->getQuery()->setOption('sbf_join_flavor', $join_structure);
        }
      }
      elseif ($type == 'advanced_fulltext') {
        if ($subtitutions = $this->getQuery()->getOption('sbf_advanced_search_filter_join', NULL)) {
          foreach ($subtitutions as $placeholder => $subquery) {
            $join_structure[$placeholder] = [
              'from' => 'its_parent_id',
              'to'   => 'its_nid',
              'v'    => $subquery
            ];
          }
          $this->getQuery()->setOption('sbf_join_flavor_advanced', $join_structure);
        }
      }
    }
  }

  /**
   * @param \Drupal\search_api\Plugin\views\query\SearchApiQuery $query
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function buildFlavorSubQuery(SearchApiQuery $query, $parse_mode, array $queryable_fields, array|string $keys) {
    $solr_field_names = $query->getIndex()
      ->getServerInstance()
      ->getBackend()
      ->getSolrFieldNames($query->getIndex());
    // Damn Solr Search API...

    $index_fields = $query->getIndex()->getFields(TRUE);

    $settings = Utility::getIndexSolrSettings($query->getIndex());
    $language_ids = $query->getLanguages();
    $flat_keys = [];
    // If there are no languages set, we need to set them. As an example, a
    // language might be set by a filter in a search view.
    if (empty($language_ids)) {
      if (!$query->getSearchApiQuery()->hasTag('views') && $settings['multilingual']['limit_to_content_language']) {
        // Limit the language to the current language being used.
        $language_ids[] = \Drupal::languageManager()
          ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)
          ->getId();
      }
      else {
        // If the query is generated by views and/or the query isn't limited
        // by any languages we have to search for all languages using their
        // specific fields.
        $language_ids = array_keys(\Drupal::languageManager()->getLanguages());
      }
    }

    if ($settings['multilingual']['include_language_independent']) {
      $language_ids[] = LanguageInterface::LANGCODE_NOT_SPECIFIED;
    }

    $field_names = $query->getIndex()
      ->getServerInstance()
      ->getBackend()->getSolrFieldNamesKeyedByLanguage($language_ids, $query->getIndex());

    foreach (($queryable_fields ?? []) as $field) {
      if (isset($solr_field_names[$field])
        && 'twm_suggest' !== $solr_field_names[$field] & strpos(
          $solr_field_names[$field], 'spellcheck'
        ) !== 0
      ) {
        $index_field = $index_fields[$field];
        $boost = $index_field->getBoost() ? '^' . $index_field->getBoost()
          : '';
        $names = [];
        $first_name = reset($field_names[$field]);
        if (strpos($first_name, 't') === 0) {
          // Add all language-specific field names. This should work for
          // non Drupal Solr Documents as well which contain only a single
          // name.
          $names = array_values($field_names[$field]);
        }
        else {
          $names[] = $first_name;
        }
        $names = array_unique($names);
        foreach ($names as &$name) {
          $name = $name . $boost;
        }
      }
    }

    if (count($names)) {
      $flat_keys[] = \Drupal\search_api_solr\Utility\Utility::flattenKeys(
        $keys, $names,
        $parse_mode->getPluginId()
      );
    }
    return implode(" ", $flat_keys);
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
          if ($datasource_id === NULL || $index->isValidDatasource($datasource_id)) {
            $field->getLabel();
            $properties = $index->getPropertyDefinitions($datasource_id);

            $property = \Drupal::getContainer()
              ->get('search_api.fields_helper')
              ->retrieveNestedProperty($properties, $field->getPropertyPath());
            $property;
          }
        }
        else {
          if ($datasource_id === NULL || $index->isValidDatasource($datasource_id)) {
            $field->getLabel();
            $properties = $index->getPropertyDefinitions($datasource_id);

            $property = \Drupal::getContainer()
              ->get('search_api.fields_helper')
              ->retrieveNestedProperty($properties, $field->getPropertyPath());
            $property;
          }

          $fields[$field_id] = $field->getPrefixedLabel() . '('
            . $field->getFieldIdentifier() .  ''. $property_path .')';
        }
        $property_path_parts = explode(":", $property_path);
        if (end($property_path_parts) == "nid"
          || $property_path == 'parent_id'
        ) {
          $fields[$field_id] = $field->getPrefixedLabel() . '('
            . $field->getFieldIdentifier() . ')';
        }
      }
      //}
    }
    return $fields;
  }


  protected function calculateEntityRelationsForField($field_id) {
    /* We need to cache this folks. Too much energy to extract each time this stuff */
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
            'field_name' => $property_definition->getFieldDefinition()
              ->getName(),
          ];
      }

      $entity_reference = $this->isEntityReferenceDataDefinition($property_definition, $cacheability);
      if ($entity_reference) {
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
        $property_definitions = $this->fieldsHelper->getNestedProperties($property_definition);
      }
      else {
        // This item no longer has "nested" properties in its Typed Data
        // definition. Thus we cannot examine it any further than the current
        // point.
        break;
      }
    }
    /* Data will contain somthing like this in case of two properties.
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
       field_name = "field_descriptive_metadata"
   1 = {array} [5]
      entity_type = "node"
      bundles = {array} [0]
      property_path_to_foreign_entity = "field_descriptive_metadata:sbf_entity_reference_ispartof:field_descriptive_metadata:sbf_entity_reference_ismemberof"
      datasource = "entity:node"
      field_name = "title"
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

  public function adminSummary() {
    $this->valueOptions = [];
    // @TODO needs to be passed from the form itself, this is just to avoid
    // optgroups flattener failing
    return parent::adminSummary(); // TODO: Change the autogenerated stub
  }


}
