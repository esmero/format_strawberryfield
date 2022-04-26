<?php

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\format_strawberryfield\EmbargoResolverInterface;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Seboettg\CiteProc\StyleSheet;
use Seboettg\CiteProc\CiteProc;
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
        'metadatadisplayentity_uselabel' => TRUE,
        'citationstyle' => NULL,
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
    //$citation_locales_file = '/var/www/html/vendor/citation-style-language/locales/locales.json';
    //$citation_locales_file_contents = file_get_contents($citation_locales_file);
    //$citation_locales = json_decode($citation_locales_file_contents, true);
    //$locale_options = array();
    //foreach($citation_locales['language-names'] as $key => $value) {
    //  $locale_string = $value[1];
    //  $locale_options[$key] = $this->t($locale_string);
    //}
    // There's a better way to get this directory
    $citation_style_directory = '/var/www/html/vendor/citation-style-language/styles-distribution';
    // Get the list of style files.
    $style_list = $this->fileSystem->scanDirectory($citation_style_directory, '/\.(csl)$/i', ['recurse' => FALSE, 'key' => 'name']);
    # Generate a list of select options and push in the styles.
    $style_options = array();
    foreach($style_list as $style) {
      $style_name = $style->name;
      $style_options[$style_name] = $this->t($style_name);
    }
    // Alphabetize them.
    asort($style_options);
    //asort($locale_options);
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
      'citationstyle' => [
        '#type' => 'select',
        '#title' => $this->t('Choose a citation style (you may select multiple).'),
        '#description' => 'Citation Style',
        '#validate_reference' => TRUE,
        '#required' => TRUE,
        '#multiple' => TRUE,
        '#options' => $style_options,
        '#default_value' => $this->getSetting('citationstyle'),
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
    if ($this->getSetting('citationstyle')) {
      $citationstyles = $this->getSetting('citationstyle');
    }
    // Build the summary.
    $summary = [];
    $summary[] = $this->t('Uses your selected template and style(s) to generate citations.');
    $summary[] = $this->t('Selected: %template', [
      '%template' => $entity_label ? $entity_label : 'None selected. Please configure this formatter by providing one in the configuration form.',
    ]);
    $summary[] = $this->t('Selected: %styles', [
      '%styles' => $citationstyles ? implode(', ', $citationstyles) : 'None selected. Please configure this formatter by providing at least one in the configuration form.',
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
    $embargo_context = [];
    $embargo_tags = [];

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
      $embargo_info = $this->embargoResolver->embargoInfo($items->getEntity()->uuid(), $jsondata);
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
        if ($embargo_info[2]) {
          $embargo_context[] = 'ip';
        }
      }
      else {
        $context_embargo['data_embargo']['embargoed'] = $embargo_info;
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
          ] + $context_embargo;
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

    // Example json string for testing until actual data gets pulled in from a template.
    $example = '[
  {
    "author": [
      {
        "family": "Doe",
        "given": "James",
        "suffix": "III"
      }
    ],
    "id": "ITEM-1",
    "issued": {
    "date-parts": [
      [
        "2001"
      ]
    ]
    },
    "title": "My Anonymous Heritage",
    "type": "book"
  },
  {
    "author": [
      {
        "family": "Anderson",
        "given": "John",
        "id": "anderson.j"
      },
      {
        "family": "Brown",
        "given": "John",
        "id": "brown.j"
      }
    ],
    "issued": {
    "date-parts": [
      [
        "1998"
      ]
    ]
    },
    "id": "ITEM-2",
    "type": "book",
    "title": "Two authors writing a book"
  },
  {
    "DOI": "10.1016/j.jhydrol.2008.05.025",
    "ISSN": "0022-1694",
    "author": [
      {
        "family": "Cole",
        "given": "Steven J.",
        "id": "steven.j"
      },
      {
        "family": "Moore",
        "given": "Robert",
        "id": "moore.r"
      }
    ],
    "container-title": "Journal of Hydrology",
    "id": "ITEM-3",
    "issue": "3-4",
    "issued": {
    "date-parts": [
      [
        2008
      ]
    ]
    },
    "page": "159-181",
    "title": "Hydrological modelling using raingauge- and radar-based estimators of areal rainfall",
    "type": "article-journal",
    "url": "http://www.sciencedirect.com/science/article/pii/S0022169408002394",
    "volume": "358"
  },
  {
    "abstract": "The PUMA project fosters the Open Access movement und aims at a better support of the researcher’s publication work. PUMA stands for an integrated solution, where the upload of a publication results automatically in an update of both the personal and institutional homepage, the creation of an entry in a social bookmarking systems like BibSonomy, an entry in the academic reporting system of the university, and its publication in the institutional repository. In this poster, we present the main features of our solution.",
    "annote": "",
    "author": [
      {
        "family": "Benz",
        "given": "Dominik",
        "id": "benz"
      },
      {
        "family": "Hotho",
        "given": "Andreas",
        "id": "hotho"
      },
      {
        "family": "Jäschke",
        "given": "Robert",
        "id": "rjaeschke"
      },
      {
        "family": "Stumme",
        "given": "Gerd",
        "id": "stumme"
      },
      {
        "family": "Halle",
        "given": "Axel"
      },
      {
        "family": "Lima",
        "given": "Angela Gerlach Sanches"
      },
      {
        "family": "Steenweg",
        "given": "Helge"
      },
      {
        "family": "Stefani",
        "given": "Sven"
      },
      {
        "family": "Dietrich",
        "given": "Bernhard"
      }
    ],
    "citation-label": "benz2010academic",
    "collection-title": "Lecture Notes in Computer Science",
    "container-title": "Proceedings of the European Conference on Research and Advanced Technology for Digital Libraries",
    "container-title-short": "ECDL",
    "edition": "",
    "event-date": {
    "date-parts": [
      [
        "2010"
      ]
    ],
      "literal": "2010"
    },
    "event-place": "Berlin/Heidelberg",
    "id": "ITEM-4",
    "interhash": "db94bafecb815048ede11f6d28e5a9f1",
    "intrahash": "11bdf4636bc92aed96461eace25484f7",
    "issue": "",
    "issued": {
    "date-parts": [
      [
        "2010"
      ]
    ],
      "literal": "2010"
    },
    "keyword": "2010 ecdl myown puma",
    "number-of-pages": "3",
    "page": "417--420",
    "page-first": "417",
    "publisher": "Springer",
    "publisher-place": "Berlin/Heidelberg",
    "status": "",
    "title": "Academic Publication Management with PUMA - collect, organize and share publications",
    "type": "paper-conference",
    "volume": "6273"
  }
]';
    try {
      // Get styles selected from formatter settings.
      $selected_styles = $this->settings['citationstyle'];
      $data = json_decode($example);
      $rendered_bibliography = '';

      // Following function taken whole cloth from here: https://stackoverflow.com/questions/3618381/parse-a-css-file-with-php
      function parse($css){
        preg_match_all( '/(?ims)([a-z0-9\s\.\:#_\-@,]+)\{([^\}]*)\}/', $css, $arr);
        $result = array();
        foreach ($arr[0] as $i => $x){
          $selector = trim($arr[1][$i]);
          $rules = explode(';', trim($arr[2][$i]));
          $rules_arr = array();
          foreach ($rules as $strRule){
            if (!empty($strRule)){
              $rule = explode(":", $strRule);
              $rules_arr[trim($rule[0])] = trim($rule[1]);
            }
          }

          $selectors = explode(',', trim($selector));
          foreach ($selectors as $strSel){
            $result[$strSel] = $rules_arr;
          }
        }
        return $result;
      }

      // Loop through each style, render it as a CSS block, convert to
      // array, and process as inline style tag against rendered HTML.
      foreach ($selected_styles as $selected_style) {
        $style = StyleSheet::loadStyleSheet($selected_style);
        $citeProc = new CiteProc($style);
        $bibliography = $citeProc->render($data, "bibliography");
        $cssStyles = $citeProc->renderCssStyles();
        $parsedStyle = parse($cssStyles);
        $processed_bibliography = $bibliography;
        // Deconstruct the CSS rules by selector.
        foreach ($parsedStyle as $css_prop => $css_statements) {
          // Remove dot from class to match against HTML block.
          $css_selector = ltrim($css_prop, '.');
          // Get the length to offset below and insert the style tag inline.
          $css_selector_len = strlen($css_selector);
          // Check if the selector exists in the HTML block.
          $pos = strpos($processed_bibliography,$css_selector);
          if($pos !== false) {
            // Construct the inline style tag string to insert.
            $inline_rule = ' style="';
            foreach($css_statements as $css_property => $css_value) {
              $inline_rule .= $css_property . ':' . $css_value . ';';
            }
            $inline_rule .= '"';
            // For each instance of the tag match insert the inline style.
            $start = 0;
            while(($inline_pos = strpos(($processed_bibliography),$css_selector,$start)) !== false) {
              // The below offset makes the assumption that the matched tag is the only class, but is that right?
              $processed_bibliography = substr_replace($processed_bibliography, $inline_rule, $inline_pos + $css_selector_len + 1, 0);
              $start = $inline_pos + 1;
            }
          }
        }
        // For now append to an HTML string for rendering.
        $rendered_bibliography .= '<br><h5>' . $selected_style . '</h5>' . $processed_bibliography;
      }
    } catch (Exception $e) {
      echo $e->getMessage();
    }
    $elements[$delta] = [
      //'#markup' => $rendered_bibliography,
      // The below has to be used so style tags don't get stripped in the render process.
      '#markup' => \Drupal\Core\Render\Markup::create($rendered_bibliography)
    ];
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
