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
class StrawberryAudioFormatter extends StrawberryDirectJsonFormatter {
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
      'use_wavesurfer' => false,
      'use_external_control' => false,
      'external_control_selector' => '.sbf_media_control',
      'viewer_overrides' => '{
        "height": 128,
        "width": 300,
        "splitChannels": false,
        "normalize": false,
        "waveColor": "#ed719e",
        "progressColor": "#dd5e98",
        "cursorColor": "#ddd5e9",
        "cursorWidth": 2,
        "barWidth": null,
        "barGap": null,
        "barRadius": null,
        "barHeight": null,
        "barAlign": "",
        "minPxPerSec": 1,
        "fillParent": true,
        "autoplay": false,
        "interact": true,
        "dragToSeek": false,
        "hideScrollbar": false,
        "audioRate": 1,
        "autoScroll": true,
        "autoCenter": true,
        "sampleRate": 8000
      }',
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
        'use_wavesurfer' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Use The WaverSurfer JS library'),
          '#description' => $this->t('Attaches https://wavesurfer.xyz to the Audio element.'),
          '#default_value' => $this->getSetting('use_wavesurfer'),
          '#required' => FALSE
        ],
        'viewer_overrides' => [
          '#type' => 'textarea',
          '#title' => $this->t('Advanced: a JSON with Wave Surfer Options.'),
          '#description' => $this->t('See <a href="https://wavesurfer.xyz/examples/?all-options.js">https://wavesurfer.xyz/examples/?all-options.js</a>. Leave Empty to use defaults.
   <em>media</em>, <em>#url</em> and <em>container</em>, can not be set and will be deleted if provided. Use with caution. An ADO can also override this formatters OSD settings by providing the following JSON key: @ado_override',[
            '@ado_override' => json_encode(["ap:viewerhints" => ["strawberry_audio_formatter"=> ["waveColor" => "#ff4e00"]]], JSON_FORCE_OBJECT|JSON_PRETTY_PRINT)
          ]),
          '#default_value' => $this->getSetting('viewer_overrides'),
          '#element_validate' => [[$this, 'validateJSON']],
          '#required' => FALSE,
        ],
        'use_external_control' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Use external (user provided) HTML Controls for the Audio Element.'),
          '#description' => $this->t('Please see Example for required CSS classes and element types.'),
          '#default_value' => $this->getSetting('use_external_control'),
          '#required' => FALSE
        ],
        'external_control_selector' => [
          '#type' => 'textfield',
          '#title' => $this->t('The Dom Query selector to be used to find the external HTML Control container in the Web Page.'),
          '#description' => $this->t('A valid DOM Query Selector. If the selector yields no DOM elements, the default browser based controls will be used.'),
          '#default_value' => $this->getSetting('external_control_selector'),
          '#required' => FALSE
        ],
      ] + parent::settingsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
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

    $upload_keys_string = strlen(trim($this->getSetting('upload_json_key_source') ?? '')) > 0 ? trim($this->getSetting('upload_json_key_source')) : '';
    $upload_keys = explode(',', $upload_keys_string);
    $upload_keys = array_filter($upload_keys);
    $hide_on_embargo =  $this->getSetting('hide_on_embargo') ?? FALSE;
    $use_wavesurfer = $this->getSetting('use_wavesurfer');
    $embargo_context = [];
    $embargo_tags = [];

    $embargo_upload_keys_string = strlen(trim($this->getSetting('embargo_json_key_source') ?? '')) > 0 ? trim($this->getSetting('embargo_json_key_source')) : '';
    $embargo_upload_keys_string = explode(',', $embargo_upload_keys_string);
    $embargo_upload_keys_string = array_filter($embargo_upload_keys_string);

    $max_width = $this->getSetting('max_width');
    $max_width_css = empty($max_width) || $max_width == 0 ? '100%' : $max_width .'px';
    $max_height = $this->getSetting('max_height');
    $max_height_css = empty($max_height) || $max_height == 0 ? 'auto' : $max_height .'px';
    // Basically min 90px height if using VTT
    $max_height_vtt_css = empty($max_height) || $max_height == 0 ? 'auto' : ($max_height <= 90 ? 90 : $max_height) .'px';

    $viewer_overrides = $this->getSetting('viewer_overrides');
    $viewer_overrides_json = json_decode(trim($viewer_overrides), TRUE);

    $json_error = json_last_error();
    if ($json_error == JSON_ERROR_NONE) {
      $viewer_overrides = $viewer_overrides_json;
    }
    else {
      $viewer_overrides = NULL;
    }


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
      if (isset($jsondata["ap:viewerhints"][$this->getPluginId()]) &&
        is_array($jsondata["ap:viewerhints"][$this->getPluginId()]) &&
        !empty($jsondata["ap:viewerhints"][$this->getPluginId()])) {
        // if we could decode it, it is already JSON.
        $viewer_overrides = $jsondata["ap:viewerhints"][$this->getPluginId()];
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
        $media = $this->fetchMediaFromJsonWithFilter($delta, $items, $elements,
          TRUE, $jsondata, 'Audio', $key, $ordersubkey, $number_media,
          $upload_keys, []);
        if (count($media)) {
          $conditions[] = [
            'source' => ['dr:mimetype'],
            'condition' => 'text/vtt',
          ];
          $vtt = $this->fetchMediaFromJsonWithFilter(
            $delta, $items,
            $elements,
            FALSE, $jsondata, 'Text', 'as:text', $ordersubkey, $number_media,
            $upload_keys, $conditions
          );
          /* This may be a bit more complex, possible situations we cover
            1.- NO vtt, all good
            2.- One Media, multiple vtt, all good
            3.- Multiple media, multiple vtt. need to grouped by sourcekey
            */
          if (count($vtt)) {
            // Yep, redundant but we have no longer these settings here
            // If $media is a single one, we will assume all VTTS belong to it, bypassing the dr:for grouping
            foreach ($media as $drforkey => $media_item) {
              if (isset($vtt[$drforkey]) || count($media) == 1) {
                foreach ($media_item as $key => $media_entry) {
                  $elements[$delta]['audio_hmtl5_'
                  . $key]['audio']['#attributes']['style']
                    = "width:{$max_width_css}; height:{$max_height_vtt_css}";
                  foreach ($vtt as $vtt_drforkey => $vtt_entries) {
                    if (count($media) == 1 || $drforkey == $vtt_drforkey) {
                      foreach ($vtt_entries as $vtt_key => &$vtt_item) {
                        $route_parameters = [
                          'node' => $nodeid,
                          'uuid' => $vtt_item['file']->uuid(),
                          'format' => 'default.' . pathinfo(
                              $vtt_item['file']->getFilename(),
                              PATHINFO_EXTENSION
                            )
                        ];
                        $publicurl = Url::fromRoute(
                          'format_strawberryfield.iiifbinary',
                          $route_parameters
                        );
                        //<track label="English" kind="subtitles" srclang="en" src="captions/vtt/sintel-en.vtt" default>//
                        // tracks need at least 30px more up. Wonder if we should add those here
                        // Or document it as min: 90px height?
                        $elements[$delta]['audio_hmtl5_'
                        . $key]['audio']['track'
                        . $vtt_key]
                          = [
                          '#type' => 'html_tag',
                          '#tag' => 'track',
                          '#attributes' => [
                            'label' => $this->t(
                              'Transcript ' . $current_language . " ({$vtt_item['file_name']})"
                            ),
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
          }
          if ($use_wavesurfer && $viewer_overrides && isset($elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['audiovideo'])) {
            foreach ($elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['audiovideo'] as $htmlid => &$properties) {
              $properties['viewer_overrides'] = $viewer_overrides;
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
    $max_width = empty($max_width) || $max_width == 0 ? NULL : $max_width;
    $max_height = $this->getSetting('max_height');
    $nodeuuid = $items->getEntity()->uuid();
    $nodeid = $items->getEntity()->id();
    $use_external_control = $this->getSetting('use_external_control');
    $use_wavesurfer = $this->getSetting('use_wavesurfer');
    $external_control_selector = $this->getSetting('external_control_selector');



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
    $htmlid = 'audio_' . $uniqueid;
    $elements[$delta]['audio_hmtl5_' . $i] = [
      '#type' => 'html_tag',
      '#tag' => 'figure',
      'audio' => [
        '#type' => 'html_tag',
        '#tag' => 'video',
        '#attributes' => [
          'class' => ['field-av', 'audio-av', 'strawberry-av-item', 'strawberry-audio-item'],
          'id' => $htmlid,
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
    // We need to add a container for the waver surfer plugin.
    if ($use_wavesurfer) {
      $elements[$delta]['audio_hmtl5_' . $i]['wavesurfer'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'class' => ['strawberry-av-item-waversurfer'],
        ]
      ];
    }

    $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['audiovideo'][$htmlid]['use_external_control'] = (bool) $use_external_control;
    $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['audiovideo'][$htmlid]['external_control_selector'] = $external_control_selector;
    $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['audiovideo'][$htmlid]['use_wavesurfer'] = $use_wavesurfer;
    $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/av_strawberry';
    $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/av_custom_control_strawberry';
  }

}
