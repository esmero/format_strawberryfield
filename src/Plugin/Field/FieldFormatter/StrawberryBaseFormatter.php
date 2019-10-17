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

  public static function defaultSettings() {
    return [
      'iiif_base_url' => \Drupal::config('format_strawberryfield.iiif_settings')->get('pub_server_url'),
      'iiif_base_url_internal' => \Drupal::config('format_strawberryfield.iiif_settings')->get('int_server_url'),
      'use_iiif_globals' => true
      ];
  }

  /**
   * Fetches IIIF Urls from Config or settings depending on user input, and returns array of public and internal url.
   *
   * @return array
   *   The form of array is (public, internal)
   */
  public function getIiifUrls() {
    return [
      'public' => boolval($this->getSetting('use_iiif_globals')) === true  ? $this->iiifConfig->get('pub_server_url') : rtrim($this->getSetting('iiif_base_url'), "/"),
      'internal' => boolval($this->getSetting('use_iiif_globals')) === true ? $this->iiifConfig->get('int_server_url') : rtrim($this->getSetting('iiif_base_url_internal'), "/")
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
      $error = $validator->checkUrl($element['#value'], $url_type);
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