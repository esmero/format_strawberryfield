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
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Url;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;

/**
 * Simplistic Video Strawberry Field formatter.
 *
 * @FieldFormatter(
 *   id = "strawberry_video_formatter",
 *   label = @Translation("Strawberry Field Video Formatter"),
 *   class = "\Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryVideoFormatter",
 *   field_types = {
 *     "strawberryfield_field"
 *   },
 *   quickedit = {
 *     "editor" = "disabled"
 *   }
 * )
 */
class StrawberryVideoFormatter extends StrawberryDirectJsonFormatter {
  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return parent::defaultSettings() + [
        'json_key_source' => 'as:video',
        'max_width' => 720,
        'max_height' => 240,
        'number_media' => 1,
        'posterframe' => 'iiif',
        'json_key_source_for_poster' => 'as:image',
        'use_wavesurfer' => false,
        'wavesurfer_filesize_limit' => '1 GB',
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
          '#title' => $this->t('Number of Video files'),
          '#description' => $this->t('Use 0 to show all Videos'),
          '#default_value' => $this->getSetting('number_media'),
          '#size' => 2,
          '#maxlength' => 2,
          '#min' => 0,
        ],
        'max_width' => [
          '#type' => 'number',
          '#title' => $this->t('Maximum width'),
          '#default_value' => $this->getSetting('max_width'),
          '#description' => $this->t('Use 0 to force 100% width'),
          '#size' => 5,
          '#maxlength' => 5,
          '#field_suffix' => $this->t('pixels'),
          '#min' => 0,
          '#required' => TRUE
        ],
        'max_height' => [
          '#type' => 'number',
          '#title' => $this->t('Maximum height'),
          '#description' => $this->t('Use 0 to force automatic proportional height'),
          '#default_value' => $this->getSetting('max_height'),
          '#size' => 5,
          '#maxlength' => 5,
          '#field_suffix' => $this->t('pixels'),
          '#min' => 0,
          '#required' => TRUE
        ],
        'posterframe' => [
          '#type' => 'select',
          '#title' => $this->t('Poster Frame generation'),
          '#default_value' => $this->getSetting('posterframe'),
          '#options' => [
            'iiif' =>  $this->t('Extract first frame of the movie via IIIF in realtime'),
            'json_key' => $this->t('Use the first Image found in this content as frame'),
            'none' => $this->t('No Poster Frame')
          ],
          '#attributes' => [
            'data-formatter-selector' => 'posterframe',
          ],
        ],
        'json_key_source_for_poster' => [
          '#type' => 'textfield',
          '#title' => t('JSON Key from where to fetch Media URL for the Poster Frame'),
          '#default_value' => $this->getSetting('json_key_source_for_poster'),
          '#states' => [
            'visible' => [
              ':input[data-formatter-selector="posterframe"]' => ['value' => 'json_key'],
            ],
          ],
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
   * @inheritDoc
   */
  public function setSettings(array $settings) {
    return parent::setSettings($settings); // TODO: Change the autogenerated stub
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $summary[] = $this->t('Plays Video from JSON');

    if ($this->getSetting('json_key_source')) {
      $summary[] = $this->t('Media fetched from JSON "%json_key_source" key', [
        '%json_key_source' => $this->getSetting('json_key_source'),
      ]);
    }
    if ($this->getSetting('number_media')) {
      $summary[] = $this->t('Number of Videos: "%number"', [
        '%number' => $this->getSetting('number_media'),
      ]);
    }
    $summary[] = $this->t(
      'Maximum size: %max_width x %max_height',
      [
        '%max_width' => (int) $this->getSetting('max_width') == 0 ? '100%' : $this->getSetting('max_width') . ' pixels',
        '%max_height' => (int) $this->getSetting('max_height') == 0 ? 'auto' : $this->getSetting('max_height') . ' pixels',
      ]
    );
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
    }


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

    //@TODO posterframe is not being used. Make it used.
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
        $message = $this->t('We could had an issue decoding as JSON your metadata for node @id, field @field',
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
      /* Expected structure of an Video item inside JSON
      @see https://www.w3.org/TR/webvtt1/#introduction-metadata for tracks
      @see http://events.linkeddata.org/ldow2014/papers/ldow2014_paper_11.pdf for LoD
      {"as:video": {
		    "urn:uuid:someuuid": {
			  "dr:fid": 32, // Drupal's FID
			  "dr:for": "some_videos_files",  // The webform element key that generated this one
			  "url": "s3://f23/new-metadata-en-image-58455d91acf7290275c1cab77531b7f561a11a84.mp3",
			  "name": "My Super Reel",
		  	"type": "Video",
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
          $embargo_tags[] = 'format_strawberryfield:embargo:' . $embargo_info[1];
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
        // This fetchMediaFromJsonWithFilter impl. has JMESPATH filtering.
        $media = $this->fetchMediaFromJsonWithFilter($delta, $items, $elements,
          TRUE, $jsondata, 'Video', $key, $ordersubkey, $number_media,
          $upload_keys, []);
        if (count($media)) {
          $conditions[] = [
            'source'    => ['dr:mimetype'],
            'condition' => 'text/vtt',
          ];
          // WE call the parent here since we do not want/nor have a JMESPATH
          // For the vtt.
          $vtt = parent::fetchMediaFromJsonWithFilter($delta, $items,
            $elements,
            FALSE, $jsondata, 'Text', 'as:text', $ordersubkey, $number_media,
            $upload_keys, $conditions);
          /* This may be a bit more complex, possible situations we cover
            1.- NO vtt, all good
            2.- One Media, multiple vtt, all good
            3.- Multiple media, multiple vtt. need to grouped by sourcekey
            */
          if (count($vtt)) {
            // If $media is a single one, we will assume all VTTS belong to it, bypassing the dr:for grouping
            foreach ($media as $drforkey => $media_item) {
              if (isset($vtt[$drforkey]) || count($media) == 1) {
                $i = 0;
                foreach ($media_item as $key => $media_entry) {
                  $elements[$delta]['video_hmtl5_' . $key]['video']['#attributes']['style'] = "width:{$max_width_css}; height:{$max_height_vtt_css}";
                  foreach ($vtt as $vtt_drforkey => $vtt_entries) {
                    if (count($media) == 1 || $drforkey == $vtt_drforkey) {
                      foreach ($vtt_entries as $vtt_key => &$vtt_item) {
                        $route_parameters = [
                          'node'   => $nodeid,
                          'uuid'   => $vtt_item['file']->uuid(),
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
                        $elements[$delta]['video_hmtl5_'
                        . $key]['video']['track'
                        . $vtt_key]
                          = [
                          '#type'       => 'html_tag',
                          '#tag'        => 'track',
                          '#attributes' => [
                            'label'   => $this->t(
                              'Transcript ' . $current_language ." ({$vtt_item['file_name']})"
                            ),
                            'kind'    => 'subtitles',
                            'srclang' => $current_language,
                            'src'     => $publicurl->toString(),
                            'default' =>  $i == 0 ? TRUE : FALSE
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
    $max_width_css = empty($max_width) || $max_width == 0 ? '100%' : $max_width .'px';
    $max_height = $this->getSetting('max_height');
    $max_height_css = empty($max_height) || $max_height == 0 ? 'auto' : $max_height .'px';
    $nodeuuid = $items->getEntity()->uuid();
    $nodeid = $items->getEntity()->id();
    $use_external_control = $this->getSetting('use_external_control');
    $use_external_control_shared = $this->getSetting('use_external_control_shared');
    $hide_native_control = $this->getSetting('hide_native_control');
    // The 1.6.0 reposition feature via JS
    $use_wavesurfer = $this->getSetting('use_wavesurfer');
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
      'node' => $nodeid,
      'uuid' => $file->uuid(),
      'format' => 'default.' . pathinfo($file->getFilename(),
          PATHINFO_EXTENSION)
    ];
    $publicurl = Url::fromRoute('format_strawberryfield.iiifbinary',
      $route_parameters);

    $uniqueid =
      'av-' . $items->getName(
      ) . '-' . $nodeuuid . '-' . $delta . '-audio' . $i;

    $cache_contexts = [
      'url.site',
      'url.path',
      'url.query_args',
      'user.permissions'
    ];

    // We will use HTML5 Video Tag on all audio/video because Audio Tag does not
    // consistently allow Tracks with Subtitles
    // @see https://www.iandevlin.com/blog/2015/12/html5/webvtt-and-audio/
    $htmlid = 'video_' . $uniqueid;
    $elements[$delta]['video_hmtl5_' . $i] = [
      '#type' => 'html_tag',
      '#tag' => 'figure',
      'video' => [
        '#type' => 'html_tag',
        '#tag' => 'video',
        '#attributes' => [
          'class' => ['field-av', 'video-av', 'strawberry-av-item', 'strawberry-video-item'],
          'id' => $htmlid,
          'controls' => TRUE,
          'style' => "width:{$max_width_css}; height:{$max_height_css}",
          'aria-label' => $media_label,
        ],
        '#alt' => $this->t(
          'Video for @label',
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

    // Pre hide if we enabled external control and provided a selector
    if ($use_external_control && strlen($external_control_selector) > 0) {
      $elements[$delta]['video_hmtl5_' . $i]['video']['#attributes']['hidden'] = TRUE;
      // JS will undo this IF the selector/external control does not match the requirements.
    }

    // We need to add a container for the waver surfer plugin.
    if ($use_wavesurfer) {
      // Disable based on file size
      $wavesurfer_filesize_limit = $this->getSetting('wavesurfer_filesize_limit') ?? '1 GB';
      $wavesurfer_filesize_limit = Bytes::validate($wavesurfer_filesize_limit) ? $wavesurfer_filesize_limit : '1 GB';
      $wavesurfer_filesize_limit = Bytes::toNumber($wavesurfer_filesize_limit);
      if ($file->getSize() >= $wavesurfer_filesize_limit) {
        $use_wavesurfer = FALSE;
      }
      $elements[$delta]['video_hmtl5_' . $i]['wavesurfer'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'class' => ['strawberry-av-item-wavesurfer'],
        ]
      ];
    }
    // Only send settings and
    if ($use_external_control || $use_wavesurfer) {
      $elements[$delta]['video_hmtl5_' . $i]['video']['#attributes']['class'][] = 'strawberry-av-item-js';
      $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['audiovideo'][$htmlid]['use_external_control'] = (bool) $use_external_control;
      $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['audiovideo'][$htmlid]['use_external_control_shared'] = (bool) $use_external_control_shared;
      $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['audiovideo'][$htmlid]['external_control_element_active_class'] = $external_control_element_active_class;
      $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['audiovideo'][$htmlid]['hide_native_control'] = (bool) $hide_native_control;
      $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['audiovideo'][$htmlid]['external_control_selector'] = $external_control_selector;
      $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['audiovideo'][$htmlid]['use_wavesurfer'] = $use_wavesurfer;
      $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/av_custom_control_strawberry';
    }
    $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/av_strawberry';
  }


}
