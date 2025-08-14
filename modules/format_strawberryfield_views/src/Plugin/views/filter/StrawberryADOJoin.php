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
 * Defines a filter for Joining ADOs to ADOs.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("sbf_ado_join")
 */
class StrawberryADOJoin extends FilterPluginBase {

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
    $options['join_field_to'] = ['default' => NULL];
    $options['ado_fields'] = ['default' => []];
    $options['negation_default'] = ['default' => ['omit']];
    $options['ado_type'] = ['default' => ''];
    $options['ado_type_fields'] = ['default' => NULL];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultExposeOptions() {
    parent::defaultExposeOptions();
    $this->options['expose']['ado_type'] = ['default' => ''];
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
    $fields = $this->getADOFulltextFields() ?? [];
    $join_fields = $this->getADONidFields() ?? [];
    $type_fields = $this->getADOTypeFields() ?? [];
    $form['ado_fields'] = [
      '#type' => 'select',
      '#title' => $this->t('ADO(node) fields that need to match.'),
      '#description' => $this->t('Select the fields that will be searched inside an ADO before Joining.'),
      '#options' => $fields,
      '#size' => min(4, count($fields)),
      '#multiple' => TRUE,
      '#default_value' => $this->options['ado_fields'],
      '#required' => TRUE,
    ];
    $form['join_field_to'] = [
      '#type' => 'select',
      '#title' => $this->t('ADO field Holding the main Node ID to join against.'),
      '#description' => $this->t('Select the fields that holds the ADOs Drupal NODE ID to be used to Join the results against. This fields need to be integers'),
      '#options' => $join_fields,
      '#multiple' => false,
      '#default_value' => $this->options['join_field_to'],
      '#required' => TRUE,
    ];
    $form['join_fields'] = [
      '#type' => 'select',
      '#title' => $this->t('ADO fields referencing a parent Node ID to be used for Joining (Join Fields).'),
      '#description' => $this->t('Select the fields that reference Parent Nodes or ADOs to be used to Join the results against the main Node ID. This fields need to be integers'),
      '#options' => $join_fields,
      '#size' => min(4, count($join_fields)),
      '#multiple' => TRUE,
      '#default_value' => $this->options['join_fields'],
      '#required' => TRUE,
    ];
    $form['negation_default'] = [
      '#type' => 'select',
      '#title' => $this->t('How/if at all to query Other ADOs when a negation is present.'),
      '#description' => $this->t('Because the nature of many to one of ADOs (e.g CWS with Children) a
      negation in the query string might still bring up some ADOs where that negation does not apply ending in ADOs/Nodes (because of the join) being added to the results that -in the
       strict sense of something not being present in a CWS- might be misleading. This setting allows to decide what to do on a negation'),
      '#options' => [
        'omit' => $this->t('Do not join ADOs in the presence of a negation'),
        'include' => $this->t('Join ADOs in the presence of a negation even if that brings more results back'),
      ],
      '#default_value' => $this->options['negation_default'],
      '#required' => TRUE,
    ];
    $form['ado_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Comma separated list of ADO "type" to join.'),
      '#description' => $this->t('If empty all ADOs that match/have a child to parent relation (based on the Join Fields) will be searched. You can limit that by e.g adding here <em>Photograph</em> to restrict it to those ADOs of type Photograph'),
      '#default_value' => $this->options['ado_type'],
      '#required' => FALSE,
    ];
    $form['ado_type_fields'] = [
      '#type' => 'select',
      '#title' => $this->t('ADO field Containing the ADO Type value.'),
      '#description' => $this->t('Select the field that holds the value for the "type" json key. Will have no effect if no value was entered for ADO Type in the previous form entry.'),
      '#options' => $type_fields,
      '#multiple' => FALSE,
      '#default_value' => $this->options['ado_type_fields'],
      '#required' => TRUE,
    ];
  }


