<?php

namespace Drupal\format_strawberryfield_views\Plugin\views\filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\ParseMode\ParseModePluginManager;
use Drupal\search_api_solr\Utility\Utility;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\search_api\Plugin\views\filter\SearchApiFilterTrait;
use Drupal\search_api\Plugin\views\filter\SearchApiFulltext;

/**
 * Defines a filter for adding a fulltext search to the view.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("sbf_advanced_search_api_fulltext")
 */
class AdvancedSearchApiFulltext extends SearchApiFulltext {

  use SearchApiFilterTrait;

  /**
   * The current count of exposed Advanced Search Fields.
   *
   * @var array
   */
  public $searchedFieldsCount = 1;

  /**
   * The list of fields selected for the search.
   *
   * @var array
   */
  public $searchedFields = [];

  /**
   * The list of inter field operators for the searches.
   *
   * @var array
   */
  public $searchedFieldsOp = [];

  /**
   * The list of operators for the extra adv searches.
   *
   * @var array
   */
  public $operatorAdv = [];



  /**
   * {@inheritdoc}
   */
  public function defineOptions() {
    $options = parent::defineOptions();
    $options['expose']['contains']['advanced_search_fields_multiple'] = ['default' => FALSE];
    $options['expose']['contains']['advanced_search_fields_count'] = ['default' => 2];
    $options['expose']['contains']['advanced_search_use_operator'] = ['default' => FALSE];
    $options['expose']['contains']['advanced_search_operator_id'] = ['default' => ''];
    $options['expose']['contains']['advanced_search_fields_add_one_label'] = ['default' => 'add one'];
    $options['expose']['contains']['advanced_search_fields_remove_one_label'] = ['default' => 'remove one'];
    return $options;
  }


  public function defaultExposeOptions() {
    parent::defaultExposeOptions();
    $this->options['expose']['advanced_search_fields_multiple'] = FALSE;
    $this->options['expose']['advanced_search_fields_count'] = 2;
    $this->options['expose']['advanced_search_use_operator'] = FALSE;
    $this->options['expose']['advanced_search_operator_id'] = $this->options['id'] . '_group_operator';
    $this->options['expose']['advanced_search_fields_add_one_label'] = 'add one';
    $this->options['expose']['advanced_search_fields_remove_one_label'] = 'remove one';
  }

