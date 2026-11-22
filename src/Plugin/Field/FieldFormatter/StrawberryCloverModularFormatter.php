<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 13/10/2026
 * Time: 11:36 AM
 */

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\format_strawberryfield\Controller\WebAnnotationController;
use Drupal\format_strawberryfield\EmbargoResolverInterface;
use Drupal\Core\Url;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Component\Utility\NestedArray;

/**
 * Clover 4 Viewer Strawberry Field formatter.
 */
#[FieldFormatter(
  id: 'strawberry_clover_modular_formatter',
  label: new TranslatableMarkup('Strawberry Field Media Formatter using the Clover 4 IIIF Viewer plugin'),
  field_types: ['strawberryfield_field']
)]
class StrawberryCloverModularFormatter extends StrawberryBaseFormatter implements ContainerFactoryPluginInterface {


  private const array ALLOWED_CLOVER_PROPS = [
    'template',
    'language',
    'high-contrast',
    'annotations',
    'annotations-shown',
    'heading-level',
    'items',
    'single-item',
    'header',
    'panel',
    'download',
    'languages',
    'interface-languages',
    'panel-open',
    'panel-toggle',
    'panel-layout',
    'panel-sections',
    'part-of-collection',
    'panel-labels',
    'sidebar-open',
    'page-layout',
    'storage-key',
    'fill',
    'collapse-controls',
    'size',
    'height',
    'gap',
    'secondary',
    'summary',
    'lightbox-items'
  ];

