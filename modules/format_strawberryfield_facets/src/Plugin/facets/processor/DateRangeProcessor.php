<?php

declare(strict_types = 1);

namespace Drupal\format_strawberryfield_facets\Plugin\facets\processor;

use Drupal\Core\Form\FormStateInterface;
use Drupal\facets\FacetInterface;
use Drupal\facets\Processor\PreQueryProcessorInterface;
use Drupal\facets\Processor\ProcessorPluginBase;
use Drupal\facets\Processor\BuildProcessorInterface;
use Drupal\facets\Result\Result;

/**
 * Provides a processor that show all values between a range of dates.
 *
 * @FacetsProcessor(
 *   id = "sbf_date_range",
 *   label = @Translation("Format Strawberryfield Date Range Picker"),
 *   description = @Translation("Show all content between min and max range dates."),
 *   stages = {
 *     "pre_query" = 60,
 *     "build" = 20
 *   }
 * )
 */
class DateRangeProcessor extends ProcessorPluginBase implements PreQueryProcessorInterface, BuildProcessorInterface {

  /**
   * {@inheritdoc}
   */
  public function preQuery(FacetInterface $facet): void {
    $active_items = $facet->getActiveItems();

    array_walk($active_items, function (&$item) {
      if (is_string($item)) {
        if (preg_match('/\(min:([^,]*),max:(.*)\)/i', $item, $matches)) {
          if (!empty($matches[1]) && !empty($matches[2])) {
            $item = [
              $matches[1],
              $matches[2],
              'min' => $matches[1],
              'max' => $matches[2],
            ];
          }
          elseif (!empty($matches[1]) && empty($matches[2])) {
            $item = [
              $matches[1],
              date('U', strtotime('+100 years')),
              'min' => $matches[1],
            ];
          }
          elseif (empty($matches[1]) && !empty($matches[2])) {
            $item = [
              date('U', 0),
              $matches[2],
              'max' => $matches[2],
            ];
          }
          else {
            $item = [
              date('U', 0),
              date('U', strtotime('+100 years')),
            ];
          }
        }
        else {
          $item = [];
        }
      }
      elseif (is_array($item)) {
        // Inverse min max if min > max
        if (isset($item[0]) && isset($item[1]) && $item[0] > $item[1]) {
          $item = [
            (int) $item[1],
            (int) $item[0],
            'min' => (int) $item[1],
            'max' => (int) $item[0],
          ];
        }
        else {
          $item = $item;
        }
      }
      else {
        $item = [];
      }
    });

    $facet->setActiveItems($active_items);
  }

