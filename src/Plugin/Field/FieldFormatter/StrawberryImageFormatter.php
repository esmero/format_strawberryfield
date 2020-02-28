<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:56 PM
 */

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\strawberryfield\Tools\Ocfl\OcflHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Cache\Cache;
use Drupal\format_strawberryfield\Tools\IiifHelper;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;

/**
 * Simplistic Strawberry Field formatter.
 *
 * @FieldFormatter(
 *   id = "strawberry_image_formatter",
 *   label = @Translation("Strawberry Field Image Formatter for IIIF media"),
 *   class = "\Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryImageFormatter",
 *   field_types = {
 *     "strawberryfield_field"
 *   },
 *   quickedit = {
 *     "editor" = "disabled"
 *   }
 * )
 */
class StrawberryImageFormatter extends StrawberryBaseFormatter {
  
  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return
      parent::defaultSettings() + [
      'json_key_source' => 'as:image',
      'max_width' => 180,
      'max_height' => 0,
      'image_type' => 'jpg',
      'number_images' => 1,
      'quality' => 'default',
      'rotation' => '0',
      'image_link' =>  TRUE,
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
        '#default_value' => $this->getSetting('json_key_source'),
        '#required' => TRUE
      ],
      'number_images' => [
        '#type' => 'number',
        '#title' => $this->t('Number of images'),
        '#default_value' => $this->getSetting('number_images'),
        '#size' => 2,
        '#maxlength' => 2,
        '#min' => 0,
      ],
      'image_link' => [
        '#type' => 'checkbox',
        '#title' => t('Link this image to the Full Node'),
        '#default_value' => $this->getSetting('image_link'),
      ],
      'max_width' => [
        '#type' => 'number',
        '#title' => $this->t('Maximum width'),
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
    $summary[] = $this->t(
      'Maximum size: %max_width x %max_height',
      [
        '%max_width' => (int) $this->getSetting('max_width') == 0 ? '100%' : $this->getSetting('max_width') . ' pixels',
        '%max_height' => $this->getSetting('max_height') . ' pixels',
      ]
    );
    $summary[] = $this->t('Link to Node? %value', [
      '%value' => boolval($this->getSetting('image_link')) === TRUE ? "Yes." : "No",
    ]);

    return $summary;
  }


  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $max_width = $this->getSetting('max_width');
    $max_width_css = empty($max_width) || $max_width == 0 ? '100%' : $max_width .'px';
    $max_height = $this->getSetting('max_height');
    $number_images =  $this->getSetting('number_images');
    /* @var \Drupal\file\FileInterface[] $files */
    // Fixing the key to extract while coding to 'Media'
    $key = $this->getSetting('json_key_source');

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
         "dr:fid": 32, // Drupal's FID
         "dr:for": "add_some_master_images", // The webform element key that generated this one
         "url": "s3:\/\/f23\/new-metadata-en-image-58455d91acf7290275c1cab77531b7f561a11a84.jpg",
         "name": "new-metadata-en-image-a8d0090cbd2cd3ca2ab16e3699577538f3049941.jpg",
         "type": "Image",
         "checksum": "f231aed5ae8c2e02ef0c5df6fe38a99b"
         }
      }*/
      $i = 0;
      if (isset($jsondata[$key])) {
        // Order Images based on a given 'sequence' key
        $ordersubkey = 'sequence';
        StrawberryfieldJsonHelper::orderSequence($jsondata, $key, $ordersubkey);
        $iiifhelper = new IiifHelper($this->getIiifUrls()['public'], $this->getIiifUrls()['internal']);
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


                $uniqueid =
                  'iiif-'.$items->getName(
                  ).'-'.$nodeuuid.'-'.$delta.'-image'.$i;

                $cache_contexts = ['url.site', 'url.path', 'url.query_args','user.permissions'];
                // @ see https://www.drupal.org/files/issues/2517030-125.patch
                $cache_tags = Cache::mergeTags($filecachetags, $items->getEntity()->getCacheTags());
                // http://localhost:8183/iiif/2/e8c%2Fa-new-label-en-image-05066d9ae32580cffb38342323f145f74faf99a1.jpg/full/220,/0/default.jpg

                $iiifpublicinfojson = $iiifhelper->getPublicInfoJson($iiifidentifier);
                $iiifsizes = $iiifhelper->getImageSizes($iiifidentifier);

                if (!$iiifsizes) {
                  $message= $this->t('We could not fetch Image sizes from IIIF @url <br> for node @id, defaulting to base formatter configuration.',
                    [
                      '@url' => $iiifpublicinfojson,
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

                  $iiifserverthumb = "{$this->getIiifUrls()['public']}/{$iiifidentifier}"."/full/{$max_width},/0/default.jpg";
                  $elements[$delta]['media_thumb' . $i] = [
                    '#theme' => 'image',
                    '#attributes' => [
                      'class' => ['field-iiif', 'image-iiif'],
                      'id' => 'thumb_' . $uniqueid,
                      'src' => $iiifserverthumb,

                    ],
                    '#alt' => $this->t(
                      'Thumbnail for @label',
                      ['@label' => $items->getEntity()->label()]
                    ),
                    '#width' => $max_width,
                    '#height' => $max_height,
                  ];

                  if (boolval($this->getSetting('image_link')) === TRUE) {
                    $elements[$delta]['media_thumb' . $i]['#url'] = $items->getEntity()->toUrl();
                  }

                  if (isset($item->_attributes)) {
                    $elements[$delta] += ['#attributes' => []];
                    $elements[$delta]['#attributes'] += $item->_attributes;
                    // Unset field item attributes since they have been included in the
                    // formatter output and should not be rendered in the field template.
                    unset($item->_attributes);
                  }
                  // @TODO deal with a lot of Media single strawberryfield
                  // Idea would be to allow a setting that says, A) all same viewer(aggregate)
                  // B) individual viewers for each?
                  // C) only first one?
                  // We will assign a group based on the UUID of the node containing this
                  // to idenfity all the divs that we will create. And only first one will be the container in case of many?
                  // so a jquery selector that uses that group as filter for a search.
                  // Drupal JS settings get accumulated. So in a single search results site we will have for each
                  // Formatter one passed. Reason we use 'innode' array using our $uniqueid
                  // @TODO probably better to use uuid() or the node id() instead of $uniqueid
                  $elements[$delta]['media'.$i]['#attributes']['data-iiif-infojson'] = $iiifpublicinfojson;
                  $elements[$delta]['media'.$i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon']['innode'][$uniqueid] = $nodeuuid;
                }

              }
              else {
                // @TODO Deal with no access here
                // Should we put a thumb? Just hide?
                // @TODO we can bring a plugin here and there that deals with
                $elements[$delta]['media'.$i] = [
                  '#markup' => '<i class="fas fa-times-circle"></i>',
                  '#prefix' => '<span>',
                  '#suffix' => '</span>',
                ];
              }
            } elseif (isset($mediaitem['url'])) {
              $elements[$delta]['media'.$i] = [
                '#markup' => 'Non managed '.$mediaitem['url'],
                '#prefix' => '<pre>',
                '#suffix' => '</pre>',
              ];
            }

          }
        }
      }
      // Get rid of empty #attributes key to avoid render error
      if (isset( $elements[$delta]["#attributes"]) && empty( $elements[$delta]["#attributes"])) {
        unset($elements[$delta]["#attributes"]);
      }
    }
    return $elements;
  }
}
