<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:56 PM
 */

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\strawberryfield\Tools\Ocfl\OcflHelper;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\Core\Field\FieldDefinitionInterface;
use stdClass;
use Twig_Error_Syntax;
use Twig_Environment;
use Twig_Error_Runtime;
use Twig_Loader_Array;

/**
 * Simplistic Strawberry Field formatter.
 *
 * @FieldFormatter(
 *   id = "strawberry_paged_formatter",
 *   label = @Translation("Strawberry Field Paged Formatter using IABook Reader
 *   plugin"), class =
 *   "\Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryPagedFormatter",
 *   field_types = {
 *     "strawberryfield_field"
 *   },
 *   quickedit = {
 *     "editor" = "disabled"
 *   }
 * )
 */
class StrawberryPagedFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

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
   * @var \Drupal\Core\Template\TwigEnvironment
   */
  protected $twig;

  /**
   * Constructs a FormatterBase object.
   *
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings.
   */
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
   * @param array
   *   Any third party settings.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current User
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The Entity Type manager
   * @param \Drupal\Core\Template\TwigEnvironment $twigEnvironment
   *   The Loaded twig Environment
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    $label,
    $view_mode,
    array $third_party_settings,
    AccountInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager,
    TwigEnvironment $twigEnvironment
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $label,
      $view_mode,
      $third_party_settings
    );
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->twig = $twigEnvironment;

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
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('twig')
    );
  }


  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'iiif_base_url' => 'http://localhost:8183/iiif/2/',
      'iiif_base_url_internal' => 'http://esmero-cantaloupe:8182/iiif/2/',
      'iiif_group' => TRUE,
      'mediasource' => 'json_key',
      'json_key_source' => 'as:image',
      'metadatadisplayentity_source' => NULL,
      'manifesturl_source' => 'iiifmanifest',
      'max_width' => 720,
      'max_height' => 480,

    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    //@TODO validate IIIF server responses, first one via AJAX, second via CURL.
    //@TODO document that 2 base urls are just needed when developing (localhost syndrom)

    $entity = NULL;
    if ($this->getSetting('metadatadisplayentity_source')) {
      $entity = $this->entityTypeManager->getStorage('metadatadisplay_entity')
        ->load($this->getSetting('metadatadisplayentity_source'));
    }

    return [
      'iiif_base_url' => [
        '#type' => 'url',
        '#title' => $this->t(
          'Base URL of your IIIF Media Server public accesible from the Outside World'
        ),
        '#default_value' => $this->getSetting('iiif_base_url'),
        '#required' => TRUE,
      ],
      'iiif_base_url_internal' => [
        '#type' => 'url',
        '#title' => $this->t(
          'Base URL of your IIIF Media Server accesible from inside this Webserver'
        ),
        '#default_value' => $this->getSetting('iiif_base_url_internal'),
        '#required' => TRUE,
      ],
      'mediasource' => [
        '#type' => 'select',
        '#title' => $this->t('Source for your paged media'),
        '#options' => [
          'json_key' => $this->t('Strawberryfield JSON Key with a list of Images'),
          'manifesturl' => $this->t('Strawberryfield JSON Key with a Manifest URL'),
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
      'json_key_source' => [
        '#type' => 'textfield',
        '#title' => t('JSON Key from where to fetch Media URLs'),
        '#default_value' => $this->getSetting('json_key_source'),
        '#states' => [
          'visible' => [
            ':input[data-formatter-selector="mediasource"]' => ['value' => 'json_key'],
          ],
        ],
      ],
      'manifesturl_source' => [
        '#type' => 'textfield',
        '#title' => t('JSON Key from where to fetch an absolute full IIIF manifest URL'),
        '#default_value' => $this->getSetting('manifesturl_source'),
        '#states' => [
          'visible' => [
            ':input[data-formatter-selector="mediasource"]' => ['value' => 'manifesturl'],
          ],
        ],
      ],
      'metadatadisplayentity_source' => [
        '#type' => 'entity_autocomplete',
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
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $summary[] = $this->t(
      'Displays Paged Media from JSON using a IIIF server and the IABook Reader viewer.'
    );
    if ($this->getSetting('iiif_base_url')) {
      $summary[] = $this->t(
        'External IIIF Media Server base URI: %iiif_base_url',
        [
          '%iiif_base_url' => $this->getSetting('iiif_base_url'),
        ]
      );
    }
    if ($this->getSetting('iiif_base_url_internal')) {
      $summary[] = $this->t(
        'Internal IIIF Media Server base URI: %iiif_base_url',
        [
          '%iiif_base_url' => $this->getSetting('iiif_base_url_internal'),
        ]
      );
    }

    if ($this->getSetting('mediasource')) {
      switch ($this->getSetting('mediasource')) {
        case 'json_key':
          $summary[] = $this->t(
            'Pages fetched from JSON "%json_key_source" key',
            [
              '%json_key_source' => $this->getSetting('json_key_source'),
            ]
          );
          break;
        case 'manifesturl':
          $summary[] = $this->t(
            'Pages fetched from a IIIF Manifest url at  "%manifesturl_source" key',
            [
              '%manifesturl_source' => $this->getSetting('manifesturl_source'),
            ]
          );
          break;
        case 'metadatadisplayentity':
          $entity = NULL;
          if ($this->getSetting('metadatadisplayentity_source')) {
            $entity = $this->entityTypeManager->getStorage(
              'metadatadisplay_entity'
            )->load($this->getSetting('metadatadisplayentity_source'));
            $label = $entity->toLink()->getText();
            $summary[] = $this->t(
              'Pages processed by the "%manifesturl_source" Metadata Data Display template',
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

    if ($this->getSetting('max_width') && $this->getSetting('max_height')) {
      $summary[] = $this->t(
        'Maximum size: %max_width x %max_height pixels',
        [
          '%max_width' => $this->getSetting('max_width'),
          '%max_height' => $this->getSetting('max_height'),
        ]
      );
    }
    elseif ($this->getSetting('max_width')) {
      $summary[] = $this->t(
        'Maximum width: %max_width pixels',
        [
          '%max_width' => $this->getSetting('max_width'),
        ]
      );
    }
    elseif ($this->getSetting('max_height')) {
      $summary[] = $this->t(
        'Maximum height: %max_height pixels',
        [
          '%max_height' => $this->getSetting('max_height'),
        ]
      );
    }

    return $summary;
  }


  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $max_width = $this->getSetting('max_width');
    $max_height = $this->getSetting('max_height');
    $pagestrategy = $this->getSetting('mediasource');


    /* @var \Drupal\file\FileInterface[] $files */
    // Fixing the key to extract while coding to 'Media'

    $baseiiifserveruri = $this->getSetting('iiif_base_url');

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
    $nodeid = $items->getEntity()->id();
    $fieldname = $items->getName();
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
      $manifest = '';
      switch ($pagestrategy) {
	case 'json_key':
	  break;
        case 'manifesturl':
	  $elements[$delta] = $this->processElementforManifestURL($delta, $jsondata, $item);
	  break;
        case 'metadatadisplayentity':
          $elements[$delta] = $this->processElementforMetadatadisplays($delta, $jsondata, $item);
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
   * {@inheritdoc}
   */
  public function view(FieldItemListInterface $items, $langcode = NULL) {

    $elements = parent::view($items, $langcode);
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity) {
    // Only check access if the current file access control handler explicitly
    // opts in by implementing FileAccessFormatterControlHandlerInterface.
    $access_handler_class = $entity->getEntityType()->getHandlerClass('access');
    if (is_subclass_of(
      $access_handler_class,
      '\Drupal\file\FileAccessFormatterControlHandlerInterface'
    )) {
      return $entity->access('view', NULL, FALSE);
    }
    else {
      return AccessResult::allowed();
    }
  }

  /**
   * Sort passed pages based on a sequence key integer
   *
   * @param array $jsondata
   */
  protected function orderPages(array &$jsondata, $mainkey = 'as:image', $orderkey ='sequence') {
    if (!isset($jsondata[$mainkey])){
      return;
    }
    uasort($jsondata[$mainkey],function($a, $b) use ($orderkey) {
      if ((array_key_exists($orderkey, $a)) && (array_key_exists($orderkey, $b))) {
        return (int) $a[$orderkey] <=> (int) $b[$orderkey];
      } else {
        return 0;
      }
    });
  }

  public function processElementsforImages(
    $delta = 0,
    array $jsondata,
    FieldItemInterface $item) {
    $key = $this->getSetting('json_key_source');
    $nodeuuid = $item->getEntity()->uuid();
    $i = 0;
    if (isset($jsondata[$key])) {
    foreach ($jsondata[$key] as $mediaitem) {
      $i++;
      if (isset($mediaitem['type']) && in_array(
          $mediaitem['type'],
          ['Image', 'Document']
        )) {
        if (isset($mediaitem['dr:fid'])) {
          // @TODO check if loading the entity is really needed to check access.
          $file = OcflHelper::resolvetoFIDtoURI(
            $mediaitem['dr:fid']
          );

          //@TODO if no media key to file loading was possible
          // means we have a broken/missing media reference
          // we should inform to logs and continue
          if (!$file) {
            continue;
          }
          if ($this->checkAccess($file)) {
            $iiifidentifier = urlencode(
              file_uri_target($file->getFileUri())
            );
            if ($iiifidentifier == NULL || empty($iiifidentifier)) {
              continue;
            }
            //@ TODO recheck cache tags here, since we are not really using the file itself.
            $filecachetags = $file->getCacheTags();

            // @TODO move the IIIF server baser URL to a global config and add local fieldformatter override.
            $iiifserver = "{$this->getSetting('iiif_base_url')}{$iiifidentifier}/info.json";


            $groupid = 'iiif-' . $item->getName(
              ) . '-' . $nodeuuid . '-' . $delta . '-media';
            $htmlid = $groupid . $i;

            $elements[$delta]['media' . $i] = [
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
                'data-iiif-infojson' => $iiifserver,
                'width' => $this->getSetting('max_width'),
                'height' => $this->getSetting('max_height'),
              ],
            ];
            if (isset($item->_attributes)) {
              $elements[$delta] += ['#attributes' => []];
              $elements[$delta]['#attributes'] += $item->_attributes;
              // Unset field item attributes since they have been included in the
              // formatter output and should not be rendered in the field template.
              unset($item->_attributes);
            }

            // @TODO probably better to use uuid() or the node id() instead of $uniqueid
            $elements[$delta]['media' . $i]['#attributes']['data-iiif-infojson'] = $iiifserver;
            $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['iabookreader'][$htmlid]['nodeuuid'] = $nodeuuid;

          }
        }
        elseif (isset($mediaitem['url'])) {
          // Lets check if its external
          if (UrlHelper::isExternal($mediaitem['url'])) {
            // @TODO now we have two choices.
            // Pass to IIIF as an ID
            // Simple use as external Image source in the viewer
          }

          $elements[$delta]['media' . $i] = [
            '#markup' => 'Non managed ' . $mediaitem['url'],
            '#prefix' => '<pre>',
            '#suffix' => '</pre>',
          ];
        }

      }
    }
  }

  }

  public function processElementsforDocuments() {

  }


  /**
   * Generates render element for a Twig generated manifest.
   *
   * @param int $delta
   * @param array $jsondata
   * @param \Drupal\Core\Field\FieldItemInterface $item
   *
   * @return array
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function processElementforMetadatadisplays(
    $delta = 0,
    array $jsondata,
    FieldItemInterface $item
  ) {
    $element = [];
    $entity = NULL;
    $nodeuuid = $item->getEntity()->uuid();
    $max_width = $this->getSetting('max_width');
    $max_height = $this->getSetting('max_height');

    if ($this->getSetting('metadatadisplayentity_source')) {
      $entity = $this->entityTypeManager->getStorage(
        'metadatadisplay_entity'
      )->load($this->getSetting('metadatadisplayentity_source'));
      if ($entity) {

        // Quickly sort the pages. We assume user will use the as:image key
        // Since the actual generation happens via a twig template.
        // @TODO add a config option for this key too.
        $mainkey = 'as:image';
        $ordersubkey = 'sequence';
        $this->orderPages($jsondata, $mainkey, $ordersubkey);

        $context = [
          'data' => $jsondata,
          'node' => $item->getEntity(),
          'baseiiifserveruri' => $this->getSetting('iiif_base_url'),
        ];
        $twigtemplate = $entity->get('twig')->getValue();
        $twigtemplate = !empty($twigtemplate) ? $twigtemplate[0]['value'] : "{{ field.label }}";
        $manifestrenderelement = $this->twig_process(
          $twigtemplate,
          $context
        );

        $manifest = $manifestrenderelement->jsonSerialize();
        $groupid = 'iiif-' . $item->getName(
          ) . '-' . $nodeuuid . '-' . $delta . '-media';
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
            'width' => $max_width,
            'height' => $max_height,
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
        $element['media']['#attached']['drupalSettings']['format_strawberryfield']['iabookreader'][$htmlid]['nodeuuid'] = $nodeuuid;
        $element['media']['#attached']['drupalSettings']['format_strawberryfield']['iabookreader'][$htmlid]['manifest'] = json_decode(
          $manifest
        );
        $element['media']['#attached']['drupalSettings']['format_strawberryfield']['iabookreader'][$htmlid]['width'] = max($max_width, 400);
        $element['media']['#attached']['drupalSettings']['format_strawberryfield']['iabookreader'][$htmlid]['height'] = max($max_height, 320);
      }
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
  public function processElementforManifestURL($delta = 0, array $jsondata, FieldItemInterface $item) {
    $element = [];
    $entity = NULL;
    $nodeuuid = $item->getEntity()->uuid();
    $max_width = $this->getSetting('max_width');
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
              'width' => $max_width,
              'height' => $max_height,
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
          $element['media']['#attached']['drupalSettings']['format_strawberryfield']['iabookreader'][$htmlid]['nodeuuid'] = $nodeuuid;
          $element['media']['#attached']['drupalSettings']['format_strawberryfield']['iabookreader'][$htmlid]['manifesturl'] = $manifest_url;
          $element['media']['#attached']['drupalSettings']['format_strawberryfield']['iabookreader'][$htmlid]['width'] = max($max_width, 400);
          $element['media']['#attached']['drupalSettings']['format_strawberryfield']['iabookreader'][$htmlid]['height'] = max($max_height, 320);
          }
      	}
      }
      return $element;
    }

  /**
   * Use to process a Template directly.
   *
   * @param string $twigtemplate
   * @param array $context
   * @param boolean $removeHTML
   *
   * @return \Drupal\Core\Render\Markup
   */
  protected function twig_process(
    string $twigtemplate,
    array $context = []
  ) {

    $build = [
      '#type' => 'inline_template',
      '#template' => $twigtemplate,
      '#context' => $context,
    ];

    return \Drupal::service('renderer')->renderPlain($build);
  }

}
