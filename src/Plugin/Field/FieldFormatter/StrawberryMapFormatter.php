<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:56 PM
 */

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\format_strawberryfield\EmbargoResolverInterface;
use Drupal\strawberryfield\Tools\Ocfl\OcflHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Component\Utility\NestedArray;

/**
 * Mirador Viewer Strawberry Field formatter.
 *
 * @FieldFormatter(
 *   id = "strawberry_map_formatter",
 *   label = @Translation("Strawberry Field Map Formatter using the Leaflet and GeoJson
 *   "), class =
 *   "\Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryMapFormatter",
 *   field_types = {
 *     "strawberryfield_field"
 *   },
 *   quickedit = {
 *     "editor" = "disabled"
 *   }
 * )
 */
class StrawberryMapFormatter extends StrawberryBaseFormatter implements ContainerFactoryPluginInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;


  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;


  /**
   * StrawberryMiradorFormatter constructor.
   *
   * @param $plugin_id
   * @param $plugin_definition
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   * @param array $settings
   * @param $label
   * @param $view_mode
   * @param array $third_party_settings
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Session\AccountInterface $current_user
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\format_strawberryfield\EmbargoResolverInterface $embargo_resolver
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    $label,
    $view_mode,
    array $third_party_settings,
    ConfigFactoryInterface $config_factory,
    AccountInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager,
    EmbargoResolverInterface $embargo_resolver
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $label,
      $view_mode,
      $third_party_settings,
      $config_factory,
      $embargo_resolver,
      $current_user
    );
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('format_strawberryfield.embargo_resolver')
    );
  }


  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return parent::defaultSettings() + [
        'json_key_source' => 'geographic_location',
        'tilemap_url' => 'https://a.tile.openstreetmap.org/{z}/{x}/{y}.png',
        'tilemap_attribution' => '&copy; <a href="https://openstreetmap.org/copyright">OpenStreetMap contributors</a>',
        'mediasource' => [
          'metadataexposeentity' => 'metadataexposeentity',
        ],
        'overlaysource' => [
          'metadataexposeentity' => 'metadataexposeentity',
        ],
        'main_mediasource' => 'metadataexposeentity',
        'main_overlaysource' => NULL,
        'metadataexposeentity_source' => NULL,
        'metadataexposeentity_overlaysource' => NULL,
        'geojsonnodelist_json_key_source' => 'locatedat',
        'geojsonurl_json_key_source' => 'geojson',
        'manifestnodelist_json_key_source' => 'isrelatedto',
        'manifesturl_json_key_source' => 'iiifmanifest',
        'iiifstatic_url_source' => NULL,
        'max_width' => 720,
        'max_height' => 480,
        'max_zoom' => 10,
        'min_zoom' => 2,
        'initial_zoom' => 5,
      ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    //@TODO document that 2 base urls are just needed when developing (localhost syndrom)

    $entity = NULL;
    if ($this->getSetting('metadataexposeentity_source')) {
      $entity = $this->entityTypeManager->getStorage(
        'metadataexpose_entity'
      )->load($this->getSetting('metadataexposeentity_source'));
    }

    $entity_manifest = NULL;
    if ($this->getSetting('metadataexposeentity_overlaysource')) {
      $entity_manifest = $this->entityTypeManager->getStorage(
        'metadataexpose_entity'
      )->load($this->getSetting('metadataexposeentity_overlaysource'));
    }


    $options_for_mainsource = is_array(
      $this->getSetting('mediasource')
    ) && !empty($this->getSetting('mediasource')) ? $this->getSetting(
      'mediasource'
    ) : self::defaultSettings()['mediasource'];

    $options_for_mainoverlaysource = is_array(
      $this->getSetting('overlaysource')
    ) && !empty($this->getSetting('overlaysource')) ? $this->getSetting(
      'overlaysource'
    ) : self::defaultSettings()['overlaysource'];

    if (($triggering_element = $form_state->getTriggeringElement(
      )) && isset($triggering_element['#ajax']['callback'])) {
      // We are getting the actual checkbox value pressed in the parents array.
      // so we need to slice by 1 at the end.
      // if Ajax class of the triggering element is this class then process
      if ($triggering_element['#ajax']['callback'][0] == get_class($this)) {
        $parents = array_slice($triggering_element['#parents'], 0, -1);
        if ($triggering_element['#ajax']['callback'][1] == "ajaxCallbackMainSource") {
          $options_for_mainsource = $form_state->getValue($parents);
        }
        elseif ($triggering_element['#ajax']['callback'][1] == "ajaxCallbackOverlaySource") {
          $options_for_mainoverlaysource = $form_state->getValue($parents);
        }
      }
    }
    $all_options_form_source = [
      'metadataexposeentity' => $this->t(
        'GeoJSON generated by a Metadata Display template'
      ),
      'geojsonurl' => $this->t(
        'Strawberryfield JSON Key with one or more GeoJSON URLs'
      ),
      'geojsonnodelist' => $this->t(
        'Strawberryfield JSON Key with one or more Node IDs or UUIDs'
      ),
    ];

    $all_options_form_overlaysource = [
      'metadataexposeentity' => $this->t(
        'A IIIF Manifest generated by a Metadata Display template'
      ),
      'manifesturl' => $this->t(
        'Strawberryfield JSON Key with one or more Manifest URLs'
      ),
      'manifestnodelist' => $this->t(
        'Strawberryfield JSON Key with one or more Node IDs or UUIDs'
      ),
      'fixediiifurl' => $this->t(
        'A Fixed, settings only, predefined URL of a IIIF Manifest'
      ),
    ];


    $options_for_mainsource = array_filter($options_for_mainsource);
    $options_for_mainsource = array_intersect_key(
      $options_for_mainsource,
      $all_options_form_source
    );

    $options_for_mainoverlaysource = array_filter($options_for_mainoverlaysource);
    $options_for_mainoverlaysource = array_intersect_key(
      $options_for_mainoverlaysource,
      $all_options_form_overlaysource
    );

    // Define #ajax callback.
    $ajax = [
      'callback' => [get_class($this), 'ajaxCallbackMainSource'],
      'wrapper' => 'main-mediasource-ajax-container',
    ];

    $ajax2 = [
      'callback' => [get_class($this), 'ajaxCallbackOverlaySource'],
      'wrapper' => 'main-overlaysource-ajax-container',
    ];
    // Because main media source needs to update its choices based on
    // Media Source checked options, we need to recalculate its default
    // Value also.
    $default_value_main_mediasource = ($this->getSetting(
        'main_mediasource'
      ) && array_key_exists(
        $this->getSetting('main_mediasource'),
        $options_for_mainsource
      )) ? $this->getSetting('main_mediasource') : reset(
      $options_for_mainsource
    );


    $default_value_main_overlaysource = ($this->getSetting(
        'main_overlaysource'
      ) && array_key_exists(
        $this->getSetting('main_overlaysource'),
        $options_for_mainsource
      )) ? $this->getSetting('main_overlaysource') : reset(
      $options_for_mainsource
    );



    // We can not use the url type for tilemap_url since the curly brackets don't validate!
    // So sad.
    $settings_form = [
        'json_key_source' => [
          '#type' => 'textfield',
          '#title' => t('JSON Key or JMESPath search string that needs to exist and not be empty to render a map.'),
          '#description' => t('Leave empty to render always a map. If present and value is empty, Map will not be rendered'),
          '#default_value' => trim($this->getSetting('json_key_source')),
        ],
        'tilemap_url' => [
          '#type' => 'textfield',
          '#title' => t('Base Map (Tiles) URL to use on this Map'),
          '#description' => t('E.g https://a.tile.openstreetmap.org/{z}/{x}/{y}.png'),
          '#default_value' => trim($this->getSetting('tilemap_url')),
          '#required' => TRUE,
        ],
        'tilemap_attribution' => [
          '#type' => 'textfield',
          '#title' => t('Attribution HTML string for the Base Map.'),
          '#description' => t('E.g &amp;copy; &lt;a href=&quot;https://openstreetmap.org/copyright&quot;&gt;OpenStreetMap contributors&lt;/a&gt;'),
          '#default_value' => trim($this->getSetting('tilemap_attribution')),
          '#required' => TRUE,
        ],
        'mediasource' => [
          '#type' => 'checkboxes',
          '#title' => $this->t('Source(s) for your GeoJSON URLs.'),
          '#options' => $all_options_form_source,
          '#default_value' => $this->getSetting('mediasource'),
          '#required' => TRUE,
          '#attributes' => [
            'data-formatter-selector' => 'mediasource',
          ],
          '#ajax' => $ajax,
        ],
        'overlaysource' => [
          '#type' => 'checkboxes',
          '#title' => $this->t('Optional: Source(s) for IIIF Manifest that will provide Georeferenced W3C Annotations for Overlays.'),
          '#options' => $all_options_form_overlaysource,
          '#default_value' => $this->getSetting('overlaysource'),
          '#required' => FALSE,
          '#attributes' => [
            'data-formatter-selector' => 'overlaysource',
          ],
          '#ajax' => $ajax2,
        ],
        'main_mediasource' => [
          '#type' => 'select',
          '#title' => $this->t(
            'Select which Source will be handled as the primary one.'
          ),
          '#options' => $options_for_mainsource,
          '#default_value' => $default_value_main_mediasource,
          '#required' => FALSE,
          '#prefix' => '<div id="main-mediasource-ajax-container">',
          '#suffix' => '</div>',
        ],
        'main_overlaysource' => [
          '#type' => 'select',
          '#title' => $this->t(
            'Select which Overlay IIIF Source will be handled as the primary one.'
          ),
          '#options' => $options_for_mainoverlaysource,
          '#default_value' => $default_value_main_overlaysource,
          '#required' => FALSE,
          '#prefix' => '<div id="main-overlaysource-ajax-container">',
          '#suffix' => '</div>',
        ],

        'metadataexposeentity_source' => [
          '#type' => 'entity_autocomplete',
          '#target_type' => 'metadataexpose_entity',
          '#title' => $this->t(
            'Select which Exposed Metadata Endpoint will generate the GeoJSON'
          ),
          '#description' => $this->t(
            'This value is used for Metadata Exposed Entities and also for Node Lists as Processing source for GeoJSON'
          ),
          '#selection_handler' => 'default',
          '#validate_reference' => TRUE,
          '#default_value' => $entity,
          '#states' => [
            [
              'visible' => [
                ':input[data-formatter-selector="mediasource"][value="metadataexposeentity"]' => ['checked' => TRUE],
              ]
            ],
            [
              'visible' => [
                ':input[data-formatter-selector="mediasource"][value="geojsonnodelist"]' => ['checked' => TRUE],
              ]
            ]
          ],
        ],
        'geojsonurl_json_key_source' => [
          '#type' => 'textfield',
          '#title' => t(
            'JSON Key from where to fetch one or more GeoJSON URLs. URLs can be external.'
          ),
          '#default_value' => $this->getSetting('geojsonurl_json_key_source'),
          '#states' => [
            'visible' => [
              ':input[data-formatter-selector="mediasource"][value="geojsonurl"]' => ['checked' => TRUE],
            ],
          ],
        ],
        'geojsonnodelist_json_key_source' => [
          '#type' => 'textfield',
          '#title' => t(
            'JSON Key from where to fetch one or more Nodes. Values can be either NODE IDs (Integers) or UUIDs (Strings). But all of the same type.'
          ),
          '#default_value' => $this->getSetting(
            'geojsonnodelist_json_key_source'
          ),
          '#states' => [
            'visible' => [
              ':input[data-formatter-selector="mediasource"][value="geojsonnodelist"]' => ['checked' => TRUE],
            ],
          ],
        ],
        'metadataexposeentity_overlaysource' => [
          '#type' => 'entity_autocomplete',
          '#target_type' => 'metadataexpose_entity',
          '#title' => $this->t(
            'Select which Exposed Metadata Endpoint will generate the IIIF Manifest with georeferenced Annotations'
          ),
          '#description' => $this->t(
            'This value is used for Metadata Exposed Entities and also for Node Lists as Processing source for IIIF Manifests'
          ),
          '#selection_handler' => 'default',
          '#validate_reference' => TRUE,
          '#default_value' => $entity_manifest,
          '#states' => [
            [
              'visible' => [
                ':input[data-formatter-selector="overlaysource"][value="metadataexposeentity"]' => ['checked' => TRUE],
              ]
            ],
            [
              'visible' => [
                ':input[data-formatter-selector="overlaysource"][value="manifestnodelist"]' => ['checked' => TRUE],
              ]
            ]
          ],
        ],

        'manifesturl_json_key_source' => [
          '#type' => 'textfield',
          '#title' => t(
            'JSON Key from where to fetch one or more IIIF Manifest URLs. URLs can be external.'
          ),
          '#default_value' => $this->getSetting('manifesturl_json_key_source'),
          '#states' => [
            'visible' => [
              ':input[data-formatter-selector="overlaysource"][value="manifesturl"]' => ['checked' => TRUE],
            ],
          ],
        ],
        'manifestnodelist_json_key_source' => [
          '#type' => 'textfield',
          '#title' => t(
            'JSON Key from where to fetch one or more Nodes to be passed as arguments for a IIIF Manifest. Values can be either NODE IDs (Integers) or UUIDs (Strings). But all of the same type.'
          ),
          '#default_value' => $this->getSetting(
            'manifestnodelist_json_key_source'
          ),
          '#states' => [
            'visible' => [
              ':input[data-formatter-selector="overlaysource"][value="manifestnodelist"]' => ['checked' => TRUE],
            ],
          ],
        ],
        'iiifstatic_url_source' => [
          '#type' => 'url',
          '#title' => t(
            'A predefined, static IIIF Manifest URL to be used as Overlay.'
          ),
          '#default_value' => $this->getSetting(
            'iiifstatic_url_source'
          ),
          '#states' => [
            'visible' => [
              ':input[data-formatter-selector="overlaysource"][value="fixediiifurl"]' => ['checked' => TRUE],
            ],
          ],
        ],

        'max_width' => [
          '#type' => 'number',
          '#title' => $this->t('Maximum width'),
          '#description' => $this->t('Use 0 to force 100% width'),
          '#default_value' => $this->getSetting('max_width'),
          '#size' => 5,
          '#maxlength' => 5,
          '#field_suffix' => $this->t('pixels'),
          '#min' => 0,
          '#required' => TRUE
        ],
        'max_height' => [
          '#type' => 'number',
          '#title' => $this->t('Maximum height'),
          '#default_value' => $this->getSetting('max_height'),
          '#size' => 5,
          '#maxlength' => 5,
          '#field_suffix' => $this->t('pixels'),
          '#min' => 0,
          '#required' => TRUE
        ],
        'initial_zoom' => [
          '#type' => 'number',
          '#title' => $this->t('Initial Zoom'),
          '#description' => $this->t('Only applies when a single Point is in the map. When more fit to bounds apply.'),
          '#default_value' => $this->getSetting('initial_zoom'),
          '#size' => 2,
          '#maxlength' => 2,
          '#min' => 1,
          '#max' => 22,
        ],
        'min_zoom' => [
          '#type' => 'number',
          '#title' => $this->t('Minimum possible Zoom'),
          '#default_value' => $this->getSetting('min_zoom'),
          '#size' => 2,
          '#maxlength' => 2,
          '#min' => 0,
          '#max' => 22,
        ],
        'max_zoom' => [
          '#type' => 'number',
          '#title' => $this->t('Maximum possible Zoom'),
          '#default_value' => $this->getSetting('max_zoom'),
          '#size' => 2,
          '#maxlength' => 2,
          '#min' => 0,
          '#max' => 22,
        ],
      ] + parent::settingsForm($form, $form_state);
    if (empty($options_for_mainsource)) {
      // let's give people a hint of what they are doing wrong
      $settings_form['main_mediasource']['#empty_option'] = t(
        '- No Source for your GeoJSON Urls. Please check at least one! -'
      );
    }
    return $settings_form;
  }

  /**
   * Ajax callback for mediasource.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   An associative array containing entity reference details element.
   */
  public static function ajaxCallbackMainSource(
    array $form,
    FormStateInterface $form_state
  ) {
    $form_parents = $form_state->getTriggeringElement()['#array_parents'];
    $form_parents = array_slice($form_parents, 0, -2);
    $form_parents[] = 'main_mediasource';
    return NestedArray::getValue($form, $form_parents);
  }

  /**
   * Ajax callback for Overlay Source.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   An associative array containing entity reference details element.
   */
  public static function ajaxCallbackOverlaySource(
    array $form,
    FormStateInterface $form_state
  ) {
    $form_parents = $form_state->getTriggeringElement()['#array_parents'];
    $form_parents = array_slice($form_parents, 0, -2);
    $form_parents[] = 'main_overlaysource';
    return NestedArray::getValue($form, $form_parents);
  }



  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary[] = $this->t(
      'Displays Map using the Leaflet and GeoJSON Sources <br>'
    );
    $summary[] = $this->t(
        'JSON Key or JMESPATH that needs to return a value for Map to render: %key',
        [
          '%key' => !empty(trim($this->getSetting('json_key_source'))) ? trim($this->getSetting('json_key_source')) : 'No restriction set'

        ]
    );
    $summary[] = $this->t(
      'Base Map (Tiles) URL: %map',
      [
        '%map' => $this->getSetting('tilemap_url'),
      ]
    );

    $summary[] = $this->t(
      'Attribution for Base map: %attributions',
      [
        '%attributions' => $this->getSetting('tilemap_attribution'),
      ]
    );

    $main_mediasource = $this->getSetting(
      'main_mediasource'
    ) ? $this->getSetting('main_mediasource') : NULL;
    $main_overlaysource = $this->getSetting(
      'main_overlaysource'
    ) ? $this->getSetting('main_overlaysource') : NULL;
    if ($this->getSetting('mediasource') && is_array($this->getSetting('mediasource'))) {
      $mediasource = $this->getSetting('mediasource');
      foreach ($mediasource as $source => $enabled) {
        $on = (string)$enabled;
        if ($on == "metadataexposeentity") {
          $entity = NULL;
          if ($this->getSetting('metadataexposeentity_source')) {
            $entity = $this->entityTypeManager->getStorage(
              'metadataexpose_entity'
            )->load($this->getSetting('metadataexposeentity_source'));
            if ($entity) {
              $label = $entity->label();
              $summary[] = $this->t(
                'GeoJSON generated by the "%metadatadisplayentity" Metadata Data Expose Endpoint%primary.',
                [
                  '%metadatadisplayentity' => $label,
                  '%primary' => ($main_mediasource == $on) ? '(PRIMARY)' : '',
                ]
              );
            }
            else {
              $summary[] = $this->t(
                'GeoJSON generated by a non existing "%metadatadisplayentity" Metadata Data Expose Endpoint%primary. Please correct this.',
                [
                  '%metadatadisplayentity' => $this->getSetting(
                    'metadataexposeentity_source'
                  ),
                  '%primary' => ($main_mediasource == $on) ? '(PRIMARY)' : '',
                ]
              );
            }
          }
          else {
            $summary[] = $this->t(
              'GeoJSON generated by the Metadata Data Expose Endpoint%primary but none set. Please setup this correctly',
              [
                '%primary' => ($main_mediasource == $on) ? '(PRIMARY)' : '',
              ]
            );
          }
          continue 1;
        }
        if ($on == "geojsonurl") {
          $summary[] = $this->t(
            'GeoJSON URL fetched from JSON "%geojsonurl_json_key_source" key%primary.',
            [
              '%geojsonurl_json_key_source' => $this->getSetting(
                'geojsonurl_json_key_source'
              ),
              '%primary' => ($main_mediasource == $on) ? '(PRIMARY)' : '',
            ]
          );
          continue 1;
        }
        if ($on == "geojsonnodelist") {
          $summary[] = $this->t(
            'GeoJSON generated from Node IDs fetched from JSON "%geojsonnodelist_json_key_source" key%primary.',
            [
              '%geojsonnodelist_json_key_source' => $this->getSetting(
                'geojsonnodelist_json_key_source'
              ),
              '%primary' => ($main_mediasource == $on) ? '(PRIMARY)' : '',
            ]
          );
          continue 1;
        }
      }
    }
    else {
      $summary[] = $this->t('This formatter still needs to be setup');
    }
    if ($this->getSetting('overlaysource') && is_array($this->getSetting('overlaysource'))) {
      $overlaysource = $this->getSetting('overlaysource');
      foreach ($overlaysource as $source => $enabled) {
        $on = (string)$enabled;
        if ($on == "metadataexposeentity") {
          $entity = NULL;
          if ($this->getSetting('metadataexposeentity_overlaysource')) {
            $entity = $this->entityTypeManager->getStorage(
              'metadataexpose_entity'
            )->load($this->getSetting('metadataexposeentity_overlaysource'));
            if ($entity) {
              $label = $entity->label();
              $summary[] = $this->t(
                'IIIF Manifest generated by the "%metadatadisplayentity" Metadata Data Expose Endpoint%primary.',
                [
                  '%metadatadisplayentity' => $label,
                  '%primary' => ($main_overlaysource == $on) ? '(PRIMARY)' : '',
                ]
              );
            }
            else {
              $summary[] = $this->t(
                'IIIF Manifest generated by a non existing "%metadatadisplayentity" Metadata Data Expose Endpoint%primary. Please correct this.',
                [
                  '%metadatadisplayentity' => $this->getSetting(
                    'metadataexposeentity_overlaysource'
                  ),
                  '%primary' => ($main_overlaysource == $on) ? '(PRIMARY)' : '',
                ]
              );
            }
          }
          else {
            $summary[] = $this->t(
              'IIIF Manifest generated by the Metadata Data Expose Endpoint%primary but none set. Please setup this correctly',
              [
                '%primary' => ($main_overlaysource == $on) ? '(PRIMARY)' : '',
              ]
            );
          }
          continue 1;
        }
        if ($on == "manifesturl") {
          $summary[] = $this->t(
            'IIIF Manifest URL fetched from JSON "%manifesturl_json_key_source" key%primary.',
            [
              '%manifesturl_json_key_source' => $this->getSetting(
                'manifesturl_json_key_source'
              ),
              '%primary' => ($main_overlaysource == $on) ? '(PRIMARY)' : '',
            ]
          );
          continue 1;
        }
        if ($on == "manifestnodelist") {
          $summary[] = $this->t(
            'IIIF Manifest generated from Node IDs fetched from JSON "%manifestnodelist_json_key_source" key%primary.',
            [
              '%manifestnodelist_json_key_source' => $this->getSetting(
                'manifestnodelist_json_key_source'
              ),
              '%primary' => ($main_overlaysource == $on) ? '(PRIMARY)' : '',
            ]
          );
          continue 1;
        }
        if ($on == "fixediiifurl") {
          $summary[] = $this->t(
            'IIIF Manifest generated static URL "%iiifstatic_url_source" key%primary.',
            [
              '%iiifstatic_url_source' => $this->getSetting(
                'iiifstatic_url_source'
              ),
              '%primary' => ($main_overlaysource == $on) ? '(PRIMARY)' : '',
            ]
          );
          continue 1;
        }
      }
    }

    $summary[] = $this->t(
      'Zoom Levels: Min(%min_zoom)|Max(%max_zoom)|Initial(%initial_zoom)',
      [
        '%min_zoom' => (int) $this->getSetting('min_zoom'),
        '%max_zoom' => $this->getSetting('max_zoom'),
        '%initial_zoom' => $this->getSetting('initial_zoom'),
      ]
    );

    return array_merge($summary, parent::settingsSummary());
  }


  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $geojsons = [];
    $iiifmanifests = [];
    $max_width = $this->getSetting('max_width');
    $max_width_css = empty($max_width) || (int) $max_width == 0 ? '100%' : $max_width .'px';
    $max_height = $this->getSetting('max_height');
    $mediasource = is_array($this->getSetting('mediasource')) ? $this->getSetting('mediasource') : [];
    $overlaysource = is_array($this->getSetting('overlaysource')) ? $this->getSetting('overlaysource') : [];
    $main_mediasource = $this->getSetting('main_mediasource');
    $main_overlaysource = $this->getSetting('main_overlaysource');

    /* @var \Drupal\file\FileInterface[] $files */
    // Fixing the key to extract while coding to 'Media'

    // This little one is a bit different to the Open Seadragon viewer.
    // Needs to deal with as type:Image and as type Document
    // Since people can setup this to a key we will handle both.
    // Main difference is how we generate the IIIF image sequence.
    // So we have at least 4 ways.
    // For type:Image its pretty much the same as Media Formatter
    // For type:Document we will use number of pages as default
    // But also allow a Table of Content if such structure exists.
    // We also allow a Twig template / Media Display to be used
    // To generate an on the Fly geojson. We coded our JS to read from geojsons
    // Finally we allow also an geojson URL to be passed.

    $nodeuuid = $items->getEntity()->uuid();
    /* @var \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem $item */
    foreach ($items as $delta => $item) {
      $main_property = $item->getFieldDefinition()
        ->getFieldStorageDefinition()
        ->getMainPropertyName();
      $value = $item->{$main_property};
      if (empty($value)) {
        continue;
      }
      /* @var array $jsondata */
      $jsondata = json_decode($item->value, TRUE);
      // @TODO use future flatversion precomputed at field level as a property
      $json_error = json_last_error();
      if ($json_error != JSON_ERROR_NONE) {
        return $elements[$delta] = ['#markup' => $this->t('ERROR')];
      }

      $json_key = trim($this->getSetting('json_key_source'));
      // Check if we have a json key and it returns an actual value
      // @TODO if the key has no . (dots) we can simply evaluate as an array key
      // That is faster.
      if (empty($json_key) || !empty($item->searchPath($json_key))) {
        // Processes GeoJSON
        foreach ($mediasource as $iiifsource) {
          $pagestrategy = (string)$iiifsource;
          switch ($pagestrategy) {
            case 'metadataexposeentity':
              $geojsons['metadataexposeentity'] = $this->processGeoJsonforMetadataExposeEntity(
                $jsondata,
                $item
              );
              continue 2;
            case 'geojsonurl':
              $geojsons['geojsonurl'] = $this->processGeoJsonforURL(
                $jsondata,
                $item
              );
              continue 2;
            case 'geojsonnodelist':
              $geojsons['geojsonnodelist'] = $this->processGeoJsonforNodeList(
                $jsondata,
                $item
              );
              continue 2;
          }
        }
        //Process IIIF Manifests
        foreach ($overlaysource as $iiifsource) {
          $pagestrategy = (string)$iiifsource;
          switch ($pagestrategy) {
            case 'metadataexposeentity':
              $iiifmanifests['metadataexposeentity'] = $this->processIIIFforMetadataExposeEntity(
                $jsondata,
                $item
              );
              continue 2;
            case 'manifesturl':
              $iiifmanifests['manifesturl'] = $this->processIIIFforURL(
                $jsondata,
                $item
              );
              continue 2;
            case 'manifestnodelist':
              $iiifmanifests['manifestnodelist'] = $this->processIIIFforNodeList(
                $jsondata,
                $item
              );
              continue 2;
            case 'fixediiifurl':
              // Easy one, directly from the key.
              $iiifmanifests['fixediiifurl'] = $this->processIIIFstaticURL();
              continue 2;
          }
        }

        // Check which one is our main source and if it really exists
        if (isset($geojsons[$main_mediasource]) && !empty($geojsons[$main_mediasource])) {
          // Take only the first since we could have more
          $main_geojsonurl = array_shift($geojsons[$main_mediasource]);
          $all_geojsonurl =  array_reduce($geojsons,'array_merge',[]);
        } else {
          // reduce flattens and applies a merge. Basically we get a simple list.
          $all_geojsonurl = array_reduce($geojsons,'array_merge',[]);
          $main_geojsonurl = array_shift($all_geojsonurl);
        }

        // Check which one is our main overlay source (if any) and if it really exists
        if (isset($iiifmanifests[$main_overlaysource]) && !empty($iiifmanifests[$main_overlaysource])) {
          // Take only the first since we could have more
          $main_iiifurl = array_shift($iiifmanifests[$main_mediasource]);
          $all_iiifurl =  array_reduce($iiifmanifests,'array_merge',[]);
        } else {
          // reduce flattens and applies a merge. Basically we get a simple list.
          $all_iiifurls = array_reduce($iiifmanifests,'array_merge',[]);
          $main_iiifurl = array_shift($all_iiifurls);
        }

        // Only process is we got at least one geojson
        if (!empty($main_geojsonurl)) {

          $groupid = 'iiif-' . $item->getName(
            ) . '-' . $nodeuuid . '-' . $delta . '-map';
          $htmlid = $groupid;

          $elements[$delta]['media'] = [
            '#type' => 'container',
            '#default_value' => $htmlid,
            '#attributes' => [
              'id' => $htmlid,
              'class' => [
                'strawberry-leaflet-item',
                'leafletViewer',
                'field-iiif',
                'container',
              ],
              'style' => "width:{$max_width_css}; height:{$max_height}px",
            ],
          ];

          // get the URL to our Metadata Expose Endpoint, we will get a string here.

          $elements[$delta]['media']['#attributes']['data-iiif-infojson'] = '';
          $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['leaflet'][$htmlid]['nodeuuid'] = $nodeuuid;
          $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['leaflet'][$htmlid]['geojsonurl'] = $main_geojsonurl;
          $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['leaflet'][$htmlid]['geojsonother'] = is_array($all_geojsonurl) ? $all_geojsonurl : [];
          /* IIIF Manifests */
          $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['leaflet'][$htmlid]['iiifurl'] = $main_iiifurl ?? [];
          $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['leaflet'][$htmlid]['iiifother'] = is_array($all_iiifurl) ? $all_iiifurl : [];

          $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['leaflet'][$htmlid]['width'] = $max_width_css;
          $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['leaflet'][$htmlid]['height'] = max(
            $max_height,
            480
          );
          $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['leaflet'][$htmlid]['maxzoom'] = $this->getSetting('max_zoom');
          $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['leaflet'][$htmlid]['minzoom'] = $this->getSetting('min_zoom');
          $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['leaflet'][$htmlid]['initialzoom'] = $this->getSetting('initial_zoom');

          $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['leaflet'][$htmlid]['tilemap_url'] = $this->getSetting('tilemap_url');
          $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['leaflet'][$htmlid]['tilemap_attribution'] = $this->getSetting('tilemap_attribution');
          $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/leaflet_strawberry';
          if (isset($item->_attributes)) {
            $elements[$delta] += ['#attributes' => []];
            $elements[$delta]['#attributes'] += $item->_attributes;
            // Unset field item attributes since they have been included in the
            // formatter output and should not be rendered in the field template.
          }
        }
      }
    }
    unset($item->_attributes);
    return $elements;
  }

  /**
   *  Generates URL string for a Twig generated geojson for the current Node.
   *
   * @param array $jsondata
   * @param \Drupal\Core\Field\FieldItemInterface $item
   * @return array
   *    A List of URLs pointing to geojson for this node.
   *    We are using an array even if we only return one
   *    to match other processgeojson Functions and have a single way
   *    of Processing them.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function processGeoJsonforMetadataExposeEntity(
    array $jsondata,
    FieldItemInterface $item
  ) {
    $entity = NULL;
    $nodeuuid = $item->getEntity()->uuid();
    $geojsons = [];

    if ($this->getSetting('metadataexposeentity_source'
    )) {
      /* @var $entity \Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity */
      $entity = $this->entityTypeManager->getStorage(
        'metadataexpose_entity'
      )->load($this->getSetting('metadataexposeentity_source'));
      if ($entity) {
        $url = $entity->getUrlForItemFromNodeUUID($nodeuuid, TRUE);
        $geojsons[] = $url;
      }
    }
    return $geojsons;
  }

  /**
   *  Generates URL string for a Twig generated IIIF Manifest for the current Node.
   *
   * @param array $jsondata
   * @param \Drupal\Core\Field\FieldItemInterface $item
   * @return array
   *    A List of URLs pointing to a IIIF Manifest for this node.
   *    We are using an array even if we only return one
   *    to match other processgeojson Functions and have a single way
   *    of Processing them.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function processIIIFforMetadataExposeEntity(
    array $jsondata,
    FieldItemInterface $item
  ) {
    $entity = NULL;
    $nodeuuid = $item->getEntity()->uuid();
    $iiifmanifests = [];

    if ($this->getSetting('metadataexposeentity_overlaysource'
    )) {
      /* @var $entity \Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity */
      $entity = $this->entityTypeManager->getStorage(
        'metadataexpose_entity'
      )->load($this->getSetting('metadataexposeentity_overlaysource'));
      if ($entity) {
        $url = $entity->getUrlForItemFromNodeUUID($nodeuuid, TRUE);
        $iiifmanifests[] = $url;
      }
    }
    return $iiifmanifests;
  }

  /**
   *  Fetches geojson URLs from a JSON Key.
   *
   * @param array $jsondata
   * @param \Drupal\Core\Field\FieldItemInterface $item
   * @return array
   *    A List of URLs pointing to a IIIF geojson for this node.
   *    We are using an array even if we only return one
   *    to match other processgeojson Functions and have a single way
   *    of Processing them.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function processGeoJsonforURL(
    array $jsondata,
    FieldItemInterface $item
  ) {

    $geojsons = [];

    if ($this->getSetting('geojsonurl_json_key_source'
    )) {
      $jsonkey = $this->getSetting('geojsonurl_json_key_source');

      if (isset($jsondata[$jsonkey])) {
        if (is_array($jsondata[$jsonkey])) {
          foreach ($jsondata[$jsonkey] as $url) {
            if (is_string($url) && UrlHelper::isValid($url, TRUE)) {
              $geojsons[] = $url;
            }
          }
        }
        else {
          if (is_string($jsondata[$jsonkey]) && UrlHelper::isValid(
              $jsondata[$jsonkey],
              TRUE
            )) {
            $geojsons[] = $jsondata[$jsonkey];
          }
        }
      }
    }
    return $geojsons;
  }

  /**
   *  Fetches IIIF Manifests URLs from a JSON Key.
   *
   * @param array $jsondata
   * @param \Drupal\Core\Field\FieldItemInterface $item
   * @return array
   *    A List of URLs pointing to a IIIF geojson for this node.
   *    We are using an array even if we only return one
   *    to match other processgeojson Functions and have a single way
   *    of Processing them.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function processIIIFforURL(
    array $jsondata,
    FieldItemInterface $item
  ) {

    $iiifmanifests = [];

    if ($this->getSetting('manifesturl_json_key_source'
    )) {
      $jsonkey = $this->getSetting('manifesturl_json_key_source');

      if (isset($jsondata[$jsonkey])) {
        if (is_array($jsondata[$jsonkey])) {
          foreach ($jsondata[$jsonkey] as $url) {
            if (is_string($url) && UrlHelper::isValid($url, TRUE)) {
              $iiifmanifests[] = $url;
            }
          }
        }
        else {
          if (is_string($jsondata[$jsonkey]) && UrlHelper::isValid(
              $jsondata[$jsonkey],
              TRUE
            )) {
            $iiifmanifests[] = $jsondata[$jsonkey];
          }
        }
      }
    }
    return $iiifmanifests;
  }

  /**
   * Generates geojson URLs from a JSON Key containing a list of nodes.
   *
   * This function reuses 'metadataexposeentity_json_key_source'
   *
   * @param array $jsondata
   * @param \Drupal\Core\Field\FieldItemInterface $item
   * @return array
   *    A List of URLs pointing to a IIIF geojson for this node.
   *    We are using an array even if we only return one
   *    to match other processgeojson Functions and have a single way
   *    of Processing them.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function processGeoJsonforNodeList(
    array $jsondata,
    FieldItemInterface $item
  ) {
    $geojsons = [];
    $cleannodelist = [];
    if ($this->getSetting('geojsonnodelist_json_key_source') && $this->getSetting('metadataexposeentity_source')) {
      $jsonkey = $this->getSetting('geojsonnodelist_json_key_source');
      $entity = $this->entityTypeManager->getStorage(
        'metadataexpose_entity'
      )->load($this->getSetting('metadataexposeentity_source'));
      if ($entity) {
        $access_manager = \Drupal::service('access_manager');
        if (isset($jsondata[$jsonkey])) {
          if (is_array($jsondata[$jsonkey])) {
            $cleannodelist = [];
            foreach ($jsondata[$jsonkey] as $nodeid) {
              if (is_integer($nodeid)) {
                $cleannodelist[] = $nodeid;
              }
            }
          }
          else {
            if (is_integer($jsondata[$jsonkey])) {
              $cleannodelist[] = $jsondata[$jsonkey];
            }
          }

          foreach ($this->entityTypeManager->getStorage('node')->loadMultiple(
            $cleannodelist
          ) as $node) {
            $has_access = $access_manager->checkNamedRoute(
              'format_strawberryfield.metadatadisplay_caster',
              [
                'node' => $node->uuid(),
                'metadataexposeconfig_entity' => $entity->id(),
                'format' => 'geojson.json'
              ],
              $this->currentUser
            );
            if ($has_access) {
              $geojsons[] = $entity->getUrlForItemFromNodeUUID(
                $node->uuid(),
                TRUE
              );
            }
          }
        }
      }
    }
    return $geojsons;
  }
  /**
   * Generates geojson URLs from a JSON Key containing a list of nodes.
   *
   * This function reuses 'metadataexposeentity_json_key_source'
   *
   * @param array $jsondata
   * @param \Drupal\Core\Field\FieldItemInterface $item
   * @return array
   *    A List of URLs pointing to a IIIF geojson for this node.
   *    We are using an array even if we only return one
   *    to match other processgeojson Functions and have a single way
   *    of Processing them.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function processIIIFforNodeList(
    array $jsondata,
    FieldItemInterface $item
  ) {
    $iiifmanifests = [];
    $cleannodelist = [];
    if ($this->getSetting('manifestnodelist_json_key_source') && $this->getSetting('metadataexposeentity_overlaysource')) {
      $jsonkey = $this->getSetting('manifestnodelist_json_key_source');
      $entity = $this->entityTypeManager->getStorage(
        'metadataexpose_entity'
      )->load($this->getSetting('metadataexposeentity_overlaysource'));
      if ($entity) {
        $access_manager = \Drupal::service('access_manager');
        if (isset($jsondata[$jsonkey])) {
          if (is_array($jsondata[$jsonkey])) {
            $cleannodelist = [];
            foreach ($jsondata[$jsonkey] as $nodeid) {
              if (is_integer($nodeid)) {
                $cleannodelist[] = $nodeid;
              }
            }
          }
          else {
            if (is_integer($jsondata[$jsonkey])) {
              $cleannodelist[] = $jsondata[$jsonkey];
            }
          }

          foreach ($this->entityTypeManager->getStorage('node')->loadMultiple(
            $cleannodelist
          ) as $node) {
            $has_access = $access_manager->checkNamedRoute(
              'format_strawberryfield.metadatadisplay_caster',
              [
                'node' => $node->uuid(),
                'metadataexposeconfig_entity' => $entity->id(),
                'format' => 'iiifmanifest.jsonld'
              ],
              $this->currentUser
            );
            if ($has_access) {
              $iiifmanifests[] = $entity->getUrlForItemFromNodeUUID(
                $node->uuid(),
                TRUE
              );
            }
          }
        }
      }
    }
    return $iiifmanifests;
  }

  /**
   *  Validates the fixed URL.
   *
   * @return array
   *    with a single URL if the passed setting passes sanity.
   *
   */
  public function processIIIFstaticURL() {

    $iiifmanifests = [];
    if ($this->getSetting('iiifstatic_url_source'
    )) {
      $url = $this->getSetting('iiifstatic_url_source');
      if (is_string($url) && UrlHelper::isValid($url, TRUE)) {
        $iiifmanifests[] = $url;
      }
    }
    return $iiifmanifests;
  }
}
