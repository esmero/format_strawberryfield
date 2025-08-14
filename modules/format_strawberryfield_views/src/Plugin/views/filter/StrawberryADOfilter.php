<?php

namespace Drupal\format_strawberryfield_views\Plugin\views\filter;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\TypedData\FieldItemDataDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\OptGroup;
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
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Render\RenderContext;

/**
 * Defines a filter that handles ADO/ADO UUIDs against any indexed subproperty.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("sbf_ado_filter")
 */
class StrawberryADOfilter extends InOperator /* FilterPluginBase */
{

  use SearchApiFilterTrait;

  protected $valueFormType = 'select';

  protected $alwaysMultiple = TRUE;

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
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;


  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container,
    array $configuration, $plugin_id, $plugin_definition
  ) {
    /** @var static $plugin */
    $plugin = parent::create(
      $container, $configuration, $plugin_id, $plugin_definition
    );

    $plugin->setNodeStorage(
      $container->get('entity_type.manager')->getStorage('node')
    );
    $plugin->setFieldsHelper($container->get('search_api.fields_helper'));
    $plugin->setViewStorage(
      $container->get('entity_type.manager')->getStorage('view')
    );
    $plugin->setCache($container->get('cache.default'));
    $plugin->currentUser = $container->get('current_user');
    return $plugin;
  }

  /**
   * Returns information about the available operators for this filter.
   *
   * @return array[]
   *   An associative array mapping operator identifiers to their information.
   *   The operator information itself is an associative array with the
   *   following keys:
   *   - title: The translated title for the operator.
   *   - short: The translated short title for the operator.
   *   - values: The number of values the operator requires as input.
   */
  public function operators() {
    return [
      'and' => [
        'title'  => $this->t('Contains all of the resolved values'),
        'short'  => $this->t('and'),
        'values' => 1,
      ],
      'or'  => [
        'title'  => $this->t('Contains any of the resolved values'),
        'short'  => $this->t('or'),
        'values' => 1,
      ],
      'not' => [
        'title'  => $this->t('Contains none of the resolved values'),
        'short'  => $this->t('not'),
        'values' => 1,
      ],
    ];
  }


  /**
   * {@inheritdoc}
   */
  public function defineOptions() {
    $options = parent::defineOptions();
    $options['value']['default'] = [];
    $options['operator']['default'] = 'or';
    $options['internal_operator']['default'] = 'and';
    $options['views_source_ids'] = ['default' => []];
    $options['sbf_fields'] = ['default' => []];
    $options['expose']['contains']['value_form_type'] = ['default' => 'select'];
    $options['expose']['contains']['placeholder'] = ['default' => ''];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultExposeOptions() {
    parent::defaultExposeOptions();
    $this->options['expose']['reduce'] = FALSE;
    $this->options['expose']['value_form_type'] = 'select';
    $this->options['expose']['placeholder'] = '- Select a Digital Object -';
  }

  protected function valueSubmit($form, FormStateInterface $form_state) {
    $form_state = $form_state;
  }

  /**
   * Sets the Node Storage.
   *
   * @param \Drupal\node\NodeStorageInterface $nodestorage
   *   The node storage.
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
   *   The view Storage.
   *
   * @return $this
   */
  public function setViewStorage(EntityStorageInterface $viewstorage) {
    $this->viewStorage = $viewstorage;
    return $this;
  }

  /**
   * Sets the Cache Backed.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend. Use to store complex calculations of property paths.
   *
   * @return $this
   */
  public function setCache(CacheBackendInterface $cache) {
    $this->cache = $cache;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $fields = $this->getSbfNodeFields() ?? [];
    // Note we can not use a manyToOne class as base
    // because _search_api_views_handler_mapping() does not evaluate a fields full property path
    // but just the end type. That is Ok though. I could go deeper but might affect
    // the normal behavior of comparing a value against a value
    // instead of what we will do there which is a Node resolved against a property path (a field) against a value

    $form['sbf_fields'] = [
      '#type' => 'select',
      '#title' => $this->t(
        'Node based Entity Reference (or in its property path) Fields that need to match.'
      ),
      '#description' => $this->t(
        'Select the fields that will be filtered against the corresponding values resolved from the Node used as query input.'
      ),
      '#options' => $fields,
      '#size' => min(6, count($fields)),
      '#multiple' => TRUE,
      '#default_value' => $this->options['sbf_fields'],
      '#required' => TRUE,
    ];
  }

  /**
   * Shortcut to display the operator form.
   */
  public function showOperatorForm(&$form, FormStateInterface $form_state) {
    $this->operatorForm($form, $form_state);
    $form['operator']['#prefix'] = '<div class="views-group-box views-left-30">';
    $form['operator']['#suffix'] = '</div>';
    $form['operator']['#title'] = $this->t('Resolved Values Operator');
    $form['internal_operator'] = [
      '#type' => 'radios',
      '#title' => $this->t('Interfield Operator'),
      '#default_value' => $this->options['internal_operator'] ?? 'or',
      '#options' => [
        'and'=>'AND',
        'or'=>'OR',
      ]
    ];
    $form['internal_operator']['#prefix'] = '<div class="views-group-box views-left-30">';
    $form['internal_operator']['#suffix'] = '</div>';
  }


  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    parent::submitOptionsForm(
      $form, $form_state
    );
    // Just a quick way of regenerating the caches, just in case.
    $this->getEntityRelationsForFields(
      $form_state->getValue(['options', 'sbf_fields']), FALSE
    );
  }

  protected function valueForm(&$form, FormStateInterface $form_state) {
    // Always remember diego. If this is exposed then the same Form shown during
    // Config will be shown for the end user
    // Not what we want!
    // problem here. Views will return an ID, we want UUIDs ...

    /* This does not allow mixed Ids and UUIDs.. i guess that is OK */
    $nodes = [];
    $this->value = is_array($this->value) ? $this->value : (array) $this->value;
    if (array_filter($this->value, 'is_numeric') === $this->value) {
      $nodes = $this->value ? $this->nodeStorage->loadByProperties(
        ['nid' => $this->value]
      ) : [];
    }
    else {
      $nodes = $this->value ? $this->nodeStorage->loadByProperties(
        ['uuid' => $this->value]
      ) : [];
    }
    if (!$form_state->get('exposed')) {
      $form['value'] = [
        '#type' => 'sbf_entity_autocomplete_uuid',
        '#title' => $this->t('ADOs'),
        '#description' => $this->t(
          'Enter a comma separated list of Archipelago Digital Objects.'
        ),
        '#target_type' => 'node',
        '#tags' => TRUE,
        '#default_value' => $nodes,
        '#selection_handler' => 'default:nodewithstrawberry',
        '#validate_reference'=> TRUE,
        '#maxlength' => 300,
      ];
    }
    elseif ($this->isExposed()) {
      if ($this->options['views_source_ids']) {
        $view_parts = explode(':', $this->options['views_source_ids'] ?? '');
        $form['value'] = [];
        if (count($view_parts) == 2) {
          if ($this->options['expose']['value_form_type'] == 'select') {
            // only call the options if we are going to show all of them
            $options = $this->readExposedOptionsForSelectFromView();
            $form['value'] = [
              '#type' => $this->options['expose']['value_form_type'],
              '#title' => $this->options['expose']['placeholder'],
              '#options' => $options,
            ];
            $form_value_selection = [];
          }
          else {
            $form_value_selection = [
              '#selection_handler'  => 'solr_views',
              '#validate_reference' => TRUE,
              '#selection_settings' => [
                'view' => [
                  'view_name' => $view_parts[0],
                  'display_name' => $view_parts[1],
                  'arguments' => [],
                ],
              ],
            ];
          }
        }
        else {
          $form_value_selection = [
            '#target_type' => 'node',
            '#tags' => TRUE,
            '#selection_handler' => 'default:nodewithstrawberry',
            '#validate_reference' => TRUE,
          ];
        }
      }
      if (!empty($this->options['expose']['placeholder']) && $this->options['expose']['value_form_type'] !== 'select') {
        $form['value']['#attributes']['placeholder'] = $this->options['expose']['placeholder'];
      }

      $form['value'] = $form['value'] + [
          '#type'        => 'entity_autocomplete',
          '#title'       => t('Select an ADO'),
          '#target_type' => 'node',
        ] + $form_value_selection;
    }
  }

  protected function valueValidate($form, FormStateInterface $form_state) {
    $node_uuids = [];
    if ($values = $form_state->getValue(['options', 'value'])) {
      if (!is_array($values)) { (array) $values;}
      foreach ($values as $value) {
        $node_uuids_or_ids[] = $value;
      }
      sort($node_uuids_or_ids);
    }
    $form_state->setValue(['options', 'value'], $node_uuids_or_ids);
  }

  public function hasExtraOptions() {
    return TRUE;
  }


  /**
   * {@inheritdoc}
   */
  public function buildExposeForm(&$form, FormStateInterface $form_state) {
    parent::buildExposeForm($form, $form_state);

    $form['expose']['placeholder'] = [
      '#type' => 'textfield',
      '#default_value' => $this->options['expose']['placeholder'],
      '#title' => $this->t('Placeholder'),
      '#size' => 40,
      '#description' => $this->t(
        'Hint text that appears inside the field when empty when using the autocomplete widget'
      ),
    ];

    $form['expose']['value_form_type'] = [
      '#type' => 'radios',
      '#default_value' => $this->options['expose']['value_form_type'],
      '#options'  => [
        'autocomplete' => 'autocomplete',
        'select' => 'select'
      ],
      '#title' => $this->t('Type of exposed Widget'),
      '#description'=> $this->t(
        'Either a text autocomplete field or a Select. Select requires a View driving the list. See extra settings for this field.'
      ),
    ];
    // No need to reduce here bc the options are driven by a View. The admin
    // is responsible for reducing what is available.
    unset($form['expose']['reduce']);
  }


  public function buildExtraOptionsForm(&$form, FormStateInterface $form_state
  ) {
    $options = $this->getApplicationViewsAsOptions() ?? [];
    // We only do this when the form is displayed.
    $options['null'] = '- Do not use a Views -';
    if (empty($this->definition['views'])) {
      $form['views_source_ids'] = [
        '#type' => 'radios',
        '#title' => $this->t('View'),
        '#options' => $options,
        '#description' => $this->t(
          'Select which View to use to show/generate ADOs list in the regular options .'
        ),
        '#default_value' => $this->options['views_source_ids'],
      ];
      $form['views_source_inherit_relationship'] = [
        '#type' => 'checkbox',
        '#title' => $this->t(
          'Inherit this Views\' Relationships or Context'
        ),
        '#description' => $this->t(
          'This allows the main View to pass its Contextual Values to the Views that generates the Exposed Options.'
        ),
        '#default_value' => $this->options['views_source_inherit_relationship'],
      ];
    }
  }

  private function getApplicationViewsAsOptions() {
    $displays_entity_reference = Views::getApplicableViews(
      'entity_reference_display'
    );
    // Only key that allows to me get REST and FEEDS
    $displays_rest = Views::getApplicableViews('returns_response');
    $displays = $displays_entity_reference + $displays_rest;
    foreach ($displays as $data) {
      [$view_id, $display_id] = $data;
      $view = $this->viewStorage->load($view_id);
      $display = $view->get('display');
      $options[$view_id . ':' . $display_id] = $view_id . ' - '
        . $display[$display_id]['display_title'];
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
    if (empty($this->value)) {
      return;
    }
    // Select boxes will always generate a single value.
    // I could check here or cast sooner on validation?
    if (!is_array($this->value)) {
      $this->value = (array) $this->value;
    }

    $query = $this->getQuery();
    if (array_filter($this->value, 'is_numeric') === $this->value) {
      $nodes = $this->value ? $this->nodeStorage->loadByProperties(
        ['nid' => $this->value]
      ) : [];
    }
    else {
      $nodes = $this->value ? $this->nodeStorage->loadByProperties(
        ['uuid' => $this->value]
      ) : [];
    }

    $data = $this->getEntityRelationsForFields($this->options['sbf_fields']);
    $resolved_values = [];
    foreach ($nodes as $node) {
      foreach ($data as $field_id => $field_data) {
        $field = $query->getIndex()->getField($field_id);
        $object = $node->getTypedData();
        if (isset($field_data['path_to_resolve'])) {
          $info = [
            'label'         => 'nan',
            'type'          => $field->getType(),
            'datasource_id' => $field->getDatasourceId(),
            'property_path' => $field_data['path_to_resolve'],
          ];
          $fields = [
            $field_data['path_to_resolve']
            => [
              $this->fieldsHelper->createField(
                $query->getIndex(), 'fake_id', $info
              ),
            ],
          ];
          $this->fieldsHelper->extractFields($object, $fields);
          foreach ($fields as $property_path => $property_fields) {
            foreach ($property_fields as $field) {
              $field_values = $field->getValues();
              sort($field_values);
              if (!isset($field_values[$property_path])) {
                $resolved_values[$field_id] = array_unique($field_values);
              }
            }
          }
        }
      }
    }
    if (empty($resolved_values)) {
      return;
    }
    $internal_operator = $this->options['internal_operator'] ?? 'OR';
    $internal_operator = strtoupper($internal_operator);
    $condition_group = $this->getQuery()->createConditionGroup($internal_operator);
    $this->getQuery()->addConditionGroup(
        $condition_group, $this->options['group']
    );


    foreach($resolved_values as $field_id => $field_values) {
      if ($this->operator !== 'and') {
        $operator = $this->operator === 'not' ? 'NOT IN' : 'IN';
        if (!empty($field_values)) {
          $condition_group->addCondition(
            $field_id, $field_values, $operator, $this->options['group']
          );
        }
      }
      else {
        foreach ((array) $field_values as $value) {
          $condition_group->addCondition($field_id, $value, '=');
        }
      }
    }
    return;
  }


  public function validate() {
    $this->getValueOptions();
    $errors = parent::validate();

    if (!in_array($this->operator, $this->operatorValues(1))) {
      $errors[] = $this->t(
        'The operator is invalid on filter: @filter.',
        ['@filter' => $this->adminLabel(TRUE)]
      );
    }
    if (is_array($this->value)) {
      if (!isset($this->valueOptions)) {
        // Don't validate if there are none value options provided, for example for special handlers.
        return $errors;
      }
      if ($this->options['exposed'] && !$this->options['expose']['required']
        && empty($this->value)
      ) {
        // Don't validate if the field is exposed and no default value is provided.
        return $errors;
      }

      // Some filter_in_operator usage uses optgroups forms, so flatten it.
      $flat_options = OptGroup::flattenOptions($this->valueOptions);

      // Remove every element which is not known.
      foreach ($this->value as $value) {
        if (!isset($flat_options[$value])) {
          unset($this->value[$value]);
        }
      }
      // Choose different kind of output for 0, a single and multiple values.
      if (count($this->value) == 0) {
        $errors[] = $this->t(
          'No valid values found on filter: @filter.',
          ['@filter' => $this->adminLabel(TRUE)]
        );
      }
    }
    elseif (!empty($this->value) && !is_scalar($this->value)) {
      // We allow a single scalar value to pass trough. We will cast back into array
      // when/if needed. This is because we allow a select box to be used too.
      $errors[] = $this->t(
        'The value @value is not an array for @operator on filter: @filter', [
          '@value'    => var_export($this->value, TRUE),
          '@operator' => $this->operator,
          '@filter'   => $this->adminLabel(TRUE),
        ]
      );
    }
    return $errors;
  }

  public function validateExposed(&$form, FormStateInterface $form_state) {
    // Only validate exposed input.
    if (empty($this->options['exposed'])
      || empty($this->options['expose']['identifier'])
    ) {
      return;
    }

    $identifier = $this->options['expose']['identifier'];
    $input = $form_state->getValue($identifier);

    if ($this->options['is_grouped'] && isset($this->options['group_info']['group_items'][$input])) {
      $this->operator = $this->options['group_info']['group_items'][$input]['operator'];
      $input = $this->options['group_info']['group_items'][$input]['value'];
    }

    $node_uuids_or_ids = [];
    $values = (array) $form_state->getValue($identifier);


    if ($values &&
      (!$this->options['is_grouped'] && ($this->options['expose']['value_form_type'] != 'select' && $input != 'All')) ||
      ($this->options['is_grouped'] && ($input != 'All'))
    ) {
      foreach ($values as $value) {
        $node_uuids_or_ids[] = is_scalar($value) ? $value : NULL;
      }
    }
    $node_uuids_or_ids = array_filter($node_uuids_or_ids);
    if ($node_uuids_or_ids) {
      $this->validated_exposed_input = $node_uuids_or_ids;
    }
  }


  public function acceptExposedInput($input) {
    $rc = parent::acceptExposedInput($input);

    if ($rc) {
      // If we have previously validated input, override.
      if (isset($this->validated_exposed_input)) {
        $this->value = $this->validated_exposed_input;
      }
    }

    return $rc;
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
      if (strpos($field->getType(), 'text') === FALSE
        && ($property_path !== "nid" || $property_path !== "uuid")
      ) {
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
        $property_path_parts = explode(":", $property_path ?? '');
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

  protected function getEntityRelationsForFields($fields, $cached = TRUE) {
    $cacheid = md5(
      $this->getIndex()->id() . $this->field . $this->view->id()
      . $this->view->current_display
    );
    $cid = "format_strawberryfield_views:{$cacheid}:property_fields";
    if ($cached) {
      $cache = $this->cache->get($cid);
      if ($cache) {
        return $cache->data;
      }
    }
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($this->getIndex());
    $field_data = [];
    foreach ($fields as $field_id) {
      $field_data[$field_id] = $this->calculateEntityRelationsForField(
        $field_id, $cacheability
      );
    }
    $this->cache->set(
      $cid, $field_data, $cacheability->getCacheMaxAge(),
      $cacheability->getCacheTags()
    );
    return $field_data;
  }


  protected function calculateEntityRelationsForField($field_id, $cacheability
  ) {
    /* We need to cache this folks. Too much energy to extract each
    time we need to query  */
    // $cacheability is passed by reference bc object and stuff.

    $index = Index::load(substr($this->table, 17));
    /** @var \Drupal\search_api\IndexInterface $index */

    $field = $index->getField($field_id);
    $data = [];
    try {
      $datasource = $field->getDatasource();
    } catch (SearchApiException $e) {
      return [];
    }
    if (!$datasource) {
      return [];
    }
    // path_to_resolve is not added here so we can actually + the arrays.
    $relation_info = [
      'datasource'      => $datasource->getPluginId(),
      'entity_type'     => NULL,
      'type'            => $field->getType(),
      'bundles'         => NULL,
    ];
    $seen_path_chunks = [];
    $usable_path_chunks = [];
    $property_definitions = $datasource->getPropertyDefinitions();
    $field_property = \Drupal\search_api\Utility\Utility::splitPropertyPath(
      $field->getPropertyPath(), FALSE
    );
    for (
    ; $field_property[0];
      $field_property = \Drupal\search_api\Utility\Utility::splitPropertyPath(
        $field_property[1] ?? '', FALSE
      )
    ) {
      $property_definition = $this->fieldsHelper->retrieveNestedProperty(
        $property_definitions, $field_property[0]
      );
      if (!$property_definition) {
        // Seems like we could not map it from the property path to some Typed
        // Data definition. In the absence of a better alternative, let's
        // simply disregard this field.
        break;
      }

      $seen_path_chunks[] = $usable_path_chunks[] = $field_property[0];

      if ($property_definition instanceof FieldItemDataDefinitionInterface
        && $property_definition->getFieldDefinition()->isComputed()
      ) {
        // We cannot really deal with computed fields since we have no
        // knowledge about their internal logic. Thus we cannot process
        // this field any further.
        break;
      }

      if ($relation_info['entity_type']
        && $property_definition instanceof FieldItemDataDefinitionInterface
      ) {
        // Parent is an entity. Hence this level is fields of the entity.
        $cacheability->addCacheableDependency(
          $property_definition->getFieldDefinition()
        );
        // We want only the last piece of the chunks?
        $usable_path_chunks = [];
        $usable_path_chunks[] = $field_property[0];
      }

      $entity_reference = $this->isEntityReferenceDataDefinition(
        $property_definition, $cacheability
      );
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
          && $field_property[0] === 'entity'
        ) {
          $entity_reference['bundles'] = $relation_info['bundles'];
        }
        $relation_info = $entity_reference;
        // Not used but good for debugging
        $relation_info['property_path_to_foreign_entity'] = implode(
          IndexInterface::PROPERTY_PATH_SEPARATOR, $seen_path_chunks
        );
        $relation_info['datasource'] = $datasource->getPluginId();
      }


      if ($property_definition instanceof ComplexDataDefinitionInterface) {
        /// Not even sure i need this?
        $property_definitions = $this->fieldsHelper->getNestedProperties(
          $property_definition
        );
      }
      else {

        if (empty($data) && count($seen_path_chunks) > 0) {
          // Means we reached the root of the properties and there were no Entities/references inbetween
          // But means also the property path is already connected at the base (so invisible to this) to an entity
          // So we can use it directly
          $relation_info['full_property_path'] = implode(
            IndexInterface::PROPERTY_PATH_SEPARATOR, $seen_path_chunks
          );
          $relation_info['datasource'] = $datasource->getPluginId();
        }
        // This item no longer has "nested" properties in its Typed Data
        // definition. Thus we cannot examine it any further than the current
        // point.
        break;
      }
    }
    $data = $relation_info + [
        'path_to_resolve' => implode(
          IndexInterface::PROPERTY_PATH_SEPARATOR, $usable_path_chunks
        ),
      ];
    /* data looks like and we really only need `path_to_resolve`
    result = {array} [6]
 entity_type = "node"
 bundles = {array} [0]
 property_path_to_foreign_entity = "field_descriptive_metadata:sbf_entity_reference_ismemberof"
 datasource = "entity:node"
 full_property_path = "field_descriptive_metadata:sbf_entity_reference_ismemberof:field_descriptive_metadata:digital_object_type"
 path_to_resolve = "field_descriptive_metadata:digital_object_type"
    */

    return $data;
  }

  /**
   * Determines whether the given property is a reference to an entity.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface           $property_definition
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
  protected function isEntityReferenceDataDefinition(DataDefinitionInterface $property_definition,
    RefinableCacheableDependencyInterface $cacheability
  ): array {
    $return = [];

    if ($property_definition instanceof FieldItemDataDefinitionInterface
      && $property_definition->getFieldDefinition()->getType()
      === 'entity_reference'
    ) {
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

  protected function readExposedOptionsForSelectFromView(): array {
    $view_parts = [];
    $options = [];
    if ($this->options['views_source_ids']) {
      $view_parts = explode(':', $this->options['views_source_ids'] ?? '');
    }
    if (count($view_parts) == 2) {
      $view = $this->viewStorage->load($view_parts[0]);
      if ($view) {
        $display = $view ? $view->getDisplay($view_parts[1]) : NULL;
        $executable = $view->getExecutable();
        /** @var \Drupal\views\ViewExecutable $executable */
        if ($display) {
          $executable->setDisplay($view_parts[1]);
          //$executable->setArguments(array_values($arguments));
          $views_validation = $executable->validate();

          // Check if we need to inherit this views arguments and pass them to
          // the exposed options generating one.
          $args = $this->options['views_source_inherit_relationship'] ? $this->view->args : [];
          if (!empty($args)) {
            $executable->setArguments($args);
          }

          if (empty($views_validation)) {
            try {
              $this->getRenderer()->executeInRenderContext(
                new RenderContext(),
                function () use ($executable, $view_parts) {
                  // Damn view renders forms and stuff. GOSH!
                  $executable->execute($view_parts[1]);
                }
              );
            } catch (\InvalidArgumentException $exception) {
              error_log('Views failed to render' . $exception->getMessage());
              $exception->getMessage();
            }

            $total = $executable->pager->getTotalItems() != 0
              ? $executable->pager->getTotalItems()
              : count(
                $executable->result
              );
            $current_page = $executable->pager->getCurrentPage();
            $num_per_page = $executable->pager->getItemsPerPage();
            $offset = $executable->pager->getOffset();

            foreach ($executable->result as $resultRow) {
              if ($resultRow instanceof
                \Drupal\search_api\Plugin\views\ResultRow
              ) {
                //@TODO move to its own method\
                if ($resultRow->_item) {
                  $node = $resultRow->_item->getOriginalObject()->getValue() ??
                    NULL;
                  if ($node) {
                    $options[$node->uuid()] = $node->label();
                  }
                }
              }
            }
          }
        }
      }
    }
    return $options;
  }

}
