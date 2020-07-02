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
use Drupal\file\FileInterface;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;

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
class StrawberryPannellumFormatter extends StrawberryBaseFormatter {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return parent::defaultSettings() + [
        'json_key_source' => 'as:image',
        'json_key_hotspots' => 'hotspot',
        'json_key_multiscene' => 'panorama_tour',
        'max_width' => 600,
        'max_height' => 400,
        'panorama_type' => 'equirectangular',
        'json_key_settings' => 'as:formatter',
        'image_type' => 'jpg',
        'number_images' => 1,
        // todo: quality, rotation, and hotspotdebug not used but I put them in schema for now
        'quality' => 'default',
        'rotation' => '0',
        'hotSpotDebug' => TRUE,
        'autoLoad' => FALSE,
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
        'json_key_hotspots' => [
          '#type' => 'textfield',
          '#title' => t('JSON Key from where to fetch Pannellum Hotspots'),
          '#default_value' => $this->getSetting('json_key_hotspots'),
        ],
        'json_key_multiscene' => [
          '#type' => 'textfield',
          '#title' => t(
            'JSON Key from where to fetch a Multi Scene Panellum Tour.'
          ),
          '#description' => t(
            'If found, JSON Key from where to fetch Media URLs will be used to load images from other Digital Object Panoramas'
          ),
          '#default_value' => $this->getSetting('json_key_multiscene'),
        ],
        'json_key_settings' => [
          '#type' => 'textfield',
          '#title' => t('JSON Key from where to fetch Pannellum Viewer Settings'),
          '#default_value' => $this->getSetting('json_key_settings'),
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
        'autoLoad' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Autoload Panoramas'),
          '#default_value' => $this->getSetting('autoLoad'),
        ],
      ] + parent::settingsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $summary[] = $this->t(
      'Displays Panoramas from JSON using Pannellum and a IIIF server endpoint'
    );
    if ($this->getSetting('json_key_source')) {
      $summary[] = $this->t(
        'Media fetched from JSON "%json_key_source" key',
        [
          '%json_key_source' => $this->getSetting('json_key_source'),
        ]
      );
    }
    if ($this->getSetting('number_images')) {
      $summary[] = $this->t(
        'Number of images: "%number"',
        [
          '%number' => $this->getSetting('number_images'),
        ]
      );
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
    $max_height = $this->getSetting('max_height');
    $number_images = $this->getSetting('number_images');
    /* @var \Drupal\file\FileInterface[] $files */
    // Fixing the key to extract while coding to 'Media'
    $key = $this->getSetting('json_key_source');
    $hotspots = $this->getSetting('json_key_hotspots');
    $multiscene = trim($this->getSetting('json_key_multiscene'));
    $settings_hotspotdebug = $this->getSetting('hotSpotDebug');
    $settings_autoload = $this->getSetting('autoLoad');
    $setttings_key = $this->getSetting('json_key_settings');

    $nodeuuid = $items->getEntity()->uuid();
    $nodeid = $items->getEntity()->id();
    $fieldname = $items->getName();
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
        $message = $this->t(
          'We could had an issue decoding as JSON your metadata for node @id, field @field',
          [
            '@id' => $nodeid,
            '@field' => $items->getName(),
          ]
        );
        return $elements[$delta] = ['#markup' => $this->t('ERROR')];
      }

