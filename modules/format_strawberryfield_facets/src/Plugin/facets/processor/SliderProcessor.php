<?php

namespace Drupal\format_strawberryfield_facets\Plugin\facets\processor;

use Drupal\facets\FacetInterface;
use Drupal\facets\Processor\PostQueryProcessorInterface;
use Drupal\facets\Processor\ProcessorPluginBase;
use Drupal\facets\Result\Result;

/**
 * Provides a processor that adds all values between an min and max range.
 *
 * @FacetsProcessor(
 *   id = "sbf_date_slider",
 *   label = @Translation("Format Strawberryfield Date Slider"),
 *   description = @Translation("Add Date results for all the steps between min and max range."),
 *   stages = {
 *     "post_query" = 60
 *   }
 * )
 */
class SliderProcessor extends ProcessorPluginBase implements PostQueryProcessorInterface {

  /**
   * {@inheritdoc}
   */
  public function postQuery(FacetInterface $facet) {
    $widget = $facet->getWidgetInstance();
    $config = $widget->getConfiguration();
    $simple_results = [];

    // Generate all the "results" between min and max, with the configured step.
    foreach ($facet->getResults() as $result) {
      $simple_results['f_' . (float) $result->getRawValue()] = [
        'value' => (float) $result->getRawValue(),
        'count' => (int) $result->getCount(),
      ];
    }
    uasort($simple_results, function ($a, $b) {
      return (int) $a['value'] - $b['value'];
    });

    $step = $config['step'];
    if ($config['min_type'] == 'fixed') {
      $min = $config['min_value'];
      $max = $config['max_value'];
    }
    else {
      $min = reset($simple_results)['value'] ?? 0;
      $max = end($simple_results)['value'] ?? 0;
      // If max is not divisible by step, we should add the remainder to max to
      // make sure that we don't lose any possible values.
      if ($max % $step !== 0) {
        $max = $max + ($step - $max % $step);
      }
    }

    // Creates an array of all results between min and max by the step from the
    // configuration.
    $new_results = [];
    for ($i = $min; $i <= $max; $i += $step) {
      $count = isset($simple_results['f_' . $i]) ? $simple_results['f_' . $i]['count'] : 0;
      $new_results[] = new Result($facet, (float) $i, (float) $i, $count);
    }

    // Overwrite the current facet values with the generated results.
    $facet->setResults($new_results);
  }

}
