<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:56 PM
 */

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\format_strawberryfield\EmbargoResolverInterface;
use Drupal\strawberryfield\StrawberryfieldUtilityServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;

/**
 * Strawberry Field Paged Formatter using IABook Readerplugin.
 *
 * @FieldFormatter(
 *   id = "strawberry_paged_formatter",
 *   label = @Translation("Strawberry Field Paged Formatter using IABook Readerplugin"),
 *   class = "\Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryPagedFormatter",
 *   field_types = {
 *     "strawberryfield_field"
 *   },
 *   quickedit = {
 *     "editor" = "disabled"
 *   }
 * )
 */
class StrawberryPagedFormatter extends StrawberryBaseFormatter implements ContainerFactoryPluginInterface {

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\Core\Template\TwigEnvironment
   */
  protected $twig;

  /**
   * The Strawberry Field Utility Service.
   *
   * @var \Drupal\strawberryfield\StrawberryfieldUtilityService
   */
  protected $strawberryFieldUtility;

  /**
   * StrawberryMetadataTwigFormatter constructor.
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
   * @param array $third_party_settings
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current User
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The Entity Type manager
   * @param \Drupal\Core\Template\TwigEnvironment $twigEnvironment
   *   The Loaded twig Environment
   * @param \Drupal\strawberryfield\StrawberryfieldUtilityServiceInterface $strawberryfield_utility_service
   *   The SBF Utility Service.
   * @param \Drupal\format_strawberryfield\EmbargoResolverInterface $embargo_resolver
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, ConfigFactoryInterface $config_factory, AccountInterface $current_user, EntityTypeManagerInterface $entity_type_manager, TwigEnvironment $twigEnvironment, StrawberryfieldUtilityServiceInterface $strawberryfield_utility_service,  EmbargoResolverInterface $embargo_resolver) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings, $config_factory, $embargo_resolver, $current_user);
    $this->entityTypeManager = $entity_type_manager;
    $this->twig = $twigEnvironment;
    $this->strawberryFieldUtility = $strawberryfield_utility_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
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
      $container->get('twig'),
      $container->get('strawberryfield.utility'),
      $container->get('format_strawberryfield.embargo_resolver')

    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return parent::defaultSettings() + [
        'iiif_group' => TRUE,
        'mediasource' => 'json_key',
        'json_key_source' => 'as:image',
        'metadatadisplayentity_uuid' => NULL,
        'manifesturl_source' => 'iiifmanifest',
        'metadataexposeentity_source' => NULL,
        'max_width' => 720,
        'max_height' => 480,
        'hide_on_embargo' => FALSE,
        'textselection' => FALSE,
        'hascover_json_key_source' => 'hascover',
        'ia_reader_images_base_url' => 'https://cdn.jsdelivr.net/gh/internetarchive/bookreader@4.40.3/BookReader/images/',
      ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    //@TODO document that 2 base urls are just needed when developing (localhost syndrom)
    $entity = NULL;
    if ($this->getSetting('metadatadisplayentity_uuid')) {
      $entities = $this->entityTypeManager
        ->getStorage('metadatadisplay_entity')
        ->loadByProperties(['uuid' => $this->getSetting('metadatadisplayentity_uuid')]);
      $entity = reset($entities);
    }

    $entity2 = NULL;
    if ($this->getSetting('metadataexposeentity_source')) {
      $entity2 = $this->entityTypeManager->getStorage(
        'metadataexpose_entity'
      )->load($this->getSetting('metadataexposeentity_source'));
    }

    return [
        'mediasource' => [
          '#type' => 'select',
          '#title' => $this->t('Source for your paged media'),
          '#options' => [
            'manifesturl' => $this->t(
              'Strawberryfield JSON Key with a Manifest URL'
            ),
            'metadatadisplayentity' => $this->t(
              'A IIIF Manifest generated by a Metadata Display template'
            ),
            'metadataexposeentity' =>  $this->t(
              'A IIIF Manifest generated by an Exposed Metadata Display Entity'
            ),
          ],
          '#default_value' => $this->getSetting('mediasource'),
          '#required' => TRUE,
          '#attributes' => [
            'data-formatter-selector' => 'mediasource',
          ],
        ],
        'manifesturl_source' => [
          '#type' => 'textfield',
          '#title' => t(
            'JSON Key from where to fetch an absolute full IIIF manifest URL'
          ),
          '#default_value' => $this->getSetting('manifesturl_source'),
          '#states' => [
            'visible' => [
              ':input[data-formatter-selector="mediasource"]' => ['value' => 'manifesturl'],
            ],
          ],
        ],
        'metadataexposeentity_source' => [
          '#type' => 'entity_autocomplete',
          '#target_type' => 'metadataexpose_entity',
          '#title' => $this->t(
            'Select which Exposed Metadata Endpoint will generate the Manifests'
          ),
          '#description' => $this->t(
            'This value is used for Metadata Exposed Entities as Processing source for IIIF Manifests'
          ),
          '#selection_handler' => 'default',
          '#validate_reference' => TRUE,
          '#default_value' => $entity2,
          '#states' => [
            'visible' => [
              ':input[data-formatter-selector="mediasource"]' => ['value' => 'metadataexposeentity'],
            ],
          ],
        ],
        'metadatadisplayentity_uuid' => [
          '#type' => 'sbf_entity_autocomplete_uuid',
          '#target_type' => 'metadatadisplay_entity',
          '#selection_handler' => 'default:metadatadisplay',
          '#validate_reference' => FALSE,
          '#default_value' => $entity,
          '#states' => [
            'visible' => [
              ':input[data-formatter-selector="mediasource"]' => ['value' => 'metadatadisplayentity'],
            ],
          ],
        ],
        'hascover_json_key_source' => [
          '#type' => 'textfield',
          '#title' => t('JSON Key(s) that provides a boolean (true/false) value to determine if a cover (defaults to YES) .'),
          '#description' => t('By default IA Book reader will treat any resource as a book with a Book Cover. If this JSON key is present in your Strawberryfield JSON field it will be evaluated as boolean. If the value is or evaluates to "False", when seeing a resource with opposing pages (2up), two opposing pages will be shown initially (a spread) instead of treating the first as the cover. By default the value of this is "TRUE" which is the normal behavior of this IIIF Viewer.'),
          '#default_value' => $this->getSetting('hascover_json_key_source'),
          '#required' => FALSE,
          '#maxlength' => 255,
          '#size' => 64,
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
        'ia_reader_images_base_url' => [
          '#type' => 'textfield',
          '#title' => t(
            'Base-URL for IA Reader images (optional)'
          ),
          '#description' => 'If you don\'t specify a URL, the system automatically uses the default base URL ('.self::defaultSettings()['ia_reader_images_base_url'].') for the IA Reader. You have the option to specify a different location by providing either: An absolute URL (starting with \'https://...\'), or a relative path from your filesystem (e.g., \'/libraries/BookReader/images/\'). Please ensure to add a trailing slash at the end of the path.',
          '#default_value' => $this->getSetting('ia_reader_images_base_url'),
        ],
        'hide_on_embargo' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Hide the Viewer in the presence of an Embargo.'),
          '#description' => $this->t('If unchecked, acting on an embargo will be delegated to the IIIF Manifest driving the viewer.'),
          '#default_value' => $this->getSetting('hide_on_embargo') ?? FALSE,
          '#required' => FALSE,
          '#attributes' => [
            'data-formatter-selector' => 'hide_on_embargo',
          ],
        ],
        'textselection' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Enable the Text Selection plugin via the internal DjvuXml Endpoint.'),
          '#description' => $this->t('If enabled, direct selection on OCRed pages will be possible. Do not enable if the manifest combines more than a single OCRed resource (multiple PDFs etc)'),
          '#default_value' => $this->getSetting('textselection') ?? FALSE,
          '#required' => FALSE,
          '#attributes' => [
            'data-formatter-selector' => 'textselection',
          ],
        ]
      ] + parent::settingsForm($form, $form_state);
  }

  public function setSettings(array $settings) {
    return parent::setSettings($settings); // TODO: Change the autogenerated stub
  }


  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $summary[] = $this->t('Displays Paged Media from JSON using a IIIF server and the IABook Reader viewer.');
    if ($this->getSetting('mediasource')) {
      switch ($this->getSetting('mediasource')) {
        case 'json_key':
          $summary[] = $this->t('Pages fetched from JSON "%json_key_source" key',
            [
              '%json_key_source' => $this->getSetting('json_key_source'),
            ]
          );
          break;
        case 'manifesturl':
          $summary[] = $this->t('Pages fetched from a IIIF Manifest url at  "%manifesturl_source" key',
            [
              '%manifesturl_source' => $this->getSetting('manifesturl_source'),
            ]
          );
          break;
        case 'metadatadisplayentity':
          $entity = NULL;
          if ($this->getSetting('metadatadisplayentity_uuid')) {
            $entities = $this->entityTypeManager
              ->getStorage('metadatadisplay_entity')
              ->loadByProperties(['uuid' => $this->getSetting('metadatadisplayentity_uuid')]);
            $entity = reset($entities);
            $label = $entity->toLink()->getText();
            $summary[] = $this->t('Pages processed by the "%manifesturl_source" Metadata Data Display template',
              [
                '%manifesturl_source' => $label,
              ]
            );
          }
          break;
        case 'metadataexposeddisplayentity':
          $entity = NULL;
          if ($this->getSetting('metadataexposeentity_source')) {
            $entity = $this->entityTypeManager->getStorage(
              'metadataexpose_entity'
            )->load($this->getSetting('metadataexposeentity_source'));
            if ($entity) {
              $label = $entity->toLink()->getText();
              $summary[] = $this->t('Pages processed by the "%manifesturl_source" Metadata Data Display template',
                [
                  '%manifesturl_source' => $label,
                ]
              );
            }
            else {
              $summary[] = $this->t('This formatter is configured to use an exposed Metadata display entity as source for a IIIF manifest but has no valid one configured (yet).');
            }
          }
          break;
        default:
          $summary[] = $this->t('This formatter still needs to be setup');
      }
    }
    $ia_reader_images_base_url = $this->getSetting('ia_reader_images_base_url');

    $summary[] = $this->t('Base IA Reader images URL: %ia_reader_images_base_url',
      [
        '%ia_reader_images_base_url' => $ia_reader_images_base_url
      ]
    );

    $summary[] = $this->t('Maximum size: %max_width x %max_height',
      [
        '%max_width' => (int) $this->getSetting('max_width') == 0 ? '100%' : $this->getSetting('max_width') . ' pixels',
        '%max_height' => $this->getSetting('max_height') . ' pixels',
      ]
    );

    $summary[] = $this->t('Viewer for embargoed Objects is %hide',
      [
        '%hide' => $this->getSetting('hide_on_embargo') ? 'hidden' : 'visible'
      ]
    );

    $summary[] = $this->t('JSON key providing Book Cover configuration: %key',
      [
        '%key' => $this->getSetting('hascover_json_key_source') ?? 'hascover'
      ]
    );

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $pagestrategy = $this->getSetting('mediasource');
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

    /* @var \Drupal\Core\Field\FieldItemInterface $item */
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
      // A rendered Manifest.
      switch ($pagestrategy) {
        // We can pass the processed exposed metadata display url so basically the same strategy
        case 'manifesturl':
        case 'metadataexposeentity':
          $elements[$delta] = $this->processElementforManifestURL(
            $delta,
            $jsondata,
            $item,
            $pagestrategy
          );
          break;
        case 'metadatadisplayentity':
          $elements[$delta] = $this->processElementforMetadatadisplays(
            $delta,
            $jsondata,
            $item
          );
          break;
      }

