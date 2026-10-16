<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 13/10/2026
 * Time: 11:36 AM
 */

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\format_strawberryfield\Controller\WebAnnotationController;
use Drupal\format_strawberryfield\EmbargoResolverInterface;
use Drupal\Core\Url;
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
 * Triiiceratops Viewer Strawberry Field formatter.
 *
 * @FieldFormatter(
 *   id = "strawberry_triiiceratops_formatter",
 *   label = @Translation("Strawberry Field Media Formatter using the Triiiceratops
 *   IIIF Viewer plugin"), class =
 *   "\Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryMiradorFormatter",
 *   field_types = {
 *     "strawberryfield_field"
 *   },
 *   quickedit = {
 *     "editor" = "disabled"
 *   }
 * )
 */
class StrawberryTriiiceratopsFormatter extends StrawberryBaseFormatter implements ContainerFactoryPluginInterface {

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
    $settings = parent::defaultSettings();
    unset($settings['hide_on_embargo']);
    return $settings + [
        'mediasource' => [
          'metadataexposeentity' => 'metadataexposeentity',
        ],
        'main_mediasource' => 'metadataexposeentity',
        'metadataexposeentity_source' => NULL,
        'manifestnodelist_json_key_source' => 'isrelatedto',
        'manifesturl_json_key_source' => 'iiifmanifest',
        'viewer_overrides' => '',
        'viewer_theme_overrides' => '',
        'enabled_plugins' => [],
        'webannotations' => FALSE,
        'max_width' => 720,
        'max_height' => 480,
        'hide_on_embargo' => FALSE,
      ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $entity = NULL;
    if ($this->getSetting('metadataexposeentity_source')) {
      $entity = $this->entityTypeManager->getStorage(
        'metadataexpose_entity'
      )->load($this->getSetting('metadataexposeentity_source'));
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
      if ($triggering_element['#ajax']['callback'][0] == get_class($this)) {
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
      'callback' => [get_class($this), 'ajaxCallbackMainSource'],
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

    $plugin_options = [
      "plugin-image-manipulation" => "Image tools",
      "plugin-av" => "Audio and Video",
      "plugin-pdf-export" => "PDF Download",
      "plugin-image-export" => "Image Download",
      "plugin-annotation-editor" => "Annotation Editor",
    ];

    $settings_form = [
        'mediasource' => [
          '#type' => 'checkboxes',
          '#title' => $this->t('Source for your IIIF Manifest URLs.'),
          '#options' => $all_options_form_source ?? [],
          '#default_value' => $this->getSetting('mediasource') ?? [],
          '#required' => TRUE,
          '#attributes' => [
            'data-triiiceratops-formatter-selector' => 'mediasource',
          ],
          '#ajax' => $ajax,
        ],
        'viewer_overrides' => [
          '#type' => 'textarea',
          '#title' => $this->t('Advanced: a JSON with Triiiceratops Viewer configuration overrides.'),
          '#description' => $this->t("See <a href=\"https://triiiceratops.org/configure/\">https://triiiceratops.org/configure/</a>(Portable Viewer Configuration
) or all config <a href=\"https://triiiceratops.org/docs/configuration/\">options</a> programatically . Leave Empty to use defaults.
   Use with caution. An ADO can also override this formatter's settings by providing e.g. the following JSON key (notice the 'config' top key): @ado_override",[
            '@ado_override' => json_encode(["ap:viewerhints" => ["strawberry_triiiceratops_formatter"=> ["config" => ["gallery" => ["open" => TRUE]]]]], JSON_FORCE_OBJECT|JSON_PRETTY_PRINT)
          ]),
          '#default_value' => $this->getSetting('viewer_overrides'),
          '#element_validate' => [[$this, 'validateJSON']],
          '#required' => FALSE,
        ],
        'viewer_theme_overrides' => [
          '#type' => 'textarea',
          '#title' => $this->t('Advanced: a JSON with Triiiceratops Viewer Theme configuration overrides.'),
          '#description' => $this->t("See <a href=\"https://triiiceratops.org/configure/\">https://triiiceratops.org/configure/</a>(Custom Theme). Leave Empty to use defaults.
   Use with caution. An ADO can also override this formatter's theme settings by providing e.g. the following JSON key (notice the 'theme-config' top key): @ado_override",[
            '@ado_override' => json_encode(["ap:viewerhints" => ["strawberry_triiiceratops_formatter"=> ["theme-config" =>["primary" => "#ed719e"]]]], JSON_FORCE_OBJECT|JSON_PRETTY_PRINT)
          ]),
          '#default_value' => $this->getSetting('viewer_theme_overrides'),
          '#element_validate' => [[$this, 'validateJSON']],
          '#required' => FALSE,
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
            'Select which Exposed Metadata Endpoint will generate the Manifest'
          ),
          '#description' => $this->t(
            'Note: <br> 
Since Triiiceratops does not have a native way of exposing multiple windows/manifests at the same time, to replicate what our Mirador Integration does (JSON Key with NODE Numeric IDs or UUIDS and
JSON key with external URLs, please expose a Collection Manifest instead that encapsulates that logic. Triiiceratops will always open automatically the first Manifest found there'
          ),
          '#selection_handler' => 'default',
          '#validate_reference' => TRUE,
          '#default_value' => $entity,
          '#states' => [
            [
              'visible' => [
                ':input[data-triiiceratops-formatter-selector="mediasource"][value="metadataexposeentity"]' => ['checked' => TRUE],
              ]
            ],
          ],
        ],
        'enabled_plugins' => [
          '#type' => 'checkboxes',
          '#options' => $plugin_options,
          '#title' => $this->t('Which Content types will this metadata be allowed to be exposed?'),
          '#required'=> TRUE,
          '#attributes' => [
            'data-triiiceratops-formatter-selector' => 'enabled-plugins',
          ],
          '#default_value' => $this->getSetting('enabled_plugins') ?? []
        ],
        'webannotations' => [
          '#type' => 'checkbox',
          '#title' => t('Enable loading/editing of W3C webAnnotations.'),
          '#description' => t('<a href="https://www.w3.org/TR/annotation-model/#index-of-json-keys">Click here</a> To learn more about the JSON format of a Web Annotation'),
          '#default_value' => $this->getSetting('webannotations'),
          '#attributes' => [
            'data-formatter-selector' => 'webannotations',
          ],
          '#states' => [
            'visible' => [
              ':input[data-triiiceratops-formatter-selector="enabled-plugins"][value="plugin-annotation-editor"]' => ['checked' => TRUE],
            ]
          ],
          '#default_value' => $this->getSetting('webannotations') ?? FALSE
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
          '#description' => t('If unchecked, acting on an embargo will be delegated to the IIIF Manifest driving the viewer.'),
          '#default_value' => $this->getSetting('hide_on_embargo') ?? FALSE,
          '#required' => FALSE,
          '#attributes' => [
            'data-triiiceratops-formatter-selector' => 'hide_on_embargo',
          ],
        ],
      ] + parent::settingsForm($form, $form_state);
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
      'Displays Media using the Triiiceratops IIIF viewer <br>'
    );
    $main_mediasource = $this->getSetting(
      'main_mediasource'
    ) ? $this->getSetting('main_mediasource') : NULL;
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
    $viewer_overrides = $this->getSetting('viewer_overrides');
    $enabled_plugins = $this->getSetting('enabled_plugins') ?? [];
    $viewer_overrides_json = json_decode(trim($viewer_overrides), TRUE);
    $json_error = json_last_error();
    if ($json_error == JSON_ERROR_NONE) {
      $viewer_overrides = $viewer_overrides_json;
    }
    else {
      $viewer_overrides = NULL;
    }
    $viewer_overrides_theme = $this->getSetting('viewer_theme_overrides');
    $viewer_overrides_theme_json = json_decode(trim($viewer_overrides_theme), TRUE);
    if ($json_error == JSON_ERROR_NONE) {
      $viewer_overrides_theme = $viewer_overrides_theme_json;
    }
    else {
      $viewer_overrides_theme = NULL;
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
        // if we could decode it, it is already JSON..
        $viewer_overrides = $jsondata["ap:viewerhints"][$this->getPluginId()]['config'] ?? $viewer_overrides;
        $viewer_overrides_theme = $jsondata["ap:viewerhints"][$this->getPluginId()]['theme-config'] ?? $viewer_overrides_theme;
      }
      // A rendered Manifest
      if ($hide_on_embargo) {
        $embargo_info = $this->embargoResolver->embargoInfo(
          $item->getEntity(), $jsondata
        );


        if (is_array($embargo_info)) {
          $embargoed = $embargo_info[0];
          $embargo_tags[] = 'format_strawberryfield:all_embargo';
          if ($embargo_info[1]) {
            $embargo_tags[] = 'format_strawberryfield:embargo:'
              . $embargo_info[1];
          }
          if ($embargo_info[2] || ($embargo_info[3] == FALSE)) {
            $embargo_context[] = 'ip';
          }
        }
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
        // Check which one is our main source and if it really exists
        if (isset($manifests[$main_mediasource]) && !empty($manifests[$main_mediasource])) {
          // Take only the first since we could have more
          $main_manifesturl = array_shift($manifests[$main_mediasource]);
          $all_manifesturl =  array_reduce($manifests,'array_merge',[]);
        } else {
          // reduce flattens and applies a merge. Basically we get a simple list.
          $all_manifesturl = array_reduce($manifests,'array_merge',[]);
          $main_manifesturl = array_shift($all_manifesturl);
        }

        // Only process is we got at least one manifest
        if (!empty($main_manifesturl)) {
          $groupid = 'iiif-' . $item->getName() . '-' . $nodeuuid . '-' . $delta
            . '-triiiceratops';
          $htmlid = $groupid;

          $elements[$delta]['media'] = [
            '#type' => 'html_tag',
            '#tag' => 'triiiceratops-viewer',
            '#default_value' => $htmlid,
            '#attributes' => [
              'id' => $htmlid,
              'class' => [
                'strawberry-triiiceratops-item',
                'TriiiCeratopsViewer',
                'field-iiif',
              ],
              'style' => "width:{$max_width_css}; height:{$max_height}px",
              'height' => $max_height,
            ],
          ];

          // get the URL to our Metadata Expose Endpoint, we will get a string here.
          $elements[$delta]['media']['#attributes']['data-iiif-infojson'] = '';
          $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['triiiceratops'][$htmlid]['nodeuuid']
            = $nodeuuid;
          $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['triiiceratops'][$htmlid]['manifesturl']
            = $main_manifesturl;
          $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['triiiceratops'][$htmlid]['manifestother']
            = is_array($all_manifesturl) ? $all_manifesturl : [];
          $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['triiiceratops'][$htmlid]['width']
            = $max_width_css;
          $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['triiiceratops'][$htmlid]['height']
            = max(
            $max_height,
            480
          );
          $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['triiiceratops'][$htmlid]['viewer_overrides'] = $viewer_overrides;
          $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['triiiceratops'][$htmlid]['viewer_overrides_theme'] = $viewer_overrides_theme;
          $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['triiiceratops'][$htmlid]['enabled_plugins'] = $enabled_plugins;
          $elements[$delta]['#attached']['library'][]
            = 'format_strawberryfield/triiiceratops_strawberry';
          foreach ($enabled_plugins as $plugin) {
            if ($this->currentUser->hasPermission('view strawberryfield webannotation') && $plugin == 'plugin-annotation-editor' && $webannotations) {
              $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['triiiceratops'][$htmlid]['webannotations'] = (boolean) $webannotations ?? FALSE;
              $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['triiiceratops'][$htmlid]['keystoreid'] = WebAnnotationController::getTempStoreKeyName($items->getName(), $delta, $nodeuuid);
              if ($this->currentUser) {
                $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['triiiceratops'][$htmlid]['user']['url'] = Url::fromRoute('entity.user.canonical', ['user' => $this->currentUser->getAccount()->id()])->toString();
                $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['triiiceratops'][$htmlid]['user']['name'] = $this->currentUser->getAccount()->getAccountName();
              }
              else {
                $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['triiiceratops'][$htmlid]['user']['url'] = null;
                $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['triiiceratops'][$htmlid]['user']['name'] = 'anonymous';
              }
            }
            $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/triiiceratops_' . $plugin;
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
    $entity = NULL;
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
}
