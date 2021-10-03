<?php

/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:56 PM
 */

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Twig based Strawberry Field formatter.
 *
 * @FieldFormatter(
 *   id = "strawberry_metadata_formatter",
 *   label = @Translation("Strawberry Field Formatter for Custom Metadata Templates"),
 *   class = "\Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryMetadataTwigFormatter",
 *   field_types = {
 *     "strawberryfield_field"
 *   },
 *   quickedit = {
 *     "editor" = "disabled"
 *   }
 * )
 */
class StrawberryMetadataTwigFormatter extends StrawberryBaseFormatter implements ContainerFactoryPluginInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The Twig environment.
   *
   * @var \Drupal\Core\Template\TwigEnvironment
   */
  protected $twig;

  /**
   * StrawberryMetadataTwigFormatter constructor.
   *
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings settings.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current User.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The Entity Type manager.
   * @param \Drupal\Core\Template\TwigEnvironment $twigEnvironment
   *   The Loaded twig Environment.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The Config factory.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, AccountInterface $current_user, EntityTypeManagerInterface $entity_type_manager, TwigEnvironment $twigEnvironment, ConfigFactoryInterface $config_factory) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings, $config_factory);
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->twig = $twigEnvironment;
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
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('twig'),
      $container->get('config.factory')

    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return parent::defaultSettings() + [
      'label' => 'Descriptive Metadata',
      'specs' => 'http://schema.org',
      'metadatadisplayentity_uuid' => NULL,
      'metadatadisplayentity_uselabel' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $entity = NULL;
    if ($this->getSetting('metadatadisplayentity_uuid')) {
      $entities = $this->entityTypeManager->getStorage('metadatadisplay_entity')->loadByProperties(['uuid' => $this->getSetting('metadatadisplayentity_uuid')]);
      $entity = reset($entities);
    }

    return [
      'customtext' => [
        '#markup' => '<h3>Use this form to select the template for your metadata.</h3><p>Several templates such as MODS 3.6 and a simple Object Description ship with Archipelago. To design your own template for any metadata standard you like, or see the full list of existing templates, visit <a href="/metadatadisplay/list">/metadatadisplay/list</a>. </p>',
      ],
      'metadatadisplayentity_uuid' => [
        '#type' => 'sbf_entity_autocomplete_uuid',
        '#title' => $this->t('Choose your metadata template (Start typing! Autocomplete.)'),
        '#target_type' => 'metadatadisplay_entity',
        '#description' => 'Metadata template name',
        '#selection_handler' => 'default:metadatadisplay',
        '#validate_reference' => TRUE,
        '#required' => TRUE,
        '#default_value' => $entity,
      ],
      'specs' => [
        '#type' => 'url',
        '#title' => $this->t('URL that helps your visitors (and you) understand the metadata displayed'),
        '#default_value' => $this->getSetting('specs'),
        '#required' => TRUE,
      ],
      'label' => [
        '#type' => 'textfield',
        '#title' => $this->t('Public facing Label for this Metadata Display'),
        '#default_value' => $this->getSetting('label'),
        '#required' => TRUE,
      ],
      'metadatadisplayentity_uselabel' => [
        '#type' => 'checkbox',
        '#title' => t('Use also the Metadata Display Name as label?'),
        '#description' => t('If enabled we will also generate a collapsible container around the display for you.'),
        '#default_value' => $this->getSetting('metadatadisplayentity_uselabel'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    // Get the metadata template's label for display in the summary.
    $entity_label = NULL;
    if ($this->getSetting('metadatadisplayentity_uuid')) {
      $entities = $this->entityTypeManager->getStorage('metadatadisplay_entity')->loadByProperties(['uuid' => $this->getSetting('metadatadisplayentity_uuid')]);
      $entity = reset($entities);
      if ($entity) {
        $entity_label = $entity->label();
      }
    }

    // Build the summary.
    $summary = [];
    $summary[] = $this->t('Casts your plain Strawberry Field JSON into other metadata formats using configurable templates.');
    $summary[] = $this->t('Selected: %template', [
      '%template' => $entity_label ? $entity_label : 'None selected. Please configure this formatter by providing one in the configuration form.',
    ]);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $usemetadatalabel = $this->getSetting('metadatadisplayentity_uselabel');
    $metadatadisplayentity_uuid = $this->getSetting('metadatadisplayentity_uuid');
    $nodeid = $items->getEntity()->id();

    foreach ($items as $delta => $item) {
      $main_property = $item->getFieldDefinition()->getFieldStorageDefinition()->getMainPropertyName();
      $value = $item->{$main_property};
      if (empty($value)) {
        continue;
      }
      if (empty($metadatadisplayentity_uuid)) {
        continue;
      }


      $metadatadisplayentities = $this->entityTypeManager->getStorage('metadatadisplay_entity')->loadByProperties(['uuid' => $this->getSetting('metadatadisplayentity_uuid')]);
      /** @var \Drupal\format_strawberryfield\Entity\MetadataDisplayEntity $metadatadisplayentity */
      $metadatadisplayentity = reset($metadatadisplayentities);
      if ($metadatadisplayentity == NULL) {
        continue;
      }

      $jsondata = json_decode($item->value, TRUE);

      // Probably good idea to strip our own keys here.
      // @TODO remove private access to keys

      // @TODO use future flatversion precomputed at field level as a property
      $json_error = json_last_error();
      if ($json_error != JSON_ERROR_NONE) {
        $message = $this->t('We could had an issue decoding as JSON your metadata for node @id, field @field',
          [
            '@id' => $nodeid,
            '@field' => $items->getName(),
          ]);
        return $elements[$delta] = ['#markup' => $message];
      }

      try {
        // @TODO So we can generate two type of outputs here,
        // A) HTML visible (like smart metadata displays)
        // B) Downloadable formats.
        // C) Embeded (but hidden JSON-LD, etc)
        // So we need to make sure People can "tag" that need.

        // Order as: structures based on sequence key
        // We will assume here people are using our automatic keys
        // If they are using other ones, they will have to apply ordering
        // Directly on their Twig Templates.
        $ordersubkey = 'sequence';
        foreach (StrawberryfieldJsonHelper::AS_FILE_TYPE as $key) {
          StrawberryfieldJsonHelper::orderSequence($jsondata, $key, $ordersubkey);
        }
        $context = [
          'data' => $jsondata,
          'node' => $items->getEntity(),
          'iiif_server' => $this->getIiifUrls()['public'],
        ];
        $original_context = $context;

        // Allow other modules to provide extra Context!
        // Call modules that implement the hook, and let them add items.
        \Drupal::moduleHandler()->alter('format_strawberryfield_twigcontext', $context);
        // In case someone decided to wipe the original context?
        // We bring it back!
        $context = $context + $original_context;
        $templaterenderelement = $metadatadisplayentity->processHtml($context);

        if ($usemetadatalabel) {
          $elements[$delta]['container'] = [
            '#type' => 'details',
            '#title' => $metadatadisplayentity->toLink()->getText(),
            '#open' => FALSE,
            'content' => $templaterenderelement,
          ];
        }
        else {
          $elements[$delta]['content'] = $templaterenderelement;
        }

      }
      catch (\Exception $e) {
        // Render each element as markup.
        $elements[$delta] = [
          '#markup' => json_encode(
            json_decode($item->value, TRUE),
            JSON_PRETTY_PRINT
          ),
        ];
      }
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function setSetting($key, $value) {
    $this->twig->invalidate();

    return parent::setSetting($key, $value);
  }

}
