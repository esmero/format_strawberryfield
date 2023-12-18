<?php

namespace Drupal\format_strawberryfield_rest_oai_pmh\Form;

use Drupal\format_strawberryfield_rest_oai_pmh\Plugin\OaiMetadataMap\MetadatadisplayTemplateMapDc;
use Drupal\format_strawberryfield_rest_oai_pmh\Plugin\OaiMetadataMap\MetadatadisplayTemplateMapMods;
use Drupal\format_strawberryfield_rest_oai_pmh\Utilities\Utilities as FSROPUtilities;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\ProxyClass\Routing\RouteBuilder;

/**
 * Class RestOaiPmhSettingsForm.
 */
class FormatStrawberryfieldRestOaiPmhSettingsForm extends ConfigFormBase {


  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The path validator service.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected $pathValidator;


  /**
   * The cache discovery service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheDiscovery;

  /**
   * The router builder service.
   *
   * @var \Drupal\Core\ProxyClass\Routing\RouteBuilder
   */
  protected $routerBuilder;

  /**
   * Constructs a \Drupal\system\ConfigFormBase object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler, PathValidatorInterface $path_validator, CacheBackendInterface $cache_discovery, RouteBuilder $router_builder) {
    parent::__construct($config_factory);

    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
    $this->pathValidator = $path_validator;
    $this->cacheDiscovery = $cache_discovery;
    $this->routerBuilder = $router_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
          $container->get('config.factory'),
          $container->get('entity_type.manager'),
          $container->get('module_handler'),
          $container->get('path.validator'),
          $container->get('cache.discovery'),
          $container->get('router.builder')
      );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'format_strawberryfield_rest_oai_pmh.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rest_oai_pmh_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('format_strawberryfield_rest_oai_pmh.settings');

    $query = \Drupal::entityQuery('metadatadisplay_entity')
      ->accessCheck()->condition('mimetype', 'application/xml');
    $results = $query->execute();
    $entities = \Drupal::entityTypeManager()->getStorage('metadatadisplay_entity')->loadMultiple($results);
    $options = [];
    /** @var \Drupal\format_strawberryfield\Entity\MetadataDisplayEntity $entity */
    foreach($entities as $id => $entity) {
      $options[$id] = $entity->label();
    }
    $form['mods'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('MODS Configuration'),
      //      '#description' => $this->t(''),

    ];
    $form['dc'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Dublin Core Configuration'),
    ];
    $form['mods']['mods-template'] = [
      '#type' => 'select',
      '#empty_value' => '',
      '#options' => $options,
      '#title' => t('MODS template'),
      '#description' => t('Select a metadatadisplay template to transform strawberryfield json data into OAI-PMH MODS xml'),
      '#default_value' => $config->get('mods-template') ?? '',
      '#required' => TRUE,
    ];
    $form['mods']['mods-wrapper-elements'] = [
      '#type' => 'textarea',
      '#empty_value' => '',
      '#title' => 'MODS wrapper elements',
      '#description' => 'Provide attributes and values in json format',
      '#default_value' =>  json_encode($config->get('mods-wrapper-elements') ?? MetadatadisplayTemplateMapMods::defaultMetadataWrapperElements(), JSON_PRETTY_PRINT),
      '#required' => TRUE,
      '#rows' => 8,
    ];
    $form['dc']['dc-template'] = [
      '#type' => 'select',
      '#empty_value' => '',
      '#options' => $options,
      '#title' => t('Dublin Core template'),
      '#description' => t('Select a metadatadisplay template to transform strawberryfield json data into OAI-PMH Dublin Core xml'),
      '#default_value' => $config->get('dc-template') ?? '',
      '#required' => TRUE,
    ];
    $form['dc']['dc-wrapper-elements'] = [
      '#type' => 'textarea',
      '#empty_value' => '',
      '#title' => 'DC wrapper elements',
      '#description' => 'Provide attributes and values in json format',
      '#default_value' =>  json_encode($config->get('dc-wrapper-elements') ?? MetadatadisplayTemplateMapDc::defaultMetadataWrapperElements(), JSON_PRETTY_PRINT),
      '#required' => TRUE,
      '#rows' => 8,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    foreach(['mods', 'dc'] as $prefix) {
      $key = $prefix . "-wrapper-elements";
      $json = $form_state->getValue($key);
      $wrapper_elements = json_decode($json);
      if(empty($wrapper_elements)) {
        $form_state->setErrorByName($key, $this->t('Invalid JSON for @key: "@error".', ['@key' => $key, '@error' => json_last_error_msg()] ));
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $config = $this->config('format_strawberryfield_rest_oai_pmh.settings');
    foreach(['mods', 'dc'] as $prefix) {
      $key = $prefix . '-template';
      $template = $form_state->getValue($key);
      $config->set($key, $template);
      $key = $prefix . "-wrapper-elements";
      $wrapper_elements = json_decode($form_state->getValue($key), TRUE);
      $config->set($key, $wrapper_elements);
    }
    $config->save();
  }
}
