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
    if ($this->getSetting('metadatadisplayentity_uuid')) {
      $entities = $this->entityTypeManager
        ->getStorage('metadatadisplay_entity')
        ->loadByProperties(['uuid' => $this->getSetting('metadatadisplayentity_uuid')]);
      $entity = reset($entities);
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
        default:
          $summary[] = $this->t('This formatter still needs to be setup');

      }
    }

    $summary[] = $this->t('Maximum size: %max_width x %max_height',
      [
        '%max_width' => (int) $this->getSetting('max_width') == 0 ? '100%' : $this->getSetting('max_width') . ' pixels',
        '%max_height' => $this->getSetting('max_height') . ' pixels',
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
        case 'manifesturl':
          $elements[$delta] = $this->processElementforManifestURL(
            $delta,
            $jsondata,
            $item
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
      $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/iiif_openseadragon';
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
    $max_width = $this->getSetting('max_width');
    $max_width_css = empty($max_width) || $max_width == 0 ? '100%' : $max_width .'px';
    $max_height = $this->getSetting('max_height');

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
        StrawberryfieldJsonHelper::orderSequence($jsondata, $mainkey, $ordersubkey);

        $embargo_info = $this->embargoResolver->embargoInfo($item->getEntity()->uuid(), $jsondata);
        $embargo_context = [];
        // This one is for the Twig template
        // We do not need the IP here. No use of showing the IP at all?
        $context_embargo = ['data_embargo' => ['embargoed' => false, 'until' => NULL]];
        $embargo_tags = [];
        if (is_array($embargo_info)) {
          $embargoed = $embargo_info[0];
          $context_embargo['data_embargo']['embargoed'] = $embargoed;

          $embargo_tags[] = 'format_strawberryfield:all_embargo';
          if ($embargo_info[1]) {
            $embargo_tags[]= 'format_strawberryfield:embargo:'.$embargo_info[1];
            $context_embargo['data_embargo']['until'] = $embargo_info[1];
          }
          if ($embargo_info[2]) {
            $embargo_context[] = 'ip';
          }
        }
        else {
          $context_embargo['data_embargo']['embargoed'] = $embargo_info;
        }
        $context = [
          'data' => $jsondata,
          'node' => $item->getEntity(),
          'iiif_server' => $this->getIiifUrls()['public'],
        ];
        $original_context = $context;
        // Allow other modules to provide extra Context!
        // Call modules that implement the hook, and let them add items.
        \Drupal::moduleHandler()->alter('format_strawberryfield_twigcontext', $context);
        // In case someone decided to wipe the original context?
        // We bring it back!
        $context = $context + $original_context;

        $manifestrenderelement = $metadatadisplayentity->renderNative($context);

        $manifest = $manifestrenderelement->jsonSerialize();
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
            'style' => "width:{$max_width_css}; height:{$max_height}px",
            'data-iiif-infojson' => '',
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
          'manifest' => json_decode($manifest),
          'width' => $max_width_css,
          'height' => max($max_height, 520),
          // While Bookreader has a way to enable/disable search via the "enableSearch"
          // parameter, it doesn't work properly at the moment and we have opened an
          // issue to fix it, meanwhile it's hidden via jQuery.
          // @see https://github.com/internetarchive/bookreader/pull/613
        ];
      }
    }
    $elements['#cache'] = [
      'context' => Cache::mergeContexts($items->getEntity()->getCacheContexts(), ['user.permissions', 'user.roles'], $embargo_context),
      'tags' => Cache::mergeTags($items->getEntity()->getCacheTags(), $embargo_tags, ['config:format_strawberryfield.embargo_settings']),
    ];

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
  public function processElementforManifestURL($delta, array $jsondata, FieldItemInterface $item) {
    $delta = $delta ?? 0;
    $element = [];
    $entity = NULL;
    $nodeuuid = $item->getEntity()->uuid();
    $max_width = $this->getSetting('max_width');
    $max_width_css = empty($max_width) || $max_width == 0 ? '100%' : $max_width .'px';
    $max_height = $this->getSetting('max_height');

    if ($this->getSetting('manifesturl_source')) {
      $manifest_url_key = $this->getSetting('manifesturl_source');
      if ($jsondata[$manifest_url_key]) {
        $manifest_url = $jsondata[$manifest_url_key];
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
            // @see self::processElementforMetadatadisplays()
          ];
        }
      }
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
