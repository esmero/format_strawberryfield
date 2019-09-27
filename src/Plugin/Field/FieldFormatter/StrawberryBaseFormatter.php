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

/**
 * Base Strawberry Field formatter with dependency injection.
 *
 * @FieldFormatter(
 *   id = "strawberry_base_formatter",
 *   label = @Translation("Strawberry Field Base Formatter for others using IIIF references"),
 *   class = "\Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryBaseFormatter",
 *   field_types = {
 *     "strawberryfield_field"
 *   },
 *   quickedit = {
 *     "editor" = "disabled"
 *   }
 * )
 */

abstract class StrawberryBaseFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * The HTTP client to check if IIIF servers are there.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * @var string
   */
  protected $iiif_pub_server;

  /**
   * @var string
   */
  protected $iiif_int_server;


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

  /**
   * @param string $plugin_id
   * @param array $settings
   * @param mixed $plugin_definition
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param GuzzleHttp\ClientInterface $httpClient
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param string $label
   *   The formatter settings.
   * @param $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings.
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
    $config = $config_factory->get('format_strawberryfield.iiif_settings');
    $this->iiif_pub_server = $config->get('pub_server_url');
    $this->iiif_int_server = $config->get('int_server_url');
  }

  //todo @marlo -- use config factory, Docs say best practice is to use dep injection, not \Drupal::
  public static function defaultSettings() {
    return [
      'iiif_base_url' => \Drupal::config('format_strawberryfield.iiif_settings')->getOriginal('pub_server_url', FALSE),
      'iiif_base_url_internal' => \Drupal::config('format_strawberryfield.iiif_settings')->getOriginal('int_server_url', FALSE),
      ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function IiifSettingsForm(array $form, FormStateInterface $form_state) {
    return [
      'iiif_base_url' => [
        '#type' => 'url',
        '#title' => $this->t('Base Public accesible URL of your IIIF Media Server'),
        '#default_value' => $this->getSetting('iiif_base_url'),
        '#required' => TRUE
      ],
      'iiif_base_url_internal' => [
        '#type' => 'url',
        '#title' => $this->t('Base URL of your IIIF Media Server accesible from inside this Webserver'),
        '#default_value' => $this->getSetting('iiif_base_url_internal'),
        '#required' => TRUE,
        '#element_validate' => [[$this, 'validateIiifUrl']],
      ],
      'iiif_reset_button' => [
        '#type' => 'button',
        '#name' => 'iiif_reset_button',
        '#value' => t('Reset to IIIF Defaults'),
        '#executes_submit_callback' => TRUE,
        '#submit' => [[$this, 'resetIiifDefaults']],
      ],
    ];
  }


  public function validateIiifUrl(array $form, FormStateInterface $form_state) {
    // todo: @marlo -- also broken, same issue with non-updated form_state
    error_log(var_export( $form_state->get('iiif_base_url_internal'), true));
    if ($form_state->get('iiif_base_url_internal')) {
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
  public function resetIiifDefaults(array $form, FormStateInterface $form_state) {
    $this->setSettings([
      'iiif_base_url' => $this->iiif_pub_server,
      'iiif_base_url_internal' => $this->iiif_int_server
    ]);
    //todo: @marlo - this is broken :(
    $form_state->set('iiif_base_url', $this->iiif_pub_server);
    $form_state->set('iiif_base_url_internal', $this->iiif_int_server);
    $form_state->setRebuild(true);
    return $form;
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