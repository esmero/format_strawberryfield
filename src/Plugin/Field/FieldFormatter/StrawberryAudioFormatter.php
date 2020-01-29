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
    $number_media =  $this->getSetting('number_media');
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
      $i = 0;
      if (isset($jsondata[$key])) {
        // Order Audio based on a given 'sequence' key
        $ordersubkey = 'sequence';
        StrawberryfieldJsonHelper::orderSequence($jsondata, $key, $ordersubkey);
        foreach ($jsondata[$key] as $mediaitem) {
          $i++;
          if ($i > (int) $number_media) {
            break;
          }
          if (isset($mediaitem['type']) && $mediaitem['type'] == 'Audio') {
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
                  'av-' . $items->getName(
                  ) . '-' . $nodeuuid . '-' . $delta . '-audio' . $i;

                $cache_contexts = [
                  'url.site',
                  'url.path',
                  'url.query_args',
                  'user.permissions'
                ];
                // @ see https://www.drupal.org/files/issues/2517030-125.patch
                $cache_tags = Cache::mergeTags(
                  $filecachetags,
                  $items->getEntity()->getCacheTags()
                );
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
                      'controls' => TRUE
                    ],
                    '#alt' => $this->t(
                      'Audio for @label',
                      ['@label' => $items->getEntity()->label()]
                    ),
                    '#width' => $max_width,
                    '#height' => $max_height,
                    'source' => [
                      '#type' => 'html_tag',
                      '#tag' => 'source',
                      '#attributes' => [
                        'src' => $publicurl->toString(),
                        'type' => $file->getMimeType(),
                      ]
                    ]
                  ]
                  //@TODO add tracks from structure,
                  // \Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryAudioFormatter::processTracksElement
                ];
                if (isset($item->_attributes)) {
                  $elements[$delta] += ['#attributes' => []];
                  $elements[$delta]['#attributes'] += $item->_attributes;
                  // Unset field item attributes since they have been included in the
                  // formatter output and should not be rendered in the field template.
                  unset($item->_attributes);
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
      $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/av_strawberry';
    }
    return $elements;
  }

  protected function processTracksElement(array $media_elements = [], array $mediaitem) {
    if (isset($mediaitem['tracks']) && is_array($mediaitem['tracks'])) {
      foreach ($mediaitem['tracks'] as $trackid => $track) {
        $vtt_url = NULL;
        if (isset($track['url'])) {
          if (UrlHelper::isExternal($track['url'])) {
            $vtt_url = $track['url'];
          }
          elseif (isset($track['dr:fid'])) {
            $file = OcflHelper::resolvetoFIDtoURI(
              $track['dr:fid']
            );
            if (!$file) {
              continue;
            }
            if ($this->checkAccess($file)) {
              $vtt_url = $file->getFileUri();
            }
          }
        }

        if ($vtt_url) {
          $media_element["track_{$trackid}"] = [
            '#type' => 'html_tag',
            '#tag' => 'track',
            '#attributes' => [
              'src' => $vtt_url,
              'type' => isset($track['type']) ? $track['type'] : 'subtitles' ,
              'label' => isset($track['label']) ? $track['label'] : $this->t('Subtitle Track'),
              'srclang' =>  isset($track['subtitleLanguage']) ? $track['subtitleLanguage']  : 'en'
            ]
          ];
          if ($trackid == 1) {
            $media_element["track_{$trackid}"]['#attributes']['default'] = NULL;
          }
        }
        else {
          //@TODO if no media key to file loading was possible
          // means we have a broken/missing media reference
          // we should inform to logs and continue
          //@TODO add a common, base method to deal with this
        }
      }
    }
    return $media_element;
  }
}