<?php

namespace Drupal\format_strawberryfield\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenOffCanvasDialogCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Language\Language;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\format_strawberryfield\Entity\MetadataDisplayEntity;
use Twig\Error\Error as TwigError;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for the MetadataDisplayEntity entity edit forms.
 *
 * @ingroup format_strawberryfield
 */
class MetadataDisplayForm extends ContentEntityForm {

  /**
   * Formatter Plugin Manager.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected $formatterPluginManager;

  /**
   * The Twig environment.
   *
   * @var \Drupal\Core\Template\TwigEnvironment
   */
  protected $twig;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->formatterPluginManager = $container->get('plugin.manager.field.formatter');
    $instance->twig = $container->get('twig');
    $instance->renderer = $container->get('renderer');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    $form['langcode'] = [
      '#title' => $this->t('Language'),
      '#type' => 'language_select',
      '#default_value' => $this->entity->getUntranslated()->language()->getId(),
      '#languages' => Language::STATE_ALL,
    ];

    $form['footer']['help'] = [
      '#title' => $this->t('Help? Full list of available Twig replacements and functions in Drupal 8.'),
      '#type' => 'link',
      '#url' => Url::fromUri('https://www.drupal.org/docs/8/theming/twig/functions-in-twig-templates',
        [
          'attributes' =>
            [
              'target' => '_blank',
              'rel' => 'nofollow',
            ],
        ]
      ),
    ];

    // Display a Preview feature.
    $form['preview'] = [
      '#attributes' => ['id' => 'metadata-preview-container'],
      '#type' => 'details',
      '#title' => $this->t('Preview'),
      '#open' => FALSE,
    ];
    $form['preview']['ado_context_preview'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('ADO to preview'),
      '#description' => $this->t('The ADO used to preview the data.'),
      '#target_type' => 'node',
      '#maxlength' => 1024,
      '#selection_handler' => 'default:nodewithstrawberry',
    ];
    $form['preview']['button_preview'] = [
      '#type' => 'button',
      '#op' => 'preview',
      '#value' => $this->t('Show preview'),
      '#ajax' => [
        'callback' => [$this, 'ajaxPreview'],
      ],
      '#states' => [
        'visible' => [':input[name="ado_context_preview"]' => ['filled' => true]],
      ],
    ];
    $form['preview']['render_native'] = [
      '#type' => 'checkbox',
      '#defaut_value' => FALSE,
      '#title' => 'Show Preview using native Output Format (e.g HTML)',
      '#description' => 'If errors are found Preview will fail.',
      '#states' => [
        'visible' => [':input[name="ado_context_preview"]' => ['filled' => true]],
      ],
    ];
    $form['preview']['show_json_table'] = [
      '#type' => 'checkbox',
      '#defaut_value' => FALSE,
      '#title' => 'Show Preview with JSON keys used in this template.',
      '#states' => [
        'visible' => [':input[name="ado_context_preview"]' => ['filled' => true]],
      ],
    ];

    // Enable autosaving in code mirror.
    $form['#attached']['library'][] = 'format_strawberryfield/code_mirror_autosave';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    try {
      if (isset($form_state->getTriggeringElement()['#op']) && $form_state->getTriggeringElement()['#op']!='preview') {
        $build = [
          '#type'     => 'inline_template',
          '#template' => $form_state->getValue('twig')[0]['value'],
          '#context'  => ['data' => []],
        ];
        $this->renderer->renderPlain($build);
      }
    }
    catch (\Exception $exception) {
      $message = 'Error in parsing the template';
      // Make the Message easier to read for the end user
      if ($exception instanceof TwigError) {
        $message = $exception->getRawMessage() . ' at line ' . $exception->getTemplateLine();
      } else {
        $message = $exception->getMessage();
      }
      // Do not set Form Errors if running a Preview Operation.
      if (isset($form_state->getTriggeringElement()['#type']) &&
        $form_state->getTriggeringElement()['#type'] == 'submit') {
        // This is not showing correctly. Why is the message missing?
        $this->messenger()->addError($message);
        $form_state->setErrorByName('twig', $message);
      }
    }
    return parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $status = parent::save($form, $form_state);

