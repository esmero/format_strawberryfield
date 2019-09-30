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
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ConnectException;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;


abstract class StrawberryBaseFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * The HTTP client to check if IIIF servers are there.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The Config Factory for getting default IIIF config settings.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface;
   */
  protected $configFactory;


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
   * @param \GuzzleHttp\ClientInterface $httpClient
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
    ClientInterface $httpClient,
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
    $this->httpClient = $httpClient;
    $this->configFactory = $config_factory->get('format_strawberryfield.iiif_settings');
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
      $container->get('http_client'),
      $container->get('config.factory')
    );
  }

  public static function defaultSettings() {
    return [
      'iiif_base_url' => \Drupal::config('format_strawberryfield.iiif_settings')->getOriginal('pub_server_url', FALSE),
      'iiif_base_url_internal' => \Drupal::config('format_strawberryfield.iiif_settings')->getOriginal('int_server_url', FALSE),
      ];
  }

  /**
   * {@inheritdoc}
   */
  public function IiifsettingsForm(array $form, FormStateInterface $form_state) {

    //todo: URL VS TEXTFIELD ?? what is the difference.
    $element['iiif_base_url'] = [
        '#type' => 'url',
        '#title' => $this->t('Base Public accesible URL of your IIIF Media Server'),
        '#default_value' => $this->getSetting('iiif_base_url'),
        '#prefix' => '<div id="wrapper_iiif_base_url">',
        '#suffix' => '</div>',
        '#required' => TRUE
      ];
      $element['iiif_base_url_internal'] = [
        '#type' => 'url',
        '#title' => $this->t('Base URL of your IIIF Media Server accesible from inside this Webserver'),
        '#default_value' => $this->getSetting('iiif_base_url_internal'),
        '#prefix' => '<div id="wrapper_iiif_base_url_internal">',
        '#suffix' => '</div>',
        '#required' => TRUE,
        '#element_validate' => [[$this, 'validateIiifUrl']],
      ];

      // If either pub_server_url or int_server_url is set in the config, display the reset to global defaults button
      if (!empty($this->configFactory->get('pub_server_url')) || !empty($this->configFactory->get('int_server_url'))) {
        $element['iiif_reset_button'] = [
          '#type' => 'button',
          '#name' => 'iiif_reset_button',
          '#value' => $this->t('Reset to global IIIF Defaults'),
          '#ajax' => [
            'callback' => [$this, 'resetIiifDefaults'],
            'wrapper' => ['wrapper_iiif_base_url', 'wrapper_iiif_base_url_internal']
          ]
        ];
      }

    return $element;
  }


  /**
   * {@inheritdoc}
   */
  public function IiifSettingsSummary() {
    $summary = [];
    $summary[] = $this->t('Displays Static from JSON using a IIIF server endpoint');
    if ($this->getSetting('iiif_base_url')) {
      $summary[] = $this->t('IIIF Media Server base URI: %iiif_base_url', [
        '%iiif_base_url' => $this->getSetting('iiif_base_url'),
      ]);
    }
    if ($this->getSetting('iiif_base_url_internal')) {
      $summary[] = $this->t('Internal IIIF Media Server base URI: %iiif_base_url', [
        '%iiif_base_url' => $this->getSetting('iiif_base_url_internal'),
      ]);
    }
    return $summary;
  }


  /**
   * {@inheritdoc}
   */
  public function resetIiifDefaults(array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $this->setSetting('iiif_base_url', $this->configFactory->get('pub_server_url'));
    $this->setSetting('iiif_base_url_internal', $this->configFactory->get('int_server_url'));
    // In settings forms, the form snippet created in settingsForm() is not at the root of the form, but rather nested deeply. This is why we can't simply say e.g. $form['iiif_base_url'].
    $form['fields'][$field_name]['plugin']['settings_edit_form']['settings']['iiif_base_url']['#value'] = $this->configFactory->get('pub_server_url');
    $form['fields'][$field_name]['plugin']['settings_edit_form']['settings']['iiif_base_url_internal']['#value'] = $this->configFactory->get('int_server_url');
    $response = new AjaxResponse();
    // Custom response. Issue commands that replaces the elements with given #ids. This way we can return changes to more than one element.
    $response->addCommand(new ReplaceCommand('#wrapper_iiif_base_url', $form['fields'][$field_name]['plugin']['settings_edit_form']['settings']['iiif_base_url']));
    $response->addCommand(new ReplaceCommand('#wrapper_iiif_base_url_internal', $form['fields'][$field_name]['plugin']['settings_edit_form']['settings']['iiif_base_url_internal']));

    return $response;
  }


  public function validateIiifUrl(array $form, FormStateInterface $form_state) {
    //todo: @marlo -- also broken, same issue with non-updated form_state
    //todo: @marlo Since this is also used somewhere else and we will need to validate URLs until we grow old, maybe we can join all the http validations into another class.. just an idea.
//    dpm($form_state->get('iiif_base_url_internal'));
    if ($form_state->get('iiif_base_url_internal')) {
      error_log(var_export( 'validate '.$form_state->get('iiif_base_url_internal'), true));
      try {
        $response = $this->httpClient
          ->head(
            $form_state->get('iiif_base_url_internal')
          );
      }
      catch(ConnectException $exception) {
        $responseMessage = $exception->getMessage();
        $form_state->setErrorByName('iiif_base_url_internal', t("We could not contact your Public IIIF server: @error", [
          '@error' => $responseMessage
        ]));
      }
      catch(ClientException $exception) {
        $responseMessage = $exception->getMessage();
        $form_state->setErrorByName('iiif_base_url_internal', t("We could not contact your Public IIIF server: @error", [
          '@error' => $responseMessage
        ]));
      }
      catch (ServerException $exception) {
        $responseMessage = $exception->getMessage();
        $form_state->setErrorByName('iiif_base_url_internal', t("We could not contact your Public IIIF server: @error", [
          '@error' => $responseMessage
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


}