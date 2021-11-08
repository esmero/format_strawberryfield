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
      ] + parent::settingsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $summary[] = $this->t('Displays 3 Models from JSON using the JSM Modeller Library');

    if ($this->getSetting('json_key_source')) {
      $summary[] = $this->t('Media fetched from JSON "%json_key_source" key', [
        '%json_key_source' => $this->getSetting('json_key_source'),
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
    $upload_keys_string = strlen(trim($this->getSetting('upload_json_key_source'))) > 0 ? trim($this->getSetting('upload_json_key_source')) : NULL;
    $upload_keys = explode(',', $upload_keys_string);
    $upload_keys = array_filter($upload_keys);
    $upload_keys = array_map('trim', $upload_keys);

    $embargo_upload_keys_string = strlen(trim($this->getSetting('embargo_json_key_source'))) > 0 ? trim($this->getSetting('embargo_json_key_source')) : NULL;
    $embargo_upload_keys_string = explode(',', $embargo_upload_keys_string);
    $embargo_upload_keys_string = array_filter($embargo_upload_keys_string);
    $publicimageurl = NULL;

    $number_media = $this->getSetting('number_models');
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
      $embargo_info = $this->embargoResolver->embargoInfo($items->getEntity()->uuid(), $jsondata);
      // Check embargo
      $embargo_context = [];
      $embargo_tags = [];
      if (is_array($embargo_info)) {
        $embargoed = $embargo_info[0];
        $embargo_tags[] = 'format_strawberryfield:all_embargo';
        if ($embargo_info[1]) {
          $embargo_tags[]= 'format_strawberryfield:embargo:'.$embargo_info[1];
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
        $ordersubkey = 'sequence';
        $media = $this->fetchMediaFromJsonWithFilter($delta, $items, $elements,
          TRUE, $jsondata, 'Model', $key, $ordersubkey, $number_media,
          $upload_keys, []);
        // Now get me all images
        if (count($media)) {
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
              $iiifidentifier = urlencode(
                StreamWrapperManager::getTarget($image['file']->getFileUri())
              );
              if (!empty($iiifidentifier)) {
                $publicimageurl = "{$this->getIiifUrls()['public']}/{$iiifidentifier}" . "/full/full/0/default.jpg";
                break 2;
              }
            }
          }

          // Add the texture
          // Its always the same.
          foreach ($elements[$delta] as &$element) {
            if (isset($element['#attributes'])) {
              $element['#attributes']['data-iiif-texture'] = $publicimageurl;
            }
          }
        }
      }
      if (empty($elements[$delta])) {
        $elements[$delta] = [
          '#markup' => '<i class="fas fa-times-circle"></i>',
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
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  protected function generateElementForItem(int $delta, FieldItemListInterface $items, FileInterface $file, IiifHelper $iiifhelper, int $i, array &$elements, array $jsondata) {

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