    /** @var \Drupal\format_strawberryfield\MetadataDisplayInterface $entity */
    $entity = $this->entity;
    if ($status == SAVED_UPDATED) {
      $this->messenger()->addMessage($this->t('The Metadata Display %entity has been updated.', ['%entity' => $entity->toLink()->toString()]));
    }
    else {
      $this->messenger()->addMessage($this->t('The Metadata Display %entity has been added.', ['%entity' => $entity->toLink()->toString()]));
    }
    $this->formatterPluginManager->clearCachedDefinitions();
    $this->twig->invalidate();

    return $status;
  }

  /**
   * Provides similar functionality to StrawberryfieldJsonHelper::arrayToFlatJsonPropertypaths,
   * but adds some extra properties for reporting and returns an array of the properties.
   *
   * @todo: add the extra property functionality as an option to the existing
   * function and import here.
   *
   * @param array $array
   *     An associative array of an ADO's JSON.
   * @param string $recursive_key
   *     A string to track the JSON key across recursions.
   * @param int $array_depth
   *     An integer to track the depth of the array in order to limit the depth.
   * @param array $excludepaths
   *     An array of paths to exclude.
   */
  public static function flattenKeys(array $array, string $recursive_key = '', int $array_depth = 0, array $excludepaths = []) {
    $return = [];
    $array_depth_max = 10;
    ++$array_depth;
    if (!empty($excludepaths) && in_array(rtrim($recursive_key,'.'), $excludepaths)) {
      return $return;
    }
    foreach($array as $key=>$value) {
      if(filter_var($key, FILTER_VALIDATE_URL) || StrawberryfieldJsonHelper::validateURN($key)) {
        $key = "*";
      } elseif (is_integer($key)) {
        $key = '[*]';
      }
      $value_type = empty($value) && !is_null($value) ? 'empty ' . gettype($value) : gettype($value);
      if ($key == '[*]' || $key == '*') {
        $key = empty($recursive_key) ? $key : $recursive_key  . $key;
      } else {
        $key = empty($recursive_key) ? $key : $recursive_key . '.' . $key;
      }
      if (is_array($value)) {
        $return['data.' . $key]['type'] = $value_type;
        $return['data.' . $key]['used'] = '';
        if ($array_depth <= $array_depth_max) {
          $return = array_merge($return, static::flattenKeys($value, $key, $array_depth));
        }
      }
      else {
        $return['data.' . $key]['type'] = $value_type;
        $return['data.' . $key]['used'] = '';
      }
    }
    return $return;
  }

  /**
   * Takes a provided property path and inserts one for URLs and arrays
   * and returns an array of modified paths to check against.
   */
  public static function addPropertyPath(string $property_path) {
    $last_dot_pos = strrpos($property_path,'.');
    $url_property_path = substr_replace($property_path, '*.', $last_dot_pos, 1);
    $url_property_path_end = $property_path . '*';
    $array_property_path = substr_replace($property_path, '[*].', $last_dot_pos, 1);
    $array_property_path_end = $property_path . '[*]';
    return [
      $url_property_path,
      $url_property_path_end,
      $array_property_path,
      $array_property_path_end
    ];
  }

  /**
   * Takes an error message and returns
   * the status message container.
   *
   * @param string $message
   *   The error message to display to the user.
   */
  public static function buildAjaxPreviewError(string $message) {
    $preview_error = [
      '#type' => 'container',
      '#weight' => -1000,
      '#theme' => 'status_messages',
      '#message_list' => [
        'error' => [
          t($message),
        ],
      ],
      '#status_headings' => [
        'error' => t('Error message'),
      ],
    ];
    return $preview_error;
  }

  /**
   * Takes ADO JSON keys and a given MetadataDisplay entity and generates
   * a table to display in a MetadataDisplay Preview.
   *
   * @param array $jsondata
   *     An associative array of an ADO's JSON.
   * @param MetadataDisplayEntity $entity
   *     A Metadata Display entity.
   */
  public static function buildUsedVariableTable(array $jsondata, MetadataDisplayEntity $entity) {
    $used_vars = $entity->getTwigVariablesUsed();
    $data_json = MetadataDisplayForm::flattenKeys($jsondata);
    ksort($data_json);
    $used_keys = [];
    foreach($used_vars as $used_key => $used_var) {
      $used_var_path = $used_key;
      $used_var_line = $used_var['line'];
      $used_var_exploded = explode('.', $used_var_path);
      array_push($used_keys, $used_var_path);
      $wildcard_paths = static::addPropertyPath($used_var_path);
      if (isset($data_json[$used_var_path])) {
        $data_json[$used_var_path]['used'] = 'Used';
        $data_json[$used_var_path]['line'] = $used_var_line;
      }
      foreach($wildcard_paths as $wildcard_path) {
        if (isset($data_json[$wildcard_path])) {
          $data_json[$wildcard_path]['used'] = 'Used';
          $data_json[$wildcard_path]['line'] = $used_var_line;
        }
        if (count($used_var_exploded) > 2) {
          $used_var_parts = array_slice($used_var_exploded,0, 2);
          $used_var_part = implode('.', $used_var_parts);
          if (isset($data_json[$used_var_part])) {
            $data_json[$used_var_part]['used'] = 'Used';
            $data_json[$used_var_part]['line'] = $used_var_line;
          }
        }
      }
    }
    $unused_vars = $data_json;

    $unused_keys = array_keys($unused_vars);
    $unused_rows = array_map(function($unused_key, $unused_value) {
      return [
        $unused_key,
        $unused_value['type'],
        $unused_value['used'],
        isset($unused_value['line']) ? implode(', ', $unused_value['line']) : ''
      ];
    }, $unused_keys,$unused_vars);
    $json_table = [
      '#type' => 'table',
      '#header' => [
        t('JSON key'),
        t('Type'), t('Used'),
        t('Line No.')
      ],
      '#rows' => $unused_rows,
      '#empty' => t('No content has been found.'),
    ];
    return $json_table;
  }

  /**
   * AJAX callback.
   */
  public static function ajaxPreview($form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    /** @var \Drupal\format_strawberryfield\MetadataDisplayInterface $entity */
    $entity = $form_state->getFormObject()->getEntity();

    // Attach the library necessary for using the OpenOffCanvasDialogCommand and
    // set the attachments for this Ajax response.
    $form['#attached']['library'][] = 'core/drupal.dialog.off_canvas';
    $form['#attached']['library'][] = 'codemirror_editor/editor';
    $response->setAttachments($form['#attached']);


    if (!empty($form_state->getValue('ado_context_preview'))) {
      /** @var \Drupal\node\NodeInterface $preview_node */
      $preview_node = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->load($form_state->getValue('ado_context_preview'));
      if (empty($preview_node)) {
        return $response;
      }
      // Check if render native is requested and get mimetype
      $mimetype = $form_state->getValue('mimetype');
      $mimetype = !empty($mimetype) ? $mimetype[0]['value'] : 'text/html';
      $show_render_native = $form_state->getValue('render_native');

      if ($show_render_native) {
        set_error_handler('_format_strawberryfield_metadata_preview_error_handler');
      }

      $sbf_fields = \Drupal::service('strawberryfield.utility')
        ->bearsStrawberryfield($preview_node);

      // Set initial context.
      $context = [
        'node' => $preview_node,
        'iiif_server' => \Drupal::service('config.factory')
          ->get('format_strawberryfield.iiif_settings')
          ->get('pub_server_url'),
      ];

      // Add the SBF json context.
      // @see MetadataExposeDisplayController::castViaTwig()
      foreach ($sbf_fields as $field_name) {
        /** @var \Drupal\strawberryfield\Field\StrawberryFieldItemList $field */
        $field = $preview_node->get($field_name);
        foreach ($field as $offset => $fielditem) {
          $jsondata = json_decode($fielditem->value, TRUE);
          // Preorder as:media by sequence.
          $ordersubkey = 'sequence';
          foreach (StrawberryfieldJsonHelper::AS_FILE_TYPE as $key) {
            StrawberryfieldJsonHelper::orderSequence($jsondata, $key, $ordersubkey);
          }
          if ($offset === 0) {
            $context['data'] = $jsondata;
          }
          else {
            $context['data'][$offset] = $jsondata;
          }
        }
      }

      $output = [];
      $output['json'] = [
        '#type' => 'details',
        '#title' => t('JSON Data'),
        '#open' => FALSE,
      ];
      $output['json']['data'] = [
        '#type' => 'codemirror',
        '#rows' => 60,
        '#value' => json_encode($context['data'], JSON_PRETTY_PRINT),
        '#codemirror' => [
          'lineNumbers' => FALSE,
          'toolbar' => FALSE,
          'readOnly' => TRUE,
          'mode' => 'application/json',
        ],
      ];

      try {
        // Try to Ensure we're using the twig from user's input instead of the entity's
        // default.
        $input = $form_state->getUserInput();
        $entity->set('twig', $input['twig'][0], FALSE);
        $render = $entity->renderNative($context);

        $show_json_table = $form_state->getValue('show_json_table');
        if ($show_json_table) {
          $json_table = static::buildUsedVariableTable($jsondata, $entity);
        }

        if ($show_render_native && empty($render)) {
          throw new \Exception(
            'Twig Template is empty.',
            0,
            null
          );
        }
        elseif ($show_render_native) {
          $message = '';
          switch ($mimetype) {
            case 'application/ld+json':
            case 'application/json':
              $render_encoded = json_decode((string) $render);
              if (JSON_ERROR_NONE !== json_last_error()) {
                throw new \Exception(
                  'Error parsing JSON: ' . json_last_error_msg(),
                  0,
                  null
                );
              }
              else {
                $render = json_encode($render_encoded, JSON_PRETTY_PRINT);
              }
              break;
            case 'text/html':
              libxml_use_internal_errors(true);
              $dom = new \DOMDocument('1.0', 'UTF-8');
              if ($dom->loadHTML((string) $render)) {
                if ($error = libxml_get_last_error()) {
                  libxml_clear_errors();
                  $message = $error->message;
                }
                break;
              }
              else {
                throw new \Exception(
                  'Error parsing HTML',
                  0,
                  null
                );
              }
            case 'application/xml':
              libxml_use_internal_errors(true);
              try {
                libxml_clear_errors();
                $dom = new \SimpleXMLElement((string) $render);
                if ($error = libxml_get_last_error()) {
                  $message = $error->message;
                }
              } catch (\Exception $e) {
                throw new \Exception(
                  "Error parsing XML: {$e->getMessage()}",
                  0,
                  null
                );
              }
              break;
          }
        }
      } catch (\Exception $exception) {
        // Make the Message easier to read for the end user
        if ($exception instanceof TwigError) {
          $message = $exception->getRawMessage() . ' at line ' . $exception->getTemplateLine();
        }
        else {
          $message = $exception->getMessage();
        }
      } finally {
        if (!empty($message)) {
          $preview_error = static::buildAjaxPreviewError($message);
          $output['preview_error'] = $preview_error;
        }
        if ($render && (!$show_render_native || ($show_render_native && $mimetype != 'text/html'))) {
          $output['preview'] = [
            '#type' => 'codemirror',
            '#rows' => 60,
            '#value' => $render,
            '#codemirror' => [
              'lineNumbers' => FALSE,
              'toolbar' => FALSE,
              'readOnly' => TRUE,
              'mode' => $mimetype,
            ],
          ];
        }
        else if ($show_render_native && $render) {
          $output['preview'] = [
            '#type' => 'details',
            '#open' => TRUE,
            '#title' => 'HTML Output',
            'render' => [
              '#markup' => $render,
            ],
          ];
        }
        if ($show_json_table) {
          $output['json_unused'] = [
            '#type' => 'details',
            '#open' => FALSE,
            '#title' => 'JSON keys',
            'render' => [
              'table' => $json_table
            ],
          ];
        }
      }
      if ($show_render_native) {
        restore_error_handler();
      }
      $response->addCommand(new OpenOffCanvasDialogCommand(t('Preview'), $output, ['width' => '50%']));
    }
    // Always refresh the Preview Element too.
    $form['preview']['#open'] = TRUE;
    $response->addCommand(new ReplaceCommand('#metadata-preview-container', $form['preview']));
    \Drupal::messenger()->deleteByType(MessengerInterface::TYPE_STATUS);
    if ($form_state->getErrors()) {
      // Clear errors so the user does not get confused when reloading.
      \Drupal::messenger()->deleteByType(MessengerInterface::TYPE_ERROR);

      $form_state->clearErrors();
    }
    return $response;
  }
}
