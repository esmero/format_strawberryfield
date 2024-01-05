<?php

namespace Drupal\format_strawberryfield\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\search_api\Entity\Index;

/**
 * Class ViewModeMappingSettingsForm.
 */
class ViewModeMappingSettingsForm extends ConfigFormBase {

  /**
   * Constructs a \Drupal\system\ConfigFormBase object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->setConfigFactory($config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'format_strawberryfield.viewmodemapping_settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'format_strawberryfield_view_mode_mapping_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('format_strawberryfield.viewmodemapping_settings');
    $form['info'] = [
      '#markup' => $this->t(
        'This Form allows you to map ADO (Archipelago Digital Object) "types" to existing Drupal View Mode Configurations.'
      ),
    ];

    $view_mode_list = $this->getViewModes();

    $form['add_fieldset'] = [
      '#title' => $this->t('Add a new mapping'),
      '#type' => 'fieldset',
      '#collapsible' => FALSE,
      '#collapsed' => FALSE,
      '#tree' => TRUE,
    ];
    $form['add_fieldset']['type'] = [
      '#type' => 'select',
      '#options' => $this->getTypesFromSolr(),
      '#title' => $this->t('The ADO Type'),
      '#description' => $this->t(
        'The value of a "type" Key as found in a SBF JSON of an ADO.'
      ),
      '#default_value' => '',
      '#required' => TRUE,
    ];
    $form['add_fieldset']['viewmode'] = [
      '#type' => 'select',
      '#options' => $view_mode_list,
      '#title' => $this->t('View Mode'),
      '#description' => $this->t(
        'A View Mode to be used when displaying a Node bearing SBF that contains in its JSON a "type" key with the first value.'
      ),
      '#default_value' => 'Default',
      '#required' => TRUE,
    ];

    $form['add_fieldset']['add_more'] = [
      '#type' => 'submit',
      '#value' => t('Add'),
      // No validation.
      '#limit_validation_errors' => [
        ['add_fieldset', 'type'],
        ['add_fieldset', 'viewmode'],
      ],
      // #submit required.
      '#submit' => ['::addPair'],
      '#ajax' => [
        'callback' => '::addmoreCallback',
        'wrapper' => 'table-fieldset-wrapper',
      ],

    ];

    $form['table-row'] = [
      '#type' => 'table',
      '#prefix' => '<div id="table-fieldset-wrapper">',
      '#suffix' => '</div>',
      '#header' => [
        $this->t('JSON Type'),
        $this->t('View Mode Name'),
        $this->t('IS ACTIVE ?'),
        $this->t('Actions'),
        $this->t('Sort'),

      ],
      '#empty' => $this->t('Sorry, There are no items!'),
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'table-sort-weight',
        ],
      ],
    ];
    $storedsettings = $config->get('type_to_viewmode');
    if (empty(
      $form_state->get(
        'vmmappings'
      )
      ) && !empty($storedsettings) && !$form_state->isRebuilding()) {
      // Prepopulate our $formstate vmmappings variable from stored settings;.
      $form_state->set('vmmappings', $storedsettings);
    }
    $current_mappings = !empty(
    $form_state->get(
      'vmmappings'
    )
    ) ? $form_state->get('vmmappings') : [];
    foreach ($current_mappings as $index => $mapping) {
      $key = $index + 1;
      $form['table-row'][$key]['#attributes']['class'][] = 'draggable';
      $form['table-row'][$key]['#weight'] = isset($mapping['weight']) ? $mapping['weight'] : $key;

      $form['table-row'][$key]['type'] = [
        '#type' => 'textfield',
        '#required' => TRUE,
        '#default_value' => $mapping['jsontype'],
      ];
      $view_mode_mapping_for_this_row = isset($view_mode_list[$mapping['view_mode']]) ? $view_mode_list[$mapping['view_mode']] : t(
        'View mode @view_mode does not exist! Please select a new one.',
        ['@view_mode' => $mapping['view_mode']]
      );

      if (isset($view_mode_list[$mapping['view_mode']])) {
        $form['table-row'][$key]['vm'] = [
          '#prefix' => '<div>' . $view_mode_mapping_for_this_row . '</div>',
          '#type' => 'value',
          '#required' => TRUE,
          '#default_value' => $mapping['view_mode'],
        ];
      }
      else {
        // The View Mode saved in our settings is no longer available, show a select box
        $form['table-row'][$key]['vm'] = [
          '#prefix' => '<div>' . $view_mode_mapping_for_this_row . '</div>',
          '#type' => 'select',
          '#options' => $view_mode_list,
          '#required' => TRUE,
          '#default_value' => 'default',
        ];
      }
      $form['table-row'][$key]['active'] = [
        '#type' => 'checkbox',
        '#required' => FALSE,
        '#default_value' => isset($mapping['active']) ? $mapping['active'] : TRUE,
      ];
      $form['table-row'][$key]['actions'] = [
        '#type' => 'submit',
        '#rowtodelete' => $key,
        '#name' => 'deleteitem_' . $key,
        '#value' => t('Remove'),
        // No validation.
        '#limit_validation_errors' => [['table-row']],
        // #submit required if ajax!.
        '#submit' => ['::deletePair'],
        '#ajax' => [
          'callback' => '::deleteoneCallback',
          'wrapper' => 'table-fieldset-wrapper',
        ],
      ];
      $form['table-row'][$key]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t(
          'Weight for @title',
          ['@title' => $mapping['jsontype']]
        ),
        '#title_display' => 'invisible',
        '#default_value' => isset($mapping['weight']) ? $mapping['weight'] : $key,
        '#attributes' => ['class' => ['table-sort-weight']],
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * Fetches ADO types from Solr.
   *
   * This needs a setting form too, since people could have types in other
   * places.
   */
  protected function getTypesFromSolr() {
    $options = [];
    /** @var \Drupal\search_api\IndexInterface[] $indexes */
    $indexes = \Drupal::entityTypeManager()
      ->getStorage('search_api_index')
      ->loadMultiple();

    $facets = [
      'type' => [
        'field' => 'type_1',
        'limit' => 50,
        'operator' => 'AND',
        'min_count' => 1,
        'missing' => TRUE,
      ],
    ];

    foreach ($indexes as $index) {
      $index = Index::load($index->id());
      /** @var \Drupal\search_api\Query\QueryInterface $query */
      $query = $index->query();
      // $query->setLanguages([LanguageInterface::LANGCODE_NOT_SPECIFIED]);.
      $query->range(0, 0);

      // Set additional options.
      // (In this case, retrieve facets, if supported by the backend.)
      $server = $index->getServerInstance();
      if ($server->supportsFeature('search_api_facets')) {
        $query->setOption('search_api_facets', $facets);
      }
      // Execute the search.
      $results = $query->execute();

      $facet_result = $results->getExtraData('search_api_facets', []);
      if (isset($facet_result['type'])) {
        foreach ($facet_result['type'] as $facet) {
          $value = trim($facet['filter'], '"');
          $options[$value] = $value;
        }
      }

    }
    return $options;
  }