      /* Expected structure of an Media item inside JSON
      "as:images": {
         "s3:\/\/f23\/new-metadata-en-image-58455d91acf7290275c1cab77531b7f561a11a84.jpg": {
         "dr:fid": 32, // Drupal's FID
         "dr:for": "add_some_master_images", // The webform element key that generated this one
         "url": "s3:\/\/f23\/new-metadata-en-image-58455d91acf7290275c1cab77531b7f561a11a84.jpg",
         "name": "new-metadata-en-image-a8d0090cbd2cd3ca2ab16e3699577538f3049941.jpg",
         "type": "Image",
         "sequence" : 1,
         "checksum": "f231aed5ae8c2e02ef0c5df6fe38a99b"
         }
      }*/

      /* Expected structure of an Document item inside JSON

      "as:documents" :  {
         "s3:\/\/f23\/new-metadata-en-document-58455d91acf7290275c1cab77531b7f561a11a84.pdf": {
         "dr:fid": 32, // Drupal's FID
         "dr:for": "add_some_pdf_files", // The webform element key that generated this one
         "url": "s3:\/\/f23\/new-metadata-en-document-58455d91acf7290275c1cab77531b7f561a11a84.pdf",
         "name": "new-metadata-en-document-58455d91acf7290275c1cab77531b7f561a11a84.pdf",
         "type": "Document",
         "numberOfPages": 200,
         "checksum": "f231aed5ae8c2e02ef0c5df6fe38a99b"
         }
      */

