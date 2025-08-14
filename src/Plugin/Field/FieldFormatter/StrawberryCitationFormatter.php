<?php

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\format_strawberryfield\EmbargoResolverInterface;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\format_strawberryfield\CiteProc\Render;
use Drupal\Core\File\FileSystemInterface;

/**
 * Simplistic Strawberry Field formatter.
 *
 * @FieldFormatter(
 *   id = "strawberry_citation_formatter",
 *   label = @Translation("Strawberry Field Simple Citation Formatter"),
 *   class = "\Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryCitationFormatter",
 *   field_types = {
 *     "strawberryfield_field"
 *   },
 *   quickedit = {
 *     "editor" = "disabled"
 *   }
 * )
 */
class StrawberryCitationFormatter extends StrawberryBaseFormatter {

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
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  protected $cslRoot = DRUPAL_ROOT . '/../vendor/citation-style-language';

  protected $cslStylesPath = DRUPAL_ROOT . '/../vendor/citation-style-language/styles';
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
   * @param \Drupal\format_strawberryfield\EmbargoResolverInterface $embargo_resolver
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, AccountInterface $current_user, EntityTypeManagerInterface $entity_type_manager, TwigEnvironment $twigEnvironment, ConfigFactoryInterface $config_factory, EmbargoResolverInterface $embargo_resolver, FileSystemInterface $file_system) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings, $config_factory, $embargo_resolver, $current_user);
    $this->entityTypeManager = $entity_type_manager;
    $this->twig = $twigEnvironment;
    $this->fileSystem = $file_system;
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
      $container->get('config.factory'),
      $container->get('format_strawberryfield.embargo_resolver'),
      $container->get('file_system')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return parent::defaultSettings() + [
        'label' => 'Descriptive Metadata',
        'metadatadisplayentity_uuid' => NULL,
        'citationstyle' => NULL,
        'localekey' => NULL
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
    $csl_root = $this->cslRoot;
    $csl_exists = is_dir($csl_root);
    $style_options = array();
    if (!$csl_exists) {
      $this->messenger()->addWarning('Please run "drush archipelago-download-citeproc-dependencies" before using this formatter.', 'warning');
    }
    else {
      $citation_style_directory = $this->cslStylesPath;
      // Get the list of style files.
      $style_list = $this->fileSystem->scanDirectory($citation_style_directory, '/\.(csl)$/i', ['recurse' => FALSE, 'key' => 'name']);
      // Generate a list of select options and push in the styles.
      foreach($style_list as $style) {
        $style_name = $style->name;
        $style_xml = simplexml_load_file($style->uri);
	// Check if the bibliography node exists and only add to the list if it does.
        $style_bibliography_exists = isset($style_xml->bibliography);
        if($style_bibliography_exists) {
          $style_title = $style_xml->info->title->__toString();
          $style_options[$style_name] = $this->t($style_title);
        }
      }
      // Alphabetize them.
      asort($style_options);
    }
    return [
      'customtext' => [
        '#markup' => '<h3>Use this form to select options for your citations.</h3>',
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
        '#maxlength' => 300,
      ],
      'citationstyle' => [
        '#type' => 'select',
        '#title' => $this->t('Choose a citation style (you may select multiple).'),
        '#description' => 'Citation Style',
        '#validate_reference' => TRUE,
        '#required' => TRUE,
        '#multiple' => TRUE,
        '#options' => $style_options,
        '#disabled' => !$csl_exists,
        '#default_value' => $this->getSetting('citationstyle'),
      ],
      'localekey' => [
        '#type' => 'textfield',
        '#title' => $this->t('Provide a metadata key to use as the locale (language) for citations.'),
        '#default_value' => $this->getSetting('localekey'),
        '#required' => FALSE,
        '#disabled' => !$csl_exists,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    // Get the metadata template's label for display in the summary.
    $entity_label = NULL;
    $citationstyles = NULL;
    $localekey = NULL;
    if ($this->getSetting('metadatadisplayentity_uuid')) {
      $entities = $this->entityTypeManager->getStorage('metadatadisplay_entity')->loadByProperties(['uuid' => $this->getSetting('metadatadisplayentity_uuid')]);
      $entity = reset($entities);
      if ($entity) {
        $entity_label = $entity->label();
      }
    }
    if ($this->getSetting('citationstyle')) {
      $citationstyles = $this->getSetting('citationstyle');
    }
    if ($this->getSetting('localekey')) {
      $localekey = $this->getSetting('localekey');
    }
    // Build the summary.
    $summary = [];
    $summary[] = $this->t('Uses your selected template, style(s), and metadata locale (language) key to generate citations.');
    $summary[] = $this->t('Selected Metadata Template: %template', [
      '%template' => $entity_label ? $entity_label : 'None selected. Please configure this formatter by providing one in the configuration form.',
    ]);
    // Long titles used on form are too long for here so tried using short, but not available for all so sticking with filenames for now.
    $summary[] = $this->t('Selected Citation Style(s): %styles', [
      '%styles' => $citationstyles ? implode(', ', $citationstyles) : 'None selected. Please configure this formatter by providing at least one in the configuration form.',
    ]);
    $summary[] = $this->t('Selected Metadata key for locale (language): %locale', [
      '%locale' => $localekey ? $localekey : 'None selected. The default will be used.',
    ]);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $metadatadisplayentity_uuid = $this->getSetting('metadatadisplayentity_uuid');
    $hide_on_embargo =  $this->getSetting('hide_on_embargo') ?? FALSE;
    $nodeid = $items->getEntity()->id();
    $embargo_context = [];
    $embargo_tags = [];
    $nodeuuid = $items->getEntity()->uuid();

    foreach ($items as $delta => $item) {
      $uniqueid =
        'bibliography-' . $items->getName(
        ) . '-' . $nodeuuid . '-' . $delta;
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
        $message = $this->t('There was an issue decoding your metadata as JSON for node @id, field @field',
          [
            '@id' => $nodeid,
            '@field' => $items->getName(),
          ]);
        return $elements[$delta] = ['#markup' => $message];
      }
      $embargo_info = $this->embargoResolver->embargoInfo($items->getEntity(), $jsondata);
      // This one is for the Twig template
      // We do not need the IP here. No use of showing the IP at all?
      $context_embargo = ['data_embargo' => ['embargoed' => false, 'until' => NULL]];

      if (is_array($embargo_info)) {
        $embargoed = $embargo_info[0];
        $context_embargo['data_embargo']['embargoed'] = $embargoed;

        $embargo_tags[] = 'format_strawberryfield:all_embargo';
        if ($embargo_info[1]) {
          $embargo_tags[]= 'format_strawberryfield:embargo:'.$embargo_info[1];
          $context_embargo['data_embargo']['until'] = $embargo_info[1];
        }
        if ($embargo_info[2] || ($embargo_info[3] == FALSE)) {
          $embargo_context[] = 'ip';
        }
      }
      else {
        $context_embargo['data_embargo']['embargoed'] = $embargo_info;
      }

      try {
        if (!$embargoed || ($embargoed && !$hide_on_embargo)) {
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
            ] + $context_embargo;
          $original_context = $context;

          // Allow other modules to provide extra Context!
          // Call modules that implement the hook, and let them add items.
          \Drupal::moduleHandler()
            ->alter('format_strawberryfield_twigcontext', $context);
          // In case someone decided to wipe the original context?
          // We bring it back!
          $context = $context + $original_context;
          // Render data from metadata template into JSON string.
          $rendered_json_string = $metadatadisplayentity->renderNative($context);

          // Get styles selected from formatter settings.
          $selected_styles = $this->settings['citationstyle'];
          // Get language key from settings.
          $selected_locale_key = FALSE;
          if ($this->getSetting('localekey')) {
            $selected_locale_key = $this->settings['localekey'];
          }
          $selected_locale_value = array_key_exists($selected_locale_key, $jsondata) ? $jsondata[$selected_locale_key] : FALSE;
          if ($selected_locale_value) {
            $available_locale = trim($selected_locale_value);
          }
          elseif ($langcode) {
            $available_locale = trim($langcode);
          }

          $data = json_decode($rendered_json_string);
          $json_error = json_last_error();
          if ($json_error != JSON_ERROR_NONE) {
            $message = $this->t('There was an issue decoding your metadata as JSON for node @id, field @field',
              [
                '@id' => $nodeid,
                '@field' => $items->getName(),
              ]);
            return $elements[$delta] = ['#markup' => $message];
          }
          $render = new Render();
          $bibliography = $render->bibliography($available_locale, $selected_styles, $data);
          $elements[$delta] = [
            '#type' => 'container',
            '#attributes' => [
              'id' => 'bibliography' . $uniqueid,
              'class' => ['bibliography'],
            ]
          ];
          $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/citations_strawberry';
          $elements[$delta]['bibliography'] = [
            '#markup' => \Drupal\Core\Render\Markup::create($bibliography),
          ];
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
  public function setSetting($key, $value) {
    $this->twig->invalidate();

    return parent::setSetting($key, $value);
  }

}
