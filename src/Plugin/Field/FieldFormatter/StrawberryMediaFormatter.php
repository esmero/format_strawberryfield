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
use Drupal\format_strawberryfield\Tools\IiifHelper;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\format_strawberryfield\Controller\WebAnnotationController;
use Drupal\Core\Url;
/**
 * Simplistic Strawberry Field formatter.
 *
 * @FieldFormatter(
 *   id = "strawberry_media_formatter",
 *   label = @Translation("Strawberry Field Media Formatter for IIIF media"),
 *   class = "\Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryMediaFormatter",
 *   field_types = {
 *     "strawberryfield_field"
 *   },
 *   quickedit = {
 *     "editor" = "disabled"
 *   }
 * )
 */
class StrawberryMediaFormatter extends StrawberryBaseFormatter {
  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return parent::defaultSettings() + [
      'iiif_group' => TRUE,
      'json_key_source' => 'as:image',
      'max_width' => 720,
      'max_height' => 480,
      'webannotations' => FALSE,
      'webannotations_tool' => 'polygon',
      'thumbnails' => TRUE,
    ];
  }
  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    //@TODO document that 2 base urls are just needed when developing (localhost syndrom)
    return [
        'iiif_group' => [
          '#type' => 'checkbox',
          '#title' => t('Group all Media files in a single viewer?'),
          '#default_value' => $this->getSetting('iiif_group'),
        ],
        'thumbnails' => [
          '#type' => 'checkbox',
          '#title' => t('Show a thumbnail reference bar.'),
          '#default_value' => $this->getSetting('thumbnails'),
        ],
        'webannotations' => [
          '#type' => 'checkbox',
          '#title' => t('Enable loading/editing of W3C webAnnotations.'),
          '#description' => t('<a href="https://www.w3.org/TR/annotation-model/#index-of-json-keys">Click here</a> To learn more about the JSON format of a Web Annotation'),
          '#default_value' => $this->getSetting('webannotations'),
          '#attributes' => [
            'data-formatter-selector' => 'webannotations',
          ],
        ],
        'webannotations_tool' => [
          '#type' => 'select',
          '#options' => [
            'rect' => 'Rectangle',
            'polygon' => 'Polygon'
          ],
          '#title' => t('What tool to enable'),
          '#description' => t('This defines if the user will be able to use the Polygon or the Rectangle Tool'),
          '#default_value' => $this->getSetting('webannotations_tool'),
          '#states' => [
            'visible' => [
              ':input[data-formatter-selector="webannotations"]' => ['checked' => TRUE],
            ],
          ]
        ],
        'json_key_source' => [
          '#type' => 'textfield',
          '#title' => t('JSON Key from where to fetch Media URLs'),
          '#default_value' => $this->getSetting('json_key_source'),
          '#required' => TRUE
        ],
        'max_width' => [
          '#type' => 'number',
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
          '#title' => $this->t('Maximum height'),
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
    $summary[] = $this->t('Displays Zoomable Media from JSON using a IIIF server and the OpenSeadragon viewer.');

    if ($this->getSetting('iiif_group')) {
      $summary[] = $this->t('Use a single Viewer for multiple media: %iiif_group', [
        '%iiif_group' => $this->getSetting('iiif_group'),
      ]);
    }
    if ($this->getSetting('webannotations')) {
      $summary[] = $this->t('Enable W3C WebAnnotations: %webannotations', [
        '%webannotations' => $this->getSetting('webannotations'),
      ]);
    }
    if ($this->getSetting('thumbnails')) {
      $summary[] = $this->t('Show thumbnail navigation bar: %thumbnails', [
        '%thumbnails' => $this->getSetting('thumbnails'),
      ]);
    }
    if ($this->getSetting('json_key_source')) {
      $summary[] = $this->t('Media fetched from JSON "%json_key_source" key', [
        '%json_key_source' => $this->getSetting('json_key_source'),
      ]);
    }
    $summary[] = $this->t(
      'Maximum size: %max_width x %max_height',
      [
        '%max_width' => (int) $this->getSetting('max_width') == 0 ? '100%' : $this->getSetting('max_width') . ' pixels',
        '%max_height' => $this->getSetting('max_height') . ' pixels',
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
    $grouped = $this->getSetting('iiif_group');
    $thumbnails = $this->getSetting('thumbnails');
    $webannotations = $this->getSetting('webannotations');
    $webannotations_tool = $this->getSetting('webannotations_tool');

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
        return $elements[$delta] = ['#markup' => $this->t('Sorry, we had issues processing this metadata')];
      }
      /* Expected structure of an Media item inside JSON
      "as:images": {
         "urn:uuid:someuuid": {
         "dr:fid": 32, // Drupal's FID
         "dr:for": "add_some_master_images", // The webform element key that generated this one
         "url": "s3:\/\/f23\/new-metadata-en-image-58455d91acf7290275c1cab77531b7f561a11a84.jpg",
         "name": "new-metadata-en-image-a8d0090cbd2cd3ca2ab16e3699577538f3049941.jpg",
         "type": "Image",
         "checksum": "f231aed5ae8c2e02ef0c5df6fe38a99b"
         }
      }*/
      $i = 0;
      // We need to load main Library on each page for views to see it.
      $elements[$delta]['#attached']['library'][] = 'format_strawberryfield/iiif_openseadragon_strawberry';

      if (isset($jsondata[$key])) {
        // Order Images based on a given 'sequence' key
        $ordersubkey = 'sequence';
        StrawberryfieldJsonHelper::orderSequence($jsondata, $key, $ordersubkey);
        $iiifhelper = new IiifHelper($this->getIiifUrls()['public'], $this->getIiifUrls()['internal']);
        foreach ($jsondata[$key] as $mediaitem) {
          $i++;
          if (isset($mediaitem['type']) && $mediaitem['type'] == 'Image') {
            if (isset($mediaitem['dr:fid'])) {
              // @TODO check if loading the entity is really needed to check access.
              $file = OcflHelper::resolvetoFIDtoURI(
                $mediaitem['dr:fid']
              );

              //@TODO if no media key to file loading was possible
              // means we have a broken/missing media reference
              // we should inform to logs and continue
              if (!$file) {
                continue;

              }
              if ($this->checkAccess($file)) {
                $iiifidentifier = urlencode(
                  StreamWrapperManager::getTarget($file->getFileUri())
                );
                if ($iiifidentifier == NULL || empty($iiifidentifier)) {
                  continue;
                }
                // ImageToolKit use the $file->getFileUri(), we don't want that yet
                // @see https://github.com/esmero/format_strawberry/issues/1

                //@ TODO recheck cache tags here, since we are not really using the file itself.
                $filecachetags = $file->getCacheTags();
                $iiifpublicinfojson = $iiifhelper->getPublicInfoJson($iiifidentifier);

                $groupid = 'iiif-'.$items->getName(
                  ).'-'.$nodeuuid.'-'.$delta.'-media';
                $uniqueid =  $groupid.$i;

                $elements[$delta]['media'.$i] = [
                  '#type' => 'container',
                  '#default_value' => $uniqueid,
                  '#attributes' => [
                    'id' => $uniqueid,
                    'class' => ['strawberry-media-item','field-iiif','container'],
                    'data-iiif-infojson' => $iiifpublicinfojson,
                    'data-iiif-group' => $grouped ? $groupid : $uniqueid,
                    'data-iiif-thumbnails' => $thumbnails,
                    'style' => "width:{$max_width_css}; height:{$max_height}px",
                  ],
                ];
                if (isset($item->_attributes)) {
                  $elements[$delta] += ['#attributes' => []];
                  $elements[$delta]['#attributes'] += $item->_attributes;
                  // Unset field item attributes since they have been included in the
                  // formatter output and should not be rendered in the field template.
                  unset($item->_attributes);
                }
                // @TODO deal with a lot of Media single strawberryfield
                // Idea would be to allow a setting that says, A) all same viewer(aggregate)
                // B) individual viewers for each?
                // C) only first one?
                // We will assign a group based on the UUID of the node containing this
                // to idenfity all the divs that we will create. And only first one will be the container in case of many?
                // so a jquery selector that uses that group as filter for a search.
                // Drupal JS settings get accumulated. So in a single search results site we will have for each
                // Formatter one passed. Reason we use 'innode' array key using our $uniqueid
                // @TODO probably better to use uuid() or the node id() instead of $uniqueid
                $elements[$delta]['media'.$i]['#attributes']['data-iiif-infojson'] = $iiifpublicinfojson;
                $elements[$delta]['media'.$i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon']['innode'][$uniqueid] = $nodeuuid;
                $elements[$delta]['media'.$i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['width'] = $max_width_css;
                $elements[$delta]['media'.$i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['dr:uuid']  = $file->uuid();
                // Used to keep annotations around during edit.
                // Note: Since View modes are cached, if no change to the NODE this will be served from a cache! mmm.
                if ($this->currentUser->hasPermission('view strawberryfield webannotation') && $webannotations) {
                  $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['keystoreid'] = WebAnnotationController::getTempStoreKeyName($fieldname, $delta, $nodeuuid);
                  $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['webannotations'] = (boolean) $webannotations;
                  $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['webannotations_tool'] = $webannotations_tool ? $webannotations_tool : 'rect';
                  // This also never runs if cached. So after deletion we better call the controller!
                  if (!empty($jsondata['ap:annotationCollection']) && is_array($jsondata['ap:annotationCollection'])) {
                    $keystoreid = $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['keystoreid'];
                    WebAnnotationController::primeKeyStore($items[$delta]);
                  }
                }
                if ($this->currentUser) {
                   $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['user']['url'] = Url::fromRoute('entity.user.canonical', ['user' => $this->currentUser->getAccount()->id()])->toString();
                  $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['user']['name'] = $this->currentUser->getAccount()->getAccountName();
                } else {
                  $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['user']['url'] = null;
                  $elements[$delta]['media' . $i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['user']['name'] = 'anonymous';
                }


                $elements[$delta]['media'.$i]['#attached']['drupalSettings']['format_strawberryfield']['openseadragon'][$uniqueid]['height'] = max(
                  $max_height,
                  480
                );
              }
            } elseif (isset($mediaitem['url'])) {
              $elements[$delta]['media'.$i] = [
                '#markup' => 'Non managed '.$mediaitem['url'],
                '#prefix' => '<pre>',
                '#suffix' => '</pre>',
              ];
            }

          }
        }
      }
      else {
         $elements[$delta] = ['#markup' => $this->t('This Object has no Media')];
      }
      // Get rid of empty #attributes key to avoid render error
      if (isset( $elements[$delta]["#attributes"]) && empty( $elements[$delta]["#attributes"])) {
        unset($elements[$delta]["#attributes"]);
      }
    }

    return $elements;
  }
}