  /**
   * {@inheritdoc}
   */
  public function buildExposeForm(&$form, FormStateInterface $form_state) {
    parent::buildExposeForm($form, $form_state);

    $form['expose']['advanced_search_fields_multiple'] = [
      '#type' => 'checkbox',
      '#default_value' => $this->options['expose']['advanced_search_fields_multiple'],
      '#title' => $this->t('Multiple/add more Search Fields'),
      '#description' => $this->t('This allows users to add more Search Fields with the same general exposed settings. All identifiers passed by this filter in the URL after the ? will get an incremental suffix.'),
      '#states' => [
        'visible' => [
          ':input[name="options[expose][expose_fields]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $form['expose']['advanced_search_fields_count'] = [
      '#type' => 'number',
      '#default_value' => $this->options['expose']['advanced_search_fields_count'],
      '#title' => $this->t('Max number of Multiple/add more Search Fields the user can expose.'),
      '#size' => 5,
      '#min' => 2,
      '#max' => 10,
      '#description' => $this->t('The number of additional search Fields with the same general exposed settings the user will be able to expose.'),
      '#states' => [
        'visible' => [
          ':input[name="options[expose][advanced_search_fields_multiple]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['expose']['advanced_search_fields_add_one_label'] = [
      '#type' => 'textfield',
      '#default_value' => $this->options['expose']['advanced_search_fields_add_one_label'] ?? "add one",
      '#title' => $this->t('Label to be used for the "add one" button'),
      '#description' => $this->t('"Label to be used for the "add more" button. By default it is "add one" if left empty'),
      '#states' => [
        'visible' => [
          ':input[name="options[expose][advanced_search_fields_multiple]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['expose']['advanced_search_fields_remove_one_label'] = [
      '#type' => 'textfield',
      '#default_value' => $this->options['expose']['advanced_search_fields_remove_one_label'] ?? "remove one",
      '#title' => $this->t('Label to be used for the "remove one" button'),
      '#description' => $this->t('"Label to be used for the "add one" button. By default it is "remove one" if left empty'),
      '#states' => [
        'visible' => [
          ':input[name="options[expose][advanced_search_fields_multiple]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['expose']['advanced_search_use_operator'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Expose between search fields operator'),
      '#description' => $this->t('Allow the user to choose the operator between Multiple Search Fields (AND/OR).'),
      '#default_value' => !empty($this->options['expose']['advanced_search_use_operator']),
      '#states' => [
        'visible' => [
          ':input[name="options[expose][advanced_search_fields_multiple]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['expose']['advanced_search_operator_id'] = [
      '#type' => 'textfield',
      '#default_value' => $this->options['expose']['advanced_search_operator_id'],
      '#title' => $this->t('Multiple/add more Search Fields operator (AND/OR) fields identifier'),
      '#size' => 40,
      '#description' => $this->t('This will appear in the URL after the ? to identify the operator used in the filter when multiple Search Fields and their extra options are exposed.'),
      '#states' => [
        'visible' => [
          ':input[name="options[expose][advanced_search_use_operator]"]' => ['checked' => TRUE],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function query() {

    // The plan
    // Get all the values - a.k.a exposed search fields
    // Get all setup fields and also merge/unique them
    // get all the conjunctions (per value/ exposed search fields)
    // get all the between fields operators
    // Create createConditionGroup(s) grouped by Operators
    // Add the conditions per condition group (corresponding)
    // Set the query
    // Celebrate

    /*while (is_array($this->value)) {
      $this->value = $this->value ? reset($this->value) : '';
    }*/
    if (!is_array($this->value)) {
      return;
    }
    $count = 0;

    // Get all exposed ids or the defaults:
    // Store searched fields.
    $searched_fields_identifier = $this->options['id'] . '_searched_fields';

    if (!empty($this->options['expose']['searched_fields_id'])) {
      $searched_fields_identifier
        = $this->options['expose']['searched_fields_id'];
    }

    $search_field_identifier = $this->options['id'];

    if (!empty($this->options['expose']['identifier'])) {
      $search_field_identifier = $this->options['expose']['identifier'];
    }
    $advanced_search_operator_id = $this->options['id'] . '_group_operator';
    if (!empty($this->options['expose']['advanced_search_use_operator'])
      && !empty($this->options['expose']['advanced_search_operator_id'])
    ) {
      $advanced_search_operator_id
        = $this->options['expose']['advanced_search_operator_id'];
    }

    // Accumulator/grouper of all input/passed options
    $query_able_data = [];

    foreach ($this->value as $exposed_value_id => $exposed_value) {
      // Catch empty strings entered by the user, but not "0".
      if (trim($exposed_value) === '') {
        unset($this->value[$exposed_value_id]);
        unset($this->searchedFields[$exposed_value_id]);
      }
      else {
        // We will use this to track which fields need to be queried in case
        // An empty value ended being discarded.
        // Value rules over the others.
        $valid_suffix = $count > 0 ? '_' . $count : '';
        $chosen_fields = isset($this->searchedFields[$searched_fields_identifier
          . $valid_suffix]) ? (array) $this->searchedFields[$searched_fields_identifier
        . $valid_suffix] : [];
        $query_able_data[] = [
          'value'               => $this->value[$exposed_value_id],
          'operator'            => ($valid_suffix == '') ? $this->operator
            : ($this->operatorAdv[$exposed_value_id] ?? $this->operator),
          'interfield_operator' => $this->searchedFieldsOp[$advanced_search_operator_id
            . $valid_suffix] ?? 'or',
          'fields'              => $chosen_fields,
        ];
      }
      $count++;
    }
    if (!count($this->value)) {
      return;
    }

    $fields = $this->options['fields'];
    $fields = $fields ?: array_keys($this->getFulltextFields());

    // Override the search fields, if exposed for each of the exposed searches.
    foreach ($query_able_data as &$query_able_datum) {
      if (!empty($query_able_datum['fields'])) {
        $query_able_datum['fields'] = array_intersect(
          $fields, $query_able_datum['fields']
        );
      }
      else {
        $query_able_datum['fields'] = $fields;
      }
    }
    $query = $this->getQuery();

    // Save any keywords that were already set.
    $old = $query->getKeys();
    $old_original = $query->getOriginalKeys();

    if ($this->options['parse_mode']) {
      /** @var \Drupal\search_api\ParseMode\ParseModeInterface $parse_mode */
      $parse_mode = $this->getParseModeManager()
        ->createInstance($this->options['parse_mode']);
    }

    // Humble attempt at doing this manually since we have multiple fields with varying operators.
    // A direct query won't work until we have the actual Solr Field names via \Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend::getQueryFulltextFields
    // See why https://www.drupal.org/project/search_api/issues/3049097
    // If the backend is Solr we can do this, if not we default to either the basics (single query + filters) or just filtes
    $backend = $query->getIndex()->getServerInstance()->getBackend();
    $index_fields = $query->getIndex()->getFields();

    if ($backend instanceof \Drupal\search_api_solr\SolrBackendInterface) {
      $solr_field_names = $query->getIndex()
        ->getServerInstance()
        ->getBackend()
        ->getSolrFieldNames($query->getIndex());
      // Damn Solr Search API...
      $settings = Utility::getIndexSolrSettings($query->getIndex());
      $language_ids = $query->getLanguages();
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

      $field_names = $backend->getSolrFieldNamesKeyedByLanguage($language_ids, $query->getIndex());

      $query_fields_boosted = [];

      foreach ($query_able_data as &$query_able_datum_fields) {
        foreach ($query_able_datum['fields'] ?? [] as $field) {
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

            foreach (array_unique($names) as $name) {
              $query_able_datum_fields['real_solr_fields'][] = $name . $boost;
            }
          }
        }
      }
      $use_conditions = FALSE;
    }
    else {
      // We need to use conditions/query filters if the Index is not Solr.
      $use_conditions = TRUE;
    }
    $manual_keys = [];
    $flat_keys = [];
    $negation = FALSE;
    // Now do the actual query creation/composite thing
    $j = 0;
    foreach ($query_able_data as $query_able_datum_internal) {
      $flat_key = '';
      if ($negation = $query_able_datum_internal['operator'] === 'not' ? TRUE
        : FALSE
      ) {
        $parse_mode->setConjunction('OR');
      }
      else {
        $parse_mode->setConjunction($query_able_datum_internal['operator']);
      }

      $parsed_value = $parse_mode->parseInput($query_able_datum_internal['value']);
      $manual_keys = [
        $parsed_value,
      ];
      if ($negation) {
        $manual_keys[0]['#negation'] = $negation;
      }

      $flat_key = \Drupal\search_api_solr\Utility\Utility::flattenKeys(
        $manual_keys, $query_able_datum_internal['real_solr_fields'],
        $parse_mode->getPluginId()
      );
      if ($j > 0) {
        if ($query_able_datum_internal['interfield_operator'] == 'and') {
          $flat_key = ' && '.$flat_key;
        }
      }
      $flat_keys[] = $flat_key;
      $j++;
    }
    if (count($flat_keys)) {
      /** @var \Drupal\search_api\ParseMode\ParseModeInterface $parse_mode */
      $parse_mode_direct = $this->getParseModeManager()
        ->createInstance('direct');
      $this->getQuery()->setParseMode($parse_mode_direct);

      $combined_keys = implode(" ", $flat_keys);
      $this->getQuery()->keys("({$combined_keys})");
      // This is just to avoid Search API rewriting the query
      $this->getQuery()->setOption('solr_param_defType', 'edismax');
      // This will allow us to remove the edismax processor on a hook/event subscriber.
      $this->getQuery()->setOption('sbf_advanced_search_filter', TRUE);
      //$parse_mode_direct->setConjunction('OR');
      // This can't be null nor a field we need truly an empty array.
      // Only that works.
      $this->getQuery()->setFulltextFields([]);
    }
    else {
      //@TODO get old fields, old keys, parse same as the rest, so we can
      //Switch to a direct query
      $old_fields = $query->getFulltextFields();
      $use_conditions = $use_conditions
        && ($old_fields
          && (array_diff($old_fields, $fields)
            || array_diff(
              $fields, $old_fields
            )));

      if ($use_conditions) {
        // In this case we set the original chosen parse mode directly back
        // into the query.
        $query->setParseMode($parse_mode);
        //@TODO use the inbetweens to define condition groups
        $conditions = $query->createConditionGroup('OR');
        $op = $this->operator === 'not' ? '<>' : '=';
        foreach ($fields as $field) {
          $conditions->addCondition($field, $this->value, $op);
        }
        $query->addConditionGroup($conditions);
        return;
      }

      // If the operator was set to OR or NOT, set OR as the conjunction. It is
      // also set for NOT since otherwise it would be "not all of these words".
      if ($this->operator != 'and') {
        $query->getParseMode()->setConjunction('OR');
      }

      $query->setFulltextFields($fields);
      $query->keys($this->value);
      if ($this->operator == 'not') {
        $keys = &$query->getKeys();
        if (is_array($keys)) {
          $keys['#negation'] = TRUE;
        }
        else {
          // We can't know how negation is expressed in the server's syntax.
        }
        unset($keys);
      }

      // If there were fulltext keys set, we take care to combine them in a
      // meaningful way (especially with negated keys).
      if ($old) {
        $keys = &$query->getKeys();
        // Array-valued keys are combined.
        if (is_array($keys)) {
          // If the old keys weren't parsed into an array, we instead have to
          // combine the original keys.
          if (is_scalar($old)) {
            $keys = "($old) ({$this->value})";
          }
          else {
            // If the conjunction or negation settings aren't the same, we have to
            // nest both old and new keys array.
            if (empty($keys['#negation']) !== empty($old['#negation'])
              || $keys['#conjunction'] !== $old['#conjunction']
            ) {
              $keys = [
                '#conjunction' => 'AND',
                $old,
                $keys,
              ];
            }
            // Otherwise, just add all individual words from the old keys to the
            // new ones.
            else {
              foreach ($old as $key => $value) {
                if (substr($key, 0, 1) === '#') {
                  continue;
                }
                $keys[] = $value;
              }
            }
          }
        }
        // If the parse mode was "direct" for both old and new keys, we
        // concatenate them and set them both via method and reference (to also
        // update the originalKeys property.
        elseif (is_scalar($old_original)) {
          $combined_keys = "($old_original) ($keys)";
          $query->keys($combined_keys);
          $keys = $combined_keys;
        }
        unset($keys);
      }
    }
  }

  public function submitExposed(&$form, FormStateInterface $form_state) {
    if ($form_state->getTriggeringElement()['#op'] ?? NULL == $this->options['id'] . '_addone') {
      $current_count = &$form_state->getValue($this->options['id'].'_advanced_search_fields_count',1);
      $form_state->setRebuild(TRUE);
    }
    // OR HOW THE RESET BUTTON DOES IT
    //if (!$form_state->isValueEmpty('op') && $form_state->getValue('op') == $this->options['reset_button_label'])
    parent::submitExposed(
      $form, $form_state
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function valueForm(&$form, FormStateInterface $form_state) {
    parent::valueForm($form, $form_state);

    $form['value'] = [
      '#type' => 'textfield',
      '#title' => !$form_state->get('exposed') ? $this->t('Value') : '',
      '#size' => 30,
      '#default_value' => $this->value[$this->options['expose']['identifier']] ?? '',
    ];
    if (!empty($this->options['expose']['placeholder'])) {
      $form['value']['#attributes']['placeholder'] = $this->options['expose']['placeholder'];
    }
  }
  /**
   * {@inheritdoc}
   */
  public function acceptExposedInput($input) {
    if (empty($this->options['exposed'])) {
      return TRUE;
    }
    $return = parent::acceptExposedInput($input);
    // Now do things a bit differently:
    // Value in our case needs to be multiple values (bc of the multiple options)
    if ($return && $realcount = $input[$this->options['expose']['identifier'].'_advanced_search_fields_count']) {
      $this->value = [];
      $this->value[$this->options['expose']['identifier']] = $input[$this->options['expose']['identifier']];
      for($i=1;$i < $realcount && $realcount > 1; $i++) {
        if (!empty($this->options['expose']['use_operator']) && !empty($this->options['expose']['operator_id']) && isset($input[$this->options['expose']['operator_id'].'_'.$i])) {
          $this->operatorAdv[$this->options['expose']['identifier'] . '_' . $i] = $input[$this->options['expose']['operator_id'].'_'.$i];
        }
        $this->value[$this->options['expose']['identifier'] . '_' . $i] =  $input[$this->options['expose']['identifier'].'_'.$i] ?? '';
      }
    }

    if (!$return) {
      // Override for the "(not) empty" operators.
      $operators = $this->operators();
      if ($operators[$this->operator]['values'] == 0) {
        return TRUE;
      }
    }

    return $return;
  }
  /**
   * {@inheritdoc}
   */
  public function buildExposedForm(&$form, FormStateInterface $form_state) {
    parent::buildExposedForm($form, $form_state);

    if (empty($this->options['exposed'])) {
      return;
    }
    if ($this->options['expose']['expose_fields']) {
      $fields = $this->getFulltextFields();
      $configured_fields = $this->options['fields'];
      // Only keep the configured fields.
      if (!empty($configured_fields)) {
        $configured_fields = array_flip($configured_fields);
        $fields = array_intersect_key($fields, $configured_fields);
      }
      //Now the searched fields if exposed.
      $searched_fields_identifier = $this->options['id'] . '_searched_fields';
      if (!empty($this->options['expose']['searched_fields_id'])) {
        $searched_fields_identifier
          = $this->options['expose']['searched_fields_id'];
      }

      // Remove the group operator if found
      unset($form[$searched_fields_identifier]);
      $multiple_exposed_fields = $this->options['expose']['multiple'] ?? FALSE ? min(count($fields), 5) : 1;
      $newelements[$searched_fields_identifier] = [
        '#type'     => 'select',
        '#title'    => new PluralTranslatableMarkup($multiple_exposed_fields, 'Search Field', 'Search Fields'),
        '#options'  => $fields,
        '#multiple' => $this->options['expose']['multiple'] ?? FALSE,
        '#size'     => $multiple_exposed_fields,
        '#default_value' => $form_state->getValue($searched_fields_identifier),
      ];

      if (empty($form[$this->options['id'] . '_wrapper'])) {
        $form[$this->options['id'] . '_wrapper'] =
          [
            "#type" => "fieldset",
            "#title" => t('Advanced Fulltext search'),
            $this->options['id'] => $form[$this->options['id']],
           ];
        unset($form[$this->options['id']]);
      }
      $form[$this->options['id'] . '_wrapper'][$searched_fields_identifier]
        = $newelements[$searched_fields_identifier];

    }

    $advanced_search_operator_id = $this->options['id'] . '_group_operator';
    // And our own settings.
    if (!empty($this->options['expose']['advanced_search_use_operator']) && !empty($this->options['expose']['advanced_search_operator_id'])) {

      if (!empty($this->options['expose']['advanced_search_operator_id'])) {
        $advanced_search_operator_id = $this->options['expose']['advanced_search_operator_id'];
      }
      // Group operators are used to connected multiple exposed forms/groups
      // for this Filters between each other at query time
      $group_operator = [
        'and' => $this->t('AND'),
        'or'  => $this->t('OR'),
      ];
      $newelements[$advanced_search_operator_id] = [
        '#type' => 'select',
        '#title' => $this->t('Operator'),
        '#options' => $group_operator,
        '#multiple' => FALSE,
        '#weight' => '-10',
        '#access' => FALSE,
        '#default_value' => $form_state->getValue($advanced_search_operator_id,'or'),
      ];
      $form[$this->options['id'] . '_wrapper'][$advanced_search_operator_id] = $newelements[$advanced_search_operator_id];
    }

    // Yes over complicated but so far the only way i found to keep the state
    // Of this value between calls/rebuilds and searches.
    // @TODO move this into Accept input. That is easier?
    $nextcount = (int) ($form_state->getUserInput()['sbf_advanced_search_api_fulltext_advanced_search_fields_count'] ?? 1);
    $prevcount = $this->view->exposed_raw_input[$this->options['id'].'_advanced_search_fields_count'] ?? NULL;
    $form_state_count = $form_state->getValue('sbf_advanced_search_api_fulltext_advanced_search_fields_count', NULL);
    $realcount = $prevcount ?? ($form_state_count ?? $nextcount);
    // Cap it to the max limit
    $realcount = ($realcount <= $this->options['expose']['advanced_search_fields_count']) ? $realcount : $this->options['expose']['advanced_search_fields_count'];
    // Only enable if setup and the realcount is less than the max.
    $enable_more = $realcount < $this->options['expose']['advanced_search_fields_count'] && $this->options['expose']['advanced_search_fields_multiple'];
    $enable_less = $realcount > 1;
    // This fails on Preview (because of competing Ajax and replace calls)
    // @TODO Re-test without the IF bc of Sunday refactor that should have fixed it?

    if (empty($this->view->live_preview)) {
      $form[$this->options['id'].'_addone'] = [
        '#type' => 'submit',
        '#op' => $this->options['id'] . '_addone',
        '#value' => $this->t('@label', [
          '@label' => $this->options['exposed']['advanced_search_fields_add_one_label'] ?? 'add one'
        ]),
        '#access' => $enable_more,
        '#weight' => '-100',
        '#group' => 'actions',
      ];
      /* Note: #group does not work for buttons but we use this to bring them into
      'actions' key in a form alter */
      $form[$this->options['id'].'_delone'] = [
        '#type' => 'submit',
        '#op' => $this->options['id'] . '_delone',
        '#value' => $this->t('@label', [
          '@label' => $this->options['exposed']['advanced_search_fields_remove_one_label'] ?? 'remove one'
        ]),
        '#access' => $enable_less,
        '#weight' => '-101',
        '#group' => 'actions',
      ];
    }

    $form[$this->options['id'] . '_advanced_search_fields_count'] = [
      '#type' => 'hidden',
      '#value' => $realcount,
    ];

    for($i=1;$i < $realcount && $realcount > 1; $i++) {
      foreach ($form[$this->options['id'].'_wrapper'] as $key => $value) {
        if (strpos($key, '#') !== FALSE) {
          $form[$this->options['id'].'_wrapper_'.$i][$key] = $value;
        }
        else {
          $form[$this->options['id'].'_wrapper_'.$i][$key.'_'.$i] = $value;
        }
        if ($key == $this->options['expose']['identifier']) {
          $form[$this->options['id'].'_wrapper_'.$i][$key.'_'.$i]['#default_value'] = is_array($this->value) ? $this->value[$key.'_'.$i] ?? '' : '';
        }
        elseif (is_array($value) && strpos($key, '#') === FALSE) {
          $form[$this->options['id'].'_wrapper_'.$i][$key.'_'.$i]['#default_value'] = $form_state->getValue($key.'_'.$i);
          if ($key == $advanced_search_operator_id) {
            $form[$this->options['id'].'_wrapper_'.$i][$key.'_'.$i]['#access'] = TRUE;
          }
        }
      }
      $form[$this->options['id'].'_wrapper_'.$i]['#title_display'] = 'invisible';
    }
    $form = $form;
  }


  /**
   * {@inheritdoc}
   */
  public function validateExposed(&$form, FormStateInterface $form_state) {
    // Only validate exposed input.
    if (empty($this->options['exposed'])
      || empty($this->options['expose']['identifier'])
    ) {
      return;
    }

    // Store searched fields.
    $searched_fields_identifier = $this->options['id'] . '_searched_fields';
    if (!empty($this->options['expose']['searched_fields_id'])) {
      $searched_fields_identifier
        = $this->options['expose']['searched_fields_id'];
    }

    $advanced_search_operator_id = $this->options['id'] . '_group_operator';
    if (!empty($this->options['expose']['advanced_search_operator_id'])) {
      $advanced_search_operator_id
        = $this->options['expose']['advanced_search_operator_id'];
    }

    $this->searchedFields[$searched_fields_identifier] = $form_state->getValue(
      $searched_fields_identifier, []
    );

    // Store Exposed Advanced Search count
    $current_count = &$form_state->getValue(
      $this->options['id'] . '_advanced_search_fields_count', 1
    );
    if ((($triggering_element = $form_state->getTriggeringElement()['#op'] ??
        NULL) == $this->options['id'] . '_addone')
      && ($form_state->getUserInput()['op'] ?? NULL == $this->options['expose']['advanced_search_fields_add_one_label'])
      && $this->options['expose']['advanced_search_fields_multiple']
    ) {
      $this->searchedFieldsCount = $this->searchedFieldsCount
      < ($this->options['expose']['advanced_search_fields_count'] ?? 1)
        ? $this->searchedFieldsCount++
        : ($this->options['expose']['advanced_search_fields_count'] ?? 1);
      // Check if the state was set already
      $current_count++;
    }
    elseif ((($triggering_element = $form_state->getTriggeringElement()['#op'] ??
          NULL) == $this->options['id'] . '_delone')
      && ($form_state->getUserInput()['op'] ?? NULL == $this->options['expose']['advanced_search_fields_remove_one_label'])
      && $this->options['expose']['advanced_search_fields_multiple']
    ) {
      $this->searchedFieldsCount = $this->searchedFieldsCount
      > ($this->options['expose']['advanced_search_fields_count'] ?? 1)
        ? $this->searchedFieldsCount--
        : ($this->options['expose']['advanced_search_fields_count'] ?? 1);
      // Check if the state was set already
      $current_count--;
    }

    if ($this->options['expose']['advanced_search_fields_multiple']) {
      for ($i = 1; $i < $current_count; $i++) {
        if (!empty($this->options['expose']['advanced_search_use_operator'])
          && !empty($this->options['expose']['advanced_search_operator_id'])
        ) {
          $this->searchedFieldsOp[$advanced_search_operator_id . '_' . $i]
            = $form_state->getValue(
            $advanced_search_operator_id . '_' . $i, []
          );
        }
        $this->searchedFields[$searched_fields_identifier . '_' . $i]
          = $form_state->getValue($searched_fields_identifier . '_' . $i, []);
      }
    }


    $identifiers[] = $this->options['expose']['identifier'];
    for ($i = 1; $i < $current_count; $i++) {
      $identifiers[] = $this->options['expose']['identifier'] . '_' . $i;
    }
    foreach ($identifiers as $identifier) {
      $input = &$form_state->getValue($identifier, '');
      /// @TODO Add all inputs here...
      ///
      if ($this->options['is_grouped']
        && isset($this->options['group_info']['group_items'][$input])
      ) {
        $this->operator
          = $this->options['group_info']['group_items'][$input]['operator'];
        $input = &$this->options['group_info']['group_items'][$input]['value'];
      }

      // Under some circumstances, input will be an array containing the string
      // value. Not sure why, but just deal with that.
      while (is_array($input)) {
        $input = $input ? reset($input) : '';
      }
      if (trim($input) === '') {
        // No input was given by the user. If the filter was set to "required" and
        // there is a query (not the case when an exposed filter block is
        // displayed stand-alone), abort it.
        if (!empty($this->options['expose']['required']) && $this->getQuery()) {
          $this->getQuery()->abort();
        }
        // If the input is empty, there is nothing to validate: return early.
        return;
      }

      // Only continue if there is a minimum word length set.
      if ($this->options['min_length'] < 2) {
        return;
      }

      $words = preg_split('/\s+/', $input);
      foreach ($words as $i => $word) {
        if (mb_strlen($word) < $this->options['min_length']) {
          unset($words[$i]);
        }
      }
      if (!$words) {
        $vars['@count'] = $this->options['min_length'];
        $msg = $this->t(
          'You must include at least one positive keyword with @count characters or more.',
          $vars
        );
        $form_state->setErrorByName($identifier, $msg);
      }
      $input = implode(' ', $words);
    }
  }

  protected function canBuildGroup() {
    // Building groups here makes no sense. We disable it.
    return FALSE;
  }
}
