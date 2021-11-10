<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:56 PM
 */

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\file\FileInterface;
use Drupal\format_strawberryfield\Tools\IiifHelper;
use Drupal\strawberryfield\Tools\Ocfl\OcflHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Url;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;

/**
 * Simplistic Audio Strawberry Field formatter.
 *
 * @FieldFormatter(
 *   id = "strawberry_audio_formatter",
 *   label = @Translation("Strawberry Field Audio Formatter"),
 *   class = "\Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryAudioFormatter",
 *   field_types = {
 *     "strawberryfield_field"
 *   },
 *   quickedit = {
 *     "editor" = "disabled"
 *   }
 * )
 */
class StrawberryAudioFormatter extends StrawberryBaseFormatter {
  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'json_key_source' => 'as:audio',
      'max_width' => 180,
      'max_height' => 50,
      'audio_type' => 'mp3',
      'number_media' => 1,
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
      'number_media' => [
        '#type' => 'number',
        '#title' => $this->t('Number of Audio files'),
        '#default_value' => $this->getSetting('number_media'),
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
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $summary[] = $this->t('Plays Audio from JSON');

    if ($this->getSetting('json_key_source')) {
      $summary[] = $this->t('Media fetched from JSON "%json_key_source" key', [
        '%json_key_source' => $this->getSetting('json_key_source'),
      ]);
    }
    if ($this->getSetting('number_media')) {
      $summary[] = $this->t('Number of Audios: "%number"', [
        '%number' => $this->getSetting('number_media'),
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

    $embargo_upload_keys_string = strlen(trim($this->getSetting('embargo_json_key_source'))) > 0 ? trim($this->getSetting('embargo_json_key_source')) : NULL;
    $embargo_upload_keys_string = explode(',', $embargo_upload_keys_string);
    $embargo_upload_keys_string = array_filter($embargo_upload_keys_string);

    $current_language = $items->getEntity()->get('langcode')->value;
    $nodeid = $items->getEntity()->id();
    $number_media = $this->getSetting('number_media') ?? 0;
    $key = $this->getSetting('json_key_source');

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
      /* Expected structure of an Audio items inside JSON
      @see https://www.w3.org/TR/webvtt1/#introduction-metadata for tracks
      @see http://events.linkeddata.org/ldow2014/papers/ldow2014_paper_11.pdf for LoD
      {"as:audio": {
		    "urn:uuid:someuuid": {
			  "dr:fid": 32, // Drupal's FID
			  "dr:for": "some_audio_files",  // The webform element key that generated this one
			  "url": "s3://f23/new-metadata-en-image-58455d91acf7290275c1cab77531b7f561a11a84.mp3",
			  "name": "My Super Audio",
		  	"type": "Audio",
		  	"duration": "T0M15S", //https://en.wikipedia.org/wiki/ISO_8601
			  "checksum": "f231aed5ae8c2e02ef0c5df6fe38a99b",
			  "tracks": [
			  	{
				  	"subtitleLanguage": "es",
				  	"url": "s3://f11/subtitle-58455d91acf7290275c1cab77531b7f561a11a84.vtt",
            "dr:fid": 33, // Drupal's FID
				  	"type": "subtitles|captions|descriptions|chapters|metadata",
            "dr:for": "some_track_files", // The webform element key that generated this one
            "checksum": "f231aed5ae8c2e02ef0c5df6fe38a99b"
				  }
			  ]
		   }}}
      */

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
          TRUE, $jsondata, 'Audio', $key, $ordersubkey, $number_media,
          $upload_keys, []);
        if (count($media)) {
          $conditions[] = [
            'source' => ['dr:mimetype'],
            'condition' => 'text/vtt',
          ];
          $vtt = $this->fetchMediaFromJsonWithFilter($delta, $items,
            $elements,
            FALSE, $jsondata, 'Text', 'as:text', $ordersubkey, $number_media,
            $upload_keys, $conditions);
          /* This may be a bit more complex, possible situations
            1.- NO vtt, all good
            2.- One Media, multiple vtt, all good
            3.- Multiple media, single vtt (all good?)
            4.- Multiple media, multiple vtt. But there is a single media per upload_key and vtt share the upload key
            5.- Multiple media, multiple vtt, all in different upload keys. We can match by filename prefix?
            */
          if (count($vtt)) {
            // Yep, redundant but we have no longer these settings here
            // and i need to add 30px (uff) to the top.

            if  ($max_height = $this->getSetting('max_height') <= 90) {
              $max_width = $this->getSetting('max_width');
              $max_height = 90;
              $max_width_css = empty($max_width) || $max_width == 0 ? '100%' : $max_width . 'px';
            }

            foreach ($media as $drforkey => $media_item) {
              if (isset($vtt[$drforkey])) {
                foreach ($media_item as $key => $media_entry) {
                  $elements[$delta]['audio_hmtl5_' . $key]['audio']['#attributes']['style'] = "width:{$max_width_css}; height:{$max_height}px";
                  foreach ($vtt[$drforkey] as $vtt_key => &$vtt_item) {
                    $route_parameters = [
                      'node' => $nodeid,
                      'uuid' => $vtt_item['file']->uuid(),
                      'format' => 'default.' . pathinfo($vtt_item['file']->getFilename(),
                          PATHINFO_EXTENSION)
                    ];
                    $publicurl = Url::fromRoute('format_strawberryfield.iiifbinary',
                      $route_parameters);
                    //<track label="English" kind="subtitles" srclang="en" src="captions/vtt/sintel-en.vtt" default>//
                    // tracks need at least 30px more up. Wonder if we should add those here
                    // Or document it as min: 90px height?
                    $elements[$delta]['audio_hmtl5_' . $key]['audio']['track' . $vtt_key] = [
                      '#type' => 'html_tag',
                      '#tag' => 'track',
                      '#attributes' => [
                        'label' => $this->t('Transcript ' . $current_language),
                        'kind' => 'subtitles',
                        'srclang' => $current_language,
                        'src' => $publicurl->toString(),
                        'default' => TRUE
                      ]
                    ];
                  }
                }
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
    $max_width_css = empty($max_width) || $max_width == 0 ? '100%' : $max_width . 'px';
    $max_width = empty($max_width) || $max_width == 0 ? NULL : $max_width;
    $max_height = $this->getSetting('max_height');
    $nodeuuid = $items->getEntity()->uuid();
    $nodeid = $items->getEntity()->id();
    $imagefile = NULL;
    $publicimageurl = NULL;
    $fieldname = $items->getName();

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
      'av-' . $items->getName(
      ) . '-' . $nodeuuid . '-' . $delta . '-audio' . $i;

    $cache_contexts = [
      'url.site',
      'url.path',
      'url.query_args',
      'user.permissions'
    ];

    // We will use HTML5 Video tag because Audio Tag does not allow Tracks with Subtitles
    // @see https://www.iandevlin.com/blog/2015/12/html5/webvtt-and-audio/
    $elements[$delta]['audio_hmtl5_' . $i] = [
      '#type' => 'html_tag',
      '#tag' => 'figure',
      'audio' => [
        '#type' => 'html_tag',
        '#tag' => 'video',
        '#attributes' => [
          'class' => ['field-av', 'audio-av'],
          'id' => 'audio_' . $uniqueid,
          'controls' => TRUE,
          'style' => "width:{$max_width_css}; height:{$max_height}px",
        ],
        '#alt' => $this->t(
          'Audio for @label',
          ['@label' => $items->getEntity()->label()]
        ),
        'source' => [
          '#type' => 'html_tag',
          '#tag' => 'source',
          '#attributes' => [
            'src' => $publicurl->toString(),
            'type' => $file->getMimeType(),
          ]
        ],
      ],
      '#cache' => [
        'context' => $file->getCacheContexts(),
        'tags' => $file->getCacheTags(),
      ]
    ];

    $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/av_strawberry';
  }

}