      $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/iiif_iabookreader_strawberry';
    }
    return $elements;
  }

  /**
   * Generates render element for a Twig generated manifest.
   *
   * @param int $delta
   *   The order of this item in the array of sub-elements (0, 1, 2, etc.).
   * @param array $jsondata
   *   Array of data.
   * @param \Drupal\Core\Field\FieldItemInterface $item
   *   FieldItem to be displayed.
   *
   * @return array
   *   Render array.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function processElementforMetadatadisplays($delta, array $jsondata, FieldItemInterface $item) {
    $delta = $delta ?? 0;
    $element = [];
    $entity = NULL;
    $nodeuuid = $item->getEntity()->uuid();
    $hide_on_embargo =  $this->getSetting('hide_on_embargo') ?? FALSE;
    $max_width = $this->getSetting('max_width');
    $max_width_css = empty($max_width) || $max_width == 0 ? '100%' : $max_width .'px';
    $max_height = $this->getSetting('max_height');
    $ia_reader_images_base_url = self::defaultSettings()['ia_reader_images_base_url'];
    if ($this->getSetting('ia_reader_images_base_url') &&
      is_string($this->getSetting('ia_reader_images_base_url')
      && strlen(trim($this->getSetting('ia_reader_images_base_url'))) > 1)) {
      $ia_reader_images_base_url = trim($this->getSetting('ia_reader_images_base_url'));
    }

    $textselection = $this->getSetting('textselection') ?? FALSE;
    $embargo_context = [];
    $embargo_tags = [];
    $embargoed = FALSE;
    $hascover = TRUE;
    if ($this->getSetting('hascover_json_key_source')) {
      $key = is_string($this->getSetting('hascover_json_key_source')) ? trim($this->getSetting('hascover_json_key_source')) : NULL;
      if ($key && isset($jsondata[$key])) {
        $hascover = (bool) $jsondata[$key];
      }
    }

    if ($this->getSetting('metadatadisplayentity_uuid')) {
      /* @var $metadatadisplayentity \Drupal\format_strawberryfield\Entity\MetadataDisplayEntity */
      $metadatadisplayentities = $this->entityTypeManager
        ->getStorage('metadatadisplay_entity')
        ->loadByProperties(['uuid' => $this->getSetting('metadatadisplayentity_uuid')]);
      $metadatadisplayentity = reset($metadatadisplayentities);

      if ($metadatadisplayentity) {
        // Quickly sort the pages. We assume user will use the as:image key
        // Since the actual generation happens via a twig template.
        // @TODO add a config option for this key too.
        $mainkey = 'as:image';
        $ordersubkey = 'sequence';
        StrawberryfieldJsonHelper::orderSequence(
          $jsondata, $mainkey, $ordersubkey
        );

        $embargo_info = $this->embargoResolver->embargoInfo(
          $item->getEntity()->uuid(), $jsondata
        );
        // This one is for the Twig template
        // We do not need the IP here. No use of showing the IP at all?
        $context_embargo = [
          'data_embargo' => [
            'embargoed' => FALSE,
            'until'     => NULL
          ]
        ];

        if (is_array($embargo_info)) {
          $embargoed = $embargo_info[0];
          $context_embargo['data_embargo']['embargoed'] = $embargoed;
          $embargo_tags[] = 'format_strawberryfield:all_embargo';
          if ($embargo_info[1]) {
            $embargo_tags[] = 'format_strawberryfield:embargo:'
              . $embargo_info[1];
            $context_embargo['data_embargo']['until'] = $embargo_info[1];
          }
          if ($embargo_info[2]) {
            $embargo_context[] = 'ip';
          }
        }
        else {
          $context_embargo['data_embargo']['embargoed'] = $embargo_info;
        }

        // Only process render elements if hide on embargo is set
        if (!$embargoed || ($embargoed && !$hide_on_embargo)) {
          $context = [
              'data'        => $jsondata,
              'node'        => $item->getEntity(),
              'iiif_server' => $this->getIiifUrls()['public'],
            ] + $context_embargo;
          $original_context = $context;
          // Allow other modules to provide extra Context!
          // Call modules that implement the hook, and let them add items.
          \Drupal::moduleHandler()->alter(
            'format_strawberryfield_twigcontext', $context
          );
          // In case someone decided to wipe the original context?
          // We bring it back!
          $context = $context + $original_context;

          $manifestrenderelement = $metadatadisplayentity->renderNative(
            $context
          );

          $manifest = $manifestrenderelement->jsonSerialize();
          $groupid = 'iiif-' . $item->getName() . '-' . $nodeuuid . '-' . $delta
            . '-media';
          $htmlid = $groupid;

          $element['media'] = [
            '#type'          => 'container',
            '#default_value' => $htmlid,
            '#attributes'    => [
              'id' => $htmlid,
              'class' => [
                'strawberry-iabook-item',
                'BookReader',
                'field-iiif',
                'container',
              ],
              'style' => "width:{$max_width_css}; height:{$max_height}px",
              'data-iiif-infojson' => '',
            ],
          ];

          $element['media']['#attributes']['data-iiif-infojson'] = '';
          $element['media']['#attached']['drupalSettings']['format_strawberryfield']['iabookreader'][$htmlid]
            = [
            'nodeuuid' => $nodeuuid,
            'manifest' => json_decode($manifest),
            'width'    => $max_width_css,
            'height'   => max($max_height, 520),
            'iareaderimagesbaseurl' => $ia_reader_images_base_url,
            'textselection' => $textselection,
            'hascover' => $hascover ?? TRUE,
            // While Bookreader has a way to enable/disable search via the "enableSearch"
            // parameter, it doesn't work properly at the moment and we have opened an
            // issue to fix it, meanwhile it's hidden via jQuery.
            // @see https://github.com/internetarchive/bookreader/pull/613
          ];
        }
      }
    }

    if (empty($element)) {
      $element = [
        '#markup' => '<i class="d-none fas fa-times-circle"></i>',
        '#prefix' => '<span>',
        '#suffix' => '</span>',
      ];
    }

    if (isset($item->_attributes)) {
      $element += ['#attributes' => []];
      $element['#attributes'] += $item->_attributes;
      // Unset field item attributes since they have been included in the
      // formatter output and should not be rendered in the field template.
      unset($item->_attributes);
    }

    // Get rid of empty #attributes key to avoid render error
    if (isset($element["#attributes"]) && empty($elements["#attributes"])) {
      unset($element["#attributes"]);
    }

    $element['#cache'] = [
      'context' => Cache::mergeContexts($item->getEntity()->getCacheContexts(), ['user.permissions', 'user.roles'], $embargo_context),
      'tags' => Cache::mergeTags($item->getEntity()->getCacheTags(), $embargo_tags, ['config:format_strawberryfield.embargo_settings']),
    ];
    if (isset($embargo_info[3]) && $embargo_info[3] === FALSE) {
      $element['#cache']['max-age'] = 0;
    }

    return $element;
  }

  /**
   * Generates render element for a manifest URL.
   *
   * @param int $delta
   * @param array $jsondata
   * @param \Drupal\Core\Field\FieldItemInterface $item
   *
   * @return array
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function processElementforManifestURL($delta, array $jsondata, FieldItemInterface $item, string $pagestrategy) {
    $delta = $delta ?? 0;
    $element = [];
    $entity = NULL;
    $nodeuuid = $item->getEntity()->uuid();
    $max_width = $this->getSetting('max_width');
    $hide_on_embargo =  $this->getSetting('hide_on_embargo') ?? FALSE;
    $max_width_css = empty($max_width) || $max_width == 0 ? '100%' : $max_width .'px';
    $max_height = $this->getSetting('max_height');
    $ia_reader_images_base_url = self::defaultSettings()['ia_reader_images_base_url'];
    if ($this->getSetting('ia_reader_images_base_url') &&
      is_string($this->getSetting('ia_reader_images_base_url')
        && strlen(trim($this->getSetting('ia_reader_images_base_url'))) > 1)) {
      $ia_reader_images_base_url = trim($this->getSetting('ia_reader_images_base_url'));
    }

    $textselection = $this->getSetting('textselection') ?? FALSE;
    $embargoed = FALSE;
    $manifest_url = '';
    $hascover = TRUE;
    if ($this->getSetting('hascover_json_key_source')) {
      $key = is_string($this->getSetting('hascover_json_key_source')) ? trim($this->getSetting('hascover_json_key_source')) : NULL;
      if ($key && isset($jsondata[$key])) {
        $hascover = (bool) $jsondata[$key];
      }
    }



    $embargo_context = [];
    if ($this->getSetting('manifesturl_source') && $pagestrategy == 'manifesturl') {
      $manifest_url_key = $this->getSetting('manifesturl_source');
      if ($jsondata[$manifest_url_key]) {
        $manifest_url = $jsondata[$manifest_url_key];
      }
    }
    if  ($this->getSetting('metadataexposeentity_source') && $pagestrategy == 'metadataexposeentity') {
      $entity = $this->entityTypeManager->getStorage(
        'metadataexpose_entity'
      )->load($this->getSetting('metadataexposeentity_source'));
      if ($entity) {
        $manifest_url = $entity->getUrlForItemFromNodeUUID($nodeuuid, TRUE);
      }
    }

    $embargo_info = $this->embargoResolver->embargoInfo(
      $item->getEntity()->uuid(), $jsondata
    );

    if (is_array($embargo_info)) {
      $embargoed = $embargo_info[0];
      $embargo_tags[] = 'format_strawberryfield:all_embargo';
      if ($embargo_info[1]) {
        $embargo_tags[] = 'format_strawberryfield:embargo:'
          . $embargo_info[1];
      }
      if ($embargo_info[2]) {
        $embargo_context[] = 'ip';
      }
    }

    // Only process render elements if hide on embargo is set
    if (!$embargoed || ($embargoed && !$hide_on_embargo)) {
      if (UrlHelper::isValid($manifest_url, TRUE)) {
        $groupid = 'iiif-' . $item->getName() . '-' . $nodeuuid . '-' . $delta . '-media';
        $htmlid = $groupid;
        $element['media'] = [
          '#type' => 'container',
          '#default_value' => $htmlid,
          '#attributes' => [
            'id' => $htmlid,
            'class' => [
              'strawberry-iabook-item',
              'BookReader',
              'field-iiif',
              'container',
            ],
            'data-iiif-infojson' => '',
            'style' => "width:{$max_width_css}; height:{$max_height}px",
          ],
        ];
        if (isset($item->_attributes)) {
          $element += ['#attributes' => []];
          $element['#attributes'] += $item->_attributes;
          // Unset field item attributes since they have been included in the
          // formatter output and should not be rendered in the field template.
          unset($item->_attributes);
        }
        $element['media']['#attributes']['data-iiif-infojson'] = '';
        $element['media']['#attached']['drupalSettings']['format_strawberryfield']['iabookreader'][$htmlid] = [
          'nodeuuid' => $nodeuuid,
          'manifesturl' => $manifest_url,
          'width' => $max_width_css,
          'height' => max($max_height, 520),
          'textselection' => $textselection,
          'hascover' => $hascover ?? TRUE,
          'iareaderimagesbaseurl' => $ia_reader_images_base_url,
          // @see self::processElementforMetadatadisplays()
        ];
      }
    }

    $element['#cache'] = [
      'context' => Cache::mergeContexts($item->getEntity()->getCacheContexts(), ['user.permissions', 'user.roles'], $embargo_context),
      'tags' => Cache::mergeTags($item->getEntity()->getCacheTags(), $embargo_tags, ['config:format_strawberryfield.embargo_settings']),
    ];
    if (isset($embargo_info[3]) && $embargo_info[3] === FALSE) {
      $element['#cache']['max-age'] = 0;
    }

    return $element;
  }

  /**
   * Returns whether the entity is indexed in Solr and processed by OCR.
   *
   * @return bool
   *  TRUE if the entity is found processed, FALSE otherwise
   */
  protected function searchEnabled(FieldItemInterface $item): bool {
    return $this->strawberryFieldUtility->getCountByProcessorInSolr($item->getEntity(), 'ocr') > 0;
  }

}
