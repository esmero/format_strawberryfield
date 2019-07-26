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
use Drupal\strawberryfield\Tools\Ocfl\OcflHelper;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Cache\Cache;
use Drupal\format_strawberryfield\Tools\IiifHelper;
use Drupal\Core\Url;

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
class Strawberry3DFormatter extends FormatterBase {
  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'iiif_base_url' => 'http://localhost:8183/iiif/2/',
      'iiif_base_url_internal' => 'http://esmero-cantaloupe:8182/iiif/2/',
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
    $summary[] = $this->t('Displays 3 Models from JSON using the JSM Modeller Library');
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
    if ($this->getSetting('number_models')) {
      $summary[] = $this->t('Number of 3D Models: "%number"', [
        '%number' => $this->getSetting('number_models'),
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
    $number_models =  $this->getSetting('number_models');
    /* @var \Drupal\file\FileInterface[] $files */
    // Fixing the key to extract while coding to 'Media'
    $key = $this->getSetting('json_key_source');
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
                $audiourl = $file->getFileUri();
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
                      'data-iiif-image-width' => $max_width,
                      'data-iiif-image-height' => $max_height,
                      'height' => $max_height,
                      'width' => $max_width
                     ],
                    '#title' => $this->t(
                      '3D Model for @label',
                      ['@label' => $items->getEntity()->label()]
                    )
                  ];
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