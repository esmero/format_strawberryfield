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
use Drupal\webform_strawberryfield\Tools\Ocfl\OcflHelper;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Cache\Cache;
use Drupal\format_strawberryfield\Tools\IiifHelper;

/**
 * Simplistic Strawberry Field formatter.
 *
 * @FieldFormatter(
 *   id = "strawberry_pannellum_formatter",
 *   label = @Translation("Strawberry Field Panorama Formatter using Pannellum and IIIF"),
 *   class = "\Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryPannellumFormatter",
 *   field_types = {
 *     "strawberryfield_field"
 *   },
 *   quickedit = {
 *     "editor" = "disabled"
 *   }
 * )
 */
class StrawberryPannellumFormatter extends FormatterBase {
  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'iiif_base_url' => 'http://localhost:8183/iiif/2/',
      'iiif_base_url_internal' => 'http://esmero-cantaloupe:8182/iiif/2/',
      'json_key_source' => 'as:image',
      'json_key_hotspots' => 'hotspot',
      'max_width' => 600,
      'max_height' => 400,
      'panorama_type' => 'equirectangular',
      'image_type' => 'jpg',
      'number_images' => 1,
      'quality' => 'default',
      'rotation' => '0',
      'hotSpotDebug' => TRUE,


    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    return [
      'iiif_base_url' => [
        '#type' => 'url',
        '#title' => $this->t('Base Public accesible URL of your IIIF Media Server'),
        '#default_value' => $this->getSetting('iiif_base_url'),
        '#required' => TRUE,
      ],
      'iiif_base_url_internal' => [
        '#type' => 'url',
        '#title' => $this->t('Base URL of your IIIF Media Server accesible from inside this Webserver'),
        '#default_value' => $this->getSetting('iiif_base_url_internal'),
        '#required' => TRUE,
      ],
      'json_key_source' => [
        '#type' => 'textfield',
        '#title' => t('JSON Key from where to fetch Media URLs'),
        '#default_value' => $this->getSetting('json_key_source'),
      ],
      'json_key_hotspots' => [
        '#type' => 'textfield',
        '#title' => t('JSON Key from where to fetch Pannellum Hotspots'),
        '#default_value' => $this->getSetting('json_key_hotspots'),
      ],
      'number_images' => [
        '#type' => 'number',
        '#title' => $this->t('Number of images'),
        '#default_value' => $this->getSetting('number_images'),
        '#size' => 2,
        '#maxlength' => 2,
        '#min' => 0,
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
    $summary[] = $this->t('Displays Panoramas from JSON using Pannellum and a IIIF server endpoint');
    if ($this->getSetting('iiif_base_url')) {
      $summary[] = $this->t('IIIF Media Server base URI: %iiif_base_url', [
        '%iiif_base_url' => $this->getSetting('iiif_base_url'),
      ]);
    }
    if ($this->getSetting('iiif_base_url_internal')) {
      $summary[] = $this->t('Internal IIIF Media Server base URI: %iiif_base_url', [
        '%iiif_base_url' => $this->getSetting('iiif_base_url_internal'),
      ]);
    }
    if ($this->getSetting('json_key_source')) {
      $summary[] = $this->t('Media fetched from JSON "%json_key_source" key', [
        '%json_key_source' => $this->getSetting('json_key_source'),
      ]);
    }
    if ($this->getSetting('number_images')) {
      $summary[] = $this->t('Number of images: "%number"', [
        '%number' => $this->getSetting('number_images'),
      ]);
    }
    if ($this->getSetting('max_width') && $this->getSetting('max_height')) {
      $summary[] = $this->t('Maximum size: %max_width x %max_height pixels', [
        '%max_width' => $this->getSetting('max_width'),
        '%max_height' => $this->getSetting('max_height'),
      ]);
    }
    elseif ($this->getSetting('max_width')) {
      $summary[] = $this->t('Maximum width: %max_width pixels', [
        '%max_width' => $this->getSetting('max_width'),
      ]);
    }
    elseif ($this->getSetting('max_height')) {
      $summary[] = $this->t('Maximum height: %max_height pixels', [
        '%max_height' => $this->getSetting('max_height'),
      ]);
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
    $number_images =  $this->getSetting('number_images');
    /* @var \Drupal\file\FileInterface[] $files */
    // Fixing the key to extract while coding to 'Media'
    $key = $this->getSetting('json_key_source');
    $hotspots =  $this->getSetting('json_key_hotspots');
    $baseiiifserveruri = $this->getSetting('iiif_base_url');
    $baseiiifserveruri_internal =  $this->getSetting('iiif_base_url_internal');

    $nodeuuid = $items->getEntity()->uuid();
    $nodeid = $items->getEntity()->id();
    $fieldname = $items->getName();
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
      /* Expected structure of an Media item inside JSON
      "as:image": {
         "s3:\/\/f23\/new-metadata-en-image-58455d91acf7290275c1cab77531b7f561a11a84.jpg": {
         "fid": 32, // Drupal's FID
         "for": "add_some_master_images", // The webform element key that generated this one
         "url": "s3:\/\/f23\/new-metadata-en-image-58455d91acf7290275c1cab77531b7f561a11a84.jpg",
         "name": "new-metadata-en-image-a8d0090cbd2cd3ca2ab16e3699577538f3049941.jpg",
         "type": "Image",
         "checksum": "f231aed5ae8c2e02ef0c5df6fe38a99b"
         }
      }*/
      $i = 0;
      if (isset($jsondata[$key])) {
        foreach ($jsondata[$key] as $mediaitem) {
          $i++;
          if ($i > $number_images) {
            break;
          }
          if (isset($mediaitem['type']) && $mediaitem['type'] == 'Image') {
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
                $iiifidentifier = urlencode(
                  file_uri_target($file->getFileUri())
                );

                if ($iiifidentifier == NULL || empty($iiifidentifier)) {
                  continue;
                  // @TODO add a default Thumbnail here.
                }
                $filecachetags = $file->getCacheTags();
                //@TODO check this filecachetags and see if they make sense

                // @TODO move the IIIF server baser URL to a global config and an local fieldformatter override.
                $iiifserver = "{$baseiiifserveruri}{$iiifidentifier}/info.json";

                $uniqueid =
                  'iiif-'.$items->getName(
                  ).'-'.$nodeuuid.'-'.$delta.'-panorama'.$i;
                $htmlid = $uniqueid;

                $cache_contexts = ['url.site', 'url.path', 'url.query_args','user.permissions'];
                // @ see https://www.drupal.org/files/issues/2517030-125.patch
                $cache_tags = Cache::mergeTags($filecachetags, $items->getEntity()->getCacheTags());
                // http://localhost:8183/iiif/2/e8c%2Fa-new-label-en-image-05066d9ae32580cffb38342323f145f74faf99a1.jpg/full/220,/0/default.jpg
                $internal_iiifserver = "{$baseiiifserveruri_internal}{$iiifidentifier}/info.json";
                $iiifhelper = new IiifHelper($internal_iiifserver);
                $iiifsizes = $iiifhelper->getImageSizes();

                if (!$iiifsizes) {
                  $message= $this->t('We could not fetch Image sizes from IIIF @url <br> for node @id, defaulting to base formatter configuration.',
                    [
                      '@url' => $iiifserver,
                      '@id' => $nodeid,
                    ]);
                  \Drupal::logger('format_strawberryfield')->warning($message);
                  //continue; // Nothing can be done here?
                }
                else {
                  //@see \template_preprocess_image for further theme_image() attributes.
                  // Look. This one uses the public accesible base URL. That is how world works.
                  if (($max_width == 0) && ($max_height == 0)) {
                    $max_width = $iiifsizes[0]['width'];
                    $max_height = $iiifsizes[0]['height'];
                  }
                  if (($max_width == 0) &&  ($max_height > 0)){
                    $max_width = round($iiifsizes[0]['width']/$iiifsizes[0]['height'] * $max_height,0);

                  }
                  elseif (($max_width > 0) &&  ($max_height == 0)){
                    $max_height = round($iiifsizes[0]['height']/$iiifsizes[0]['width'] * $max_width,0);
                  }
                  // Pannellum recommends max 4096 pixel width images for WebGl. Lets use that as max.
                  $max_width_source = ($iiifsizes[0]['width'] > 4096) ? '4096,' : 'max';


                  $iiifserverimg = "{$baseiiifserveruri}{$iiifidentifier}"."/full/{$max_width_source}/0/default.jpg";
                  $elements[$delta]['panorama' . $i] = [
                    '#type' => 'container',
                    '#attributes' => [
                      'class' => ['field-iiif', 'strawberry-panorama-item'],
                      'id' => $htmlid,
                      'data-iiif-image' => $iiifserverimg,
                      'data-iiif-image-width' => $max_width,
                      'data-iiif-image-height' => $max_height,
                    ],
                    '#title' => $this->t(
                      'Panorama for @label',
                      ['@label' => $items->getEntity()->label()]
                    )
                  ];
                  // Lets add hotspots
                  $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['pannellum'][$htmlid]['nodeuuid'] = $nodeuuid;
                  $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/iiif_pannellum_strawberry';
                  // Hotspots are a list of objects in the form of
                  /*{
                    "yaw": "-14.626185026728738",
                     "text": "Sheryl's team at the Theater",
                    "type": "info",
                    "pitch": "-4.409886580572494"
                   }, */
                  // @TODO enable multiple scenes and more hotspot options

                  if (isset($jsondata[$hotspots])) {
                    $hotspotsjs = [];
                    $i=0;
                    foreach ($jsondata[$hotspots] as $hotspotitems) {
                      $i++;
                      $hotspotdefaults = [
                        'id' => $i,
                        'pitch' => 0,
                        'yaw' => 0,
                        'type' => 'info',
                        'text' => '',
                      ];
                      $hotspotsjs[] = $hotspotitems + $hotspotdefaults;
                    }
                    $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['pannellum'][$htmlid]['hotspots'] = $hotspotsjs;
                  }

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
                $elements[$delta]['panorama'.$i] = [
                  '#markup' => '<i class="fas fa-times-circle"></i>',
                  '#prefix' => '<span>',
                  '#suffix' => '</span>',
                ];
              }
            } elseif (isset($mediaitem['url'])) {
              $elements[$delta]['[panorama'.$i] = [
                '#markup' => 'Non managed '.$mediaitem['url'],
                '#prefix' => '<pre>',
                '#suffix' => '</pre>',
              ];
            }

          }
        }
      }
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
    if (is_subclass_of($access_handler_class, '\Drupal\file\FileAccessFormatterControlHandlerInterface')) {
      return $entity->access('view', NULL, FALSE);
    }
    else {
      return AccessResult::allowed();
    }
  }
}