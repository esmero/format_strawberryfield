<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:56 PM
 */

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\Annotation\FieldFormatter;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\format_strawberryfield\EmbargoResolverInterface;
use Drupal\strawberryfield\Tools\Ocfl\OcflHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\format_strawberryfield\Tools\IiifHelper;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Simplistic Strawberry Field formatter.
 *
 * @FieldFormatter(
 *   id = "strawberry_pannellum_formatter",
 *   label = @Translation("Strawberry Field Panorama Formatter using Pannellum
 *   and IIIF"), class =
 *   "\Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryPannellumFormatter",
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
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;


  /**
   * StrawberryMiradorFormatter constructor.
   *
   * @param $plugin_id
   * @param $plugin_definition
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   * @param array $settings
   * @param $label
   * @param $view_mode
   * @param array $third_party_settings
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Session\AccountInterface $current_user
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\format_strawberryfield\EmbargoResolverInterface $embargo_resolver
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    $label,
    $view_mode,
    array $third_party_settings,
    ConfigFactoryInterface $config_factory,
    AccountInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager,
    EmbargoResolverInterface $embargo_resolver
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $label,
      $view_mode,
      $third_party_settings,
      $config_factory,
      $embargo_resolver,
      $current_user
    );
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('format_strawberryfield.embargo_resolver')
    );
  }
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
        'json_key_settings' => 'ap:viewerhints',
        'image_type' => 'jpg',
        'number_images' => 1,
        // todo: quality, rotation, and hotspotdebug not used but I put them in schema for now
        'quality' => 'default',
        'viewer_overrides' => '',
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
          '#required' => TRUE,
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
          '#title' => t('JSON Key from where to fetch Pannellum Viewer Settings.'),
          '#description' => t('To conform to other Strawberryfield formatters and their Viewers overrides settings, this should be set to <em>ap:viewerhints</em>, but this formatter allows to use the legacy as:formatter too'),
          '#default_value' => 'ap:viewerhints',
        ],
        'viewer_overrides' => [
          '#type' => 'textarea',
          '#title' => $this->t('Advanced: a JSON with Panellum viewer Settings.'),
          '#description' => $this->t('See <a href="https://github.com/mpetroff/pannellum/blob/master/doc/json-config-parameters.md">https://github.com/mpetroff/pannellum/blob/master/doc/json-config-parameters.md</a>. Leave Empty to use defaults.
   <em>panorama</em> can not be overriden. Use with caution. An ADO can also override this formatter\'s settings by providing the following JSON key for a single panorama: @ado_override or for a tour per scene (with scene 1 as an example) as  @ado_override_tour',[
            '@ado_override' => json_encode(["ap:viewerhints" => ["strawberry_pannellum_formatter" => ["mouseZoom" => FALSE]]], JSON_FORCE_OBJECT|JSON_PRETTY_PRINT),
            '@ado_override_tour' => json_encode(["ap:viewerhints" => ["strawberry_pannellum_formatter" => ["1" => ["mouseZoom" => FALSE]]]], JSON_FORCE_OBJECT|JSON_PRETTY_PRINT)
          ]),
          '#default_value' => $this->getSetting('viewer_overrides'),
          '#element_validate' => [[$this, 'validateJSON']],
          '#required' => FALSE,
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
    $max_width_css = empty($max_width) ? '100%' : $max_width . 'px';
    $max_height = $this->getSetting('max_height');
    $number_images = $this->getSetting('number_images');
    /* @var \Drupal\file\FileInterface[] $files */
    // Fixing the key to extract while coding to 'Media'
    $key = $this->getSetting('json_key_source');
    $hotspots = $this->getSetting('json_key_hotspots');
    $multiscene = trim($this->getSetting('json_key_multiscene') ?? '');
    $settings_hotspotdebug = $this->getSetting('hotSpotDebug');
    $settings_autoload = $this->getSetting('autoLoad');
    $settings_key = $this->getSetting('json_key_settings') ?? "ap:viewerhints";

    $upload_keys_string = strlen(trim($this->getSetting('upload_json_key_source') ?? '')) > 0 ? trim($this->getSetting('upload_json_key_source')) : '';
    $upload_keys = explode(',', $upload_keys_string);
    $upload_keys = array_filter($upload_keys);
    $upload_keys = array_map('trim', $upload_keys);
    $hide_on_embargo =  $this->getSetting('hide_on_embargo') ?? FALSE;
    $embargo_context = [];
    $embargo_tags = [];

    $embargo_upload_keys_string = strlen(trim($this->getSetting('embargo_json_key_source') ?? '')) > 0 ? trim($this->getSetting('embargo_json_key_source')) : '';
    $embargo_upload_keys_string = explode(',', $embargo_upload_keys_string);
    $embargo_upload_keys_string = array_filter($embargo_upload_keys_string);
    $publicimageurl = NULL;

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

      $json_error = json_last_error();
      if ($json_error != JSON_ERROR_NONE) {
        $message = $this->t(
          'We could had an issue decoding as JSON your metadata for node @id, field @field',
          [
            '@id' => $nodeid,
            '@field' => $items->getName(),
          ]
        );
        \Drupal::logger('format_strawberryfield')->warning($message);
        return $elements[$delta] = ['#markup' => $this->t('ERROR')];
      }

      $viewer_overrides = $this->getSetting('viewer_overrides');
      $viewer_overrides_json = json_decode(trim($viewer_overrides), TRUE);

      $json_error = json_last_error();
      if ($json_error == JSON_ERROR_NONE) {
        $viewer_overrides = $viewer_overrides_json;
      }
      else {
        // Because this particular formatter will pass a single config, we need
        // to ensure it is an array, so we can merge it later in the code.
        $viewer_overrides = [];
      }

      if (isset($jsondata[$settings_key][$this->getPluginId()]) &&
        is_array($jsondata[$settings_key][$this->getPluginId()]) &&
        !empty($jsondata[$settings_key][$this->getPluginId()])) {
        // if we could decode it, it is already JSON.
        $viewer_overrides = $jsondata[$settings_key][$this->getPluginId()];
        foreach ($viewer_overrides as $setting_key => $setting_value) {
            if (is_array($setting_value)) {
              // We remove any legacy $mediakey][setting here. We only deal with that if the mediakey matches
              // further down.
              unset($viewer_overrides[$setting_key]);
            }
        }
      }

      $embargo_info = $this->embargoResolver->embargoInfo($item->getEntity(), $jsondata);
      // This one is for the Twig template
      // We do not need the IP here. No use of showing the IP at all?
      $context_embargo = ['data_embargo' => ['embargoed' => false, 'until' => NULL]];

      if (is_array($embargo_info)) {
        $embargoed = $embargo_info[0];
        $context_embargo['data_embargo']['embargoed'] = $embargoed;

        $embargo_tags[] = 'format_strawberryfield:all_embargo';
        if ($embargo_info[1]) {
          $embargo_tags[]= 'format_strawberryfield:embargo:'.$embargo_info[1];
          $context_embargo['data_embargo']['until'] = $embargo_info[1];
        }
        if ($embargo_info[2] || ($embargo_info[3] == FALSE)) {
          $embargo_context[] = 'ip';
        }
      }
      else {
        $context_embargo['data_embargo']['embargoed'] = $embargo_info;
      }
      // @NOTE: For now we are going to only enforce Embargoes for
      // Main ADO. If a tour we can not (right now) go and check
      // For this release each ADO because if one is embargoed
      // The whole Tour would break.

      if (!$embargoed || !empty($embargo_upload_keys_string)) {

        if (!empty($multiscene) && isset($jsondata[$multiscene]) && is_array($jsondata[$multiscene]) && count(
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
            // because we are calling each individual panorama recursively here
            // we need to merge the settings back. Panorama level settings should win.
            // But we don't have the $htmlid anymore. So we iterate.
            foreach ($elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['pannellum'] ?? [] as $hmtl_id_scene => $attached_settings) {
              $scene_settings = $attached_settings['settings'] ?? [];
              $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['pannellum'][$hmtl_id_scene]['settings'] = array_merge($scene_settings,
                $viewer_overrides);
            }
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
          StrawberryfieldJsonHelper::orderSequence($jsondata, $key,
            $ordersubkey);
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
                  $iiifidentifier = urlencode(StreamWrapperManager::getTarget($file->getFileUri()));

                  if ($iiifidentifier == NULL || empty($iiifidentifier)) {
                    continue;
                    // @TODO add a default Thumbnail here.
                  }

                  $uniqueid = 'iiif-' . $items->getName() . '-' . $nodeuuid . '-' . $delta . '-panorama' . $i;
                  $htmlid = $uniqueid;

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
                    \Drupal::logger('format_strawberryfield')
                      ->warning($message);
                    //continue; // Nothing can be done here?
                  }
                  else {
                    // Give it the minimum in case things go wrong.
                    // Why 256Mbytes? Compression (if source is JPEG to be scaled can take way more)
                    $iiifsizes = array_reverse($iiifsizes);
                    foreach ($iiifsizes as $iiifsize) {
                      $max_iiif_sizes = $iiifsize;
                      // 16 bits, 3 Channels. If PNG it should be 4 channels.
                      if (round($iiifsize['height'] * $iiifsize['width'] * 16 * 3 / 8 / 1024 / 1024) <= 256) {
                        break;
                      }
                    }

                    if (($max_width == 0) && ($max_height == 0)) {
                      $max_height = $max_iiif_sizes['height'];
                      $max_width = $max_iiif_sizes['width'];
                    }
                    if (($max_width == 0) && ($max_height > 0)) {
                      $max_width = round(
                        $max_iiif_sizes['width'] / $max_iiif_sizes['height'] * $max_height,
                        0
                      );
                      // Overide $max_width_css in this only case
                      // But we allow 100% since Panellum will accomodate for the actual distortion
                      $max_width_css = $max_width_css == '100%' ? $max_width_css : $max_width . 'px';
                    }
                    elseif (($max_width > 0) && ($max_height == 0)) {
                      $max_height = round(
                        $max_iiif_sizes['height'] / $max_iiif_sizes['width'] * $max_width,
                        0
                      );
                    }
                    // Pannellum recommends max 4096 pixel width images for WebGl. Lets use that as max.
                    // Standard webGL Max is 16384 but Modern OSX with newer Intel reports double.
                    $max_width_source_comp = ($max_iiif_sizes['width'] >= 16384) ? '16384,' : $max_iiif_sizes['width'] . ',';
                    $max_width_source_mob = ($max_iiif_sizes['width'] >= 4096) ? '4096,' : $max_iiif_sizes['width'] . ',';
                    $iiifserverimg = "{$this->getIiifUrls()['public']}/{$iiifidentifier}" . "/full/{$max_width_source_comp}/0/default.jpg";
                    $iiifserverimg_mobile = "{$this->getIiifUrls()['public']}/{$iiifidentifier}" . "/full/{$max_width_source_mob}/0/default.jpg";
                    $elements[$delta]['panorama' . $i] = [
                      '#type' => 'container',
                      '#attributes' => [
                        'class' => ['field-iiif', 'strawberry-panorama-item'],
                        'id' => $htmlid,
                        'data-iiif-image' => $iiifserverimg,
                        'data-iiif-image-mobile' => $iiifserverimg_mobile,
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
                    /* This makes me nervous */
                    /* @TODO This might go better in a general page template preprocess
                    or template. Having two (by mistake) modals with the same
                    ID might be a mess.
                     */
                    $elements[$delta]['modal'] =  [
                      '#type' => 'markup',
                      '#allowed_tags' => ['button','span','div', 'h5'],
                      '#markup' => '<div id="sbfModal" class="modal fade" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">HotSpot</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div id="sbfModalBody" class="modal-body">
                </div>
                <div class="modal-footer">
                </div>
            </div>
        </div>
    </div>'
                    ];

                    $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['pannellum'][$htmlid]['settings'] = array_merge($viewer_overrides, [
                      'hotSpotDebug' => $settings_hotspotdebug,
                      'autoLoad' => $settings_autoload,
                    ]);

                    // @NOTE: for 1.5.0 the use of a $mediaitemkey item and a settings keys is discouraged.
                    // For a panorama, if one of the scenes (at each individual ADO level) had a key, those would be merged here
                    // But since many panoramas could commpete, and also each panorama would not have more than a single Image,
                    // having  "as:formatter": {
                    //"strawberry_pannellum_formatter": {
                    //"urn:uuid:b999aec3-dd4d-4894-af5e-7f8f0b651f1e": {
                    //"settings": {
                    //"mouseZoom": false
                    //}
                    // is more complex to write than
                    // ap:viewerhints:{"strawberry_pannellum_formatter": {"mouseZoom": false}} which is the prefered way
                    // Still, for backwayds compatibility reasons we will keep this.
                    if (isset($jsondata[$settings_key][$this->pluginId][$mediaitemkey]['settings'])) {
                      $viewer_settings = $elements[$delta]['#attached']['drupalSettings']['format_strawberryfield']['pannellum'][$htmlid]['settings'];
                      $viewer_settings = array_merge($viewer_settings,
                        $jsondata[$settings_key][$this->pluginId][$mediaitemkey]['settings']);
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
                    '#markup' => '<i class="d-none field-iiif-no-viewer"></i>',
                    '#prefix' => '<span>',
                    '#suffix' => '</span>',
                  ];
                }
              }
            }
          }
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
   * Processes a Multi Scene JSON key and extracts referenced Scenes and
   * HotSpots.
   *
   * @param array $jsondata_scene
   * @param string $fieldname
   * @param int $ownnodeid
   *
   * @return array
   */
  public function processMultiScene(array $jsondata_scene, string $fieldname, int $ownnodeid) {
    // We need to check if there are many or a single one first.
    $allscenes = [];
    if (isset($jsondata_scene['scene'])) {
      // Means its a unique scene.
      $allscenes[] = $jsondata_scene;
    }
    else {
      foreach ($jsondata_scene as $scene) {
        $allscenes[] = $scene;
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
    foreach ($allscenes as $key => $scenes) {
      // Now this is sweet, We will assume that the user wants same specs as this
      // Formatter (makes sense!).
      if (isset($scenes["scene"])) {
        $nid = $scenes["scene"];
        // Don't allow circular references
        if ($nid != $ownnodeid)
          $node = $this->entityTypeManager->getStorage('node')->load($nid);
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
                $single_scene_details->panoramaMobile = $renderarray['panorama1']['#attributes']['data-iiif-image-mobile'];
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
                // Adds the mobile version which on the JS will replace the normal panorama if
                // we are on mobile mode.
                $single_scene_details->panoramaMobile = $renderarray['panorama1']['#attributes']['data-iiif-image-mobile'];
                $single_scene_details->hotSpots = isset($scenes['hotspots']) ? $scenes['hotspots'] : [];
                $single_scenes->{"$nid"} = clone $single_scene_details;
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

}
