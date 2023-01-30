<?php

declare(strict_types = 1);

namespace Drupal\format_strawberryfield_facets\Plugin\facets\processor;

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

      $query[$filter_key][] = $facet->getUrlAlias() . $url_processor->getSeparator() . '(min:__date_range_min__,max:__date_range_max__)';
      $url->setOption('query', $query);
      $result->setUrl($url);
    }
    if (count($results)) {
      $urlProcessorManager = \Drupal::service('plugin.manager.facets.url_processor');
      $url_processor = $urlProcessorManager->createInstance($facet->getFacetSourceConfig()->getUrlProcessorName(), ['facet' => $facet]);
      $active_filters = $url_processor->getActiveFilters();
      unset($active_filters[$facet->id()]);
      $urlGenerator = \Drupal::service('facets.utility.url_generator');
      if ($active_filters) {
        $url_active = $urlGenerator->getUrl($active_filters, FALSE);
      }
      else {
        $request = \Drupal::request();
        $facet_source = $facet->getFacetSource();
        $url_active = $urlGenerator->getUrlForRequest($request, $facet_source ? $facet_source->getPath() : NULL);
        $params = $request->query->all();
        unset($params[$url_processor->getFilterKey()]);
        $url_active->setRouteParameter('facets_query', '');
        $url_active->setOption('query', $params);
      }
      $range = [];
      $range[] = $this->getRangeFromResults($results);
      if (count($range) == 0) {
        // Means we are already are not inside a valid range, give this a 0 count
        // Just to allow people to reset this.
        $range = $facet->getActiveItems();
        if (isset($range[0])) {
          $range[0]['count'] = 0;
        }
      }
      // generate an active value for the active facet
      foreach ($range as $range_entry) {
        $min_unix = (int) $range_entry['min'] ?? 0;
        $max_unix = (int) $range_entry['max'] ?? 0;
        $date_min = date("Y", $min_unix);
        $date_max = date("Y", $max_unix);
        if ($date_min == $date_max) {
          $date_min = date("Y/m/d", $min_unix);
          $date_max = date("Y/m/d", $max_unix);
        }
        $label = $date_min . ' - ' . $date_max;
        if ($date_min == $date_max) {
          $label = $date_min;
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

}
