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
use Drupal\strawberryfield\Tools\Ocfl\OcflHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Url;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;

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
        'upload_json_key_source' => NULL,
        'uv_texture_json_key_source' => NULL,

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
        'upload_json_key_source' => [
          '#type' => 'textfield',
          '#title' => t('Comma separated list of JSON Keys where media was upload to (e.g the Webform Key for the 3D Model Upload element: "model").'),
          '#description' => t('Leave empty to not filter against the media upload JSON Key'),
          '#default_value' => $this->getSetting('upload_json_key_source'),
          '#required' => FALSE
        ],
        'uv_texture_json_key_source' => [
          '#type' => 'textfield',
          '#title' => t('JSON Key where UV texture(s) was(were) uploaded to (e.g Webform Key for the Upload element: "uvtexture").'),
          '#description' => t('Leave empty if you want the viewer to try to use any Square Image as UV texture independently of where it was uploaded to'),
          '#default_value' => $this->getSetting('uv_texture_json_key_source'),
          '#required' => FALSE
        ],
        'number_models' => [
          '#type' => 'number',
          '#title' => $this->t('Number of 3D Models'),
          '#default_value' => $this->getSetting('number_models'),
          '#size' => 2,
          '#maxlength' => 2,
          '#min' => 0,
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
    $max_width = $this->getSetting('max_width');
    $max_width_css = empty($max_width) || $max_width == 0 ? '100%' : $max_width .'px';
    // Because canvases can not be dynamic. But we can make them scale with JS?
    $max_width = empty($max_width) || $max_width == 0 ? NULL : $max_width ;
    $max_height = $this->getSetting('max_height');
    $number_models =  $this->getSetting('number_models');
    /* @var \Drupal\file\FileInterface[] $files */
    // Fixing the key to extract while coding to 'Media'
    $key = $this->getSetting('json_key_source');

    $nodeuuid = $items->getEntity()->uuid();
    $nodeid = $items->getEntity()->id();
    foreach ($items as $delta => $item) {
      $main_property = $item->getFieldDefinition()->getFieldStorageDefinition()->getMainPropertyName();
      $value = $item->{$main_property};
      if (empty($value)) {
        continue;
      }

      $jsondata = json_decode($item->value, true);
      // @TODO use future flatversion precomputed at field level as a property
      $json_error = json_last_error();
      if ($json_error != JSON_ERROR_NONE) {
        $message= $this->t('We could had an issue decoding as JSON your metadata for node @id, field @field',
          [
            '@id' => $nodeid,
            '@field' => $items->getName(),
          ]);
        return $elements[$delta] = ['#markup' => $this->t('ERROR')];
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
      $i = 0;
      if (isset($jsondata[$key])) {
        // Order 3D Models based on a given 'sequence' key
        $ordersubkey = 'sequence';
        StrawberryfieldJsonHelper::orderSequence($jsondata, $key, $ordersubkey);
        foreach ($jsondata[$key] as $mediaitem) {
          $i++;
          if ($i > $number_models) {
            break;
          }
          if (isset($mediaitem['type']) && $mediaitem['type'] == 'Model') {
            if (isset($mediaitem['dr:fid'])) {
              // @TODO check if loading the entity is really needed to check access.
              // @TODO we can refactor a lot here and move it to base methods
              $file = OcflHelper::resolvetoFIDtoURI(
                $mediaitem['dr:fid']
              );
              if (!$file) {
                continue;
              }


              //@TODO if no media key to file loading was possible
              // means we have a broken/missing media reference
              // we should inform to logs and continue
              if ($this->checkAccess($file)) {
                $imagefile = NULL;
                $publicimageurl = NULL;
                foreach ($jsondata['as:image'] as $imageitem) {
                  // let's check if there is at least one image and its square (UVs will always be)
                  if (isset($imageitem['dr:fid']) &&
                    isset($imageitem['flv:exif']['ImageWidth']) &&
                    isset($imageitem['flv:exif']['ImageHeight']) &&
                    $imageitem['flv:exif']['ImageHeight'] == $imageitem['flv:exif']['ImageWidth']
                  ) {
                    $imagefile = OcflHelper::resolvetoFIDtoURI(
                      $imageitem['dr:fid']
                    );
                    if (!$imagefile) {
                      continue;
                    }
                  }
                }


                if ($imagefile && $this->checkAccess($imagefile)) {
                  $iiifidentifier = urlencode(
                    StreamWrapperManager::getTarget($imagefile->getFileUri())
                  );

                  if (!empty($iiifidentifier)) {
                    $publicimageurl = "{$this->getIiifUrls()['public']}/{$iiifidentifier}"."/full/full/0/default.jpg";
                    // @TODO add a default Thumbnail here.
                  }
                }


                // We assume here file could not be accessible publicly
                $route_parameters = [
                  'node' => $nodeid,
                  'uuid' => $file->uuid(),
                  'format' => 'default.'. pathinfo($file->getFilename(), PATHINFO_EXTENSION)
                ];
                $publicurl = Url::fromRoute('format_strawberryfield.iiifbinary', $route_parameters);

                $filecachetags = $file->getCacheTags();
                //@TODO check this filecachetags and see if they make sense

                $uniqueid =
                  'iiif-'.$items->getName(
                  ).'-'.$nodeuuid.'-'.$delta.'-model'.$i;
                $htmlid = $uniqueid;

                $cache_contexts = ['url.site', 'url.path', 'url.query_args','user.permissions'];
                // @ see https://www.drupal.org/files/issues/2517030-125.patch
                $cache_tags = Cache::mergeTags($filecachetags, $items->getEntity()->getCacheTags());
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
                    )
                  ];
                  if ($max_width) {
                    $elements[$delta]['model' . $i]['#attributes']['width'] = $max_width;
                  }
                  // Lets add hotspots
                  $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/jsm_model_strawberry';

                  if (isset($item->_attributes)) {
                    $elements[$delta] += ['#attributes' => []];
                    $elements[$delta]['#attributes'] += $item->_attributes;
                    // Unset field item attributes since they have been included in the
                    // formatter output and should not be rendered in the field template.
                    unset($item->_attributes);
                  }
                }
              }
              else {
                // @TODO Deal with no access here
                // Should we put a thumb? Just hide?
                // @TODO we can bring a plugin here and there that deals with
                $elements[$delta]['model'.$i] = [
                  '#markup' => '<i class="fas fa-times-circle"></i>',
                  '#prefix' => '<span>',
                  '#suffix' => '</span>',
                ];
              }
            } elseif (isset($mediaitem['url'])) {
              $elements[$delta]['[model'.$i] = [
                '#markup' => 'Non managed '.$mediaitem['url'],
                '#prefix' => '<pre>',
                '#suffix' => '</pre>',
              ];
            }

          }

      }
    }
    return $elements;
  }
}