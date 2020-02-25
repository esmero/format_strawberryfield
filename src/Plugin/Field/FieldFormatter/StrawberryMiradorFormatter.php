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
 *   id = "strawberry_mirador_formatter",
 *   label = @Translation("Strawberry Field Media Formatter using the Mirador
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
class StrawberryMiradorFormatter extends StrawberryBaseFormatter implements ContainerFactoryPluginInterface {

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
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   * @param string $label
   *   The formatter settings.
   * @param $view_mode
   *   The view mode.
   * @param array
   *   Any third party settings.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current User
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The Entity Type manager
   * @param \Drupal\Core\Template\TwigEnvironment $twigEnvironment
   *   The Loaded twig Environment
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
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
    EntityTypeManagerInterface $entity_type_manager
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $label,
      $view_mode,
      $third_party_settings,
      $config_factory
    );
    $this->currentUser = $current_user;
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
      $container->get('entity_type.manager')
    );
  }


  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return parent::defaultSettings() + [
        'mediasource' => [
          'metadataexposeentity' => 'metadataexposeentity',
        ],
        'main_mediasource' => 'metadataexposeentity',
        'metadataexposeentity_source' => NULL,
        'manifestnodelist_json_key_source' => 'isrelatedto',
        'manifesturl_json_key_source' => 'iiifmanifest',
        'max_width' => 720,
        'max_height' => 480,
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
      ),
      'manifesturl' => $this->t(
        'Strawberryfield JSON Key with one or more Manifest URLs'
      ),
      'manifestnodelist' => $this->t(
        'Strawberryfield JSON Key with one or more Node IDs or UUIDs'
      ),
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

    $settings_form = [
        'mediasource' => [
          '#type' => 'checkboxes',
          '#title' => $this->t('Source for your IIIF Manifest URLs.'),
          '#options' => $all_options_form_source,
          '#default_value' => $this->getSetting('mediasource'),
          '#required' => TRUE,
          '#attributes' => [
            'data-formatter-selector' => 'mediasource',
          ],
          '#ajax' => $ajax,
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
            'Select which Exposed Metadata Endpoint will generate the Manifests'
          ),
          '#description' => $this->t(
            'This value is used for Metadata Exposed Entities and also for Node Lists as Processing source for IIIF Manifests'
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
                ':input[data-formatter-selector="mediasource"][value="manifestnodelist"]' => ['checked' => TRUE],
              ]
            ]
          ],
        ],
        'manifesturl_json_key_source' => [
          '#type' => 'textfield',
          '#title' => t(
            'JSON Key from where to fetch one or more IIIF manifest URLs. URLs can be external.'
          ),
          '#default_value' => $this->getSetting('manifesturl_json_key_source'),
          '#states' => [
            'visible' => [
              ':input[data-formatter-selector="mediasource"][value="manifesturl"]' => ['checked' => TRUE],
            ],
          ],
        ],

        'manifestnodelist_json_key_source' => [
          '#type' => 'textfield',
          '#title' => t(
            'JSON Key from where to fetch one or more Nodes. Values can be either NODE IDs (Integers) or UUIDs (Strings). But all of the same type.'
          ),
          '#default_value' => $this->getSetting(
            'manifestnodelist_json_key_source'
          ),
          '#states' => [
            'visible' => [
              ':input[data-formatter-selector="mediasource"][value="manifestnodelist"]' => ['checked' => TRUE],
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
        ],
        'max_height' => [
          '#type' => 'number',
          '#title' => $this->t('Maximum height'),
          '#default_value' => $this->getSetting('max_height'),
          '#size' => 5,
          '#maxlength' => 5,
          '#field_suffix' => $this->t('pixels'),
          '#min' => 0,
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
      'Displays Media using the Mirador IIIF viewer <br>'
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
        if ($on == "manifesturl") {
          $summary[] = $this->t(
            'IIIF Manifest URL fetched from JSON "%manifesturl_json_key_source" key%primary.',
            [
              '%manifesturl_json_key_source' => $this->getSetting(
                'manifesturl_json_key_source'
              ),
              '%primary' => ($main_mediasource == $on) ? '(PRIMARY)' : '',
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

    if ($this->getSetting('max_width') && $this->getSetting('max_height')) {
      $summary[] = $this->t(
        'Maximum size: %max_width x %max_height',
        [
          '%max_width' => (int) $this->getSetting('max_width') == 0 ? '100%' : $this->getSetting('max_width') . ' pixels',
          '%max_height' => $this->getSetting('max_height') . 'pixels',
        ]
      );
    }
    elseif ($this->getSetting('max_width')) {
      $summary[] = $this->t(
        'Maximum width: %max_width',
        [
          '%max_width' => (int) $this->getSetting('max_width') == 0 ? '100%' : $this->getSetting('max_width') . ' pixels',
        ]
      );
    }
    elseif ($this->getSetting('max_height')) {
      $summary[] = $this->t(
        'Maximum height: %max_height',
        [
          '%max_height' => $this->getSetting('max_height') . ' pixels',
        ]
      );
    }

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
    $mediasource = is_array($this->getSetting('mediasource')) ? $this->getSetting('mediasource') : [];
    $main_mediasource = $this->getSetting('main_mediasource');

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
    // To generate an on the Fly Manifest. We coded our JS to read from manifests
    // Finally we allow also an Manifest URL to be passed.

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
        return $elements[$delta] = ['#markup' => $this->t('ERROR')];
      }
      // A rendered Manifest

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

        $groupid = 'iiif-' . $item->getName(
          ) . '-' . $nodeuuid . '-' . $delta . '-media';
        $htmlid = $groupid;

        $elements[$delta]['media'] = [
          '#type' => 'container',
          '#default_value' => $htmlid,
          '#attributes' => [
            'id' => $htmlid,
            'class' => [
              'strawberry-mirador-item',
              'MiradorViewer',
              'field-iiif',
              'container',
            ],
            'style' => "width:{$max_width_css}; height:{$max_height}px",
            'width' => $max_width,
            'height' => $max_height,
          ],
        ];

        // get the URL to our Metadata Expose Endpoint, we will get a string here.

        $elements[$delta]['media']['#attributes']['data-iiif-infojson'] = '';
        $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['mirador'][$htmlid]['nodeuuid'] = $nodeuuid;
        $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['mirador'][$htmlid]['manifesturl'] = $main_manifesturl;
        $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['mirador'][$htmlid]['manifestother'] = is_array($all_manifesturl) ? $all_manifesturl : [];
        $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['mirador'][$htmlid]['width'] = $max_width_css;
        $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['mirador'][$htmlid]['height'] = max(
          $max_height,
          480
        );
        $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/mirador_strawberry';
        if (isset($item->_attributes)) {
          $elements[$delta] += ['#attributes' => []];
          $elements[$delta]['#attributes'] += $item->_attributes;
          // Unset field item attributes since they have been included in the
          // formatter output and should not be rendered in the field template.
        }
      }
    }
    unset($item->_attributes);
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
