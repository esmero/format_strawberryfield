<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:56 PM
 */

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Annotation\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\strawberryfield\Tools\Ocfl\OcflHelper;
use Drupal\format_strawberryfield\Tools\IiifHelper;
use Drupal\file\FileInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Url;

/**
 * Simplistic Strawberry Field formatter.
 *
 * @FieldFormatter(
 *   id = "strawberry_warc_formatter",
 *   label = @Translation("Strawberry Warc Formatter using replay.web embedded player"),
 *   class = "\Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryWarcFormatter",
 *   field_types = {
 *     "strawberryfield_field"
 *   },
 *   quickedit = {
 *     "editor" = "disabled"
 *   }
 * )
 */
class StrawberryWarcFormatter extends StrawberryDirectJsonFormatter {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return
      parent::defaultSettings() + [
        'json_key_source' => 'as:document',
        'json_key_starting_url' => 'web_url',
        'warcurl_json_key_source' => '',
        'max_width' => 0,
        'max_height' => 520,
      ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    return [
        'json_key_source' => [
          '#type' => 'textfield',
          '#title' => t('JSON Key from where to fetch Media URLs (WARC files)'),
          '#default_value' => $this->getSetting('json_key_source'),
          '#required' => TRUE,
        ],
        'json_key_starting_url' => [
          '#type' => 'textfield',
          '#title' => t('JSON Key from where to fetch the first loaded URL for a WARC file.'),
          '#default_value' => $this->getSetting('json_key_starting_url'),
          '#required' => TRUE,
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
        'navbar' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Enable navbars and menus.'),
          '#description' => $this->t('Check to display full navigation bar inside the replayweb widget.'),
          '#required' => FALSE,
          '#default_value' => $this->getSetting('navbar') ? $this->getSetting('navbar') : TRUE,
        ],

        'warcurl_json_key_source' => [
          '#type' => 'textfield',
          '#title' => t('JSON key containing a list or external Warc URLs. Leave empty to skip'),
          '#default_value' => $this->getSetting('warcurl_json_key_source'),
        ],
      ] + parent::settingsForm($form, $form_state);
  }


  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    if ($this->getSetting('json_key_source')) {
      $summary[] = $this->t('WARC file fetched from JSON "%json_key_source" key', [
        '%json_key_source' => $this->getSetting('json_key_source'),
      ]);
    }
    $summary[] = $this->t(
      'Maximum size: %max_width x %max_height',
      [
        '%max_width' => (int) $this->getSetting('max_width') == 0 ? '100%' : $this->getSetting('max_width') . ' pixels',
        '%max_height' => $this->getSetting('max_height') . ' pixels',
      ]
    );
    if ($this->getSetting('warcurl_json_key_source')) {
      $summary[] = $this->t('External WARC file URLs fetched from JSON "%json_key_source" key', [
        '%json_key_source' => $this->getSetting('warcurl_json_key_source'),
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
    $url_key = $this->getSetting('json_key_starting_url');
    $navbar = $this->getSetting('navbar');
    $max_width_css = empty($max_width) || $max_width == 0 ? '100%' : $max_width . 'px';
    $max_height = $this->getSetting('max_height');
    $hide_on_embargo =  $this->getSetting('hide_on_embargo') ?? FALSE;
    //@TODO allow more than one?
    $number_warcs = $this->getSetting('number_warcs');

    $upload_keys_string = strlen(trim($this->getSetting('upload_json_key_source') ?? '')) > 0 ? trim($this->getSetting('upload_json_key_source')) : '';
    $upload_keys = explode(',', $upload_keys_string);
    $upload_keys = array_filter($upload_keys);

    $embargo_upload_keys_string = strlen(trim($this->getSetting('embargo_json_key_source') ?? '')) > 0 ? trim($this->getSetting('embargo_json_key_source')) : '';
    $embargo_upload_keys_string = explode(',', $embargo_upload_keys_string);
    $embargo_upload_keys_string = array_filter($embargo_upload_keys_string);

    $current_language = $items->getEntity()->get('langcode')->value;
    $nodeid = $items->getEntity()->id();
    $number_media = $this->getSetting('number_media') ?? 1;
    $key = $this->getSetting('json_key_source');
    $embargo_context = [];
    $embargo_tags = [];

    $nodeuuid = $items->getEntity()->uuid();
    $nodeid = $items->getEntity()->id();
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
        \Drupal::logger('format_strawberryfield')->warning($message);
        return $elements[$delta] = ['#markup' => $this->t('ERROR')];
      }
      /* Expected structure of an Media item inside JSON
      "as:document": {
         "urn:uuid:1170c27d-431e-46e2-b003-3fb51cfcd166": {
         "dr:fid": 66, // Drupal's FID
         "dr:for": "add_some_warc_files", // The webform element key that generated this one
         "url": "s3:\/\/f23\/google.com-crawl-1999.warc",
         "name": "Google Crawl we did back on 1999.warc",
         "type": "Application",
         "mimetype: "application/warc"
         "checksum": "f231aed5ae8c2e02ef0c5df6fe38a99b"
         }
      }*/
      $i = 0;
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
        // Since the conditionals here are more complex
        // we do not call $this->fetchMediaFromJsonWithFilter()
        $jsondatafiltered = $this->filterMediaByJMESPath($jsondata, $key);

        foreach ($jsondatafiltered as $mediaitem) {
          $i++;
          if ($i > 1) {
            break;
          }
          if (isset($mediaitem['type']) && (
              $mediaitem['dr:mimetype'] == 'application/warc' ||
              $mediaitem['dr:mimetype'] == 'application/wacz' ||
              $mediaitem['dr:mimetype'] == 'application/zip' ||
              $mediaitem['dr:mimetype'] == 'application/gzip' ||
              $mediaitem['dr:mimetype'] == 'application/vnd.datapackage+zip' ||
              $mediaitem['dr:mimetype'] == 'application/x-gzip'
            )
          ) {
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
                // We assume here file could not be accessible publicly;
                $route_parameters = [
                  'node' => $nodeid,
                  'uuid' => $file->uuid(),
                  'format' => urlencode($mediaitem['name']),
                ];
                $publicurl = Url::fromRoute('format_strawberryfield.iiifbinary',
                  $route_parameters);
                $filecachetags = $file->getCacheTags();

                $uniqueid = 'replayweb-' . $items->getName() . '-' . $nodeuuid . '-' . $delta . '-warc' . $i;

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

                // @see https://www.iandevlin.com/blog/2015/12/html5/webvtt-and-audio/
                $elements[$delta]['warc_replayweb_' . $i] = [
                  '#type' => 'html_tag',
                  '#tag' => 'replay-web-page',
                  '#cache' => [
                    'tags' =>
                      $cache_tags,
                  ],
                  '#attributes' => [
                    'id' => $uniqueid,
                    'source' => $publicurl->toString(),
                    'replaybase' => "/replay/",
                    'deeplink' => "true",
                    'style' => "width:{$max_width_css}; height:{$max_height}px; display:block",
                  ],
                ];

                if (isset($jsondata[$url_key])) {
                  if (is_array($jsondata[$url_key]) && isset($jsondata[$url_key][$i]) &&
                    is_string($jsondata[$url_key][$i]) && !empty(trim($jsondata[$url_key][$i]))
                  ) {
                    $elements[$delta]['warc_replayweb_' . $i]['#attributes']['url'] = $jsondata[$url_key][$i];

                  }
                  elseif (!is_array($jsondata[$url_key]) && !empty(trim($jsondata[$url_key]))) {
                    // This is if there is a single URL. WE assume its for all WARC files.
                    // But let's be honest. We go for a single WARC file for now
                    $elements[$delta]['warc_replayweb_' . $i]['#attributes']['url'] = $jsondata[$url_key];
                  }
                }
                else {
                  if (!$navbar) {
                    $elements[$delta]['warc_replayweb_' . $i]['#attributes']['view'] = "pages";
                  }
                }


                $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/replayweb';
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
              $elements[$delta]['media_thumb' . $i] = [
                '#markup' => '<i class="fas fa-times-circle"></i>',
                '#prefix' => '<span>',
                '#suffix' => '</span>',
              ];
            }
          }
        }
      }
      // Get rid of empty #attributes key to avoid render error
      if (isset($elements[$delta]["#attributes"]) && empty($elements[$delta]["#attributes"])) {
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

  /**
   * {@inheritdoc}
   */
  protected function generateElementForItem(int $delta, FieldItemListInterface $items, FileInterface $file, IiifHelper $iiifhelper, int $i, array &$elements, array $jsondata, array $mediaitem) {
    // Empty for now since we kept the inline logic (too complex to refactor).
    return [];
  }


}
