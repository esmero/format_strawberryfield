<?php

namespace Drupal\format_strawberryfield_facets\Plugin\facets\processor;

use Drupal\Core\Cache\UnchangingCacheableDependencyTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\facets\FacetInterface;
use Drupal\facets\Processor\BuildProcessorInterface;
use Drupal\facets\Processor\ProcessorPluginBase;

/**
 * Provides a processor that excludes specified items.
 *
 * @FacetsProcessor(
 *   id = "sbf_exclude_specified_items",
 *   label = @Translation("Format Strawberry Field Exclude specified items"),
 *   description = @Translation("Exclude items depending on their raw or display value (such as node IDs or titles) using multi line input."),
 *   stages = {
 *     "build" = 50
 *   }
 * )
 */
class SbfExcludeSpecifiedItemsProcessor extends ProcessorPluginBase implements BuildProcessorInterface {

  use UnchangingCacheableDependencyTrait;

  /**
   * {@inheritdoc}
   */
  public function build(FacetInterface $facet, array $results) {
    $config = $this->getConfiguration();

    /** @var \Drupal\facets\Result\ResultInterface $result */
    $exclude_item = $config['exclude'];
    foreach ($results as $id => $result) {
      $is_excluded = FALSE;
      if ($config['regex']) {
        $matcher = '/' . trim(str_replace('/', '\\/', $exclude_item)) . '/';
        if (preg_match($matcher, $result->getRawValue()) || preg_match($matcher, $result->getDisplayValue())) {
          $is_excluded = TRUE;
        }
      }
      else {
        $exclude_items = explode("\n", $exclude_item);
        foreach ($exclude_items as $item) {
          if ($config['exclude_case_insensitive']) {
            if (strtolower($result->getRawValue()) == strtolower($item)
              || strtolower($result->getDisplayValue()) == strtolower($item)
            ) {
              $is_excluded = TRUE;
            }
          }
          else {
            if ($result->getRawValue() == $item
              || $result->getDisplayValue() == $item
            ) {
              $is_excluded = TRUE;
            }
          }
        }
      }

      // Invert the is_excluded result when the invert setting is active.
      if ($config['invert']) {
        $is_excluded = !$is_excluded;
      }

      // Filter by the excluded results.
      if ($is_excluded) {
        unset($results[$id]);
      }
    }

    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, FacetInterface $facet) {
    $config = $this->getConfiguration();

    $build['exclude'] = [
      '#title' => $this->t('Exclude items'),
      '#type' => 'textarea',
      '#default_value' => $config['exclude'],
      '#description' => $this->t("List of titles or values that should be excluded, matching either an item's title or value. Enter one by line. Starting and trailing spaces won't be trimmed to allow facets that contain spaces to be matched exactly"),
    ];
    $build['exclude_case_insensitive'] = [
      '#title' => $this->t('Exclude Items in an case insensitive way'),
      '#type' => 'checkbox',
      '#default_value' => $config['exclude_case_insensitive'],
      '#description' => $this->t('Makes the comparison for the Excluded items list case insensitive'),
    ];
    $build['regex'] = [
      '#title' => $this->t('Regular expressions used'),
      '#type' => 'checkbox',
      '#default_value' => $config['regex'],
      '#description' => $this->t('Interpret each exclude list item as a regular expression pattern.<br /><small>(Slashes are escaped automatically, patterns using a comma can be wrapped in "double quotes", and if such a pattern uses double quotes itself, just make them double-double-quotes (""))</small>.'),
    ];
    $build['invert'] = [
      '#title' => $this->t('Invert - only list matched items'),
      '#type' => 'checkbox',
      '#default_value' => $config['invert'],
      '#description' => $this->t('Instead of excluding items based on the pattern specified above, only matching items will be displayed.'),
    ];

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'exclude' => '',
      'exclude_case_insensitive' => 0,
      'regex' => 0,
      'invert' => 0,
    ];
  }

}
