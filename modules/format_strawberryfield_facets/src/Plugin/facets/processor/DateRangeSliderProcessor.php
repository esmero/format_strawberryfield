<?php

namespace Drupal\format_strawberryfield_facets\Plugin\facets\processor;

use Drupal\facets\FacetInterface;
use Drupal\facets\Processor\BuildProcessorInterface;
use Drupal\facets\Processor\PreQueryProcessorInterface;
use Drupal\facets\Result\Result;
use Drupal\format_strawberryfield_facets\Plugin\facets\processor\DateRangeProcessor;

/**
 * Provides a processor that adds all range values between and min and max range.
 *
 * @FacetsProcessor(
 *   id = "sbf_date_range_slider",
 *   label = @Translation("Format Strawberryfield Date Range slider"),
 *   description = @Translation("Add range Date results for all the steps between min and max range."),
 *   stages = {
 *     "pre_query" =60,
 *     "build" = 20
 *   }
 * )
 */
class DateRangeSliderProcessor extends DateRangeProcessor implements PreQueryProcessorInterface, BuildProcessorInterface {

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

      $query[$filter_key][] = $facet->getUrlAlias() . $url_processor->getSeparator() . '(min:__date_range_min__,max:__date_range_max__)';
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
        // This has core issues, and we hope it won't ever match
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

}
