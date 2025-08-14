<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:56 PM
 */

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\format_strawberryfield\Tools\IiifHelper;

/**
 * Simplistic Strawberry 3D Field formatter.
 *
 * @FieldFormatter(
 *   id = "strawberry_3d_formatter",
 *   label = @Translation("Strawberry Field 3D Model Formatter"),
 *   class = "\Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\Strawberry3DFormatter",
 *   field_types = {
 *     "strawberryfield_field"
 *   },
 *   quickedit = {
 *     "editor" = "disabled"
 *   }
 * )
 */
class Strawberry3DFormatter extends StrawberryBaseFormatter {
  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return parent::defaultSettings() + [
        'json_key_source' => 'as:model',
        'max_width' => 600,
        'max_height' => 400,
        'number_models' => 1,
        'invert_up_axis' => FALSE,
      ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    return [
        'json_key_source' => [
          '#type' => 'textfield',
          '#title' => t('JSON Key from where to fetch Media URLs'),
          '#description' => t('An Archipelago managed "as:mediatype". For 3D models its always "as:model"'),
          '#default_value' => $this->getSetting('json_key_source'),
          '#required' => TRUE
        ],
        'number_models' => [
          '#type' => 'number',
          '#title' => $this->t('Number of 3D Models. This Formatter currently permits only one.'),
          '#default_value' => $this->getSetting('number_models'),
          '#size' => 2,
          '#maxlength' => 2,
          '#min' => 1,
          '#max' => 1,
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
        'invert_up_axis' => [
          '#type' => 'checkbox',
          '#title' => t('Check this in case your Model was exported with inverted UP axis. e.g OBJ coming from Blender.'),
          '#description' => t('You can always provide per ADO a json key under <code>"ap:viewerhints":{"strawberry_3d_formatter":{"cameraUpVector":[0,0,1]}}</code> with [x=0,y=0,z=1] being the default and [0,1,0] what this checkbox generates. It needs to be exactly a three entries array with 0 or 1 values. If present that will always override this setting.'),
          '#default_value' => $this->getSetting('invert_up_axis') ?? FALSE,
        ],
      ] + parent::settingsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $summary[] = $this->t('Displays 3D Models from JSON using the JSM Modeller Library');

    if ($this->getSetting('json_key_source')) {
      $summary[] = $this->t('Media fetched from JSON "%json_key_source" key', [
        '%json_key_source' => $this->getSetting('json_key_source'),
      ]);
    }
    if ($this->getSetting('invert_up_axis')) {
      $summary[] = $this->t('Inverted Up Axis: "%inverted"', [
        '%inverted' => $this->getSetting('invert_up_axis') ? 'YES' : 'NO',
      ]);
    }
    if ($this->getSetting('number_models')) {
      $summary[] = $this->t('Number of 3D Models: "%number"', [
        '%number' => $this->getSetting('number_models'),
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
    $upload_keys_string = strlen(trim($this->getSetting('upload_json_key_source') ?? '')) > 0 ? trim($this->getSetting('upload_json_key_source')) : '';
    $upload_keys = explode(',', $upload_keys_string);
    $upload_keys = array_filter($upload_keys);
    $upload_keys = array_map('trim', $upload_keys);
    $hide_on_embargo =  $this->getSetting('hide_on_embargo') ?? FALSE;
    $embargo_context = [];
    $embargo_tags = [];

    $embargo_upload_keys_string = strlen(trim($this->getSetting('embargo_json_key_source') ?? '')) > 0 ? trim($this->getSetting('embargo_json_key_source')) : '';
    $embargo_upload_keys_string = explode(',', $embargo_upload_keys_string);
    $embargo_upload_keys_string = array_filter($embargo_upload_keys_string);
    $public_iiif_image_url = NULL;
    $publicimageurl = NULL;
    // List of all images that are square keyed by they original filenames
    // Used to map back MTL textures into remote URLS at the JS level
    $publicimageurls = [];
    $publicmtlurl = NULL;
    $nodeid = $items->getEntity()->id();

    $number_media = $this->getSetting('number_models') ?? 1;
    $key = $this->getSetting('json_key_source');
    $inverted = $this->getSetting('invert_up_axis');

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
      /* Expected structure of an Model item inside JSON
      "as:model": {
         "urn:uuid:someuuid": {
         "fid": 32, // Drupal's FID
         "for": "add_some_master_3dmodel", // The webform element key that generated this one
         "url": "s3:\/\/f23\/new-metadata-en-image-58455d91acf7290275c1cab77531b7f561a11a84.stl",
         "name": "new-metadata-en-model-a8d0090cbd2cd3ca2ab16e3699577538f3049941.stl",
         "type": "Model",
         "checksum": "f231aed5ae8c2e02ef0c5df6fe38a99b"
         }
      }*/
      $embargo_info = $this->embargoResolver->embargoInfo($items->getEntity(), $jsondata);
      // Check embargo
      if (is_array($embargo_info)) {
        $embargoed = $embargo_info[0];
        $embargo_tags[] = 'format_strawberryfield:all_embargo';
        if ($embargo_info[1]) {
          $embargo_tags[]= 'format_strawberryfield:embargo:'.$embargo_info[1];
        }
        if ($embargo_info[2] || ($embargo_info[3] == FALSE)) {
          $embargo_context[] = 'ip';
        }
      }
      else {
        $embargoed = $embargo_info;
      }
      if ($embargoed) {
        $upload_keys = $embargo_upload_keys_string;
      }

      if (!$embargoed || (!empty($embargo_upload_keys_string) && !$hide_on_embargo) || ($embargoed && !$hide_on_embargo)) {
        $ordersubkey = 'sequence';
        $conditions_model[] = [
          'source' => ['dr:mimetype'],
          'condition' => 'model/mtl',
          'comp' => '!==',
        ];
        $media = $this->fetchMediaFromJsonWithFilter($delta, $items, $elements,
          TRUE, $jsondata, 'Model', $key, $ordersubkey, $number_media,
          $upload_keys, $conditions_model);

        if ($inverted) {
          $camera_up = [0.0, 1.0, 0.0];
        }
        else {
          $camera_up = [0.0, 0.0, 1.0];
        }

        if (count($media)) {
          // check if we have a metadata level override for the camera
          if (isset($jsondata["ap:viewerhints"]["strawberry_3d_formatter"]["cameraUpVector"]) &&
            is_array($jsondata["ap:viewerhints"]["strawberry_3d_formatter"]["cameraUpVector"]) &&
            count($jsondata["ap:viewerhints"]["strawberry_3d_formatter"]["cameraUpVector"]) == 3) {
            $vector = array_filter($jsondata["ap:viewerhints"]["strawberry_3d_formatter"]["cameraUpVector"],
              function($item) {
                return ($item == 1 ||  $item == 0);
              }
            );
            if (count($vector) == 3) {
              $camera_up = $vector;
            }
          }




          // Now get me materials
          $conditions_mtl[] = [
            'source' => ['dr:mimetype'],
            'condition' => 'model/mtl',
            'comp' => '===',
          ];

          $mtls = $this->fetchMediaFromJsonWithFilter($delta, $items, $elements,
            FALSE, $jsondata, 'Model', $key, $ordersubkey, $number_media,
            $upload_keys, $conditions_mtl);

          if (count($mtls)) {
            foreach ($mtls as $uploadkey => $mtl_in_key) {
              foreach ($mtl_in_key as $mtl) {
                $route_parameters = [
                  'node' => $nodeid,
                  'uuid' => $mtl['file']->uuid(),
                  'format' => $mtl['file_name'],
                ];
                $publicmtlurl = Url::fromRoute('format_strawberryfield.iiifbinary',
                  $route_parameters)->toString();
              }
            }
            foreach ($elements[$delta] as &$element) {
              if (isset($element['#attributes'])) {
                $element['#attributes']['data-iiif-material'] = $publicmtlurl ?? 'null';
              }
            }
          }

          // Now get me all images.
          $conditions[] = [
            'source' => ['flv:exif', 'ImageHeight'],
            'condition' => ['flv:exif', 'ImageWidth'],
          ];
          $images = $this->fetchMediaFromJsonWithFilter($delta, $items,
            $elements,
            FALSE, $jsondata, 'Image', 'as:image', $ordersubkey, 0,
            $upload_keys,
            $conditions);
          foreach ($images as $uploadkey => $images_in_key) {
            foreach ($images_in_key as $image) {
              if (count($mtls)) {
                $route_parameters = [
                  'node' => $nodeid,
                  'uuid' => $image['file']->uuid(),
                  'format' => $image['file_name'],
                ];
                $publicimageurls[] = Url::fromRoute('format_strawberryfield.iiifbinary',
                  $route_parameters)->toString();
              }
              else {
                $iiifidentifier = urlencode(
                  StreamWrapperManager::getTarget($image['file']->getFileUri())
                );
                if ($iiifidentifier) {
                  $publicimageurl = "{$this->getIiifUrls()['public']}/{$iiifidentifier}" . "/full/full/0/default.jpg";
                  break 2;
                }
              }
            }
          }
        }
        // Attach the Library
        $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/jsm_modeler';
        // Add the texture
        // It is always the same.

        foreach ($elements[$delta] as &$element) {
          if (isset($element['#attributes'])) {
            $uniqueid = $element['#attributes']['id'] ?? NULL;
            $element['#attributes']['data-iiif-texture'] = $publicimageurl ?? 'null';
            $element['#attributes']['data-iiif-filename2texture'] = implode('|', $publicimageurls);
            $element['#attributes']['data-iiif-camera-up-vector'] = $camera_up ?? 'null';
            if ($uniqueid) {
              $element['#attached']['drupalSettings']['format_strawberryfield']['strawberry_3d_formatter'][$uniqueid]['camera-up-vector'] = $camera_up ?? 'null';
              }
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
   * {@inheritdoc}
   */
  protected function generateElementForItem(int $delta, FieldItemListInterface $items, FileInterface $file, IiifHelper $iiifhelper, int $i, array &$elements, array $jsondata, array $mediaitem) {

    $max_width = $this->getSetting('max_width');
    $max_width_css = empty($max_width) || $max_width == 0 ? '100%' : $max_width . 'px';
    // Because canvases can not be dynamic. But we can make them scale with JS?
    $max_width = empty($max_width) || $max_width == 0 ? NULL : $max_width;
    $max_height = $this->getSetting('max_height');
    $nodeuuid = $items->getEntity()->uuid();
    $nodeid = $items->getEntity()->id();
    $imagefile = NULL;
    $publicimageurl = NULL;

    // We assume here file could not be accessible publicly
    $route_parameters = [
      'node' => $nodeid,
      'uuid' => $file->uuid(),
      'format' => 'default.' . pathinfo($file->getFilename(),
          PATHINFO_EXTENSION)
    ];
    $publicurl = Url::fromRoute('format_strawberryfield.iiifbinary',
      $route_parameters);

    $filecachetags = $file->getCacheTags();
    //@TODO check this filecachetags and see if they make sense

    $uniqueid =
      'iiif-' . $items->getName() . '-' . $nodeuuid . '-' . $delta . '-model' . $i;
    $htmlid = $uniqueid;

    $cache_contexts = [
      'url.site',
      'url.path',
      'url.query_args',
      'user.permissions'
    ];

    // For Textures and materials see
    // https://github.com/kovacsv/Online3DViewer/blob/master/embeddable/multiple.html
    $elements[$delta]['model' . $i] = [
      '#type' => 'html_tag',
      '#tag' => 'canvas',
      '#attributes' => [
        'class' => ['field-iiif', 'strawberry-3d-item'],
        'id' => $htmlid,
        'data-iiif-model' => $publicurl->toString(),
        'data-iiif-texture' => $publicimageurl,
        'data-iiif-image-width' => $max_width,
        'data-iiif-image-height' => $max_height,
        'data-ado-title' => substr($items->getEntity()->label(),0,16) . '...',
        'height' => $max_height,
        'style' => "width:{$max_width_css}; height:{$max_height}px"
      ],
      '#title' => $this->t(
        '3D Model for @label',
        ['@label' => $items->getEntity()->label()]
      ),
      '#cache' => [
        'context' => $file->getCacheContexts(),
        'tags' => $file->getCacheTags(),
      ],
    ];
    $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/jsm_model_strawberry';
    if ($max_width) {
      $elements[$delta]['model' . $i]['#attributes']['width'] = $max_width;
    }
  }
}