  /**
   * Fetches current enabled Drupal View Modes.
   */
  protected function getViewModes() {
    $vm = \Drupal::service('entity_display.repository')->getViewModes('node');
    $options = ['Default' => t('Default')];
    // Careful here. Default is really '' But we want to enforce a value here.
    // But once we apply this on Node render we need to convert back to nothing.
    foreach ($vm as $key => $item) {
      $options[$key] = $item['label'];
    }
    return $options;
  }

  /**
   * Callback for both ajax-enabled buttons.
   *
   * Selects and returns the fieldset with the names in it.
   */
  public function addmoreCallback(
    array &$form,
    FormStateInterface $form_state
  ) {
    return $form['table-row'];
  }

  /**
   * Callback for both ajax-enabled buttons.
   *
   * Selects and returns the fieldset with the names in it.
   */
  public function deleteoneCallback(
    array &$form,
    FormStateInterface $form_state
  ) {
    return $form['table-row'];
  }

  /**
   * Submit handler for the "addmore" button.
   *
   * Adds Key and View Mode to the Table Drag  Table.
   */
  public function addPair(array &$form, FormStateInterface $form_state) {

    $vmmappings = $form_state->get('vmmappings') ? $form_state->get(
      'vmmappings'
    ) : [];
    $vmmappings[] = [
      'jsontype' => $form_state->getValue(
        ['add_fieldset', 'type']
      ),
      'view_mode' => $form_state->getValue(['add_fieldset', 'viewmode']),
    ];
    $this->messenger()->addWarning('You have unsaved changes.');
    $form_state->set('vmmappings', $vmmappings);
    $form_state->setRebuild();
  }

  /**
   * Submit handler for the "deleteone" button.
   *
   * Adds Key and View Mode to the Table Drag  Table.
   */
  public function deletePair(array &$form, FormStateInterface $form_state) {

    $triggering = $form_state->getTriggeringElement();
    if (isset($triggering['#rowtodelete'])) {
      $vmmappings = $form_state->get('vmmappings') ? $form_state->get(
        'vmmappings'
      ) : [];
      unset($vmmappings[$triggering['#rowtodelete'] - 1]);
      $form_state->set('vmmappings', array_values($vmmappings));
      $this->messenger()->addWarning('You have unsaved changes.');
      $userinput = $form_state->getUserInput();
      // Only way to get that tabble drag form to rebuild completely
      // If not we get always the same table back with the last element
      // removed.
      unset($userinput["table-row"]);
      $form_state->setUserInput($userinput);
    }

    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $settings = [];
    $rows = $form_state->getValue('table-row');
    // When empty it will be a string!.
    if (is_array($rows) && !empty($rows)) {
      usort(
        $rows,
        [
          '\Drupal\format_strawberryfield\Form\ViewModeMappingSettingsForm',
          'sortSettings',
        ]
      );
      foreach ($rows as $row) {
        $settings['type_to_viewmode'][] = [
          'jsontype' => $row['type'],
          'view_mode' => $row['vm'],
          'active' => $row['active'],
          'weight' => $row['weight'],
        ];
      }
      $this->config('format_strawberryfield.viewmodemapping_settings')->set(
        'type_to_viewmode',
        $settings['type_to_viewmode']
      )->save();
    }
    else {
      $this->config('format_strawberryfield.viewmodemapping_settings')->delete(
      );
    }
    parent::submitForm($form, $form_state);
  }

  /**
   * Sorts Array by its weight associative index.
   *
   * @param array $a
   *   Array to sort.
   * @param array $b
   *   Same Array to sort against.
   *
   * @return int
   *   The new order
   */
  public static function sortSettings(array $a, array $b) {
    return $a['weight'] - $b['weight'];

  }

}