      if (!empty($multiscene) && isset($jsondata[$multiscene]) && count(
          $jsondata[$multiscene]
        )) {
        // We assume that any other Entity will contain Data in the same fieldname
        // @TODO explore edge cases of multi SBFs around?
        // WE could use the SBF service to get the field names too.
        $elements[$delta] = $this->processMultiScene(
          $jsondata[$multiscene],
          $fieldname,
          $nodeid
        );
        if (is_array($elements[$delta]) && !empty($elements[$delta])) {
          continue;
        }
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
        $iiifhelper = new IiifHelper(
          $this->getIiifUrls()['public'],
          $this->getIiifUrls()['internal']
        );
        // Order Images based on a given 'sequence' key
        $ordersubkey = 'sequence';
        StrawberryfieldJsonHelper::orderSequence($jsondata, $key, $ordersubkey);
        foreach ($jsondata[$key] as $mediaitemkey => $mediaitem) {
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
                  'iiif-' . $items->getName(
                  ) . '-' . $nodeuuid . '-' . $delta . '-panorama' . $i;
                $htmlid = $uniqueid;

                $cache_contexts = [
                  'url.site',
                  'url.path',
                  'url.query_args',
                  'user.permissions',
                ];
                // @ see https://www.drupal.org/files/issues/2517030-125.patch
                $cache_tags = Cache::mergeTags(
                  $filecachetags,
                  $items->getEntity()->getCacheTags()
                );
                // http://localhost:8183/iiif/2/e8c%2Fa-new-label-en-image-05066d9ae32580cffb38342323f145f74faf99a1.jpg/full/220,/0/default.jpg
                $iiifpublicinfojson = $iiifhelper->getPublicInfoJson(
                  $iiifidentifier
                );
                $iiifsizes = $iiifhelper->getImageSizes($iiifidentifier);

                if (!$iiifsizes) {
                  $message = $this->t(
                    'We could not fetch Image sizes from IIIF @url <br> for node @id, defaulting to base formatter configuration.',
                    [
                      '@url' => $iiifpublicinfojson,
                      '@id' => $nodeid,
                    ]
                  );
                  \Drupal::logger('format_strawberryfield')->warning($message);
                  //continue; // Nothing can be done here?
                }
                else {
                  //@see \template_preprocess_image for further theme_image() attributes.
                  // Look. This one uses the public accessible base URL.
                  // That is how world works.
                  if (($max_width == 0) && ($max_height == 0)) {
                    $max_width = $iiifsizes[0]['width'];
                    $max_height = $iiifsizes[0]['height'];
                  }
                  if (($max_width == 0) && ($max_height > 0)) {
                    $max_width = round(
                      $iiifsizes[0]['width'] / $iiifsizes[0]['height'] * $max_height,
                      0
                    );
                  }
                  elseif (($max_width > 0) && ($max_height == 0)) {
                    $max_height = round(
                      $iiifsizes[0]['height'] / $iiifsizes[0]['width'] * $max_width,
                      0
                    );
                  }
                  // Pannellum recommends max 4096 pixel width images for WebGl. Lets use that as max.
                  $max_width_source = ($iiifsizes[0]['width'] > 4096) ? '4096,' : 'max';

                  $iiifserverimg = "{$this->getIiifUrls()['public']}/{$iiifidentifier}" . "/full/{$max_width_source}/0/default.jpg";
                  $elements[$delta]['panorama' . $i] = [
                    '#type' => 'container',
                    '#attributes' => [
                      'class' => ['field-iiif', 'strawberry-panorama-item'],
                      'id' => $htmlid,
                      'data-iiif-image' => $iiifserverimg,
                      'data-iiif-image-width' => $max_width_css,
                      'data-iiif-image-height' => max(
                        $max_height,
                        520
                      ),
                      'style' => "width:{$max_width_css}; height:{$max_height}px",
                    ],
                    '#title' => $this->t(
                      'Panorama for @label',
                      ['@label' => $items->getEntity()->label()]
                    ),
                  ];
                  // Lets add hotspots
                  $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['pannellum'][$htmlid]['settings'] = [
                    'hotSpotDebug' => $settings_hotspotdebug,
                    'autoLoad' => $settings_autoload,
                  ];
                  // Let's check if the user provided in-metadata settings for the viewer
                  // This is needed to adjust ROLL/PITCH/ETC for partial panoramas.

                  // @TODO. We can maybe have an option where $mediaitemkey is not set
                  // And then have general settings for every image?
                  if (isset($jsondata[$setttings_key]) &&
                    isset($jsondata[$setttings_key][$this->pluginId]) &&
                    isset($jsondata[$setttings_key][$this->pluginId][$mediaitemkey]) &&
                    isset($jsondata[$setttings_key][$this->pluginId][$mediaitemkey]['settings'])
                    ) {
                    // We only want a few settings here.
                    // Question is do we allow everything pannellum can?
                    // Or do we control this?
                    $viewer_settings = $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['pannellum'][$htmlid]['settings'];
                    $viewer_settings = array_merge($viewer_settings, $jsondata[$setttings_key][$this->pluginId][$mediaitemkey]['settings']);
                    $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['pannellum'][$htmlid]['settings'] = $viewer_settings;
                  }



                  $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['pannellum'][$htmlid]['nodeuuid'] = $nodeuuid;
                  $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['pannellum'][$htmlid]['width'] = $max_width_css;
                  $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['pannellum'][$htmlid]['height'] = max(
                    $max_height,
                    520
                  );
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
                    $i = 0;
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
                $elements[$delta]['panorama' . $i] = [
                  '#markup' => '<i class="fas fa-times-circle"></i>',
                  '#prefix' => '<span>',
                  '#suffix' => '</span>',
                ];
              }
            }
            elseif (isset($mediaitem['url'])) {
              $elements[$delta]['[panorama' . $i] = [
                '#markup' => 'Non managed ' . $mediaitem['url'],
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
   * Processes a Multi Scene JSON key and extracts referenced Scenes and
   * HotSpots.
   *
   * @param array $jsondata_scene
   * @param string $fieldname
   */
  public function processMultiScene(
    array $jsondata_scene,
    string $fieldname,
    int $ownnodeid
  ) {
    // We need to check if there are many or a single one first.

    if (isset($jsondata_scene['scene'])) {
      // Means its a unique scene.
      $scenes[] = $jsondata_scene;
    }
    else {
      foreach ($jsondata_scene as $scene) {
        $scenes[] = $scene;
      }
    }
    // Now we have a common structure
    // Create an Empty render array
    $reusedarray['panorama1'] = [
      "#type" => "container",
    ];
    $single_scenes = new \stdClass();
    $default_scene = new \stdClass();
    $full_tour = new \stdClass();
    $single_scene_details = new \stdClass();
    $panorama_id = NULL;
    foreach ($scenes as $key => $scenes) {
      // Now this is sweet, We will assume that the user wants same specs as this
      // Formatter (makes sense!).
      if (isset($scenes["scene"])) {
        $nid = $scenes["scene"];
        // Don't allow circular references
        if ($nid != $ownnodeid) {
          // @TODO inject-it as we do in the other formatters.
          $node = \Drupal::service('entity.manager')->getStorage('node')->load(
            $nid
          );
          if (!$node) {
            continue;
          }
          $type = $this->pluginId;
          $settings = $this->getSettings();
          // Let's reuse the same settings we have!
          // but remove 'multiscene' key to make sure
          // That we don't end loading circular
          // references, deep tours in tours or even ourselves!
          $settings['json_key_multiscene'] = '';
          if ($this->checkAccess($node)) {
            foreach ($node->{$fieldname} as $i => $delta) {
              // @see \Drupal\Core\Entity\EntityViewBuilderInterface::viewField()
              $renderarray = $delta->view(
                ['type' => $type, 'settings' => $settings]
              );
              // We only want first image always.
              if (isset($renderarray['panorama1'])) {
                if ($key == 0) {
                  $reusedarray = $renderarray;
                  // Lets build our objects here!
                  $default_scene->firstScene = "{$nid}";
                  $single_scene_details = new \stdClass();
                  $single_scene_details->title = $node->label();
                  $single_scene_details->type = 'equirectangular';
                  if (isset($scenes['hfov'])) {
                    $single_scene_details->hfov = (int) $scenes['hfov'];
                  }
                  if (isset($scenes['pitch'])) {
                    $single_scene_details->pitch = (int) $scenes['pitch'];
                  }
                  if (isset($scenes['yaw'])) {
                    $single_scene_details->yaw = (int) $scenes['yaw'];
                  }
                  $single_scene_details->panorama = $renderarray['panorama1']['#attributes']['data-iiif-image'];
                  $single_scene_details->hotSpots = isset($scenes['hotspots']) ? $scenes['hotspots'] : [];
                  // So. All scenes have this form: scene1-0 (more than 0-1 if SBF is multivalued)
                  $single_scenes->{"$nid"} = clone $single_scene_details;
                  $panorama_id = $renderarray['panorama1']['#attributes']['id'];

                  unset($reusedarray['panorama1']["#attached"]["drupalSettings"]["format_strawberryfield"][$panorama_id]["hotspots"]);
                }
                else {
                  $single_scene_details->title = $node->label();
                  $single_scene_details->type = 'equirectangular';
                  if (isset($scenes['hfov'])) {
                    $single_scene_details->hfov = (int) $scenes['hfov'];
                  }
                  if (isset($scenes['pitch'])) {
                    $single_scene_details->pitch = (int) $scenes['pitch'];
                  }
                  if (isset($scenes['yaw'])) {
                    $single_scene_details->yaw = (int) $scenes['yaw'];
                  }
                  $single_scene_details->panorama = $renderarray['panorama1']['#attributes']['data-iiif-image'];
                  $single_scene_details->hotSpots = isset($scenes['hotspots']) ? $scenes['hotspots'] : [];
                  $single_scenes->{"$nid"} = clone $single_scene_details;
                }
              }
            }
          }
        }
      }
    }
    $full_tour->default = $default_scene;
    $full_tour->scenes = $single_scenes;
    //@TODO we should validate these puppies probably.
    if (!empty($panorama_id)) {
      $reusedarray["#attached"]["drupalSettings"]["format_strawberryfield"]["pannellum"][$panorama_id]["tour"] = $full_tour;
    }
    return $reusedarray;
  }

  /**
   * {@inheritdoc}
   */
  public function view(FieldItemListInterface $items, $langcode = NULL) {

    $elements = parent::view($items, $langcode);
    return $elements;
  }

}