  private const array DISALLOWED_CLOVER_PROPS = [
    'search-query',
    'iiif-content',
    'canvas-id',
    'manifest-id'
  ];

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The Twig environment.
   *
   * @var \Drupal\Core\Template\TwigEnvironment
   */
  protected $twig;


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
   * @param \Drupal\Core\Template\TwigEnvironment $twigEnvironment
   *    The Loaded twig Environment.
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
    TwigEnvironment $twigEnvironment,
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
    $this->twig = $twigEnvironment;
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
      $container->get('twig'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('format_strawberryfield.embargo_resolver')
    );
  }


  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    $settings = parent::defaultSettings();
    unset($settings['hide_on_embargo']);
    return $settings + [
        'mediasource' => [
          'metadataexposeentity' => 'metadataexposeentity',
        ],
        'main_mediasource' => 'metadataexposeentity',
        'metadataexposeentity_source' => NULL,
        'metadatadisplayentity_uuid' => NULL,
        'clover_type' => 'unified',
        'unified_template' => 'default',
        'unified_items' => 'false',
        'unified_panel_sections' => ['about', 'search'],
        'viewer_overrides' => '',
        'webannotations' => FALSE,
        'content_state_drop' => FALSE,
        'content_state_url_update' => FALSE,
        'max_width' => 720,
        'max_height' => 720,
        'hide_on_embargo' => FALSE,
      ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $entity = NULL;
    $entity_metadatadisplay = NULL;
    if ($this->getSetting('metadataexposeentity_source')) {
      $entity = $this->entityTypeManager->getStorage(
        'metadataexpose_entity'
      )->load($this->getSetting('metadataexposeentity_source'));
    }

    if ($this->getSetting('metadatadisplayentity_uuid')) {
      $entities = $this->entityTypeManager->getStorage('metadatadisplay_entity')->loadByProperties(['uuid' => $this->getSetting('metadatadisplayentity_uuid')]);
      $entity_metadatadisplay = reset($entities);
    }


    $options_for_mainsource = is_array(
      $this->getSetting('mediasource')
    ) && !empty($this->getSetting('mediasource')) ? $this->getSetting(
      'mediasource'
    ) : self::defaultSettings()['mediasource'];

    if (($triggering_element = $form_state->getTriggeringElement(
      )) && isset($triggering_element['#ajax']['callback'])) {
      // We are getting the actual checkbox value pressed in the parents array.
      // so we need to slice by 1 at the end.
      // if Ajax class of the triggering element is this class then process
      if ($triggering_element['#ajax']['callback'][0] == static::class) {
        $parents = array_slice($triggering_element['#parents'], 0, -1);
        $options_for_mainsource = $form_state->getValue($parents);
      }
    }
    $all_options_form_source = [
      'metadataexposeentity' => $this->t(
        'A IIIF Manifest generated by a Metadata Display template'
      )
    ];
    $options_for_mainsource = array_filter($options_for_mainsource);
    $options_for_mainsource = array_intersect_key(
      $options_for_mainsource,
      $all_options_form_source
    );

    // Define #ajax callback.
    $ajax = [
      'callback' => [static::class, 'ajaxCallbackMainSource'],
      'wrapper' => 'main-mediasource-ajax-container',
    ];
    // Because main media source needs to update its choices based on
    // Media Source checked options, we need to recalculate its default
    // Value also.
    $default_value_main_mediasoruce = ($this->getSetting(
        'main_mediasource'
      ) && array_key_exists(
        $this->getSetting('main_mediasource'),
        $options_for_mainsource
      )) ? $this->getSetting('main_mediasource') : reset(
      $options_for_mainsource
    );
    $settings_form = [
        'mediasource' => [
          '#type' => 'checkboxes',
          '#title' => $this->t('Source for your IIIF Manifest URLs.'),
          '#options' => $all_options_form_source ?? [],
          '#default_value' => $this->getSetting('mediasource') ?? [],
          '#required' => TRUE,
          '#attributes' => [
            'data-clover-formatter-selector' => 'mediasource',
          ],
          '#ajax' => $ajax,
        ],
        'clover_type' => [
          '#type' => 'radios',
          '#options' => ['unified' => 'Unified Viewer', 'modular' => 'Modular via Metadata Display template'],
          '#title' => $this->t('Clover Viewer setup type'),
          '#description' => $this->t('The Unified Viewer generates a complete viewer and components via configurations but the layout itself is fixed. The Modular ones requires you to provide a Metadata Display (HTML) entity and through this fully customize the Layout'),
          '#default_value' => $this->getSetting('clover_type') ?? 'unified',
          '#attributes' => [
            'data-clover-formatter-selector' => 'clover_type',
          ]
        ],
        'unified_settings' => [
          '#type' => 'fieldset',
          '#title' => $this->t('Unified Viewer Settings'),
          '#description' => $this->t("See <a href=\"https://clover.iiif.library.northwestern.edu/docs/viewer#api-reference/\">https://clover.iiif.library.northwestern.edu/docs/viewer#api-reference</a>"),
          '#states' => [
            'visible' => [
              ':input[data-clover-formatter-selector="clover_type"]' => ['value' => 'unified'],
            ],
          ],
          '#process' => [[static::class, 'flattenFieldsetParents']],
          'unified_template' => [
            '#tree' => FALSE,
            '#type' => 'select',
            '#title' => $this->t(
              'Sets the "layout" config property. Select which default Layout to use.'
            ),
            '#options' => [
              'default' => $this->t('Default'),
              'document' => $this->t('Document'),
              'gallery' => $this->t('Gallery'),
            ],
            '#default_value' => $this->getSetting('unified_template') ?? 'default',
            '#required' => TRUE,
          ],
          'unified_items' => [
            '#tree' => FALSE,
            '#type' => 'select',
            '#title' => $this->t(
              'Sets the "items" config property. Select which style to use for the Items Panel. Applies to "Default" and "Gallery" layouts.'
            ),
            '#options' => [
              'false' => $this->t('No Items Panel'),
              'slider' => $this->t('Slider'),
              'grid' => $this->t('Grid'),
              'Masonry' => $this->t('Masonry'),
            ],
            '#default_value' => $this->getSetting('unified_items') ?? 'false',
            '#required' => FALSE,
          ],
          'unified_panel_sections' => [
            '#tree' => FALSE,
            '#type' => 'checkboxes',
            '#title' => $this->t(
              'Sets the "panel-sections" config property. Select which Panel Sections you want to enable. Only panels for whose data exist will be rendered.'
            ),
            '#options' => [
              'about' => $this->t('About'),
              'contents' => $this->t('Contents'),
              'search' => $this->t('Search'),
              'transcript' => $this->t('Transcript'),
              'annotations' => $this->t('Annotations'),
              'map' => $this->t('Map'),
            ],
            '#default_value' => $this->getSetting('unified_panel_sections') ?? ['contents'],
            '#required' => TRUE,
          ],
          'viewer_overrides' => [
            '#type' => 'textarea',
            '#title' => $this->t('Advanced: a JSON with Clover Viewer configuration overrides.'),
            '#description' => $this->t("See <a href=\"https://clover.iiif.library.northwestern.edu/docs/viewer#api-reference/\">https://clover.iiif.library.northwestern.edu/docs/viewer#api-reference</a>. Leave Empty to use defaults.
   Use with caution. Note: The following properties: [@not_allowed], if passed, will not be overridden and are managed by this formatter directly. If you override any property that are provided as a simplified config in this same form, the ones in this JSON will have priority. An ADO can also override this formatter's settings by providing e.g. the following JSON key (notice the 'config' top key): @ado_override",[
              '@ado_override' => json_encode(["ap:viewerhints" => ["strawberry_clover_modular_formatter"=> ["config" => ["download" => TRUE, "heading-level" => 3]]]], JSON_FORCE_OBJECT|JSON_PRETTY_PRINT),
              '@not_allowed' => implode(",", StrawberryCloverModularFormatter::DISALLOWED_CLOVER_PROPS)
            ]),
            '#default_value' => $this->getSetting('viewer_overrides'),
            '#element_validate' => [[$this, 'validateJSON']],
            '#required' => FALSE,
          ],
        ],
        'modular_settings' => [
          '#type' => 'fieldset',
          '#title' => $this->t('Modular Viewer Settings'),
          '#states' => [
            'visible' => [
              ':input[data-clover-formatter-selector="clover_type"]' => ['value' => 'modular'],
            ],
          ],
          '#process' => [[static::class, 'flattenFieldsetParents']],
          'metadatadisplayentity_uuid' => [
            '#type' => 'sbf_entity_autocomplete_uuid',
            '#title' => $this->t('Choose an HTML producing metadata template (Start typing! Autocomplete.)'),
            '#target_type' => 'metadatadisplay_entity',
            '#description' => $this->t('When using the Module Clover viewer, this Metadata Display Entity allows you (and requires you to) you  provide your chosen internal Clover Render elements. We will wrap everything around a <a href="https://clover.iiif.library.northwestern.edu/docs/root">"clover-root"</a> tag and pass the initialization settings. The rest is on you. Please see all components starting at <a href="https://clover.iiif.library.northwestern.edu/docs/composing/glossary">https://clover.iiif.library.northwestern.edu/docs/composing/glossary</a>'),
            '#selection_handler' => 'default:metadatadisplay',
            '#validate_reference' => TRUE,
            '#selection_settings' => [
              'filter' => [
                'mimetype' => ['text/html']
              ]
            ],
            '#required' => FALSE,
            '#maxlength' => 300,
            '#default_value' => $entity_metadatadisplay,
            '#states' => [
              'required' => [
                ':input[data-clover-formatter-selector="clover_type"]' => ['value' => 'modular'],
              ],
            ],
          ],
        ],
        'main_mediasource' => [
          '#type' => 'select',
          '#title' => $this->t(
            'Select which Source will be handled as the primary one.'
          ),
          '#options' => $options_for_mainsource,
          '#default_value' => $default_value_main_mediasoruce,
          '#required' => FALSE,
          '#prefix' => '<div id="main-mediasource-ajax-container">',
          '#suffix' => '</div>',
        ],
        'metadataexposeentity_source' => [
          '#type' => 'entity_autocomplete',
          '#target_type' => 'metadataexpose_entity',
          '#title' => $this->t(
            'Sets the "iiif-content" config property. Select which Exposed Metadata Endpoint will generate the main Manifest'
          ),
          '#selection_handler' => 'default',
          '#validate_reference' => TRUE,
          '#default_value' => $entity,
          '#states' => [
            [
              'visible' => [
                ':input[data-clover-formatter-selector="mediasource"][value="metadataexposeentity"]' => ['checked' => TRUE],
              ]
            ],
          ],
        ],
        'webannotations' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Enable loading of W3C WebAnnotations.'),
          '#description' => $this->t('<a href="https://www.w3.org/TR/annotation-model/#index-of-json-keys">Click here</a> To learn more about the JSON format of a Web Annotation.'),
          '#default_value' => $this->getSetting('webannotations'),
          '#attributes' => [
            'data-formatter-selector' => 'webannotations',
          ],
          '#states' => [
            'visible' => [
              ':input[data-clover-formatter-selector="enabled-plugins"][value="plugin-annotation-editor"]' => ['checked' => TRUE],
            ]
          ],
          '#default_value' => $this->getSetting('webannotations') ?? FALSE
        ],
        'content_state_drop' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Enable processing of URL based IIIF Content State arguments.'),
          '#description' => $this->t('<a href="https://iiif.io/api/content-state/1.0/">Click here</a> To learn more about the Content State. When enabled Clover will parse/process any URL holding a `?iiif-content={base64encoded_content_state}` GET argument.It has priority over the Archipelago specific #/page/{number}/search/{query} fragment'),
          '#default_value' => $this->getSetting('content_state_drop'),
          '#attributes' => [
            'data-formatter-selector' => 'content_state_drop',
          ],
          '#default_value' => $this->getSetting('content_state_drop') ?? FALSE
        ],
        'content_state_url_update'=> [
          '#type' => 'checkbox',
          '#title' => $this->t('Enable URL to be updated with a IIIF Content State reflecting the current Canvas'),
          '#description' => $this->t('When enabled Clover will process the current Viewer state into a `?iiif-content={base64encoded_content_state}` GET argument replaced over the current URL. Other arguments will be preserved but the Archipelago specific #/page/{number}/search/{query} fragment will be removed.'),
          '#default_value' => $this->getSetting('content_state_drop'),
          '#attributes' => [
            'data-formatter-selector' => 'content_state_drop',
          ],
          '#default_value' => $this->getSetting('content_state_drop') ?? FALSE
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
        'hide_on_embargo' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Hide the Viewer in the presence of an Embargo.'),
          '#description' => $this->t('If unchecked, acting on an embargo will be delegated to the IIIF Manifest driving the viewer.'),
          '#default_value' => $this->getSetting('hide_on_embargo') ?? FALSE,
          '#required' => FALSE,
          '#attributes' => [
            'data-clover-formatter-selector' => 'hide_on_embargo',
          ],
        ],
      ] + parent::settingsForm($form, $form_state);
    unset($settings_form['upload_json_key_source'], $settings_form['embargo_json_key_source']);
    if (empty($options_for_mainsource)) {
      // let's give people a hint of what they are doing wrong
      $settings_form['main_mediasource']['#empty_option'] = t(
        '- No Source for your IIIF Manifest Urls. Please check one! -'
      );

    }
    return $settings_form;
  }

  /**
   * Ajax callback.
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
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary[] = $this->t(
      'Displays Media using the Clover IIIF viewer <br>'
    );
    $main_mediasource = $this->getSetting(
      'main_mediasource'
    ) ?: NULL;
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
                'IIIF Manifest generated by the "%metadatadisplayentity" Metadata Data Expose Endpoint%primary.',
                [
                  '%metadatadisplayentity' => $label,
                  '%primary' => ($main_mediasource == $on) ? '(PRIMARY)' : '',
                ]
              );
            }
            else {
              $summary[] = $this->t(
                'IIIF Manifest generated by a non existing "%metadatadisplayentity" Metadata Data Expose Endpoint%primary. Please correct this.',
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
              'IIIF Manifest generated by the Metadata Data Expose Endpoint%primary but none set. Please setup this correctly',
              [
                '%primary' => ($main_mediasource == $on) ? '(PRIMARY)' : '',
              ]
            );
          }
          continue 1;
        }
      }
    }
    else {
      $summary[] = $this->t('This formatter still needs to be setup');
    }
    if ($this->getSetting('webannotations')) {
      $summary[] = $this->t('Enable W3C WebAnnotations: %webannotations', [
        '%webannotations' => $this->getSetting('webannotations'),
      ]);
    }

    $summary[] = $this->t(
      'Maximum size: %max_width x %max_height',
      [
        '%max_width' => (int) $this->getSetting('max_width') == 0 ? '100%' : $this->getSetting('max_width') . ' pixels',
        '%max_height' => $this->getSetting('max_height') . ' pixels',
      ]
    );

    return array_merge($summary, parent::settingsSummary());
  }


  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $max_width = $this->getSetting('max_width');
    $max_width_css = empty($max_width) || $max_width == 0 ? '100%' : $max_width .'px';
    $max_height = $this->getSetting('max_height');
    $webannotations = $this->getSetting('webannotations') ?? FALSE;
    $mediasource = is_array($this->getSetting('mediasource')) ? $this->getSetting('mediasource') : [];
    $main_mediasource = $this->getSetting('main_mediasource');
    $hide_on_embargo =  $this->getSetting('hide_on_embargo') ?? FALSE;
    $viewer_overrides = $this->getSetting('viewer_overrides') ?? '';
    $content_state_drop = $this->getSetting('content_state_drop') ?? FALSE;
    $content_state_url_update = $this->getSetting('content_state_url_update') ?? FALSE;
    $unified_template = $this->getSetting('unified_template') ?? 'default';
    $unified_items = $this->getSetting('unified_items') ?? FALSE;
    $unified_panel_sections = $this->getSetting('unified_panel_sections') ?? ['about', 'search'];
    $unified_panel_sections = json_encode(array_filter(array_values($unified_panel_sections)));
    $clover_viewer_type = $this->getSetting('clover_type') ?? 'unified';
    $viewer_overrides_json = json_decode(trim($viewer_overrides), TRUE);
    $json_error = json_last_error();
    if ($json_error == JSON_ERROR_NONE) {
      $viewer_overrides = $viewer_overrides_json;
    }
    else {
      $viewer_overrides = NULL;
    }
    $metadatadisplayentity_uuid = NULL;
    if ($clover_viewer_type == 'modular') {
      $metadatadisplayentity_uuid = $this->getSetting('metadatadisplayentity_uuid');
    }

    $embargo_info = [];
    $embargo_context = [];
    $embargo_tags = [];
    $embargoed = FALSE;

    $nodeuuid = $items->getEntity()->uuid();
    /* @var FieldItemInterface $item */
    foreach ($items as $delta => $item) {
      $main_property = $item->getFieldDefinition()
        ->getFieldStorageDefinition()
        ->getMainPropertyName();
      $value = $item->{$main_property};
      if (empty($value)) {
        continue;
      }
      // Won't be set if $clover_viewer_type == 'unified' so won't fail.
      if ($metadatadisplayentity_uuid) {
        $metadatadisplayentities = $this->entityTypeManager->getStorage('metadatadisplay_entity')
          ->loadByProperties(['uuid' => $this->getSetting('metadatadisplayentity_uuid')]);
        /** @var \Drupal\format_strawberryfield\Entity\MetadataDisplayEntity $metadatadisplayentity */
        $metadatadisplayentity = reset($metadatadisplayentities);
        if ($metadatadisplayentity == NULL) {
          // Wrong setting, the template is not there so we skip, same as if no node data.
          continue;
        }
      }

      /* @var array $jsondata */
      $jsondata = json_decode($item->value, TRUE);
      // @TODO use future flatversion precomputed at field level as a property
      $json_error = json_last_error();
      if ($json_error != JSON_ERROR_NONE) {
        return ['#markup' => $this->t('ERROR')];
      }

      if (isset($jsondata["ap:viewerhints"][$this->getPluginId()]) &&
        is_array($jsondata["ap:viewerhints"][$this->getPluginId()]) &&
        !empty($jsondata["ap:viewerhints"][$this->getPluginId()])) {
        // if we could decode it, it is already JSON.
        $viewer_overrides = $jsondata["ap:viewerhints"][$this->getPluginId()]['config'] ?? $viewer_overrides;
      }
      // Since we are not mergimg settimgs (either the ADO or the global ones win)
      //we can clean here now.
      if (!empty($viewer_overrides) && is_array($viewer_overrides)) {
        // We now clean here
        $viewer_overrides = array_intersect_key(
          array_diff_key($viewer_overrides, array_flip(self::DISALLOWED_CLOVER_PROPS)),
          array_flip(self::ALLOWED_CLOVER_PROPS)
        );
      }

      $embargo_info = $this->embargoResolver->embargoInfo(
        $item->getEntity(), $jsondata
      );
      $context_embargo = ['data_embargo' => ['embargoed' => false, 'until' => NULL]];

      if (is_array($embargo_info)) {
        $embargoed = $embargo_info[0];
        $context_embargo['data_embargo']['embargoed'] = $embargoed;

        $embargo_tags[] = 'format_strawberryfield:all_embargo';
        if ($embargo_info[1]) {
          $embargo_tags[]= 'format_strawberryfield:embargo:'.$embargo_info[1];
          $context_embargo['data_embargo']['until'] = $embargo_info[1];
        }
        // even if not embargoed for a particular user, set to IP if not cacheable
        if ($embargo_info[2] || ($embargo_info[3] == FALSE)) {
          $embargo_context[] = 'ip';
        }
      }
      else {
        $context_embargo['data_embargo']['embargoed'] = $embargo_info;
      }

      // Only process render elements if hide on embargo is TRUE
      if (!$embargoed || ($embargoed && !$hide_on_embargo)) {
        foreach ($mediasource as $iiifsource) {
          $pagestrategy = (string)$iiifsource;
          switch ($pagestrategy) {
            case 'metadataexposeentity':
              $manifests['metadataexposeentity'] = $this->processManifestforMetadataExposeEntity(
                $jsondata,
                $item
              );
              continue 2;
            case 'manifesturl':
              $manifests['manifesturl'] = $this->processManifestforURL(
                $jsondata,
                $item
              );
              continue 2;
            case 'manifestnodelist':
              $manifests['manifestnodelist'] = $this->processManifestforNodeList(
                $jsondata,
                $item
              );
              continue 2;
          }
        }
        // these before checking what type of viewer we are aiming at,
        // because we will reuse them.
        $groupid = 'iiif-' . $item->getName() . '-' . $nodeuuid . '-' . $delta
          . '-clover';
        $htmlid = $groupid;
        // Check which one is our main source and if it really exists
        if (isset($manifests[$main_mediasource]) && !empty($manifests[$main_mediasource])) {
          // Take only the first since we could have more
          $main_manifesturl = array_shift($manifests[$main_mediasource]);
          $all_manifesturl =  array_reduce($manifests,array_merge(...),[]);
        } else {
          // reduce flattens and applies a merge. Basically we get a simple list.
          $all_manifesturl = array_reduce($manifests,array_merge(...),[]);
          $main_manifesturl = array_shift($all_manifesturl);
        }

        // Only process is we got at least one manifest
        if (!empty($main_manifesturl)) {
          $elements[$delta]['wrappers'] =  [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#attributes' => [
              'style' => "width:{$max_width_css}; height:{$max_height}px;position:relative;overflow:hidden;",
            ]
          ];
          if ($clover_viewer_type == 'unified') {
            $elements[$delta]['wrappers']['media'] = [
              '#type' => 'html_tag',
              '#tag' => 'clover-viewer',
              '#default_value' => $htmlid,
              '#attributes' => [
                'iiif-content' => $main_manifesturl,
                'template' => $unified_template,
                'items' => $unified_items,
                'panel-sections' => $unified_panel_sections,
                'header' => 'true', // Having the Header inside a page by default true is bad idea
                'id' => $htmlid,
                'class' => [
                  'strawberry-clover-item',
                  'CloverViewer',
                  'field-iiif',
                ],
                'style' => "width:{$max_width_css};",
              ],
            ];
            if (is_array($viewer_overrides)) {
              // here we override what can be overridden.
              foreach ($viewer_overrides as $viewer_override_key => $viewer_override_value) {
                $value_for_prop = match ($viewer_override_value) {
                  true => "true",
                  false => "false",
                  default => is_array($viewer_override_value) ? json_encode($viewer_override_value) : $viewer_override_value,
                };
                $elements[$delta]['wrappers']['media']['#attributes'][$viewer_override_key] = $value_for_prop;
              }
            }
          }
          elseif ($clover_viewer_type == 'modular') {
            try {
              $ordersubkey = 'sequence';
              foreach (StrawberryfieldJsonHelper::AS_FILE_TYPE as $key) {
                StrawberryfieldJsonHelper::orderSequence($jsondata, $key, $ordersubkey);
              }
              $context = [
                  'data' => $jsondata,
                  'node' => $items->getEntity(),
                  'iiif_server' => $this->getIiifUrls()['public'],
                ] + $context_embargo;
              $original_context = $context;

              // Allow other modules to provide extra Context!
              // Call modules that implement the hook, and let them add items.
              \Drupal::moduleHandler()
                ->alter('format_strawberryfield_twigcontext', $context);
              // In case someone decided to wipe the original context?
              // We bring it back!
              $context = $context + $original_context;
              $templaterenderelement = $metadatadisplayentity->processHtml($context);
              $elements[$delta]['wrappers']['media'] = [
                '#type' => 'html_tag',
                '#tag' => 'clover-root',
                '#default_value' => $htmlid,
                '#attributes' => [
                  'iiif-content' => $main_manifesturl,
                  'id' => $htmlid,
                  'class' => [
                    'strawberry-clover-item',
                    'CloverRoot',
                    'field-iiif',
                  ],
                  'style' => "width:{$max_width_css}; height:{$max_height}px",
                ],
              ];
              $elements[$delta]['wrappers']['media']['clover_components']  = $templaterenderelement;
            }
            catch (\Exception) {
              $elements[$delta] = [
                '#markup' => 'This viewer can not be displayed.'
              ];
            }
          }

          if ($content_state_drop) {
            $elements[$delta]['wrappers']['absolute']['media']['#attributes']['read-content-state-from-url'] = $content_state_drop;
          }

          // get the URL to our Metadata Expose Endpoint, we will get a string here.
          $elements[$delta]['wrappers']['absolute']['media']['#attributes']['data-iiif-infojson'] = '';
          $elements[$delta]['wrappers']['absolute']['media']['#attached']['drupalSettings']['format_strawberryfield']['clover'][$htmlid]['nodeuuid']
            = $nodeuuid;
          $elements[$delta]['wrappers']['absolute']['media']['#attached']['drupalSettings']['format_strawberryfield']['clover'][$htmlid]['manifesturl']
            = $main_manifesturl;
          $elements[$delta]['wrappers']['absolute']['media']['#attached']['drupalSettings']['format_strawberryfield']['clover'][$htmlid]['width']
            = $max_width_css;
          $elements[$delta]['wrappers']['absolute']['media']['#attached']['drupalSettings']['format_strawberryfield']['clover'][$htmlid]['height']
            = max(
              $max_height,
              480
            );
          $elements[$delta]['wrappers']['absolute']['media']['#attached']['drupalSettings']['format_strawberryfield']['clover'][$htmlid]['viewer_overrides'] = $viewer_overrides;
          $elements[$delta]['wrappers']['absolute']['media']['#attached']['drupalSettings']['format_strawberryfield']['clover'][$htmlid]['content_state_url_update'] = $content_state_url_update;
          $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/clover_strawberry';
          if ( $this->currentUser && $this->currentUser->hasPermission('view strawberryfield webannotation') && $webannotations) {
            $elements[$delta]['wrappers']['absolute']['media']['#attached']['drupalSettings']['format_strawberryfield']['clover'][$htmlid]['webannotations'] = (boolean) $webannotations ?? FALSE;
            $elements[$delta]['wrappers']['absolute']['media']['#attached']['drupalSettings']['format_strawberryfield']['clover'][$htmlid]['keystoreid'] = WebAnnotationController::getTempStoreKeyNameForAnnotations($nodeuuid);
            $elements[$delta]['wrappers']['absolute']['media']['#attached']['drupalSettings']['format_strawberryfield']['clover'][$htmlid]['user']['url'] = Url::fromRoute('entity.user.canonical', [
              'user' => $this->currentUser->getAccount()
                ->id()
            ])->toString();
            $elements[$delta]['wrappers']['absolute']['media']['#attached']['drupalSettings']['format_strawberryfield']['clover'][$htmlid]['user']['name'] = $this->currentUser->getAccount()
              ->getAccountName();
          }
        }
      }
      if (empty($elements[$delta])) {
        $elements[$delta] = [
          '#markup' => '<i class="d-none field-iiif-no-viewer"></i>',
          '#prefix' => '<span>',
          '#suffix' => '</span>',
        ];
      }

      if (isset($item->_attributes)) {
        $elements[$delta] += ['#attributes' => []];
        $elements[$delta]['#attributes'] += $item->_attributes;
        // Unset field item attributes since they have been included in the
        // formatter output and should not be rendered in the field template.
        unset($item->_attributes);
      }

      // Get rid of empty #attributes key to avoid render error
      if (isset($elements[$delta]["#attributes"]) && empty($elements[$delta]["#attributes"])) {
        unset($elements[$delta]["#attributes"]);
      }
    }

    $elements['#cache'] = [
      'context' => Cache::mergeContexts($items->getEntity()->getCacheContexts(), ['user.permissions', 'user.roles'], $embargo_context),
      'tags' => Cache::mergeTags($items->getEntity()->getCacheTags(), $embargo_tags, ['config:format_strawberryfield.embargo_settings']),
    ];
    if (isset($embargo_info[3]) && $embargo_info[3] === FALSE) {
      $elements['#cache']['max-age'] = 0;
    }

    return $elements;
  }

  /**
   *  Generates URL string for a Twig generated manifest for the current Node.
   *
   * @param array $jsondata
   * @param \Drupal\Core\Field\FieldItemInterface $item
   * @return array
   *    A List of URLs pointing to a IIIF Manifest for this node.
   *    We are using an array even if we only return one
   *    to match other processManifest Functions and have a single way
   *    of Processing them.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function processManifestforMetadataExposeEntity(
    array $jsondata,
    FieldItemInterface $item
  ) {
    $nodeuuid = $item->getEntity()->uuid();
    $manifests = [];

    if ($this->getSetting('metadataexposeentity_source'
    )) {
      /* @var $entity \Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity */
      $entity = $this->entityTypeManager->getStorage(
        'metadataexpose_entity'
      )->load($this->getSetting('metadataexposeentity_source'));
      if ($entity) {
        $url = $entity->getUrlForItemFromNodeUUID($nodeuuid, TRUE);
        $manifests[] = $url;
      }
    }
    return $manifests;
  }

  /**
   *  Fetches Manifest URLs from a JSON Key.
   *
   * @param array $jsondata
   * @param \Drupal\Core\Field\FieldItemInterface $item
   * @return array
   *    A List of URLs pointing to a IIIF Manifest for this node.
   *    We are using an array even if we only return one
   *    to match other processManifest Functions and have a single way
   *    of Processing them.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function processManifestforURL(
    array $jsondata,
    FieldItemInterface $item
  ) {

    $manifests = [];

    if ($this->getSetting('manifesturl_json_key_source'
    )) {
      $jsonkey = $this->getSetting('manifesturl_json_key_source');

      if (isset($jsondata[$jsonkey])) {
        if (is_array($jsondata[$jsonkey])) {
          foreach ($jsondata[$jsonkey] as $url) {
            if (is_string($url) && UrlHelper::isValid($url, TRUE)) {
              $manifests[] = $url;
            }
          }
        }
        else {
          if (is_string($jsondata[$jsonkey]) && UrlHelper::isValid(
              $jsondata[$jsonkey],
              TRUE
            )) {
            $manifests[] = $jsondata[$jsonkey];
          }
        }
      }
    }
    return $manifests;
  }

  /**
   * Generates Manifest URLs from a JSON Key containing a list of nodes.
   *
   * This function reuses 'metadataexposeentity_json_key_source'
   *
   * @param array $jsondata
   * @param \Drupal\Core\Field\FieldItemInterface $item
   * @return array
   *    A List of URLs pointing to a IIIF Manifest for this node.
   *    We are using an array even if we only return one
   *    to match other processManifest Functions and have a single way
   *    of Processing them.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function processManifestforNodeList(
    array $jsondata,
    FieldItemInterface $item
  ) {
    $manifests = [];
    $cleannodelist = [];
    if ($this->getSetting('manifestnodelist_json_key_source') && $this->getSetting('metadataexposeentity_source')) {
      $jsonkey = $this->getSetting('manifestnodelist_json_key_source');
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
                'format' => 'manifest.jsonld'
              ],
              $this->currentUser
            );
            if ($has_access) {
              $manifests[] = $entity->getUrlForItemFromNodeUUID(
                $node->uuid(),
                TRUE
              );
            }
          }
        }
      }
    }
    return $manifests;
  }

  /**
   * {@inheritdoc}
   */
  public function setSetting($key, $value) {
    $this->twig->invalidate();
    return parent::setSetting($key, $value);
  }

}
