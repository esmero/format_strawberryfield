<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:56 PM
 */

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\file\FileInterface;
use Drupal\format_strawberryfield\Tools\IiifHelper;
use Drupal\strawberryfield\Tools\Ocfl\OcflHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;

/**
 * Simplistic PDF Strawberry Field formatter.
 *
 * @FieldFormatter(
 *   id = "strawberry_pdf_formatter",
 *   label = @Translation("Strawberry Field PDF Formatter for IIIF served PDFs"),
 *   class = "\Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryPdfFormatter",
 *   field_types = {
 *     "strawberryfield_field"
 *   },
 *   quickedit = {
 *     "editor" = "disabled"
 *   }
 * )
 */
class StrawberryPdfFormatter extends StrawberryBaseFormatter {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return
      parent::defaultSettings() + [
        'json_key_source' => 'as:document',
        'max_width' => '100%',
        'max_height' => 0,
        'initial_page' => 1,
        'number_pages' => 1,
        'quality' => 'default',
        'rotation' => '0',
      ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    return [
        'json_key_source' => [
          '#type' => 'textfield',
          '#title' => t('JSON Key from where to fetch Document URLs'),
          '#default_value' => $this->getSetting('json_key_source'),
        ],
        'number_documents' => [
          '#type' => 'number',
          '#title' => $this->t('Number of Documents to extract for Key'),
          '#description' => $this->t('Set to 0 for all Documents'),
          '#default_value' => $this->getSetting('number_documents'),
          '#size' => 2,
          '#maxlength' => 4,
          '#min' => 0,
        ],
        'number_pages' => [
          '#type' => 'number',
          '#title' => $this->t('Number of Pages'),
          '#description' => $this->t('Set to 0 for all pages'),
          '#default_value' => $this->getSetting('number_pages'),
          '#size' => 2,
          '#maxlength' => 4,
          '#min' => 0,
        ],
        'initial_page' => [
          '#type' => 'number',
          '#title' => $this->t('Initial Page'),
          '#default_value' => $this->getSetting('initial_page'),
          '#size' => 2,
          '#maxlength' => 4,
          '#min' => 0,
        ],
        'max_width' => [
          '#type' => 'textfield',
          '#title' => $this->t('Maximum width'),
          '#description' => $this->t('Use 0 to force 100% width'),
          '#default_value' => $this->getSetting('max_width'),
          '#size' => 5,
          '#maxlength' => 5,
          '#field_suffix' => $this->t('pixels'),
          '#min' => 0,
          '#required' => TRUE
        ],
        'max_height' => [
          '#type' => 'number',
          '#title' => $this->t('Maximum height in pixels'),
          '#description' => $this->t('Use 0 to force automatic proportional height'),
          '#default_value' => $this->getSetting('max_height'),
          '#size' => 5,
          '#maxlength' => 5,
          '#field_suffix' => $this->t('pixels'),
          '#min' => 0,
          '#required' => TRUE
        ],
      ] + parent::settingsForm($form, $form_state);
  }


  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    if ($this->getSetting('json_key_source')) {
      $summary[] = $this->t('Document fetched from JSON "%json_key_source" key', [
        '%json_key_source' => $this->getSetting('json_key_source'),
      ]);
    }
    if ($this->getSetting('number_pages')) {
      $summary[] = $this->t('Number of pages: "%number"', [
        '%number' => $this->getSetting('number_images'),
      ]);
    }
    if ($this->getSetting('initial_page')) {
      $summary[] = $this->t('Initial page: "%number"', [
        '%number' => $this->getSetting('initial_page'),
      ]);
    }
    $summary[] = $this->t(
      'Maximum size: %max_width x %max_height',
      [
        '%max_width' => (int) $this->getSetting('max_width') == 0 ? '100%' : $this->getSetting('max_width') . ' pixels',
        '%max_height' => (int) $this->getSetting('max_height') == 0 ? 'auto' : $this->getSetting('max_height') . ' pixels',
      ]
    );



