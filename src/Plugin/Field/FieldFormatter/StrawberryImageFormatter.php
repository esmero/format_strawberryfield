<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:56 PM
 */

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Cache\Cache;
use Drupal\file\FileInterface;
use Drupal\format_strawberryfield\Tools\IiifHelper;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Drupal\Core\StreamWrapper\StreamWrapperManager;

/**
 * Simplistic Strawberry Field formatter.
 *
 * @FieldFormatter(
 *   id = "strawberry_image_formatter",
 *   label = @Translation("Strawberry Field Simple Image Formatter using IIIF"),
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
        'webannotations' => FALSE,
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
          '#required' => TRUE,
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
        ]
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
    $upload_keys_string = strlen(trim($this->getSetting('upload_json_key_source') ?? '')) > 0 ? trim($this->getSetting('upload_json_key_source')) : '';
    $upload_keys = explode(',', $upload_keys_string);
    $upload_keys = array_filter($upload_keys);
    $embargo_upload_keys_string = strlen(trim($this->getSetting('embargo_json_key_source') ?? '')) > 0 ? trim($this->getSetting('embargo_json_key_source')) : '';
    $embargo_upload_keys_string = explode(',', $embargo_upload_keys_string);
    $embargo_upload_keys = array_filter($embargo_upload_keys_string);
    $hide_on_embargo =  $this->getSetting('hide_on_embargo') ?? FALSE;
    $number_images =  $this->getSetting('number_images');
    /* @var \Drupal\file\FileInterface[] $files */
    // Fixing the key to extract while coding to 'Media'
    $key = $this->getSetting('json_key_source');
    $embargo_context = [];
    $embargo_tags = [];
    $embargoed = FALSE;

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
        return $elements[$delta] = ['#markup' => $this->t('ERROR')];
      }

      $embargo_info = $this->embargoResolver->embargoInfo($items->getEntity(), $jsondata);
      // This one is for the Twig template
      // We do not need the IP here. No use of showing the IP at all?
      $context_embargo = ['data_embargo' => ['embargoed' => false, 'until' => NULL]];

      if (is_array($embargo_info)) {
        $embargoed = $embargo_info[0];
        $context_embargo['data_embargo']['embargoed'] = $embargoed;

        $embargo_tags[] = 'format_strawberryfield:all_embargo';
        if ($embargo_info[1]) {
          $embargo_tags[] = 'format_strawberryfield:embargo:' . $embargo_info[1];
          $context_embargo['data_embargo']['until'] = $embargo_info[1];
        }
        if ($embargo_info[2] || ($embargo_info[3] == FALSE)) {
          $embargo_context[] = 'ip';
        }
      } else {
        $context_embargo['data_embargo']['embargoed'] = $embargo_info;
      }
      /* Expected structure of a Media item inside JSON
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
      if ($embargoed) {
        $upload_keys = $embargo_upload_keys;
      }

      if (!$embargoed || (!empty($embargo_upload_keys_string) && !$hide_on_embargo) || ($embargoed && !$hide_on_embargo)) {
        $ordersubkey = 'sequence';
        $media = $this->fetchMediaFromJsonWithFilter(
          $delta, $items, $elements,
          TRUE, $jsondata, 'Image', $key, $ordersubkey, $number_images, $upload_keys
        );
      }
      // Get rid of empty #attributes key to avoid render error
      if (isset( $elements[$delta]["#attributes"]) && empty( $elements[$delta]["#attributes"])) {
        unset($elements[$delta]["#attributes"]);
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

  protected function generateElementForItem(int $delta, FieldItemListInterface $items, FileInterface $file, IiifHelper $iiifhelper, int $i, array &$elements, array $jsondata, array $mediaitem) {

    $max_width = $this->getSetting('max_width');
    $max_width = empty($max_width) || $max_width == 0 ? NULL : $max_width;
    $max_height = $this->getSetting('max_height');
    $nodeuuid = $items->getEntity()->uuid();
    $nodeid = $items->getEntity()->id();
    $uniqueid = 'iiif-'.$items->getName().'-'.$nodeuuid.'-'.$delta.'-image-'.$i;

    $iiifidentifier = urlencode(
      StreamWrapperManager::getTarget($file->getFileUri())
    );

    if ($iiifidentifier == NULL || empty($iiifidentifier)) {
      return;
    }

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
      $image_render_array = [
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

      // With Link
      if (boolval($this->getSetting('image_link')) === TRUE && !$items->getEntity()->isNew()) {
        $elements[$delta]['media_thumb' . $i] = [
          '#type' => 'link',
          '#title' => $image_render_array,
          '#url' => $items->getEntity()->toUrl(),
          '#attributes' => [
            'alt' => $items->getEntity()->label()
          ]
        ];
      }
      else {
        $elements[$delta]['media_thumb' . $i] = $image_render_array;
      }
    }
  }
}
