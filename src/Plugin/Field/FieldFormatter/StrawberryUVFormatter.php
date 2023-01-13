<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 11/02/22
 * Time: 11:00 AM
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
 * Universal IIIF Viewer Strawberry Field formatter.
 *
 * @FieldFormatter(
 *   id = "strawberry_uv_formatter",
 *   label = @Translation("Strawberry Field Media Formatter using the Universal (UV)
 *   IIIF Viewer plugin"), class =
 *   "\Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryUVFormatter",
 *   field_types = {
 *     "strawberryfield_field"
 *   },
 *   quickedit = {
 *     "editor" = "disabled"
 *   }
 * )
 */
class StrawberryUVFormatter extends StrawberryBaseFormatter implements ContainerFactoryPluginInterface {

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;


  /**
   * StrawberryUVFormatter constructor.
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
        'metadataexposeentity_source' => NULL,
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

    $settings_form = [
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
          '#default_value' => $entity,
          '#required' => TRUE,
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
    return $settings_form;
  }


  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary[] = $this->t(
      'Displays Media using the UV IIIF viewer <br>'
    );
    $entity = NULL;
    if ($this->getSetting('metadataexposeentity_source')) {
      $entity = $this->entityTypeManager->getStorage(
        'metadataexpose_entity'
      )->load($this->getSetting('metadataexposeentity_source'));
      if ($entity) {
        $label = $entity->label();
        $summary[] = $this->t(
          'IIIF Manifest generated by the "%metadatadisplayentity" Metadata Data Expose Endpoint.',
          [
            '%metadatadisplayentity' => $label,
          ]
        );
      }
      else {
        $summary[] = $this->t(
          'IIIF Manifest generated by a non existing "%metadatadisplayentity" Metadata Data Expose Endpoint. Please correct this.',
          [
            '%metadatadisplayentity' => $this->getSetting(
              'metadataexposeentity_source'
            ),
          ]
        );
      }
    }
    else {
      $summary[] = $this->t('This formatter still needs to be setup');
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

      $manifests['metadataexposeentity'] = $this->processManifestforMetadataExposeEntity(
        $jsondata,
        $item
      );
      $main_manifesturl = NULL;
      // Check which one is our main source and if it really exists
      if (isset($manifests['metadataexposeentity']) && !empty($manifests['metadataexposeentity'])) {
        // Take only the first since we could have more
        $main_manifesturl = array_shift($manifests['metadataexposeentity']);
      }

      // Only process is we got at least one manifest
      if (!empty($main_manifesturl)) {

        $groupid = 'iiif-' . $item->getName(
          ) . '-' . $nodeuuid . '-' . $delta . '-mirador';
        $htmlid = $groupid;
        // The uv css class is needed for the CDN CSS to kick in
        // Undocumented of course like a lot in UV
        $elements[$delta]['media'] = [
          '#type' => 'container',
          '#default_value' => $htmlid,
          '#attributes' => [
            'id' => $htmlid,
            'class' => [
              'strawberry-uv-item',
              'UvViewer',
              'uv',
              'field-iiif',
            ],
            'style' => "width:{$max_width_css}; height:{$max_height}px",
            'height' => $max_height,
          ],
        ];

        // get the URL to our Metadata Expose Endpoint, we will get a string here.

        $elements[$delta]['media']['#attributes']['data-iiif-infojson'] = '';
        $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['uv'][$htmlid]['nodeuuid'] = $nodeuuid;
        $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['uv'][$htmlid]['manifesturl'] = $main_manifesturl;
        $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['uv'][$htmlid]['width'] = $max_width_css;
        $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['uv'][$htmlid]['height'] = max(
          $max_height,
          480
        );

        $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/uv_strawberry';



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
}