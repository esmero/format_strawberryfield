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
 *   label = @Translation("Strawberry Field Paged Formatter using the Mirador IIIF Viewer
 *   plugin"), class =
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
          'manifesturl' => 0,
          'manifestnodelist' => 0
        ],
        'main_mediasource' => 'metadataexposeentity',
        'metadataexposeentity_source' => NULL,
        'manifestnodelist_source' => 'isrelatedto',
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
    if ($this->getSetting('metadataexposeentity_source')) {
      $entity = $this->entityTypeManager->getStorage(
        'metadataexpose_entity'
      )->load($this->getSetting('metadataexposeentity_source'));
    }
    $options_for_mainsource = is_array($this->getSetting('mediasource')) && !empty($this->getSetting('mediasource')) ? $this->getSetting('mediasource') : self::defaultSettings()['mediasource'];

    if (($triggering_element = $form_state->getTriggeringElement()) && isset($triggering_element['#ajax']['callback'])) {
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
    $options_for_mainsource =  array_intersect_key($options_for_mainsource, $all_options_form_source);

    // Define #ajax callback.
    $ajax = [
      'callback' => [get_class($this), 'ajaxCallbackMainSource'],
      'wrapper' => 'main-mediasource-ajax-container',
    ];

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
          '#title' => $this->t('Select which Source will be handled as the primary one.'),
          '#options' => $options_for_mainsource,
          '#default_value' => $this->getSetting('mediasource'),
          '#required' => FALSE,
          '#prefix' => '<div id="main-mediasource-ajax-container">',
          '#suffix' => '</div>',
        ],
        'metadataexposeentity_source' => [
          '#type' => 'entity_autocomplete',
          '#target_type' => 'metadataexpose_entity',
          '#selection_handler' => 'default',
          '#validate_reference' => TRUE,
          '#default_value' => $entity,
          '#states' => [
            'visible' => [
              ':input[data-formatter-selector="mediasource"][value="metadataexposeentity"]' => ['checked' => TRUE],
            ],
          ],
        ],
        'manifesturl_source' => [
          '#type' => 'textfield',
          '#title' => t(
            'JSON Key from where to fetch one or more IIIF manifest URLs. URLs can be external.'
          ),
          '#default_value' => $this->getSetting('manifesturl_source'),
          '#states' => [
            'visible' => [
              ':input[data-formatter-selector="mediasource"][value="manifesturl"]' => ['checked' => TRUE],
            ],
          ],
        ],

        'manifestnodelist_source' => [
          '#type' => 'textfield',
          '#title' => t(
            'JSON Key from where to fetch one or more Nodes. Values can be either NODE IDs (Integers) or UUIDs (Strings). But all of the same type.'
          ),
          '#default_value' => $this->getSetting('manifesturl_source'),
          '#states' => [
            'visible' => [
              ':input[data-formatter-selector="mediasource"][value="manifestnodelist"]' => ['checked' => TRUE],
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
      ] + parent::settingsForm($form, $form_state);
    if (empty($options_for_mainsource)) {
      // let's give people a hint of what they are doing wrong
      $settings_form['main_mediasource']['#empty_option'] = t('- No Source for your IIIF Manifest Urls. Please check one! -');

    }
    return $settings_form;
  }

  private function getActiveMetadataConfigEntitiesAsOptions() {
    $this->entityTypeManager->getStorage('metadataexpose_entity');
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
  public static function ajaxCallbackMainSource(array $form, FormStateInterface $form_state) {
    $form_parents = $form_state->getTriggeringElement()['#array_parents'];
    $form_parents = array_slice($form_parents, 0, -2);
    $form_parents[] = 'main_mediasource';
    return NestedArray::getValue($form, $form_parents);
  }




  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $summary[] = $this->t(
      'Displays Media from a IIIF API Manifest using the Mirador viewer.'
    );
    if ($this->getSetting('mediasource')) {
      switch ($this->getSetting('mediasource')) {
        case 'manifesturl':
          $summary[] = $this->t(
            'Media fetched from a IIIF Manifest url at  "%manifesturl_source" key',
            [
              '%manifesturl_source' => $this->getSetting('manifesturl_source'),
            ]
          );
          break;
        case 'metadataexposeentity':
          $entity = NULL;
          if ($this->getSetting('metadatadisplayentity_source')) {
            $entity = $this->entityTypeManager->getStorage(
              'metadatadisplay_entity'
            )->load($this->getSetting('metadataexposeentity_source'));
            $label = $entity->toLink()->getText();
            $summary[] = $this->t(
              'Media processed by the "%manifesturl_source" Metadata Data Expose Endpoint',
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
        case 'metadataexposeentity':
          $elements[$delta] = $this->processElementforMetadataExposeEntity(
            $delta,
            $jsondata,
            $item
          );
          break;
      }
      $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/mirador_strawberry';

    }
    return $elements;
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
  public function processElementforMetadataExposeEntity(
    $delta = 0,
    array $jsondata,
    FieldItemInterface $item
  ) {
    $element = [];
    $entity = NULL;
    $nodeuuid = $item->getEntity()->uuid();
    $max_width = $this->getSetting('max_width');
    $max_height = $this->getSetting('max_height');

    if ($this->getSetting('metadataexposeentity_source')) {
      /* @var $entity \Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity */
      $entity = $this->entityTypeManager->getStorage(
        'metadataexpose_entity'
      )->load($this->getSetting('metadataexposeentity_source'));
      if ($entity) {

        $groupid = 'iiif-' . $item->getName(
          ) . '-' . $nodeuuid . '-' . $delta . '-media';
        $htmlid = $groupid;

        $element['media'] = [
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
            'style' => "width: {$max_width} px; height:{$max_height}",
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
        // get the URL to our Metadata Expose Endpoint, we will get a string here.
        $url = $entity->getUrlForItemFromNodeUUID($nodeuuid, TRUE);

        $element['media']['#attributes']['data-iiif-infojson'] = '';

        $element['media']['#attached']['drupalSettings']['format_strawberryfield']['mirador'][$htmlid]['nodeuuid'] = $nodeuuid;
        $element['media']['#attached']['drupalSettings']['format_strawberryfield']['mirador'][$htmlid]['manifesturl'] = $url;

        $element['media']['#attached']['drupalSettings']['format_strawberryfield']['mirador'][$htmlid]['width'] = max(
          $max_width,
          400
        );
        $element['media']['#attached']['drupalSettings']['format_strawberryfield']['mirador'][$htmlid]['height'] = max(
          $max_height,
          320
        );
      }
    }

    return $element;
  }
}