  /**
   * {@inheritdoc}
   */
  public function build(FacetInterface $facet, array $results): array {
    /** @var \Drupal\facets\Plugin\facets\processor\UrlProcessorHandler $url_processor_handler */
    $url_processor_handler = $facet->getProcessors()['url_processor_handler'];
    $url_processor = $url_processor_handler->getProcessor();
    $filter_key = $url_processor->getFilterKey();
    $url = NULL;
    /** @var \Drupal\facets\Result\ResultInterface[] $results */
    foreach ($results as &$result) {
      $url = $result->getUrl();
      $query = $url->getOption('query');

      // Remove all the query filters for the field of the facet.
      if (isset($query[$filter_key])) {
        foreach ($query[$filter_key] as $id => $filter) {
          if (strpos($filter . $url_processor->getSeparator(), $facet->getUrlAlias()) === 0) {
            unset($query[$filter_key][$id]);
          }
        }
      }

      $query[$filter_key][] = $facet->getUrlAlias() . $url_processor->getSeparator() . '(min:__range_slider_min__,max:__range_slider_max__)';
      $url->setOption('query', $query);
      $result->setUrl($url);
    }
    if (count($results)) {
      $active_filters = $url_processor->getActiveFilters();
      /* @var \Drupal\facets\Utility\FacetsUrlGenerator $urlGenerator */
      $urlGenerator = \Drupal::service('facets.utility.url_generator');
      if ($active_filters) {
        $url_active = $urlGenerator->getUrl($active_filters, FALSE);
        unset($active_filters[$facet->id()]);
        if (!count($active_filters)){
          $options = $url_active->getOptions();
          if ($options['query']) {
            unset($options['query'][$url_processor->getFilterKey()]);
            $url_active->setOptions($options);
          }
        }
        else {
          // Calculate again by passing active_filter without the current facet
          $url_active = $urlGenerator->getUrl($active_filters, FALSE);
        }
      }
      else {
        // This has core issues and we hope it won't ever match
        // Core issue is that ::getUrlForRequest does not work well
        // And fetches for a View the wrong Display and then caches it!
        $request = \Drupal::request();
        $facet_source = $facet->getFacetSource();
        $url_active = $urlGenerator->getUrlForRequest($request, $facet_source ? $facet_source->getPath() : NULL);
        $params = $request->query->all();
        unset($params[$url_processor->getFilterKey()]);
        $url_active->setRouteParameter('facets_query', '');
        $url_active->setOption('query', $params);
      }
      $range = [];
      $range = $facet->getActiveItems();
      if (isset($range[0])) {
        $range[0]['count'] = array_sum(array_map(function ($item) {
          return $item->getCount();
        }, $results));
      }
      if (count($range) == 0) {
        // Means we are already are not inside a valid range, give this a 0 count
        // Just to allow people to reset this.
        $range[] = $this->getRangeFromResults($results);
      }
      // generate an active value for the active facet
      foreach ($range as $range_entry) {
        $min_unix = (int) ($range_entry['min'] ?? 0);
        $max_unix = (int) ($range_entry['max'] ?? 0);
        if ($this->getConfiguration()['variable_granularity']) {
          $date_min = gmdate("Y", $min_unix);
          $date_max = gmdate("Y", $max_unix);
          if ($date_min == $date_max) {
            $date_min = gmdate("Y/m/d", $min_unix);
            $date_max = gmdate("Y/m/d", $max_unix);
          }
          $label = $date_min . ' - ' . $date_max;
          if ($date_min == $date_max) {
            $label = $date_min;
          }
        }
        else {
          $date_min = gmdate("Y/m/d", $min_unix);
          $date_max = gmdate("Y/m/d", $max_unix);
          $label = $date_min . ' - ' . $date_max;
          if ($date_min == $date_max) {
            $label = $date_min;
          }
        }

        $result_item_active = new Result(
          $facet, 'summary_date_facet', $label, $range_entry['count']
        );
        $result_item_active->setActiveState(TRUE);
        $result_item_active->setUrl($url_active);
        $results[] = $result_item_active;
      }
    }
    return $results;
  }

  protected function getRangeFromResults(array $results) {
    /* @var \Drupal\facets\Result\ResultInterface[] $results */
    $min = NULL;
    $max = NULL;
    $count = 0;
    foreach ($results as $result) {
      if ($result->getRawValue() == 'summary_date_facet') {
        continue;
      }
      $min = $min ?? $result->getRawValue();
      $max = $max ?? $result->getRawValue();
      if ($result->getRawValue() >= $min) {
        $count = $count + $result->getCount();
      }
      if ($result->getRawValue() <= $max) {
        $count = $count + $result->getCount();
      }

      $min = $min < $result->getRawValue() ? $min : $result->getRawValue();
      $max = $max > $result->getRawValue() ? $max : $result->getRawValue();
    }
    if ($min && $max && $count) {
      return ['min' => $min, 'max' => $max, 'count' => $count];
    }
    else {
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'variable_granularity' => TRUE,
        'enabled' => FALSE,
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, FacetInterface $facet) {
    $configuration = $this->getConfiguration();

    $build['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use Format Strawberryfield Date Range Picker'),
      '#default_value' => $configuration['enabled'],
      '#states' => [
        'required' => [
          ':input[name="facet_settings[sbf_date_range][status]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $build['variable_granularity'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Variable Date Granularity for Facet Summary/active Range'),
      '#default_value' => $configuration['variable_granularity'],
      '#description'   => $this->t(
        'When enabled Facet Summaries (coming from the currently Active result label) will change to years when the range spans more than a single year, or full dates if otherwise. Disabled means the input dates as passed by the user will be used directly.'
      ),
      '#states' => [
        'visible' => [
          ':input[name="facet_settings[sbf_date_range][settings][enabled]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    return $build;
  }
}
