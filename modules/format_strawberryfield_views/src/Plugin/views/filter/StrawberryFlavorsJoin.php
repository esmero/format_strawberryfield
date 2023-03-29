<?php

namespace Drupal\format_strawberryfield_views\Plugin\views\filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\ParseMode\ParseModePluginManager;
use Drupal\search_api\Plugin\views\filter\SearchApiFulltext;
use Drupal\search_api\Plugin\views\query\SearchApiQuery;
use Drupal\search_api_solr\Utility\Utility;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use \Drupal\search_api\Plugin\views\filter\SearchApiFilterTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a filter for Joining Full Text searches to Strawberry Flavor Data Sources.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("sbf_flavors_join")
 */
class StrawberryFlavorsJoin extends FilterPluginBase {

  use SearchApiFilterTrait;

  /**
   * The parse mode manager.
   *
   * @var \Drupal\search_api\ParseMode\ParseModePluginManager|null
   */
  protected $parseModeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $plugin */
    $plugin = parent::create($container, $configuration, $plugin_id, $plugin_definition);

    $plugin->setParseModeManager($container->get('plugin.manager.search_api.parse_mode'));
    return $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function defineOptions() {
    $options = parent::defineOptions();

    $options['operator']['default'] = 'or';
    $options['join_fields'] = ['default' => []];
    $options['sbf_fields'] = ['default' => []];
    $options['expose']['sbf_type'] = ['default' => []];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultExposeOptions() {
    parent::defaultExposeOptions();
    $this->options['expose']['sbf_type'] = ['default' => []];
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
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
    $fields = $this->getSbfFulltextFields() ?? [];
    $join_fields = $this->getSbfNidFields() ?? [];
    $form['sbf_fields'] = [
      '#type' => 'select',
      '#title' => $this->t('Strawberry Flavor fields that need to match.'),
      '#description' => $this->t('Select the fields that will be searched inside a Strawberry Flavor Document before Joining.'),
      '#options' => $fields,
      '#size' => min(4, count($fields)),
      '#multiple' => TRUE,
      '#default_value' => $this->options['sbf_fields'],
      '#required' => TRUE,
    ];
    $form['join_fields'] = [
      '#type' => 'select',
      '#title' => $this->t('Strawberry Flavor fields referencing a parent Node ID to be used for Joining.'),
      '#description' => $this->t('Select the fields that reference Parent Nodes or ADOs to be used to Join the results.'),
      '#options' => $join_fields,
      '#size' => min(4, count($join_fields)),
      '#multiple' => TRUE,
      '#default_value' => $this->options['join_fields'],
      '#required' => TRUE,
    ];

  }


  public function query() {
    $query = $this->getQuery();
    $backend = $query->getIndex()->getServerInstance()->getBackend();
    $full_text = NULL;
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
        }
        if ($this->options['id'] == $filter_name) {
          // This is myself, break out.
          break;
        }
      }
      // If value == "" do nothing, no need to JOIN SBF for that.
      if ($full_text && ((is_scalar($full_text) && strlen($full_text) > 0) || (is_array($full_text) && count($full_text) > 0 ))) {
        $subquery = $this->buildFlavorSubQuery($query, $parse_mode, $this->options['sbf_fields'],$full_text);
        if (strlen($subquery) > 0 ) {
          $join_structure = [
            'from' => 'its_parent_id',
            'to'   => 'its_nid',
            'v'    => $subquery
          ];
          $this->getQuery()->setOption('sbf_join_flavor', $join_structure);
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
   * Retrieves a list of all available fulltext fields.
   *
   * @return string[]
   *   An options list of fulltext field identifiers mapped to their prefixed
   *   labels.
   */
  protected function getSbfFulltextFields() {
    $fields = [];
    /** @var \Drupal\search_api\IndexInterface $index */
    $index = Index::load(substr($this->table, 17));

    $fields_info = $index->getFields();
    foreach ($index->getFulltextFields() as $field_id) {
      if ($fields_info[$field_id]->getDatasourceId() == 'strawberryfield_flavor_datasource') {
        $fields[$field_id] = $fields_info[$field_id]->getPrefixedLabel() . '('.  $fields_info[$field_id]->getFieldIdentifier() .')';
      }
    }

    return $fields;
  }


  /**
   * Retrieves a list of all available fulltext fields.
   *
   * @return string[]
   *   An options list of fulltext field identifiers mapped to their prefixed
   *   labels.
   */
  protected function getSbfNidFields() {
    $fields = [];
    /** @var \Drupal\search_api\IndexInterface $index */
    $index = Index::load(substr($this->table, 17));

    $fields_info = $index->getFields();
    foreach ($fields_info as $field_id => $field) {
      if (($field->getDatasourceId() == 'strawberryfield_flavor_datasource') && ($field->getType() == "integer")) {
        $property_path = $field->getPropertyPath();
        $property_path_parts = explode(":", $property_path);
        if (end($property_path_parts) == "nid" || $property_path == 'parent_id') {
          $fields[$field_id] = $field->getPrefixedLabel() . '('
            . $field->getFieldIdentifier() . ')';
        }
      }
    }
    return $fields;
  }

}