    return $summary;
  }


  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {

    $elements = [];
    $upload_keys_string = strlen(trim($this->getSetting('upload_json_key_source') ?? '')) > 0 ? trim($this->getSetting('upload_json_key_source')) : '';
    $upload_keys = explode(',', $upload_keys_string);
    $upload_keys = array_filter($upload_keys);
    $embargo_context = [];
    $embargo_tags = [];

    $embargo_upload_keys_string = strlen(trim($this->getSetting('embargo_json_key_source') ?? '')) > 0 ? trim($this->getSetting('embargo_json_key_source')) : '';
    $embargo_upload_keys_string = explode(',', $embargo_upload_keys_string);
    $embargo_upload_keys_string = array_filter($embargo_upload_keys_string);

    $current_language = $items->getEntity()->get('langcode')->value;
    $nodeid = $items->getEntity()->id();
    $key = $this->getSetting('json_key_source');
    $number_media =  $this->getSetting('number_documents') ?? 0;

    $nodeuuid = $items->getEntity()->uuid();
    $nodeid = $items->getEntity()->id();
    $fieldname = $items->getName();

    foreach ($items as $delta => $item) {
      $main_property = $item->getFieldDefinition()->getFieldStorageDefinition()->getMainPropertyName();
      $value = $item->{$main_property};

      if (empty($value)) {
        continue;
      }

      $jsondata = json_decode($item->value, true);
      // @TODO use future flatversion precomputed at field level as a property
      $json_error = json_last_error();
      if ($json_error != JSON_ERROR_NONE) {
        $message= $this->t('We could had an issue decoding as JSON your metadata for node @id, field @field',
          [
            '@id' => $nodeid,
            '@field' => $items->getName(),
          ]);
        $this->messenger()->addWarning($message);
        return $elements[$delta] = ['#markup' => $this->t('ERROR')];
      }
      /* Expected structure of an Media item inside JSON
       "as:document": {
        "urn:uuid:0da2da57-1634-4170-9899-06e324b8307f": {
            "url": "s3:\/\/0f5\/application-0f582308ac94e102a682a3edb6272412-0da2da57-1634-4170-9899-06e324b8307f.pdf",
            "name": "0f582308ac94e102a682a3edb6272412.pdf",
            "tags": [],
            "type": "Document",
            "dr:fid": 105,
            "dr:for": "documents",
            "dr:uuid": "0da2da57-1634-4170-9899-06e324b8307f",
            "checksum": "0f582308ac94e102a682a3edb6272412",
            "sequence": 1,
            "crypHashFunc": "md5"
        }
      },
      }*/
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

      if (!$embargoed || !empty($embargo_upload_keys_string)) {
        $ordersubkey = 'sequence';
        $conditions[] = [
          'source' => ['dr:mimetype'],
          'condition' => 'application/pdf',
        ];
        // This fetchMediaFromJsonWithFilter impl. has JMESPATH filtering.
        $media = $this->fetchMediaFromJsonWithFilter($delta, $items, $elements,
          TRUE, $jsondata, 'Document', $key, $ordersubkey, $number_media,
          $upload_keys, $conditions);
      }

      if (empty($elements[$delta])) {
        $elements[$delta] = [
          '#markup' => '<i class="fas fa-times-circle"></i>',
          '#prefix' => '<span>',
          '#suffix' => '</span>',
        ];
      }

      if (isset($item->_attributes)) {
        $elements[$delta] += ['#attributes' => []];
        $elements[$delta]['#attributes'] += $item->_attributes;
        // Unset field item attributes since they have been included in the
        // formatter output and should not be rendered in the field template.
        unset($item->_attributes);
      }

      // Get rid of empty #attributes key to avoid render error
      if (isset($elements[$delta]["#attributes"]) && empty($elements[$delta]["#attributes"])) {
        unset($elements[$delta]["#attributes"]);
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
  protected function generateElementForItem(int $delta, FieldItemListInterface $items, FileInterface $file, IiifHelper $iiifhelper, int $i, array &$elements, array $jsondata, array $mediaitem) {

    $max_width = $this->getSetting('max_width');
    $max_width_css = empty($max_width) || $max_width == 0 ? '100%' : $max_width .'px';
    $max_height = $this->getSetting('max_height');
    $number_pages =  $this->getSetting('number_pages');
    $initial_page =  $this->getSetting('initial_page');
    /* @var \Drupal\file\FileInterface[] $files */
    // Fixing the key to extract while coding to 'Media'
    $nodeuuid = $items->getEntity()->uuid();
    $nodeid = $items->getEntity()->id();

    $route_parameters = [
      'node' => $nodeid,
      'uuid' => $file->uuid(),
      'format' => 'default.'. pathinfo($file->getFilename(), PATHINFO_EXTENSION)
    ];
    $publicurl = Url::fromRoute('format_strawberryfield.iiifbinary', $route_parameters);
    $uniqueid =
      'pdf-' . $items->getName(
      ) . '-' . $nodeuuid . '-' . $delta . '-document' . $i;

    $cache_contexts = [
      'url.site',
      'url.path',
      'url.query_args',
      'user.permissions'
    ];


    if ($i == 0) {
      //@TODO make a select component that is ajax driven.
      // If we have more than a single Document, simply rebuild and reload the given $delta instead of rendering
      // Multiple deltas as i do here.
      $elements[$delta]['controller' . $i] = [
        '#theme' => 'format_strawberryfield_pdfs',
        '#item' => [
          'id' => 'document_' . $uniqueid,
        ]
      ];

      if ($max_height == 0) {
        $css_style = "width:{$max_width_css};height:auto";
      }
      else {
        $css_style = "width:{$max_width_css}; height:{$max_height}px";
      }

      $elements[$delta]['pdf' . $i] = [
        '#type' => 'html_tag',
        '#tag' => 'canvas',
        '#attributes' => [
          'class' => ['field-pdf-canvas', 'strawberry-document-item'],
          'id' => 'document_' . $uniqueid,
          'style' => $css_style,
          'data-iiif-document' => $publicurl->toString(),
          'data-iiif-initialpage' => $initial_page,
          'data-iiif-pages' => $number_pages,
        ],
        '#alt' => $this->t(
          'PDF @name for @label',
          [
            '@label' => $items->getEntity()->label(),
            '@name' => $file->getFilename()
          ]),
      ];
      $options[$publicurl->toString()] =$this->t(
        'PDF @name for @label',
        [
          '@label' => $items->getEntity()->label(),
          '@name' => $mediaitem['name'] ?? $file->getFilename()
        ]);
      $elements[$delta]['select'] = [
        '#title' => t('Select a PDF'),
        '#attributes' => [
          'id' => 'document_' . $uniqueid .'_file_selector',
          'class' => ['field-pdf-selector', 'strawberry-document-selector'],
          ],
        '#weight' => -100,
        '#type' => 'select',
        '#options' => $options,
        '#default_value'  => $publicurl->toString(),
        '#access' => FALSE,
      ];

      $elements[$delta]['pdf' . $i]['#attached']['drupalSettings']['format_strawberryfield']['pdf']['innode'][$uniqueid] = $nodeuuid;
      $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/pdfs_strawberry';
    }
    else {

      $options = $elements[$delta]['select']['#options'] ?? [];
      $options[$publicurl->toString()] =$this->t(
        'PDF @name for @label',
        [
          '@label' => $items->getEntity()->label(),
          '@name' => $mediaitem['name'] ?? $file->getFilename()
        ]);

      $elements[$delta]['select']['#options'] = $options;
      $elements[$delta]['select']['#access'] = TRUE;
    }
  }

  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   */
  public static function changePdfCallBack(
    array $form,
    FormStateInterface $form_state
  ) {
    $button = $form_state->getTriggeringElement();
    $element = NestedArray::getValue(
      $form,
      array_slice($button['#array_parents'], 0, -1)
    );
    $response = new AjaxResponse();
    $element_name = $element['#name'];
    $data_selector = $element['hotspots_temp']['#attributes']['data-webform_strawberryfield-selector'];


    // Now update the JS settings
    if ($form_state->getValue([$element_name, 'scene'])) {
      $current_scene = $form_state->getValue([$element_name, 'scene']);
      static::updateJsSettings($form_state, $current_scene, $element_name, $response);
    }
    // And now replace the container
    $response->addCommand(
      new ReplaceCommand(
        '[data-webform_strawberryfield-selector="' . $data_selector . '"]',
        $element['hotspots_temp']
      )
    );
    return $response;
  }

}
