<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 9/18/18
 * Time: 8:56 PM
 */

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
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
    $max_width = $this->getSetting('max_width');
    $max_width_css = empty($max_width) || $max_width == 0 ? '100%' : $max_width .'px';
    $max_height = $this->getSetting('max_height');
    $number_pages =  $this->getSetting('number_pages');
    $number_documents =  $this->getSetting('number_documents');
    $initial_page =  $this->getSetting('initial_page');
    /* @var \Drupal\file\FileInterface[] $files */
    // Fixing the key to extract while coding to 'Media'
    $key = $this->getSetting('json_key_source');

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
      $i = 0;
      if (isset($jsondata[$key])) {
        // Order Documents based on a given 'sequence' key
        $ordersubkey = 'sequence';
        StrawberryfieldJsonHelper::orderSequence($jsondata, $key, $ordersubkey);
        foreach ($jsondata[$key] as $mediaitem) {
          $i++;
          if ($i > $number_documents && (int)$number_documents !=0) {
            break;
          }
          if (isset($mediaitem['type']) && $mediaitem['type'] == 'Document') {
            if (isset($mediaitem['dr:fid'])) {
              // @TODO check if loading the entity is really needed to check access.
              // @TODO we can refactor a lot here and move it to base methods
              $file = OcflHelper::resolvetoFIDtoURI(
                $mediaitem['dr:fid']
              );
              if (!$file) {
                continue;
              }
              //@TODO if no Document key to file loading was possible
              // means we have a broken/missing media reference
              // we should inform to logs and continue
              // Also check if user has access and the mimeType is of an PDF.
              if ($this->checkAccess($file) && $file->getMimeType() == 'application/pdf') {
                $documenturl = $file->getFileUri();
                // We assume here file could not be accessible publicly
                $route_parameters = [
                  'node' => $nodeid,
                  'uuid' => $file->uuid(),
                  'format' => 'default.'. pathinfo($file->getFilename(), PATHINFO_EXTENSION)
                ];
                $publicurl = Url::fromRoute('format_strawberryfield.iiifbinary', $route_parameters);
                $uniqueid =
                  'pdf-' . $items->getName(
                  ) . '-' . $nodeuuid . '-' . $delta . '-document' . $i;

                //@TODO make a select component that is ajax driven.
                // If we have more than a single Document, simply rebuild and reload the given $delta instead of rendering
                // Multiple deltas as i do here.
                $elements[$delta]['controller' . $i] = [
                  '#theme' => 'format_strawberryfield_pdfs',
                  '#item' =>  [
                    'id' =>  'document_' . $uniqueid,
                  ]
                ];


                if ($max_height == 0) {
                  $css_style = "width:{$max_width_css};height:auto";
                } else {
                  $css_style = "width:{$max_width_css}; height:{$max_height}px";
                }




                $elements[$delta]['pdf' . $i] = [
                  '#type' => 'html_tag',
                  '#tag' => 'canvas',
                  '#attributes' => [
                      'class' => ['field-pdf-canvas','strawberry-document-item'],
                      'id' => 'document_' . $uniqueid,
                      'style' => $css_style,
                      'data-iiif-document' =>  $publicurl->toString(),
                      'data-iiif-initialpage' => $initial_page,
                      'data-iiif-pages' => $number_pages,
                  ],
                   '#alt' => $this->t(
                      'PDF @name for  @label',
                      [
                        '@label' => $items->getEntity()->label(),
                        '@name' => $file->getFilename()
                      ]),
                  ];

                  $elements[$delta]['pdf'.$i]['#attached']['drupalSettings']['format_strawberryfield']['pdf']['innode'][$uniqueid] = $nodeuuid;
                  $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/pdfs_strawberry';

              }
              else {
                // @TODO Deal with no access here
                // Should we put a thumb? Just hide?
                // @TODO we can bring a plugin here and there that deals with
                $elements[$delta]['pdf'.$i] = [
                  '#markup' => '<i class="fas fa-times-circle"></i>',
                  '#prefix' => '<span>',
                  '#suffix' => '</span>',
                ];
              }
            } elseif (isset($mediaitem['url'])) {
              // TODO. We can serve non mananged by US PDFS directly here
              // We would just have less data. But that is all
              $elements[$delta]['[pdf'.$i] = [
                '#markup' => 'Non managed '.$mediaitem['url'],
                '#prefix' => '<pre>',
                '#suffix' => '</pre>',
              ];
            }

          }
        }
      }
      // Get rid of empty #attributes key to avoid render error
      if (isset( $elements[$delta]["#attributes"]) && empty( $elements[$delta]["#attributes"])) {
        unset($elements[$delta]["#attributes"]);
      }
    }
    return $elements;
  }
}
