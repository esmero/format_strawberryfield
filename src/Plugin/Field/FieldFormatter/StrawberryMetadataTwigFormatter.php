<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:56 PM
 */

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Template\TwigEnvironment;
use Twig_Error_Syntax;
use Twig_Environment;
use Twig_Error_Runtime;
use Twig_Loader_Array;

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
class StrawberryMetadataTwigFormatter extends FormatterBase implements ContainerFactoryPluginInterface {


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
   * @var \Drupal\Core\Template\TwigEnvironment
   */
  protected $twig;

  /**
   * Constructs a FormatterBase object.
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
   *   Any third party settings.
   */
  /**
   * StrawberryMetadataTwigFormatter constructor.
   *
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   * @param string $label
   *   The formatter settings.
   * @param $view_mode
   *   The view mode.
   * @param array
   *   Any third party settings.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current User
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The Entity Type manager
   * @param \Drupal\Core\Template\TwigEnvironment $twigEnvironment
   *   The Loaded twig Environment
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, AccountInterface $current_user, EntityTypeManagerInterface $entity_type_manager, TwigEnvironment $twigEnvironment) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
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
      $container->get('twig')
    );
  }



  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'label' => 'Descriptive Metadata',
      'specs' => 'http://schema.org',
      'metadatadisplayentity_id' => 'Media',
      'metadatadisplayentity_uselabel' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $entity = NULL;
    if ($this->getSetting('metadatadisplayentity_id')) {
      $entity = $this->entityTypeManager->getStorage('metadatadisplay_entity')->load($this->getSetting('metadatadisplayentity_id'));
    }

    return [
      'customtext' => [
        '#type' => 'item',
        '#markup' => '<h3>Use this form to select the template for your metadata.</h3><p>Several templates such as MODS 3.6 and a simple Object Description ship with Archipelago. To design your own template for any metadata standard you like, or see the full list of existing templates, visit <a href="/metadatadisplay/list">/metadatadisplay/list</a>. </p>',
      ],
      'metadatadisplayentity_id' => [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('Choose your metadata template (Start typing! Autocomplete.)'),
        '#target_type' => 'metadatadisplay_entity',
        '#description' => 'Metadata template name',
        '#selection_handler' => 'default:metadatadisplay',
        '#validate_reference' => FALSE,
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
      ]
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    // Get the metadata tempalte's label for display in the summary
    $entity_label = NULL;
    if ($this->getSetting('metadatadisplayentity_id')) {
      $entity = $this->entityTypeManager->getStorage('metadatadisplay_entity')->load($this->getSetting('metadatadisplayentity_id'));
      $entity_label = $entity->label();
    }

    // Build the summary
    $summary = [];
    $summary[] = $this->t('Casts your plain Strawberry Field JSON into other metadata formats using configurable templates.');
    $summary[] = $this->t('Selected: %template', [
      '%template' => $entity_label ? $entity_label : 'None selected. Falling back to default Raw Metadata JSON display.',
    ]);
    return $summary;
  }


  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $label = $this->getSetting('label');
    $specs = $this->getSetting('specs');
    $usemetadatalabel = $this->getSetting('metadatadisplayentity_uselabel');
    $metadatadisplayentity_id = $this->getSetting('metadatadisplayentity_id');
    $nodeuuid = $items->getEntity()->uuid();
    $nodeid = $items->getEntity()->id();
    $fieldname = $items->getName();

    foreach ($items as $delta => $item) {
      $main_property = $item->getFieldDefinition()->getFieldStorageDefinition()->getMainPropertyName();
      $value = $item->{$main_property};
      if (empty($value)) {
        continue;
      }
      if (empty($metadatadisplayentity_id)) {
        continue;
      }
      /* @var \Drupal\format_strawberryfield\Entity\MetadataDisplayEntity; */
      $metadatadisplayentity = $this->entityTypeManager->getStorage('metadatadisplay_entity')->load($this->getSetting('metadatadisplayentity_id'));
      if ($metadatadisplayentity == NULL) {
        continue;
      }

      $jsondata = json_decode($item->value, true);
      // Probably good idea to strip our own keys here
      // @TODO remove private access to keys

      // @TODO use future flatversion precomputed at field level as a property
      $json_error = json_last_error();
      if ($json_error != JSON_ERROR_NONE) {
        $message= $this->t('We could had an issue decoding as JSON your metadata for node @id, field @field',
          [
            '@id' => $nodeid,
            '@field' => $items->getName(),
          ]);
        return $elements[$delta] = ['#markup' => $this->t('ERROR')];
      }

      try {
        $twigtemplate = $metadatadisplayentity->get('twig')->getValue();
        $twigtemplate = !empty($twigtemplate) ? $twigtemplate[0]['value']: "{{ field.label }}";

        //  $markup = $environment->renderInline($element['#template'], $element['#context']);
        // @TODO So we can generate two type of outputs here,
        // A) HTML visible (like smart metadata displays)
        // B) Downloadable formats.
        // C) Embeded (but hidden JSON-LD, etc)
        // So we need to make sure People can "tag" that need.

        $templaterenderelement = [
          '#type' => 'inline_template',
          '#template' => $twigtemplate,
          '#context' => [
            'data' => $jsondata,
            'node' => $items->getEntity(),
          ],
        ];

        if ($usemetadatalabel){
          $elements[$delta]['container'] = [
            '#type' => 'details',
            '#title' => $metadatadisplayentity->toLink()->getText(),
            '#open' => FALSE, // Controls the HTML5 'open' attribute. Defaults to FALSE.
            'content' => $templaterenderelement,
          ];
        }
        else {
          $elements[$delta]['content'] = $templaterenderelement;
        }

      } catch (\Exception $e) {
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

  public function setSetting($key, $value) {
    /** @var \Drupal\Core\Template\TwigEnvironment $environment */
    $environment = \Drupal::service('twig');
    $environment->invalidate();

    return parent::setSetting(
      $key,
      $value
    );
  }


  /**
   * Use to process a Template directly.
   *
   * @param string $twigtemplate
   * @param array $context
   * @param boolean $removeHTML
   *
   * @return \Drupal\Core\Render\Markup
   */
  protected function twig_process(string $twigtemplate, array $context = [], $removeHTML = FALSE ) {
    $build = [
      '#type' => 'inline_template',
      '#template' => $twigtemplate,
      '#context' => $context,
    ];

    return \Drupal::service('renderer')->renderPlain($build);
  }

}