  public function query() {
    $query = $this->getQuery();
    $backend = $query->getIndex()->getServerInstance()->getBackend();
    $full_text = NULL;
    $type = NULL;
    // We only know how to join on Solr. All rest is terribly bad poetry
    if ($backend instanceof \Drupal\search_api_solr\SolrBackendInterface) {
      $index_fields = $query->getIndex()->getFields(TRUE);
      $solr_field_names = $backend
        ->getSolrFieldNames($query->getIndex());


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
        $join_structure = [];
        // Never ever make it easy Solr!
        // This is the Join Subquery, sadly not useful "directly" for Flavor Highlights IF the conjunction is AND
        // Because the AND implies matches across the union/intersection of main query and the Join
        // but OCR might only contain a few of these. the Idea is that the combination of all keys + searched against fields match
        // at the end the total.
        $subquery = $this->buildADOJoinSubQuery($query, $parse_mode, $this->options['ado_fields'], $full_text);
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
          $subquery_hl = $this->buildADOJoinSubQuery($query, $parse_mode, $this->options['ado_fields'], $full_text);
          foreach ($this->options['join_fields'] as $join_field) {
            if (isset($solr_field_names[$join_field])) {
              $join_structure[] = [
                'from' => $solr_field_names[$join_field],
                'to' =>  $solr_field_names[$this->options['join_field_to'] ?? 'its_nid'],
                'v' => $subquery,
                'hl' => $subquery_hl,
              ];
            }
          }
          // 'hl' might be used in the future at @TODO. Right HL is limited to Strawberry Flavors.
          // \Drupal\strawberryfield\Plugin\search_api\processor\StrawberryFieldHighlight::highlightFlavorsFromIndex
          if (!empty($join_structure)) {
            $this->getQuery()->setOption('sbf_join_ado', $join_structure);
          }
        }
      }
      elseif ($type == 'advanced_fulltext') {
        $join_structure = [];
        if ($subtitutions = $this->getQuery()->getOption('sbf_advanced_search_filter_join', NULL)) {
          foreach ($subtitutions as $placeholder => $subquery) {
            foreach ($this->options['join_fields'] as $key => $join_field) {
              if (isset($solr_field_names[$join_field])) {
                $join_structure[$placeholder . $key] = [
                  'from' => $solr_field_names[$join_field],
                  'to' => $solr_field_names[$this->options['join_field_to'] ?? 'its_nid'],
                  'v' => $subquery,
                ];
              }
            }
          }
          // 'hl' might be used in the future at @TODO. Right HL is limited to Strawberry Flavors.
          // \Drupal\strawberryfield\Plugin\search_api\processor\StrawberryFieldHighlight::highlightFlavorsFromIndex
          if (!empty($join_structure)) {
            $this->getQuery()->setOption('sbf_join_ado_advanced', $join_structure);
          }
        }
      }
    }
  }

  /**
   * @param \Drupal\search_api\Plugin\views\query\SearchApiQuery $query
   *
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function buildADOJoinSubQuery(SearchApiQuery $query, $parse_mode, array $queryable_fields, array|string $keys) {
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
    $all_names = [];
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
        $all_names = array_merge($all_names, $names);
      }
    }
    $all_names = array_unique($all_names);
    $names = $all_names;
    $type_query = NULL;
    if (count($names)) {

      // If we have $names, now calculate the AND query for ADO type if any
      if ($this->options['ado_type_fields']) {
        $ado_type_names_solr = [];
        $types = explode(",", trim($this->options['ado_type'] ?? ''));
        if (count($types)) {
          foreach($types as &$type) {
            $type = trim($type ?? '');
          }
          $types = array_filter($types);
          if (count($types)) {
            $ado_type_keys =  ['#conjunction' => "OR" ] + $types;

              if (isset($solr_field_names[$this->options['ado_type_fields']])) {
                $ado_type_names_solr[] = $solr_field_names[$this->options['ado_type_fields']];
              }
            }
            if (count($ado_type_names_solr)) {
              // Here we force terms. WE are trying to mimic a filter query even if we can't do one via a JOIN
              // (or I can't!)
              $flat_keys_type[] = \Drupal\search_api_solr\Utility\Utility::flattenKeys(
                $ado_type_keys, $ado_type_names_solr,
                'terms'
              );
              $type_query = implode(" ", $flat_keys_type);
              $type_query = 'AND (' . $type_query  . ')';
            }
          }
        }

      $flat_keys[] = \Drupal\search_api_solr\Utility\Utility::flattenKeys(
        $keys, $names,
        $parse_mode->getPluginId()
      );
      if ($type_query) {
        $flat_keys[] = $type_query;
      }
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
  protected function getADOFulltextFields() {
    $fields = [];
    /** @var \Drupal\search_api\IndexInterface $index */
    $index = Index::load(substr($this->table, 17));

    $fields_info = $index->getFields();
    foreach ($index->getFulltextFields() as $field_id) {
      if ($fields_info[$field_id]->getDatasourceId() == 'entity:node' || $fields_info[$field_id]->getDatasourceId() == NULL) {
        $fields[$field_id] = $fields_info[$field_id]->getPrefixedLabel() . '('.  $fields_info[$field_id]->getFieldIdentifier() .')';
      }
    }

    return $fields;
  }


  /**
   * Retrieves a list of all available ADO NID fields.
   *
   * @return string[]
   *   An options list of fulltext field identifiers mapped to their prefixed
   *   labels.
   */
  protected function getADONidFields() {
    $fields = [];
    /** @var \Drupal\search_api\IndexInterface $index */
    $index = Index::load(substr($this->table, 17));

    $fields_info = $index->getFields();

    // We will store the actual Solr Field name here. Has the benefit of being faster
    // Has the downside that if someone changes the field name type (same field) this will break?

    foreach ($fields_info as $field_id => $field) {
      if (($field->getDatasourceId() == 'entity:node') && ($field->getType() == "integer")) {
        $property_path = $field->getPropertyPath();
        $property_path_parts = explode(":", $property_path ?? '');
        // Very hardcoded too.
        if (end($property_path_parts) == "nid" || str_contains(end($property_path_parts), 'sbf_entity_reference_')) {
          $fields[$field_id] = $field->getPrefixedLabel() . '('
            . $field->getFieldIdentifier() . ')';
        }
      }
    }
    return $fields;
  }

  /**
   * Retrieves a list of all available ADO Type fields.
   *
   * @return string[]
   *   An options list of fulltext field identifiers mapped to their prefixed
   *   labels.
   */
  protected function getADOTypeFields() {
    $fields = [];
    /** @var \Drupal\search_api\IndexInterface $index */
    $index = Index::load(substr($this->table, 17));

    $fields_info = $index->getFields();
    foreach ($fields_info as $field_id => $field) {
      if (($field->getDatasourceId() == 'entity:node') && ($field->getType() == "string")) {
        $property_path = $field->getPropertyPath();
        $property_path_parts = explode(":", $property_path ?? '');
        // This is kinda fixed ... we might just get all strings?
        if (end($property_path_parts) == "type" || end($property_path_parts) == "digital_object_type" || str_contains($field->getFieldIdentifier(), 'type')) {
          $fields[$field_id] = $field->getPrefixedLabel() . '('
            . $field->getFieldIdentifier() . ')';
        }
      }
    }
    return $fields;
  }

}
