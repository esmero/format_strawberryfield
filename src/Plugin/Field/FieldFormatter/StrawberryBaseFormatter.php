<?php
/**
 * Created by PhpStorm.
 * User: mlongley
 * Date: 9/25/19
 * Time: 8:56 PM
 */

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\format_strawberryfield\Tools\IiifUrlValidator;
use Drupal\Core\Access\AccessResult;



abstract class StrawberryBaseFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * The Config for getting default IIIF settings.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface;
   */
  protected $iiifConfig;

  /**
   * The Config for getting formatter settings based on the View Mode
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface;
   */
  protected $viewModeConfig;

  /**
   *
   * Whether or not new global IIIF urls conflict with ones previously set in config on a new module install.
   * Set here so can be accessed in the static defaultSettings function
   *
   * @var boolean;
   */
//  protected $globalsConflict;

  /**
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   * @param string $label
   *   The formatter settings.
   * @param $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings.
   *   The definition of the field to which the formatter is associated.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    $label,
    $view_mode,
    array $third_party_settings,
    ConfigFactoryInterface $config_factory
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
    $this->viewModeConfig =  $config_factory->getEditable('core.entity_view_display.node.digital_object.'.$view_mode);
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
      $container->get('config.factory')
    );
  }

  // The only situation where this is an issue is if 1) Use Globals was previously selected as TRUE and
  // 2) There were new globals defined in the module's /config/install folder that conflict with the old globals.
  // In this case the old saved globals would be overwritten when the module is reinstalled.
  // In all other cases, like if Use Globals was unset or set to FALSE, then this is not an issue.
  // So, save the old globals as new locals, change Use Globals to false, and notify user.
  //todo: above.
  //todo: move this code somewhere that only fires on install
  //todo: also this loop is wrong. This is firing for each format_strawberryfield on the page. The whole PAGE is the form (for the view mode) and can have multiple or zero format_straberryfields.
  private function hasGlobalsConflict() {
    $viewModeFields =  $this->viewModeConfig->get('third_party_settings.ds.fields');
    foreach ($viewModeFields as $field => $fieldConfig ) {
      if (boolval($fieldConfig['settings']['formatter']['use_iiif_globals']) === true) {
        // if what's saved in config doesn't equal the new settings for either public or internal url
        if ($fieldConfig['settings']['formatter']['iiif_base_url'] !== $this->iiifConfig->get('pub_server_url') || $fieldConfig['settings']['formatter']['iiif_base_url_internal'] !== $this->iiifConfig->get('int_server_url')) {
          // set the formatter to use local, and preserve the old urls. notify the user.
//          \Drupal::messenger()->addMessage(t('You previously set a formatter to use global IIIF Urls, but your globals have changed. If you would like to use the new global urls, please edit below.'), 'status');
          //todo can't access to set the config. this isn't working.
          // preserve the old settings into this form
          $this->setSetting('iiif_base_url', $fieldConfig['settings']['formatter']['iiif_base_url']);
          $this->setSetting('iiif_base_url_internal', $fieldConfig['settings']['formatter']['iiif_base_url_internal']);
          // turn them into local settings. now there shouldn't be a conflict again, and this code will not fire again.
          $this->setSetting('use_iiif_globals', false);
          $fieldConfig['settings']['formatter']['use_iiif_globals'] = false;
//          error_log(var_export($fieldConfig['plugin_id'].' whats the global now?', true));
//          error_log(var_export('1 '. $this->getSetting('use_iiif_globals'), true));
//          error_log(var_export( '2 '.$this->getSetting('iiif_base_url'), true));
//          error_log(var_export( '3 '.$fieldConfig['settings']['formatter']['iiif_base_url'], true));
        }
      }
    }
    return false;
  }

  // todo: related to globals overwrite on new install with new globals. Again this only matters if 1) use globals was set true and 2) the module is reinstalled with new globals, and we need to preserve the old ones.
  // todo: needs to set Setting behind the scenes. This should fire when 'use globals' has been selected and the local inputs are hidden. without this function the values being sent in on form submit are still old and therefore the deep config is not updated. Even though the display knows to serve the global IIIF url config settings. Trying to do it on Ajax because there was no simple form submit to hook into on the 'Update' (for formatter; not Submit of entire View Mode form) to just set the value there. That would be the simplest approach.
  public function setGlobals(array $form, FormStateInterface $form_state) {
//    $form_state_formatter = $form_state->get(['#complete_form']['#field_settings']['display_field_copy:node-field_descriptive_metadata_image']['settings']['plugin_settings']['formatter']);
    // todo: get value of checkbox from just the $form, without help of $element variable and its parents like in validateUrl!?
//    if (boolval($form_state->getValue($checkbox)) === true) {
//      $this->setSetting('iiif_base_url', ...);
//      $this->setSetting('iiif_base_url_internal', ...);
//    }
    return $form;
  }

  public static function defaultSettings() {
    return [
      'iiif_base_url' => \Drupal::config('format_strawberryfield.iiif_settings')->get('pub_server_url'),
      'iiif_base_url_internal' => \Drupal::config('format_strawberryfield.iiif_settings')->get('int_server_url'),
      'use_iiif_globals' => true
      ];
  }

  public function getIiifUrls() {
    return [
      'public' => boolval($this->getSetting('use_iiif_globals')) === true || empty($this->getSetting('iiif_base_url')) ? $this->iiifConfig->get('pub_server_url') : rtrim($this->getSetting('iiif_base_url'), "/"),
      'internal' => boolval($this->getSetting('use_iiif_globals')) === true || empty($this->getSetting('iiif_base_url_internal')) ? $this->iiifConfig->get('int_server_url') : rtrim($this->getSetting('iiif_base_url_internal'), "/")
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
      $summary[] = $this->t('Use IIIF Global Urls? %value', [
        '%value' => boolval($this->getSetting('use_iiif_globals')) === true  ? "Yes." : "No, use custom."
      ]);
      $summary[] = $this->t('IIIF Media Server base URI: %url', [
        '%url' => $this->getIiifUrls()['public']
      ]);
      $summary[] = $this->t('IIIF Media Server Internal base URI: %url', [
        '%url' => $this->getIiifUrls()['internal']
      ]);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element['use_iiif_globals'] = [
        '#type' => 'checkbox',
        '#title' => t('Use Global IIIF Urls'),
        '#description' => t('<b>Current Globals: </b><br>') . 'Public: '.$this->iiifConfig->get('pub_server_url') . '<br>Internal: ' . $this->iiifConfig->get('int_server_url'),
        '#default_value' => $this->getSetting('use_iiif_globals'),
        // Created custom 'data-*' selector rather than using name or id, because the name and id attributes are already set by Drupal and deeply nested for settingsForms. Overriding them causes problems with data submission. Trying to access them in #states is hard. So this is easier. This is valid HTML5.
        '#attributes' => [
          'data-checkbox-selector' => 'use_iiif_globals'
        ],
//      '#ajax' => [
//        'callback' => $this->setGlobals($form, $form_state)
//      ]
      ];
    $element['iiif_base_url'] = [
        '#type' => 'url',
        '#title' => $this->t('Custom base public accessible URL of your IIIF Media Server. Trailing slashes will be removed.'),
        '#default_value' => $this->getIiifUrls()['public'],
        '#states' => [
          'visible' => [
            ':checkbox[data-checkbox-selector="use_iiif_globals"]' => ['unchecked' => true],
          ],
          'required' => [
            ':checkbox[data-checkbox-selector="use_iiif_globals"]' => ['unchecked' => true],
          ]
        ],
        '#element_validate' => [[$this, 'validateUrl']],
    ];
      $element['iiif_base_url_internal'] = [
        '#type' => 'url',
        '#title' => $this->t('Custom base URL of your IIIF Media Server accessible from inside this Webserver. Trailing slashes will be removed.'),
        '#default_value' => $this->getIiifUrls()['internal'],
        '#states' => [
          'visible' => [
            ':checkbox[data-checkbox-selector="use_iiif_globals"]' => ['unchecked' => true],
          ],
          'required' => [
            ':checkbox[data-checkbox-selector="use_iiif_globals"]' => ['unchecked' => true],
          ]
        ],
        '#element_validate' => [[$this, 'validateUrl']],
      ];

    return $element;
  }

  public function validateUrl(&$element, FormStateInterface $form_state, &$complete_form) {
    $parents = $element['#parents'];
    $url_type = end($parents);
    $checkbox = array_slice($parents, 0, -1);
    $checkbox[] = 'use_iiif_globals';

    // Only validate if using locally defined urls. The globals have already been validated.
    if (boolval($form_state->getValue($checkbox)) === false) {
      $validator = new IiifUrlValidator();
      if ($url_type === 'iiif_base_url') {
        $error = $validator->checkPublicUrl($element['#value']);

      } else if ($url_type === 'iiif_base_url_internal') {
        $error = $validator->checkInternalUrl($element['#value']);
      }

      if (!empty($error)) {
        // todo: find the actual place in form_state to place error inline.
        $form_state->setErrorByName($url_type, t("We could not contact your IIIF server: @error", [
          '@error' => $error
        ]));
      }
    }
  }

    /**
   * {@inheritdoc}
   */
  public function view(FieldItemListInterface $items, $langcode = NULL) {
    $elements = parent::view($items, $langcode);
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity) {
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
   * Tries to guess mimetype of external referenced Uris
   *
   * @param string $uripath
   *
   * @return string
   *  A guessed Mimetype
   */
  protected function guessMimeForExternalURI(string $uripath) {
    return \Drupal::service('file.mime_type.guesser')->guess($uripath);
  }

}