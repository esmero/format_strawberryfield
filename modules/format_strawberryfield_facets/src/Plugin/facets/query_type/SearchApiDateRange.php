<?php

namespace Drupal\format_strawberryfield_facets\Plugin\facets\query_type;

use Drupal\facets\QueryType\QueryTypePluginBase;
use Drupal\facets\Result\Result;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api_solr\Utility\Utility;
use DateTime;
use DateTimeZone;
use DateInterval;

/**
 * Provides support for date range facets within the Search API scope.
 *
 * This is a Solr Specific Implementation for Search API Solr.
 *
 * @FacetsQueryType(
 *   id = "search_api_sbf_date_range",
 *   label = @Translation("Date Range"),
 * )
 */
class SearchApiDateRange extends QueryTypePluginBase {

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $query = $this->query;

    // Only alter the query when there's an actual query object to alter.
    if (!empty($query)) {
      $operator = $this->facet->getQueryOperator();
      $field_identifier = $this->facet->getFieldIdentifier();
      $exclude = $this->facet->getExclude();

      if ($query->getProcessingLevel() === QueryInterface::PROCESSING_FULL) {
        // Set the options for the actual query.
        $options = &$query->getOptions();
        $facet_options_clean = $this->getFacetOptions();
        $options['search_api_facets'][$field_identifier] = $facet_options_clean;
        $options['sbf_date_stats_field'][$field_identifier] = $facet_options_clean;
      }

      // Add the filter to the query if there are active values.
      $active_items = $this->facet->getActiveItems();

      if (count($active_items)) {
        $widget_config = $this->facet->getWidgetInstance()->getConfiguration();
        $filter = $query->createConditionGroup($operator, ['facet:' . $field_identifier]);
        foreach ($active_items as $value) {
          if (is_array($value) && count($value) > 2) {
            $value = array_slice($value, 0, 2);
            $value = array_values($value);
          }
          if (is_array($value) && count($value) == 2 && isset($value[0]) && isset($value[1])) {
            $min_value = (int) $value[0];
            $max_value = (int) $value[1];
            // Calculate dynamic gap. Active Items will be in unix time/ We need years
            $options = &$query->getOptions();
            if (isset($options['search_api_facets'][$field_identifier])) {
              $min = (int) ($value[0] ?? 0);
              $max = (int) ($value[1] ?? time());
              // Seconds in a year; 31556952. Just a nerdy comment.
              if ($min > $max) {
                // IN case someone inverts the whole stuff
                $orig_min = $min;
                $min = $max;
                $max = $orig_min;
              }

              $min_count_for_facet = $this->facet->getHardLimit();
              $min_count_for_facet = $min_count_for_facet > 0 ? $min_count_for_facet : 100;
              // A min count of 0 is not useful. We will never really get all of them here
              // Also we can't divide by 0!
              try {
                $base_gap = $widget_config['step'];
                $diff_years = gmdate('Y', $max) - gmdate('Y', $min) + 1;
                $gap = abs(ceil($diff_years / $min_count_for_facet));
                $gap = $gap == 0 ? 1 : $gap;
                $reminder = $diff_years % $gap;


                $gap = (int) round($gap, 0);
                $gap = $gap < $base_gap ? $base_gap : $gap;
                // Only way to communicate to the Widget itself. Setting it here only survives a single PHP call and does
                // not permanently override the defaults. Which is what we need!
                $widget_config['dynamic_step'] = $reminder > 0 ? $reminder : $gap;
                $this->facet->getWidgetInstance()
                  ->setConfiguration($widget_config);
                // Now, Solr Index might have dates offset. Bc Drupal will calculate a certain date based on its internal timezone
                // during "index" but Solr will get then another based on UTC
                // Basically If someone asks for 1911-1915 (and our date is 1911/1915) Solr will really have
                //" dm_date_created_original":["1911-01-01T05:00:00Z", "1916-01-01T04:59:59Z"]
                // Or drm_date_as_range":["[1911-01-01T05:00:00Z TO 1916-01-01T04:59:59Z]"],

                $calculated_options = $this->getSorlFacetRangeInfoFromData($min,$max,$min_count_for_facet);
                $min_value = $calculated_options['min_value_for_query'];
                $max_value = $calculated_options['max_value_for_query'];
                $options_clean = array_intersect_key($calculated_options, $options['search_api_facets'][$field_identifier]);
                $options['search_api_facets'][$field_identifier]  = array_replace($options['search_api_facets'][$field_identifier] , $options_clean);
                $options['sbf_date_stats_field'][$field_identifier] = $options['search_api_facets'][$field_identifier];
              }
              catch (\Exception $e) {
                // Some gmdate math failed. WE do not keep processing and return to the defaults.
                $min_value = $min;
                $max_value = $max;
              }
            }
            $value = [$min_value, $max_value];
            $filter->addCondition($field_identifier, $value, $exclude ? 'NOT BETWEEN' : 'BETWEEN');
          }
        }
        $query->addConditionGroup($filter);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $query_operator = $this->facet->getQueryOperator();

    if (!empty($this->results)) {
      $facet_results = [];
      foreach ($this->results as $result) {
        if ($result['count'] || $query_operator === 'or') {
          $count = $result['count'];
          while (is_array($result['filter'])) {
            $result['filter'] = current($result['filter']);
          }
          $result_filter = trim($result['filter'], '"');
          if ($result_filter === 'NULL' || $result_filter === '') {
            // "Missing" facet items could not be handled in ranges.
            continue;
          }
          // Ideally we should set here the label based on the desired granularity
          // Which we can calculate based on the min and max if set to variable.
          $result = new Result($this->facet, $result_filter, $result_filter, $count);
          $facet_results[] = $result;
        }
      }
      $this->facet->setResults($facet_results);
    }
    return $this->facet;
  }

  /**
   * Builds facet options that will be sent to the backend.
   *
   * @return array
   *   An array of default options for the facet.
   */
  protected function getFacetOptions() {
    $this->facet->getActiveItems();
    // We will use the Widget Config here to pass min/max and other things
    $widget_config = $this->facet->getWidgetInstance()->getConfiguration();
    $min = $widget_config['min_value'] ?? 1971;
    $max = $widget_config['max_value'] ?? (int) (date("Y"));
    $base_gap = $widget_config['step'];
    // These will be swapped by active values, if any
    // GAP will be recalculated if active values are present.
    // We use 'search_api_granular' instead of this plugin, so we can
    // make Search API SOLR call directly a Solarium JSON RANGE FACET.
    // See \Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend::setFacets
    /*
     * $facet_field = $facet_set->createFacetRange([
            'local_key' => $solr_field,
            'field' => $solr_field,
            'start' => $info['min_value'],
            'end' => $info['max_value'],
            'gap' => $info['granularity'],
          ]);
          $includes = [];
          if ($info['include_lower']) {
            $includes[] = 'lower';
          }
          if ($info['include_upper']) {
            $includes[] = 'upper';
          }
          if ($info['include_edges']) {
            $includes[] = 'edge';
          }
     */

    $limit = (int) $this->facet->getHardLimit();
    $calculated_options = $this->getSorlFacetRangeInfoFromData(
      (int) gmmktime(0, 0, 0, 1, 1, $min),
      (int) gmmktime(0, 0, 0, 12, 31, $max),
      $limit
    );

    return [
      'field' => $this->facet->getFieldIdentifier(),
      'limit' => $limit,
      'operator' => $this->facet->getQueryOperator(),
      'min_count' => $this->facet->getMinCount(),
      'missing' => $this->facet->isMissing(),
      'query_type' => 'search_api_granular',
      'min_value' => $calculated_options['min_value_for_query'],
      'max_value' => $calculated_options['max_value_for_query'],
      'granularity' => $calculated_options['granularity'],
      'include_lower' => TRUE,
      'include_upper' => $calculated_options['include_upper'] ?? TRUE,
      'include_edges' => $calculated_options['include_edges'] ?? TRUE,
    ];
  }

  public function getSorlFacetRangeInfoFromData(int $min_timestamp, int $max_timestamp, int &$limit): array {
    if ($min_timestamp > $max_timestamp) {
      $orig_min = $min_timestamp;
      $min_timestamp = $max_timestamp;
      $max_timestamp = $orig_min;
    }
    if ($limit <= 0)  {
      $limit = 100;
    }

    $time_zone = Utility::getTimeZone($this->facet->getFacetSource()
      ->getIndex());
    $dt_min_utc = new DateTime('@' . $min_timestamp);
    $dt_max_utc = new DateTime('@' . $max_timestamp);
    $dt_min = new DateTime($dt_min_utc->format('Y-m-d\TH:i:s'), new DateTimeZone($time_zone));
    $dt_max = new DateTime($dt_max_utc->format('Y-m-d\TH:i:s'), new DateTimeZone($time_zone));
    // Offset for both is normally the SAME BUT, on BCE, because there was no
    // daylight time shift, the offset gets weird.
    $offset_min  = (int) ($dt_min->getOffset() / 3600);
    $offset_max  = (int) ($dt_max->getOffset() / 3600);

    if ($offset_min < 0) {
      $min_value = $dt_min->add(new DateInterval('PT' . abs($offset_min) . 'H'));
      $max_value = $dt_max->add(new DateInterval('PT' . abs($offset_max) . 'H'));
    }
    else {
      $min_value = $dt_min->sub(new DateInterval('PT' . abs($offset_min) . 'H'));
      $max_value = $dt_max->sub(new DateInterval('PT' . abs($offset_max) . 'H'));
    }

    $base_info = [
      'min_value' => $min_value->format('Y-m-d\TH:i:s\Z'),
      'max_value' => $max_value->format('Y-m-d\TH:i:s\Z'),
      'min_value_for_query' => $min_value->format('Y-m-d\TH:i:s\Z'),
      'max_value_for_query' => $max_value->format('Y-m-d\TH:i:s\Z'),
    ];

    if ($min_timestamp == $max_timestamp) {
      return  [
        'granularity' => '+1DAY',
        'include_upper' => FALSE,
        'include_edges' => FALSE,
        'max_value' => $max_value->format('Y-m-d\TH:i:s\Z').'+1DAY',
      ] + $base_info;
    }

    $date_start = $dt_min_utc;
    $date_end = $dt_max_utc;

    $diff = $date_start->diff($date_end);
    $diff_in_years = $diff->y;
    $diff_in_months = ($diff->y * 12) + $diff->m;
    $diff_in_days = $diff->days;
    $diff_in_hours = ($diff_in_days * 24) + $diff->h;
    // we want to avoid having too small buckets?

    $gap_hours = intdiv($diff_in_hours, $limit);
    $gap_hours = abs($diff_in_hours % $limit) > 0 ? $gap_hours + 1 : $gap_hours;
    if ($gap_hours <= 24) { // 1 day
      return  [
        'granularity' => "+{$gap_hours}HOUR",
        'gap' => $gap_hours,
      ] + $base_info;
    }


    $gap_days = intdiv($diff_in_days, $limit);
    $gap_days = abs($diff_in_days % $limit) > 0 ? $gap_days + 1 : $gap_days;

    if ($gap_days < 31) { // A month
      return [
        'granularity' => "+{$gap_days}DAY",
        'gap' => $gap_days,
      ] + $base_info;
    }

    $gap_months = intdiv($diff_in_months, $limit);
    $gap_months = abs($diff_in_months % $limit) > 0 ? $gap_months + 1 : $gap_months;

    if ($gap_months < 60) { // 5 years
      return [
        'granularity' => "+{$gap_months}MONTH",
        'gap' => $gap_months,
      ] + $base_info;
    }

    $gap_years = intdiv($diff_in_years, $limit);
    $gap_years = abs($diff_in_years % $limit) > 0 ? $gap_years + 1 : $gap_years;

    return [
      'granularity' => "+{$gap_years}YEARS",
      'gap' => $gap_years,
    ] + $base_info;
  }

}
