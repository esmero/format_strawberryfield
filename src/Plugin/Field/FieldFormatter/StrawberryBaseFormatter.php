<?php

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\file\FileInterface;
use Drupal\format_strawberryfield\EmbargoResolverInterface;
use Drupal\format_strawberryfield\Tools\IiifHelper;
use Drupal\strawberryfield\Tools\Ocfl\OcflHelper;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\format_strawberryfield\Tools\IiifUrlValidator;
use Drupal\Core\Access\AccessResult;

/**
 * StrawberryBaseFormatter base class for SBF/JSON based formatters.
 */
abstract class StrawberryBaseFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * The Config for getting default IIIF settings.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $iiifConfig;

  /**
   * The Current User
   * @var \Drupal\Core\Session\AccountInterface
   */

  protected $currentUser;

  /**
   * @var \Drupal\format_strawberryfield\EmbargoResolverInterface
   */
  protected $embargoResolver;

  /**
   * StrawberryBaseFormatter Constructor.
   *
   * @param string $plugin_id
   *   The Plugin ID (Formatter).
   * @param mixed $plugin_definition
   *   The Plugin Definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The main Settings.
   * @param string $label
   *   The formatter settings.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings.
   *   The definition of the field to which the formatter is associated.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The ConfigFactory Container Interface.
   * @param \Drupal\format_strawberryfield\EmbargoResolverInterface $embargo_resolver
   * @param \Drupal\Core\Session\AccountInterface $current_user
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    $label,
    string $view_mode,
    array $third_party_settings,
    ConfigFactoryInterface $config_factory,
    EmbargoResolverInterface $embargo_resolver,
    AccountInterface $current_user = NULL
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $label,
      $view_mode,
      $third_party_settings
    );
    $this->iiifConfig = $config_factory->get('format_strawberryfield.iiif_settings');
    $this->currentUser = $current_user;
    $this->embargoResolver = $embargo_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('config.factory'),
      $container->get('format_strawberryfield.embargo_resolver'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'iiif_base_url' => \Drupal::config('format_strawberryfield.iiif_settings')->get('pub_server_url'),
      'iiif_base_url_internal' => \Drupal::config('format_strawberryfield.iiif_settings')->get('int_server_url'),
      'use_iiif_globals' => TRUE,
      'upload_json_key_source' => '',
      'embargo_json_key_source' => '',
    ];
  }

  /**
   * Fetches IIIF Urls from Config or settings depending on user input.
   *
   * @return array
   *   And array in the form of:
   *   [
   *     'public' => 'a valid public URL',
   *     'internal' => 'a valid internal URL'
   *    ]
   */
  public function getIiifUrls() {
    // Note. In case you wonder. All URLs are saved with the last '/' stripped.
    // Always add a slash when using them in Twig templates
    // @TODO maybe we want the opposite? like add the slash always?
    // Also why are we not removing on save but on fetch?
    $urls = [
      'public' => boolval($this->getSetting('use_iiif_globals')) === TRUE ?  rtrim($this->iiifConfig->get('pub_server_url') ?? '',"/") : rtrim($this->getSetting('iiif_base_url') ?? '', "/"),
      'internal' => boolval($this->getSetting('use_iiif_globals')) === TRUE ? rtrim($this->iiifConfig->get('int_server_url') ?? '',"/") : rtrim($this->getSetting('iiif_base_url_internal') ?? '', "/"),
    ];
    return $urls;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $summary[] = $this->t('Use IIIF Global Urls? %value', [
      '%value' => boolval($this->getSetting('use_iiif_globals')) === TRUE ? "Yes." : "No, use custom.",
    ]);
    $summary[] = $this->t('IIIF Media Server base URI: %url', [
      '%url' => $this->getIiifUrls()['public'],
    ]);
    $summary[] = $this->t('IIIF Media Server Internal base URI: %url', [
      '%url' => $this->getIiifUrls()['internal'],
    ]);
    $summary[] = $this->t('Limited to the following file upload JSON Keys: %value', [
      '%value' => strlen(trim($this->getSetting('upload_json_key_source' ) ?? '')) == 0 ? 'Fetch from any available' : $this->getSetting('upload_json_key_source')
    ]);
    $summary[] = $this->t('Embargo Alternate upload JSON Keys: %value', [
      '%value' => strlen(trim($this->getSetting('embargo_json_key_source' ) ?? '')) == 0 ? 'Do not provide alternate files when embargoed' : $this->getSetting('embargo_json_key_source')
    ]);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element['upload_json_key_source'] = [
      '#type' => 'textfield',
      '#title' => t('JSON Key(s) where the files to be used by this formatter where uploaded/store in your JSON.'),
      '#description' => t('You can add multiple ones separated by comma. Some viewers use multiple file types, e.g audios and Subtitles, in that case please add all of them.  In case of multiple Keys for the same type e.g "audios1", "audios2", the key names will be also used for grouping. Leave empty to not filter by upload location at all.'),
      '#default_value' => $this->getSetting('upload_json_key_source'),
      '#required' => FALSE,
      '#maxlength' => 255,
      '#size' => 64,
    ];
    $element['embargo_json_key_source'] = [
      '#type' => 'textfield',
      '#title' => t('When embargo is used or applied, alternate JSON Key(s) where the files to be used by this formatter where uploaded/store in your JSON.'),
      '#description' => t('Be careful about providing same keys used for user that can by pass an embargo. You can add multiple ones separated by comma. Some viewers use multiple file types, e.g audios and Subtitles, in that case please add all of them. In case of multiple Keys for the same type e.g "audios1", "audios2", the key names will be also used for grouping. Leave empty to not provide any alternate embargo option at all.'),
      '#default_value' => $this->getSetting('upload_json_key_source'),
      '#required' => FALSE,
      '#maxlength' => 255,
      '#size' => 64,
    ];
    $element['use_iiif_globals'] = [
      '#type' => 'checkbox',
      '#title' => t('Use Global IIIF Urls'),
      '#description' => t("<b>Current Globals: </b><br> Public: @pub <br>Internal: @int", [
        '@pub' => $this->iiifConfig->get('pub_server_url'),
        '@int' => $this->iiifConfig->get('int_server_url'),
      ]),
      '#default_value' => $this->getSetting('use_iiif_globals'),
      // Created custom 'data-*' selector rather than using name
      // or id, because the name and id attributes are already set by Drupal
      // and deeply nested for settingsForms.
      // Overriding them causes problems with data submission.
      // Trying to access them in #states is hard. So this is easier.
      // This is valid HTML5.
      '#attributes' => [
        'data-checkbox-selector' => 'use_iiif_globals',
      ],
    ];
    $element['iiif_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Custom base public accessible URL of your IIIF Media Server. Trailing slashes will be removed.'),
      '#default_value' => $this->iiifConfig->get('pub_server_url'),
      '#states' => [
        'visible' => [
          ':checkbox[data-checkbox-selector="use_iiif_globals"]' => ['unchecked' => TRUE],
        ],
        'required' => [
          ':checkbox[data-checkbox-selector="use_iiif_globals"]' => ['unchecked' => TRUE],
        ],
      ],
      '#element_validate' => [[$this, 'validateUrl']],
    ];
    $element['iiif_base_url_internal'] = [
      '#type' => 'url',
      '#title' => $this->t('Custom base URL of your IIIF Media Server accessible from inside this Webserver. Trailing slashes will be removed.'),
      '#default_value' => $this->iiifConfig->get('int_server_url'),
      '#states' => [
        'visible' => [
          ':checkbox[data-checkbox-selector="use_iiif_globals"]' => ['unchecked' => TRUE],
        ],
        'required' => [
          ':checkbox[data-checkbox-selector="use_iiif_globals"]' => ['unchecked' => TRUE],
        ],
      ],
      '#element_validate' => [[$this, 'validateUrl']],
    ];

    return $element;
  }

  /**
   * Validates the IIIF Urls.
   *
   * @param array $element
   *   The Form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The Form State Object.
   * @param array $complete_form
   *   The complete form structure.
   */
  public function validateUrl(array &$element, FormStateInterface $form_state, array &$complete_form) {
    $parents = $element['#parents'];
    $url_type = end($element['#parents']);

    $checkbox = array_slice($parents, 0, -1);
    $checkbox[] = 'use_iiif_globals';

    // Only validate if using locally defined urls.
    // The globals have already been validated.
    if (boolval($form_state->getValue($checkbox)) === FALSE) {
      $validator = new IiifUrlValidator();
      $url_type = ($url_type == 'iiif_base_url_internal') ? $validator::IIIF_INTERNAL_URL_TYPE : $validator::IIIF_EXTERNAL_URL_TYPE;
      $valid = $validator->checkUrl($element['#value'], $url_type);
      if (!$valid) {
        // @TODO find the actual place in form_state to place error inline.

        $form_state->setErrorByName(implode('][', $parents), t("We could not contact your @urltype IIIF server", [
          '@urltype' => $url_type,
        ]));
      }
    }
  }

  /**
   * Validates a JSON.
   *
   * @param array $element
   *   The Form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The Form State Object.
   * @param array $complete_form
   *   The complete form structure.
   */
  public function validateJSON(array &$element, FormStateInterface $form_state, array &$complete_form) {
    $parents = $element['#parents'];
    if (strlen(trim($element['#value']) ?? '') > 0) {
      try {
        $json = json_decode($element['#value'], TRUE);
        $json_error = json_last_error();
        if ($json_error != JSON_ERROR_NONE) {
          $form_state->setErrorByName(implode('][', $parents), t("Value is not a valid JSON"));
        }
      }
      catch (\Exception $e) {
        $form_state->setErrorByName(implode('][', $parents), t("Value is not a valid JSON"));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(ContentEntityInterface $entity) {
    // Only check access if the current file access control handler explicitly
    // opts in by implementing FileAccessFormatterControlHandlerInterface.
    $access_handler_class = $entity->getEntityType()->getHandlerClass('access');
    if (is_subclass_of($access_handler_class, '\Drupal\file\FileAccessFormatterControlHandlerInterface')) {
      return $entity->access('view', NULL, FALSE);
    }
    else {
      return AccessResult::allowed();
    }
  }

  /**
   * Tries to guess mimetype of external referenced Uris.
   *
   * @param string $uripath
   *   A URL pointing to a file (hopefully)
   * @return string
   *   A guessed Mimetype
   */
  protected function guessMimeForExternalUri(string $uripath) {
    return \Drupal::service('file.mime_type.guesser')->guess($uripath);
  }


  /**
   * Fetches the needed media by a given Formatter from JSON array.
   *
   * @param int $delta
   *    The field delta
   * @param FieldItemListInterface $items
   *    The actual Field Items list
   * @param array $elements
   *    A by reference enriched render $element if
   *    $generate_element == TRUE
   * @param bool $generate_element
   *    Generate/pass by reference render $element
   * @param array $jsondata
   *    The original JSON data as an array for this particular field delta
   * @param string $mediatype
   *    The type of media to fetch, e.g 'Images' for the as: key prop.
   * @param string $key
   *    The Key from which to fetch the files, e.g as:images
   * @param string $ordersubkey
   *    Which Key inside as:images is used for sorting
   * @param int $number_media
   *    How much media to fetch, if 0 fetch all
   * @param array $upload_keys
   *    To which JSON Key the actual file was uploaded as a filter option
   *    This is passed as an array of keys.
   *
   * @param array $extra_conditions
   *
   * @return array
   *    With all files keyed by upload JSON key
   *    with each item in the following form
   * $media[$mediaitem['dr:for']][] = [
   * 'file' =>  $file / A file Entity
   * 'media_id' => $id / The key inside the chosen as:mediatype
   * ];
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function fetchMediaFromJsonWithFilter(int $delta, FieldItemListInterface $items, array &$elements, bool $generate_element, array $jsondata, string $mediatype, string $key, string $ordersubkey, int $number_media, array $upload_keys, array $extra_conditions = []) {
    $media = [];
    $iiifhelper = new IiifHelper($this->getIiifUrls()['public'], $this->getIiifUrls()['internal']);
    if (isset($jsondata[$key])) {
      $i = 0;
      // Order Media based on a given 'sequence' key
      StrawberryfieldJsonHelper::orderSequence($jsondata, $key, $ordersubkey);
      foreach ($jsondata[$key] as $id => $mediaitem) {
        if (isset($mediaitem['type']) && $mediaitem['type'] == $mediatype) {
          if ((!empty($mediaitem['dr:fid']) && !empty($mediaitem['dr:for'])) && (empty($upload_keys) || in_array($mediaitem['dr:for'], $upload_keys))) {
            // This is a bit complex but the idea is that we can pass a value or an array as a source for a condition
            // and a value or an array as the condition itself.
            // if the condition itself is also an array we assume (blindly here) that the check is against another key in the same
            // Technical metadata instead of an actual value.
            // Only simple way. So again, to check against a fixed value pass a value, to check agains another
            // Array (compare two keys) then please pass an array.
            // We can also pass an assertion (comparison operator like !==)
            foreach($extra_conditions as $condition) {
              if (isset($condition['source']) && isset($condition['condition'])) {
                if (is_array($condition['condition'])) {
                  $condition_value = NestedArray::getValue($mediaitem, $condition['condition']);
                }
                else {
                  $condition_value = $condition['condition'];
                }
                $op = "===";
                if (isset($condition['comp'])) {
                  $op = $condition['comp'] ?? $op;
                }
                if (is_array($condition['source']) && !($this->varComp(NestedArray::getValue($mediaitem, $condition['source']), $op, $condition_value))) {
                  continue 2;
                }
                elseif (!is_array($condition['source']) && isset($mediaitem[$condition['source']]) && !($this->varComp($mediaitem[$condition['source']], $op, $condition_value))) {
                  continue 2;
                }
              }
            }

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
              if ($generate_element) {
                $this->generateElementForItem($delta, $items, $file,
                  $iiifhelper, $i, $elements, $jsondata, $mediaitem);
              }
              // This allows us to group by $mediaitem['dr:for']. // e.g images
              // and also returns the key of this file inside the as:structure
              // In case i need to find quickly in some other place its
              // Technical metadata
              $media[$mediaitem['dr:for']][$i] = [
                'file' =>  $file,
                'media_id' => $id,
                'file_name' => $mediaitem['name'] ?? $file->getFilename(),
              ];
              $i++;
              if ($i > (int) $number_media && !empty($number_media)) {
                break;
              }
            }
          }
        }
      }
    }
    return $media;
  }

  /**
   * Generates the actual Render array entry for a given File.
   *
   * @param int $delta
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   * @param \Drupal\file\FileInterface $file
   * @param \Drupal\format_strawberryfield\Tools\IiifHelper $iiifhelper
   * @param int $i
   * @param array $elements
   * @param array $jsondata
   *
   * This is a stub method and each formatter needs to implements its own
   * Render array generation. For a working example see
   * @param array $mediaitem
   *
   * @see \Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryMediaFormatter::generateElementForItem
   */
  protected function generateElementForItem(int $delta, FieldItemListInterface $items, FileInterface $file, IiifHelper $iiifhelper, int $i, array &$elements, array $jsondata, array $mediaitem) {
    // WARNING. THIS is a stub method. Please implement the correct render
    // Array based on the needs of your own Formatter when
    // extending this base class.
    $max_width = $this->getSetting('max_width');
    $max_width_css = empty($max_width) ? '100%' : $max_width . 'px';
    $max_height = $this->getSetting('max_height');
    $nodeuuid = $items->getEntity()->uuid();

    $iiifidentifier = urlencode(StreamWrapperManager::getTarget($file->getFileUri()));
    if ($iiifidentifier == NULL || empty($iiifidentifier)) {
      return;
    }

    $uniqueid = 'iiif-' . $items->getName() . '-' . $nodeuuid . '-' . $delta . '-media';
    $elements[$delta]['media' . $i] = [
      '#type' => 'container',
      '#default_value' => $uniqueid,
      '#attributes' => [
        'id' => $uniqueid,
        'class' => [
          'strawberry-media-item',
          'field-iiif',
        ],
        'style' => "width:{$max_width_css}; height:{$max_height}px",
      ],

      '#cache' => [
        'tags' => $file->getCacheTags(),
      ],
    ];
    if (isset($item->_attributes)) {
      $elements[$delta] += ['#attributes' => []];
      $elements[$delta]['#attributes'] += $item->_attributes;
      // Unset field item attributes since they have been included
      // in the formatter output and should not be rendered in the
      // field template.
      unset($item->_attributes);
    }
  }

  /**
   * @param $var1
   * @param mixed $op
   * @param mixed $var2
   *
   * @return bool
   */
  protected function varComp($var1, string $op, $var2) {
    switch ($op) {
      case "==": return $var1 == $var2;
      case "!=": return $var1 != $var2;
      case "!==": return $var1 !== $var2;
      case ">=": return $var1 >= $var2;
      case "<=": return $var1 <= $var2;
      case ">": return $var1 >  $var2;
      case "<": return $var1 <  $var2;
      case "===": return $var1 ===  $var2;
      default: return true;
    }
  }

}
