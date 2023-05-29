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
use Drupal\file\FileInterface;
use Drupal\strawberryfield\Tools\Ocfl\OcflHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\format_strawberryfield\Tools\IiifHelper;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\format_strawberryfield\Controller\WebAnnotationController;
use Drupal\Core\Url;
use mysql_xdevapi\Exception;

/**
 * Simplistic Strawberry Field formatter.
 *
 * @FieldFormatter(
 *   id = "strawberry_media_formatter",
 *   label = @Translation("Strawberry Field Media Formatter using OpenSeadragon for IIIF media"),
 *   class = "\Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryMediaFormatter",
 *   field_types = {
 *     "strawberryfield_field"
 *   },
 *   quickedit = {
 *     "editor" = "disabled"
 *   }
 * )
 */
class StrawberryMediaFormatter extends StrawberryBaseIIIFManifestFormatter {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
        'iiif_group' => TRUE,
        'metadataexposeentity_source_required' => FALSE,
        'json_key_source' => 'as:image',
        'max_width' => 720,
        'max_height' => 480,
        'webannotations' => FALSE,
        'webannotations_tool' => 'polygon',
        'webannotations_opencv' => FALSE,
        'webannotations_betterpolygon' => FALSE,
        'webannotations_georeferencewidget' => FALSE,
        'thumbnails' => TRUE,
        'icons_prefixurl' => '',
        'viewer_overrides' => '',
        'mediasource' => NULL,
        'main_mediasource' => NULL,
      ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    //@TODO document that 2 base urls are just needed when developing (localhost syndrom)
    $settings_form = [
        'iiif_group' => [
          '#type' => 'checkbox',
          '#title' => t('Group all Media files in a single viewer?'),
          '#default_value' => $this->getSetting('iiif_group'),
        ],
        'thumbnails' => [
          '#type' => 'checkbox',
          '#title' => t('Show a thumbnail reference bar.'),
          '#default_value' => $this->getSetting('thumbnails'),
        ],
        'webannotations' => [
          '#type' => 'checkbox',
          '#title' => t('Enable loading/editing of W3C webAnnotations.'),
          '#description' => t('<a href="https://www.w3.org/TR/annotation-model/#index-of-json-keys">Click here</a> To learn more about the JSON format of a Web Annotation'),
          '#default_value' => $this->getSetting('webannotations'),
          '#attributes' => [
            'data-formatter-selector' => 'webannotations',
          ],
        ],
        'webannotations_tool' => [
          '#type' => 'select',
          '#options' => [
            'rect' => 'Rectangle',
            'polygon' => 'Polygon',
            'both' => 'Both',
          ],
          '#title' => t('What tool to enable'),
          '#description' => t('This defines if the user will be able to use the Polygon or the Rectangle Tool'),
          '#default_value' => $this->getSetting('webannotations_tool'),
          '#attributes' => [
            'data-formatter-selector' => 'webannotations-tool',
          ],
          '#states' => [
            'visible' => [
              ':input[data-formatter-selector="webannotations"]' => ['checked' => TRUE],
            ],
          ],
        ],
        'webannotations_opencv' => [
          '#type' => 'checkbox',
          '#title' => t('Enable OpenCV tools'),
          '#description' => t('This defines if the user will be able to use the Experimental Face Detect and Edge detection.'),
          '#default_value' => $this->getSetting('webannotations_opencv'),
          '#states' => [
            'visible' => [
              ':input[data-formatter-selector="webannotations"]' => ['checked' => TRUE],
            ],
          ],
        ],
        'webannotations_georeferencewidget' => [
          '#type' => 'checkbox',
          '#title' => t('Enable the Georeference widget'),
          '#description' => t('This defines if the user will be able to use the Georeference Widget to map/deform a fragment using 4 points.'),
          '#default_value' => $this->getSetting('webannotations_georeferencewidget'),
          '#states' => [
            'visible' => [
              ':input[data-formatter-selector="webannotations"]' => ['checked' => TRUE],
            ],
          ],
        ],
        'webannotations_betterpolygon' => [
          '#type' => 'checkbox',
          '#title' => t('Enable Better Polygon Module'),
          '#description' => t('This defines if the user will be able to use the Experimental advanced Polygon editor.'),
          '#default_value' => $this->getSetting('webannotations_betterpolygon'),
          '#states' => [
            'visible' => [
              [':input[data-formatter-selector="webannotations"]' => ['checked' => TRUE]],
              'and',
              [':input[data-formatter-selector="webannotations-tool"]' =>  ['!value' => "rect"]],
            ],
          ],
        ],
        'viewer_overrides' => [
          '#type' => 'textarea',
          '#title' => $this->t('Advanced: a JSON with Open Seadragon Viewer Library config overrides.'),
          '#description' => $this->t('See <a href="https://openseadragon.github.io/docs/OpenSeadragon.html#.Options">https://openseadragon.github.io/docs/OpenSeadragon.html#.Options</a>. Leave Empty to use defaults.
Not all options can be overriden. `id`,`tileSources`, `element` and other might have unexpected consequences. Use with caution. An ADO can also override this formatters OSD settings by providing the following JSON key: @ado_override',[
  '@ado_override' => json_encode(["ap:viewerhints" => ["strawberry_media_formatter"=> ["options" => ["showRotationControl" => TRUE]]]], JSON_FORCE_OBJECT|JSON_PRETTY_PRINT)
          ]),
          '#default_value' => $this->getSetting('viewer_overrides'),
          '#element_validate' => [[$this, 'validateJSON']],
          '#required' => FALSE,
        ],
        'icons_prefixurl' => [
          '#type' => 'textfield',
          '#title' => $this->t('based URL from where to fetch the OpenSeadragon UI/UX Icons'),
          '#description' => $this->t('E.g <b>https://cdn.jsdelivr.net/npm/openseadragon@2.4.2/build/openseadragon/images/</b>. Leave Empty to use the defaults.'),
          '#default_value' => $this->getSetting('icons_prefixurl'),
          '#required' => FALSE,
        ],
        'json_key_source' => [
          '#type' => 'textfield',
          '#title' => $this->t('JSON Key from where to fetch Media URLs.'),
          '#description'=> $this->t('When used in conjunction with IIIF Manifest Sources this setting will be <em>ignored</em> even if all IIIF manifests are wrongly structured or empty..'),
          '#default_value' => $this->getSetting('json_key_source'),
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
          '#required' => TRUE,
        ],
        'max_height' => [
          '#type' => 'number',
          '#title' => $this->t('Maximum height'),
          '#default_value' => $this->getSetting('max_height'),
          '#size' => 5,
          '#maxlength' => 5,
          '#field_suffix' => $this->t('pixels'),
          '#min' => 0,
          '#required' => TRUE,
        ],
      ] + parent::settingsForm($form, $form_state);

    return $settings_form;
    // @see \Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryBaseFormatter::settingsForm
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $summary[] = $this->t('Displays Zoomable Media from JSON using a IIIF server and the OpenSeadragon viewer.');

    if ($this->getSetting('iiif_group')) {
      $summary[] = $this->t('Use a single Viewer for multiple media: %iiif_group', [
        '%iiif_group' => $this->getSetting('iiif_group'),
      ]);
    }
    if ($this->getSetting('webannotations')) {
      $summary[] = $this->t('Enable W3C WebAnnotations: %webannotations', [
        '%webannotations' => $this->getSetting('webannotations'),
      ]);
    }
    if ($this->getSetting('thumbnails')) {
      $summary[] = $this->t('Show thumbnail navigation bar: %thumbnails', [
        '%thumbnails' => $this->getSetting('thumbnails'),
      ]);
    }
    if ($this->getSetting('json_key_source')) {
      $summary[] = $this->t('Media fetched from JSON "%json_key_source" key', [
        '%json_key_source' => $this->getSetting('json_key_source'),
      ]);
    }
    $summary[] = $this->t(
      'Maximum size: %max_width x %max_height',
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
    $upload_keys_string = strlen(trim($this->getSetting('upload_json_key_source'))) > 0 ? trim($this->getSetting('upload_json_key_source')) : NULL;
    $upload_keys = explode(',', $upload_keys_string);
    $upload_keys = array_filter($upload_keys);
    $upload_keys = array_map('trim', $upload_keys);
    $embargo_context = [];
    $embargo_tags = [];

    $embargo_upload_keys_string = strlen(trim($this->getSetting('embargo_json_key_source'))) > 0 ? trim($this->getSetting('embargo_json_key_source')) : NULL;
    $embargo_upload_keys_string = explode(',', $embargo_upload_keys_string);
    $embargo_upload_keys_string = array_filter($embargo_upload_keys_string);
    $key = $this->getSetting('json_key_source');

    foreach ($items as $delta => $item) {
      $main_property = $item->getFieldDefinition()
        ->getFieldStorageDefinition()
        ->getMainPropertyName();
      $value = $item->{$main_property};
      if (empty($value)) {
        continue;
      }

      $jsondata = json_decode($item->value, TRUE);
      // @TODO use future flatversion precomputed at field level as a property
      $json_error = json_last_error();
      if ($json_error != JSON_ERROR_NONE) {
        return $elements[$delta] = ['#markup' => $this->t('Sorry, we had issues processing this metadata')];
      }
      /* Expected structure of an Media item inside JSON
      "as:images": {
         "urn:uuid:someuuid": {
         "dr:fid": 32, // Drupal's FID
         "dr:for": "add_some_master_images", // The webform element key that generated this one
         "url": "s3:\/\/f23\/new-metadata-en-image-58455d91acf7290275c1cab77531b7f561a11a84.jpg",
         "name": "new-metadata-en-image-a8d0090cbd2cd3ca2ab16e3699577538f3049941.jpg",
         "type": "Image",
         "checksum": "f231aed5ae8c2e02ef0c5df6fe38a99b"
         }
      }*/
      $embargo_info = $this->embargoResolver->embargoInfo($items->getEntity()
        ->uuid(), $jsondata);
      // Check embargo
      if (is_array($embargo_info)) {
        $embargoed = $embargo_info[0];
        $embargo_tags[] = 'format_strawberryfield:all_embargo';
        if ($embargo_info[1]) {
          $embargo_tags[] = 'format_strawberryfield:embargo:' . $embargo_info[1];
        }
        if ($embargo_info[2]) {
          $embargo_context[] = 'ip';
        }
      }
      else {
        $embargoed = $embargo_info;
      }
      if ($embargoed) {
        $upload_keys = $embargo_upload_keys_string;
      }

      if (!$embargoed || !empty($embargo_upload_keys_string)) {

        // We need to load main Library on each page for views to see it.
        $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/iiif_openseadragon_strawberry';


        $mediasource = is_array($this->getSetting('mediasource'))
          ? $this->getSetting('mediasource') : [];
        $main_mediasource = $this->getSetting('main_mediasource');
        if (!empty($mediasource) && ($main_mediasource)) {
          $this->generateElementForIIIFManifests($delta,$items,$elements, $jsondata);
        }
        else {
          $ordersubkey = 'sequence';
          $media = $this->fetchMediaFromJsonWithFilter(
            $delta, $items, $elements,
            TRUE, $jsondata, 'Image', $key, $ordersubkey, 0, $upload_keys
          );
        }
        if (empty($elements[$delta])) {
          $elements[$delta] = ['#markup' => $this->t('This Object has no Media')];
        }
        // Get rid of empty #attributes key to avoid render error.
        if (isset($elements[$delta]["#attributes"]) && empty($elements[$delta]["#attributes"])) {
          unset($elements[$delta]["#attributes"]);
        }
      }
    }
    $elements['#cache'] = [
      'context' => Cache::mergeContexts($items->getEntity()->getCacheContexts(), ['user.permissions', 'user.roles'], $embargo_context),
      'tags' => Cache::mergeTags($items->getEntity()->getCacheTags(), $embargo_tags, ['config:format_strawberryfield.embargo_settings']),
    ];
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  protected function generateElementForItem(int $delta, FieldItemListInterface $items, FileInterface $file, IiifHelper $iiifhelper, int $i, array &$elements, array $jsondata, array $mediaitem) {

    $max_width = $this->getSetting('max_width');
    $max_width_css = empty($max_width) ? '100%' : $max_width . 'px';
    $max_height = $this->getSetting('max_height');
    $grouped = $this->getSetting('iiif_group');
    $thumbnails = $this->getSetting('thumbnails');
    $webannotations = $this->getSetting('webannotations');
    $webannotations_tool = $this->getSetting('webannotations_tool');
    $webannotations_opencv = $this->getSetting('webannotations_opencv');
    $webannotations_betterpolygon = $this->getSetting('webannotations_betterpolygon');
    $webannotations_georeferencewidget = $this->getSetting('webannotations_georeferencewidget');
    $viewer_overrides = $this->getSetting('viewer_overrides');
    $viewer_overrides_json = json_decode(trim($viewer_overrides), TRUE);
    $json_error = json_last_error();
    if ($json_error == JSON_ERROR_NONE) {
      $viewer_overrides = $viewer_overrides_json;
    }
    else {
      $viewer_overrides = NULL;
    }




    $icons_prefixurl = trim($this->getSetting('icons_prefixurl')) ?? "";

    $nodeuuid = $items->getEntity()->uuid();

    $iiifidentifier = urlencode(StreamWrapperManager::getTarget($file->getFileUri()));
    if ($iiifidentifier == NULL || empty($iiifidentifier)) {
      return;
    }

    $iiifpublicinfojson = $iiifhelper->getPublicInfoJson($iiifidentifier);

    $groupid = 'iiif-' . $items->getName() . '-' . $nodeuuid . '-' . $delta . '-media';
    $uniqueid = $groupid . $i;
    $elements[$delta]['toolbar-' . $i] = [
      '#type' => 'container',
      '#default_value' => 'toolbar-'. $uniqueid,
      '#attributes' => [
        'id' => 'toolbar-'. $uniqueid,
        'class' => [
          'toolbar-wrapper',
        ],
        'style' => "display:inline-flex",
      ],
    ];
    $elements[$delta]['media' . $i] = [
      '#type' => 'container',
      '#default_value' => $uniqueid,
      '#attributes' => [
        'id' => $uniqueid,
        'class' => [
          'strawberry-media-item',
          'field-iiif',
        ],
        'data-iiif-infojson' => $iiifpublicinfojson,
        'data-iiif-group' => $grouped ? $groupid : $uniqueid,
        'data-iiif-thumbnails' => $thumbnails ? "true" : "false",
        'style' => "width:{$max_width_css}; height:{$max_height}px",
      ],
      //@ TODO recheck cache tags here, since we are not really using
      // the file itself.
      '#cache' => [
        'tags' => $file->getCacheTags(),
      ],
    ];

    if (isset($item->_attributes)) {
      $elements[$delta] += ['#attributes' => []];
      $elements[$delta]['#attributes'] += $item->_attributes;
      // Unset field item attributes since they have been included
      // in the formatter output and should not be rendered in the
      // field template.
      unset($item->_attributes);
    }
    // @TODO deal with a lot of Media single strawberryfield
    // Idea would be to allow a setting that says, A) all same
    // viewer(aggregate)
    // B) individual viewers for each?
    // C) only first one?
    // We will assign a group based on the UUID of the node
    // containing this to idenfity all the divs that we will create.
    // And only first one will be the container in case of many? so
    // a jquery selector that uses that group as filter for a
    // search.
    // Drupal JS settings get accumulated. So in a single search
    // results site we will have for each Formatter one passed.
    // Reason we use 'innode' array key using our $uniqueid
    // @TODO probably better to use uuid() or the node id() instead of $uniqueid
    $elements[$delta]['media' . $i]['#attributes']['data-iiif-infojson'] = $iiifpublicinfojson;
    $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['path'] = \Drupal::service('extension.path.resolver')->getPath('module', 'format_strawberryfield');
    $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon']['innode'][$uniqueid] = $nodeuuid;
    $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['width'] = $max_width_css;
    $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['dr:uuid'] = $file->uuid();
    $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['icons_prefixurl'] = $icons_prefixurl;
    // Used to keep annotations around during edit.
    // Note: Since View modes are cached, if no change to the NODE
    // this will be served from a cache! mmm.
    if ($this->currentUser->hasPermission('view strawberryfield webannotation') && $webannotations) {
      $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['keystoreid'] = WebAnnotationController::getTempStoreKeyName($items->getName(), $delta, $nodeuuid);
      $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['webannotations'] = (boolean) $webannotations ?? FALSE;
      $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['webannotations_tool'] = $webannotations_tool ? $webannotations_tool : 'rect';
      $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['webannotations_opencv'] = (boolean) $webannotations_opencv ?? FALSE;
      $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['webannotations_betterpolygon'] = (boolean) $webannotations_betterpolygon ?? FALSE;
      $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['webannotations_georeferencewidget'] = (boolean) $webannotations_georeferencewidget ?? FALSE;
      $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['viewer_overrides'] = $viewer_overrides;
      // This also never runs if cached. So after deletion we better
      // call the controller!
      if (!empty($jsondata['ap:annotationCollection']) && is_array($jsondata['ap:annotationCollection'])) {
        $keystoreid = $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['keystoreid'];
        WebAnnotationController::primeKeyStore($items[$delta], $keystoreid);
      }
    }
    if ($this->currentUser) {
      $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['user']['url'] = Url::fromRoute('entity.user.canonical', ['user' => $this->currentUser->getAccount()->id()])->toString();
      $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['user']['name'] = $this->currentUser->getAccount()->getAccountName();
    }
    else {
      $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['user']['url'] = null;
      $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['user']['name'] = 'anonymous';
    }

    $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['height'] = max($max_height, 480);
  }


  protected function generateElementForIIIFManifests($delta, FieldItemListInterface $items, array &$elements, array $jsondata) {

    $max_width = $this->getSetting('max_width');
    $max_width_css = empty($max_width) ? '100%' : $max_width . 'px';
    $max_height = $this->getSetting('max_height');
    $grouped = $this->getSetting('iiif_group');
    $thumbnails = $this->getSetting('thumbnails');
    $webannotations = $this->getSetting('webannotations');
    $webannotations_tool = $this->getSetting('webannotations_tool');
    $webannotations_opencv = $this->getSetting('webannotations_opencv');
    $webannotations_betterpolygon = $this->getSetting('webannotations_betterpolygon');
    $webannotations_georeferencewidget = $this->getSetting('webannotations_georeferencewidget');
    $viewer_overrides = $this->getSetting('viewer_overrides');
    $viewer_overrides_json = json_decode(trim($viewer_overrides), TRUE);
    $json_error = json_last_error();
    if ($json_error == JSON_ERROR_NONE) {
      $viewer_overrides = $viewer_overrides_json;
    }
    else {
      $viewer_overrides = NULL;
    }


    $icons_prefixurl = trim($this->getSetting('icons_prefixurl')) ?? "";

    $nodeuuid = $items->getEntity()->uuid();
    $groupid = 'iiif-' . $items->getName() . '-' . $nodeuuid . '-' . $delta . '-media';
    $uniqueid = $groupid;
    $elements[$delta]['toolbar'] = [
      '#type' => 'container',
      '#default_value' => 'toolbar-'. $uniqueid,
      '#attributes' => [
        'id' => 'toolbar-'. $uniqueid,
        'class' => [
          'toolbar-wrapper',
        ],
        'style' => "display:inline-flex",
      ],
    ];
    $elements[$delta]['media'] = [
      '#type' => 'container',
      '#default_value' => $uniqueid,
      '#attributes' => [
        'id' => $uniqueid,
        'class' => [
          'strawberry-media-item',
          'field-iiif',
        ],
        'data-iiif-infojson' => 'iiifmanifest',
        'data-iiif-group' => $grouped ? $groupid : $uniqueid,
        'data-iiif-thumbnails' => $thumbnails ? "true" : "false",
        'style' => "width:{$max_width_css}; height:{$max_height}px",
      ],
      //@ TODO recheck cache tags here, since we are not really using
      // the file itself.
    ];

    if (isset($item->_attributes)) {
      $elements[$delta] += ['#attributes' => []];
      $elements[$delta]['#attributes'] += $item->_attributes;
      // Unset field item attributes since they have been included
      // in the formatter output and should not be rendered in the
      // field template.
      unset($item->_attributes);
    }
    $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['path'] = \Drupal::service('extension.path.resolver')->getPath('module', 'format_strawberryfield');
    $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['openseadragon']['innode'][$uniqueid] = $nodeuuid;
    $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['width'] = $max_width_css;
    $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['icons_prefixurl'] = $icons_prefixurl;
    // Used to keep annotations around during edit.
    // Note: Since View modes are cached, if no change to the NODE
    // this will be served from a cache! mmm.
    if ($this->currentUser->hasPermission('view strawberryfield webannotation') && $webannotations) {
      $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['keystoreid'] = WebAnnotationController::getTempStoreKeyName($items->getName(), $delta, $nodeuuid);
      $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['webannotations'] = (boolean) $webannotations ?? FALSE;
      $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['webannotations_tool'] = $webannotations_tool ? $webannotations_tool : 'rect';
      $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['webannotations_opencv'] = (boolean) $webannotations_opencv ?? FALSE;
      $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['webannotations_betterpolygon'] = (boolean) $webannotations_betterpolygon ?? FALSE;
      $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['webannotations_georeferencewidget'] = (boolean) $webannotations_georeferencewidget ?? FALSE;
      $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['viewer_overrides'] = $viewer_overrides;

      // This also never runs if cached. So after deletion we better
      // call the controller!
      if (!empty($jsondata['ap:annotationCollection']) && is_array($jsondata['ap:annotationCollection'])) {
        $keystoreid = $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['keystoreid'];
        WebAnnotationController::primeKeyStore($items[$delta], $keystoreid);
      }
    }
    if ($this->currentUser) {
      $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['user']['url'] = Url::fromRoute('entity.user.canonical', ['user' => $this->currentUser->getAccount()->id()])->toString();
      $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['user']['name'] = $this->currentUser->getAccount()->getAccountName();
    }
    else {
      $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['user']['url'] = null;
      $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['user']['name'] = 'anonymous';
    }

    $elements[$delta]['media']['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['height'] = max($max_height, 480);
    $this->fetchIIIF($delta, $items, $elements, $jsondata, $uniqueid, 'openseadragon');
  }


}
