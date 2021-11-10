<?php

namespace Drupal\format_strawberryfield\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\format_strawberryfield\Tools\IiifHelper;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;

/**
 * StrawberryBaseFormatter base class for SBF/JSON based formatters.
 */
abstract class StrawberryDirectJsonFormatter extends StrawberryBaseFormatter {

  const EXAMPLE_JMESPATH = "\"as:image\".*|[?\"dr:for\"=='images' && \"dr:mimetype\"== 'image/jpeg' ]|[?\"flv:exif\".ImageWidth!='null']|[?\"sequence\">=`2`]";

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return
      parent::defaultSettings() + [
        'jmespath' => [
          'use_jmespath' => FALSE,
          'fallback_jmespath' => FALSE,
          'jmespath_filter' => '',
          'jmespath_alternative_filter' => '',
        ],
      ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $jmespath_settings = $this->getSetting('jmespath');
    $summary[] = $this->t('Use JMESPath? %value', [
      '%value' => isset($jmespath_settings['use_jmespath']) && $jmespath_settings['use_jmespath'] ? 'Yes.' : 'No.',
    ]);
    if (isset($jmespath_settings['use_jmespath'])) {
      $summary[] = $this->t(
        'JMESPath expression used: <em>%expression</em>', [
          '%expression' => $jmespath_settings['jmespath_filter'],
        ]
      );
      $summary[] = $this->t(
        'Alternative JMESPath expression used: <em>%expression</em>', [
          '%expression' => $jmespath_settings['jmespath_alternative_filter'],
        ]
      );
      $summary[] = $this->t('Fallback to non JMESPath? %value', [
        '%value' => isset($jmespath_settings['fallback_jmespath']) && $jmespath_settings['fallback_jmespath'] ? 'Yes.' : 'No.',
      ]);
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {

    $form = parent::settingsForm($form, $form_state);
    $current_field = array_keys($form_state->getValue('fields'));
    $field_name = reset($current_field);
    $realparents = [
      'fields',
      $field_name,
      'settings_edit_form',
      'settings',
      'formatter',
      'jmespath',
    ];
    $jmespath_settings = $this->getSetting('jmespath');
    $form['jmespath']['use_jmespath'] = [
      '#type' => 'checkbox',
      '#title' => t('Use JMESPath Expression to fetch files used by this Formatter'),
      '#description' => t('Enabling this will allow you to have full control of which files are used for this Formatter using a JMESPath filter expression'),
      '#default_value' => isset($jmespath_settings['use_jmespath']) ? (bool) $jmespath_settings['use_jmespath'] : FALSE,
      '#attributes' => [
        'data-checkbox-selector' => 'use_jmespath',
      ],
    ];

    $form['jmespath_container'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#title' => t('JMESPath Settings'),
      '#states' => [
        'visible' => [
          ':checkbox[data-checkbox-selector="use_jmespath"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $realparents_fallback = $realparents_filter = $realparents_alternative_filter = $realparents;
    $realparents_fallback[] = 'fallback_jmespath';
    $realparents_filter[] = 'jmespath_filter';
    $realparents_alternative_filter[] = 'jmespath_alternative_filter';
    $form['jmespath_container']['fallback_jmespath'] = [
      '#type' => 'checkbox',
      '#tree' => TRUE,
      '#parents' => $realparents_fallback,
      '#title' => t('If the JMESPath Expression returns no files use the general config to choose any.'),
      '#description' => t("Enabling this will allow you use the default settings in case your expression returns no files"),
      '#default_value' => isset($jmespath_settings['fallback_jmespath']) ? (bool) $jmespath_settings['fallback_jmespath'] : FALSE,

    ];

    $form['jmespath_container']['jmespath_filter'] = [
      '#type' => 'textarea',
      '#tree' => TRUE,
      '#cols' => '80',
      '#rows' => '5',
      '#parents' => $realparents_filter,
      '#title' => t('A complete JMESPath that fetches one or more files from an as:image, etc structure'),
      '#default_value' => isset($jmespath_settings['jmespath_filter']) ? $jmespath_settings['jmespath_filter'] : static::EXAMPLE_JMESPATH,
      '#element_validate' => [[$this, 'validateJMESPath']],
      '#states' => [
        'required' => [
          ':checkbox[data-checkbox-selector="use_jmespath"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['jmespath_container']['jmespath_alternative_filter'] = [
      '#type' => 'textarea',
      '#tree' => TRUE,
      '#cols' => '80',
      '#rows' => '5',
      '#parents' => $realparents_alternative_filter,
      '#title' => t('A secondary complete JMESPath that fetches one or more files to fallback in case the main one fails to give results'),
      '#default_value' => isset($jmespath_settings['jmespath_alternative_filter']) ? $jmespath_settings['jmespath_alternative_filter'] : static::EXAMPLE_JMESPATH,
      '#element_validate' => [[$this, 'validateJMESPath']],
    ];

    return $form;
  }

  /**
   * Validates a JMESPath String.
   *
   * @param array $element
   *   The Form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The Form State Object.
   * @param array $complete_form
   *   The complete form structure.
   */
  public function validateJMESPath(array &$element, FormStateInterface $form_state, array &$complete_form) {
    $parents = $element['#parents'];
    $enabled = array_slice($parents, 0, -1);
    $enabled[] = 'use_jmespath';
    // Empty array, we only want to know if its valid or not.
    $jsonArray = [];
    // Only validate if jmespath is enabled
    if (boolval($form_state->getValue($enabled)) === TRUE) {
      try {
        $searchresult = StrawberryfieldJsonHelper::searchJson(
          $element['#value'], $jsonArray
        );
      } catch (\Exception $exception) {
        $form_state->setErrorByName(implode('][', $parents), t("Your JMESPath expression is invalid with error: @error", [
          '@error' => $exception->getMessage(),
        ]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function fetchMediaFromJsonWithFilter(int $delta, FieldItemListInterface $items, array &$elements, bool $generate_element, array $jsondata, string $mediatype, string $key, string $ordersubkey, int $number_media, array $upload_keys, array $extra_conditions = []) {
    // This implementation is different than the parent one in the sense
    // That it uses also a jmespath option.
    $media = [];
    $iiifhelper = new IiifHelper($this->getIiifUrls()['public'], $this->getIiifUrls()['internal']);
    $jmespath_settings = $this->getSetting('jmespath');
    $use_jmespath = isset($jmespath_settings['use_jmespath']) ? $jmespath_settings['use_jmespath'] : FALSE;
    if ($use_jmespath) {
      $fallback_jmespath = isset($jmespath_settings['fallback_jmespath']) ? $jmespath_settings['fallback_jmespath'] : FALSE;
      $jmespath_filter = isset($jmespath_settings['jmespath_filter']) ? $jmespath_settings['jmespath_filter'] : NULL;
      $jmespath_alternative_filter = isset($jmespath_settings['jmespath_alternative_filter']) ? $jmespath_settings['jmespath_alternative_filter'] : NULL;
      try {
        $searchresult = StrawberryfieldJsonHelper::searchJson(
          $jmespath_filter, $jsondata
        );
        if (empty($searchresult) && !empty($jmespath_alternative_filter)) {
          $searchresult = StrawberryfieldJsonHelper::searchJson(
            $jmespath_alternative_filter, $jsondata
          );
        }
        if (empty($searchresult) && $fallback_jmespath) {
          if (isset($key) && isset($jsondata[$key])) {
            // We are taking a single one here for now
            $searchresult = $jsondata[$key];
          }
          else {
            $searchresult = [];
          }
        }
        // We clear the json data to avoid other
        // keys to be considered by the parent method.
        unset($jsondata);
        $jsondata[$key] = $searchresult;
      }
      catch (\Exception $exception) {
        $this->messenger()
          ->addWarning($this->t('We could not fetch Media for this ADO. Your JMESPath settings may need to be adjusted.'));
        return $media;
      }
    }
    $media = parent::fetchMediaFromJsonWithFilter($delta, $items, $elements,
      $generate_element, $jsondata, $mediatype, $key, $ordersubkey, $number_media,
      $upload_keys, $extra_conditions);
    return $media;
  }

  /**
   * Filters an array against a JMESPATH query.
   *
   * @param array $source
   * @param null $key
   *
   * @return array
   */
  protected function filterMediaByJMESPath(array $source, $key = NULL): array {
    $ordersubkey = 'sequence';
    $jmespath_settings = $this->getSetting('jmespath');
    $use_jmespath = isset($jmespath_settings['use_jmespath']) ? $jmespath_settings['use_jmespath'] : FALSE;
    if ($use_jmespath) {
      $fallback_jmespath = isset($jmespath_settings['fallback_jmespath']) ? $jmespath_settings['fallback_jmespath'] : FALSE;
      $jmespath_filter = isset($jmespath_settings['jmespath_filter']) ? $jmespath_settings['jmespath_filter'] : NULL;
      $jmespath_alternative_filter = isset($jmespath_settings['jmespath_alternative_filter']) ? $jmespath_settings['jmespath_alternative_filter'] : NULL;
      try {
        $searchresult = StrawberryfieldJsonHelper::searchJson(
          $jmespath_filter, $source
        );
        if (empty($searchresult) && !empty($jmespath_alternative_filter)) {
          $searchresult = StrawberryfieldJsonHelper::searchJson(
            $jmespath_alternative_filter, $source
          );
        }
        if (empty($searchresult) && $fallback_jmespath) {
          if (isset($key) && isset($source[$key])) {
            // We are taking a single one here for now
            StrawberryfieldJsonHelper::orderSequence($source, $key, $ordersubkey);
            return $source[$key];
          }
          else {
            return [];
          }
        }
        return $searchresult;
      } catch (\Exception $exception) {
        $this->messenger()
          ->addWarning($this->t('We could not fetch Media for this ADO. Your JMESPath settings may need to be adjusted.'));
        return [];
      }
    }
    else {
      if (isset($key) && isset($source[$key])) {
        StrawberryfieldJsonHelper::orderSequence($source, $key, $ordersubkey);
        return $source[$key];
      }
      else {
        return [];
      }
    }
  }


  /**
   * Filters an array against another array with same keys and values
   *
   * @param array $source
   * @param array $properties
   *
   * @return array
   */
  protected function filterMediaByArray(array $source, array $properties): array {
    // Remove empties first from the properties
    $properties = array_filter($properties);
    return array_filter($source, function ($s) use ($properties) {
      $allow = FALSE;
      foreach ($properties as $prop => $value) {
        $allow = $allow ||
          isset($s[$prop]) &&
          ($s[$prop] == $value ||
            in_array((array) $s[$prop], $value));
        return $allow;
      }
      return $allow;
    });
  }

}
