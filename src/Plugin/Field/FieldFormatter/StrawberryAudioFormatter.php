<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:56 PM
 */

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\Bytes;
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
      'wavesurfer_filesize_limit' => '1 GB',
      'audiowaveform_json_key_source' => NULL,
      'use_external_control' => false,
      'hide_native_control' => false,
      'use_external_control_shared' => false,
      'external_control_element_active_class' => '',
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
          '#description' => $this->t('Attaches https://wavesurfer.xyz to the Audio element. Note: WaveSurfer Currently has issues (memory) loading and rendering large Files.'),
          '#default_value' => $this->getSetting('use_wavesurfer'),
          '#required' => FALSE,
          '#attributes' => [
            'data-checkbox-selector' => 'use_wavesurfer',
          ],
        ],
        'wavesurfer_filesize_limit' => [
          '#type' => 'textfield',
          '#title' => $this->t('Disable WaverSurfer for files larger than this size'),
          '#description' => $this->t('You can use a valid FileSize String in bytes or using human readable representation like 1 GB, 250 MB, etc. If unset the value will be 1 GB'),
          '#default_value' => $this->getSetting('wavesurfer_filesize_limit'),
          '#required' => FALSE,
          '#element_validate' => [[$this, 'validateByteString']],
          '#states' => [
            'visible' => [
              ':checkbox[data-checkbox-selector="use_wavesurfer"]' => ['checked' => TRUE],
            ],
            'required' => [
              ':checkbox[data-checkbox-selector="use_wavesurfer"]' => ['checked' => TRUE],
            ],
          ]
        ],
        'audiowaveform_json_key_source' => [
          '#type' => 'textfield',
          '#title' => t('JSON Key (upload key) from where to attempt to fetch a preprocessed AudioWaveform (JSON file) attached to this ADO.'),
          '#description' => t('Instead of real time processing of audio waveforms, a JSON file attached to this ADO and produced by the audiowaveform binary can be used. Ideally this would be generated via a Strawberry Runners Processor'),
          '#default_value' => $this->getSetting('audiowaveform_json_key_source'),
          '#required' => FALSE,
          '#states' => [
            'visible' => [
              ':checkbox[data-checkbox-selector="use_wavesurfer"]' => ['checked' => TRUE],
            ],
          ],
        ],
        'viewer_overrides' => [
          '#type' => 'textarea',
          '#title' => $this->t('Advanced: a JSON with Wave Surfer Options.'),
          '#description' => $this->t('See <a href="https://wavesurfer.xyz/examples/?all-options.js">https://wavesurfer.xyz/examples/?all-options.js</a>. Leave Empty to use defaults.
   <em>media</em>, <em>#url</em> and <em>container</em>, can not be set and will be deleted if provided. Use with caution. An ADO can also override this formatters OSD settings by providing (partial example) the following JSON key: @ado_override',[
            '@ado_override' => json_encode(["ap:viewerhints" => ["strawberry_audio_formatter"=> ["waveColor" => "#ff4e00"]]], JSON_FORCE_OBJECT|JSON_PRETTY_PRINT)
          ]),
          '#default_value' => $this->getSetting('viewer_overrides'),
          '#element_validate' => [[$this, 'validateJSON']],
          '#required' => FALSE,
          '#states' => [
            'visible' => [
              ':checkbox[data-checkbox-selector="use_wavesurfer"]' => ['checked' => TRUE],
            ],
          ],
        ],
        'use_external_control' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Use external (user provided) HTML Controls for the Audio Element.'),
          '#description' => $this->t('Please see Example for required CSS classes and element types.'),
          '#default_value' => $this->getSetting('use_external_control'),
          '#required' => FALSE,
          '#attributes' => [
            'data-checkbox-selector' => 'use_external_control',
          ],
        ],
        'external_control_selector' => [
          '#type' => 'textfield',
          '#title' => $this->t('The Dom Query selector to be used to find the external HTML Control container <em>blueprint</em> in the Web Page.'),
          '#description' => $this->t('A valid DOM Query Selector. If the selector yields no DOM elements, the default browser based controls will be used. If found, it will be cloned and repositioned.'),
          '#default_value' => $this->getSetting('external_control_selector'),
          '#required' => FALSE,
          '#states' => [
            'visible' => [
              ':checkbox[data-checkbox-selector="use_external_control"]' => ['checked' => TRUE],
            ],
            'required' => [
              ':checkbox[data-checkbox-selector="use_external_control"]' => ['checked' => TRUE],
            ],
          ],
        ],
        'external_control_element_active_class' => [
          '#type' => 'textfield',
          '#title' => $this->t('CSS Class(es) without a leading "." (dot) to be used to mark external control elements as "active".'),
          '#description' =>  $this->t('Separate by a space. Provide these to aid in making some of the external HTML controls more interactive. These classes will be used by the supporting JS to highlight the following selected elements, Subtitle Track, Media Track, and CC'),
          '#default_value' => $this->getSetting('external_control_element_active_class'),
          '#required' => FALSE,
          '#states' => [
            'visible' => [
              ':checkbox[data-checkbox-selector="use_external_control"]' => ['checked' => TRUE],
            ],
          ],
        ],
        'use_external_control_shared' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Allow multiple media to share the same External Controller.'),
          '#description' => $this->t('When checked, if another Initialized External Controller that matches the same Dom Query selector as setup in this formatter and has the required next/prev element classes set (needed to allow moving between multiple media), instead of creating a separate controller, the existing one will be able to selectively control this formatter\'s media too. This is only effective if that Existing Controller was also created from the exactly same <em>blueprint</em>'),
          '#default_value' => $this->getSetting('use_external_control_shared'),
          '#required' => FALSE,
          '#states' => [
            'visible' => [
              ':checkbox[data-checkbox-selector="use_external_control"]' => ['checked' => TRUE],
            ],
          ]
        ],
        'hide_native_control' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Hide Browser\'s (Native HTML5) Provided Control'),
          '#description' => $this->t('Only enable this if you provide external controls that cover all accessibility needs or you are using WaverSurfer and effectively want to block any UI interaction.'),
          '#default_value' => $this->getSetting('hide_native_control'),
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
    if ($this->getSetting('use_external_control')) {
      $summary[] = $this->t('Using External HTML controls');
      if ($this->getSetting('external_control_selector')) {
        $summary[] = $this->t('External HTML controls matching <em>@selector</em> DOM Selector',
          [
            '@selector' => $this->getSetting('external_control_selector')
          ]);
      }
    }

    if ($this->getSetting('use_wavesurfer')) {
      $summary[] = $this->t('Using The Wave Surfer Library');
      $summary[] = $this->t('Wave Surfer will be disabled for Files larger than @value', [
        '@value' => $this->getSetting('wavesurfer_filesize_limit')
      ]);
      if ($this->getSetting('audiowaveform_json_key_source')) {
        $summary[] = $this->t('Wave Surfer will try to load, if present, an attached/pre-processed audio waveform file in JSON format uploaded to the @value JSON key', [
          '@value' => $this->getSetting('audiowaveform_json_key_source')
        ]);
      }
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
    $nodeuuid = $items->getEntity()->uuid();
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
                      $i = 0;
                      foreach ($vtt_entries as $vtt_key => &$vtt_item) {
                        $route_parameters = [
                          'node' => $nodeuuid,
                          'uuid' => $vtt_item['file']->uuid(),
                          'format' => 'default.' . pathinfo(
                              $vtt_item['file']->getFilename(),
                              PATHINFO_EXTENSION
                            )
                        ];
                        $publicurl = Url::fromRoute(
                          'format_strawberryfield.binary',
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
                            'default' => $i == 0 ? TRUE : FALSE
                          ]
                        ];
                        $i++;
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
    $max_height = $this->getSetting('max_height');
    $nodeuuid = $items->getEntity()->uuid();
    $use_external_control = $this->getSetting('use_external_control');
    $use_external_control_shared = $this->getSetting('use_external_control_shared');
    $hide_native_control = $this->getSetting('hide_native_control');
    // The 1.6.0 reposition feature via JS
    $use_wavesurfer = $this->getSetting('use_wavesurfer');
    $json_audio_webform_url = NULL;
    if ($use_wavesurfer) {
      // @TODO challenge. This works for one audio/one JSON encoded waveform. What if the ADO holds multiple audios?
      // Same as with VTT, how do we connected -> relate one to another?
      $waveform_upload_keys_string = strlen(trim($this->getSetting('audiowaveform_json_key_source') ?? '')) > 0 ? trim($this->getSetting('audiowaveform_json_key_source')) : '';
      if ($waveform_upload_keys_string !== '') {
        $json_audio_waveform = $this->fetchMediaFromJsonWithFilter(
          $delta, $items,
          $elements,
          FALSE, $jsondata, 'Document', 'as:document', 'sequence', 1,
          [$waveform_upload_keys_string],  ['source' => ['dr:mimetype'],'condition' => 'application/json']);
        if (isset($json_audio_waveform[$waveform_upload_keys_string]) && count($json_audio_waveform[$waveform_upload_keys_string]) ) {
          $route_parameters = [
            'node' => $nodeuuid,
            'uuid' => $json_audio_waveform[$waveform_upload_keys_string][0]['file']->uuid(),
            'format' =>$json_audio_waveform[$waveform_upload_keys_string][0]['file_name'],
          ];
          $json_audio_webform_url = Url::fromRoute('format_strawberryfield.binary',
            $route_parameters)->toString();
        }
      }
    }
    $external_control_selector = trim($this->getSetting('external_control_selector') ?? '');
    $external_control_element_active_class = trim($this->getSetting('external_control_element_active_class') ?? '');
    $media_label = $file->label();
    if (isset($mediaitem['flv:exif']['Title'])) {
      $media_label = $mediaitem['flv:exif']['Title'];
    }
    elseif (isset($mediaitem['flv:mediainfo']['general']['title'])) {
      $media_label = $mediaitem['flv:mediainfo']['general']['title'];
    }

    // We assume here file could not be accessible publicly
    $route_parameters = [
      'node' => $nodeuuid,
      'uuid' => $file->uuid(),
      'format' => 'default.' . pathinfo($file->getFilename(),
          PATHINFO_EXTENSION)
    ];
    $publicurl = Url::fromRoute('format_strawberryfield.binary',
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
      'caption' => [
        '#type' => 'html_tag',
        '#tag' => 'figurecaption',
        '#value' => $this->t(
          'Audio for @label',
          ['@label' => $items->getEntity()->label()]),
        '#attributes' => [
          'class' => ['strawberry-av-item-caption','visually-hidden'],
        ]
      ],
      'audio' => [
        '#type' => 'html_tag',
        '#tag' => 'video',
        '#attributes' => [
          'class' => ['field-av', 'audio-av', 'strawberry-av-item', 'strawberry-audio-item'],
          'id' => $htmlid,
          'controls' => TRUE,
          'style' => "width:{$max_width_css}; height:{$max_height}px",
          'aria-label' => $media_label,
        ],
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
    // Pre hide if we enabled external control and provided a selector
    if ($use_external_control && strlen($external_control_selector) > 0) {
      $elements[$delta]['audio_hmtl5_' . $i]['audio']['#attributes']['hidden'] = TRUE;
      // JS will undo this IF the selector/external control does not match the requirements.
    }

    // We need to add a container for the waver surfer plugin.
    if ($use_wavesurfer) {
      // Disable based on file size
      $wavesurfer_filesize_limit = $this->getSetting('wavesurfer_filesize_limit') ?? '1 GB';
      $wavesurfer_filesize_limit = Bytes::validate($wavesurfer_filesize_limit) ? $wavesurfer_filesize_limit : '1 GB';
      $wavesurfer_filesize_limit = Bytes::toNumber($wavesurfer_filesize_limit);
      if ($file->getSize() >= $wavesurfer_filesize_limit && $json_audio_webform_url === NULL) {
        $use_wavesurfer = FALSE;
      }


      $elements[$delta]['audio_hmtl5_' . $i]['wavesurfer'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'class' => ['strawberry-av-item-wavesurfer'],
        ]
      ];
    }
    // Only send settings and
    if ($use_external_control || $use_wavesurfer) {
      $elements[$delta]['audio_hmtl5_' . $i]['audio']['#attributes']['class'][] = 'strawberry-av-item-js';
      $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['audiovideo'][$htmlid]['use_external_control'] = (bool) $use_external_control;
      $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['audiovideo'][$htmlid]['use_external_control_shared'] = (bool) $use_external_control_shared;
      $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['audiovideo'][$htmlid]['external_control_element_active_class'] = $external_control_element_active_class;
      $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['audiovideo'][$htmlid]['hide_native_control'] = (bool) $hide_native_control;
      $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['audiovideo'][$htmlid]['external_control_selector'] = $external_control_selector;
      $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['audiovideo'][$htmlid]['use_wavesurfer'] = $use_wavesurfer;
      $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['audiovideo'][$htmlid]['waveform_url'] = $json_audio_webform_url;
      $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/av_custom_control_strawberry';
    }
    $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/av_strawberry';

  }

}
