<?php

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityInterface;
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
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    $label,
    string $view_mode,
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

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'iiif_base_url' => \Drupal::config('format_strawberryfield.iiif_settings')->get('pub_server_url'),
      'iiif_base_url_internal' => \Drupal::config('format_strawberryfield.iiif_settings')->get('int_server_url'),
      'use_iiif_globals' => TRUE,
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
      'public' => boolval($this->getSetting('use_iiif_globals')) === TRUE ? $this->iiifConfig->get('pub_server_url') : rtrim($this->getSetting('iiif_base_url'), "/"),
      'internal' => boolval($this->getSetting('use_iiif_globals')) === TRUE ? $this->iiifConfig->get('int_server_url') : rtrim($this->getSetting('iiif_base_url_internal'), "/"),
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
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
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

}
