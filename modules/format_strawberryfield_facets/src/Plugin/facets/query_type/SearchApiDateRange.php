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
    $min = $widget_config['min_value'] ?? 1977;
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
    // daylight time shift, the offset gets weird. Still, we need the proper
    // ones, but to be precise because of historic LMT, we use minutes.
    $offset_max  = (int) ($dt_max->getOffset() / 60);
    $offset_min  = (int) ($dt_min->getOffset() / 60);
    if ($offset_max < 0) {
      $min_value = $dt_min->add(new DateInterval('PT' . abs($offset_min) . 'M'));
      $max_value = $dt_max->add(new DateInterval('PT' . abs($offset_max) . 'M'));
    }
    else {
      $min_value = $dt_min->sub(new DateInterval('PT' . abs($offset_min) . 'M'));
      $max_value = $dt_max->sub(new DateInterval('PT' . abs($offset_max) . 'M'));
    }
    $widget_config = $this->facet->getWidgetInstance()->getConfiguration();
    // mmmm... because the dates are already indexed using an offset
    // the query v/s the range might need adjustments so we actually FIT
    // what is in the index.
    // Search API will kick out any attempts of setting value for query
    // with a /DAY op.
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
    $reminder = abs($diff_in_hours % $limit);
    // Review if adding 1 is ok or not.
    if ($reminder > $gap_hours) { // means my last bucket does not span the full date range.
      $gap_hours = $gap_hours + 1;
      // Redefines limit (less values)
      $limit = intdiv($diff_in_hours, $gap_hours); // lower limit to fit an increased gap
      $reminder = abs($diff_in_hours % $limit);
    }
    if ($gap_hours <= 24) { // 1 day
      $granularity = $gap_hours == 1 ? '+1HOUR' : "+{$gap_hours}HOURS";
      $widget_config['dynamic_step'] = $reminder > 0 ? $reminder : $gap_hours;
      $widget_config['dynamic_step_granularity_unit'] = 'hours';
      $this->facet->getWidgetInstance()
        ->setConfiguration($widget_config);
      return  [
        'granularity' => $granularity,
        'gap' => $gap_hours,
      ] + $base_info;
    }


    $gap_days = intdiv($diff_in_days, $limit);
    $reminder = abs($diff_in_days % $limit);
    if ($reminder > $gap_days) { // means my last bucket does not span the full date range.
      $gap_days = $gap_days + 1;
      // Redefines limit (less values)
      $limit = intdiv($diff_in_days, $gap_days); // lower limit to fit an increased gap
      $reminder = abs($diff_in_days % $limit);
    }

    if ($gap_days < 31) { // A month
      $granularity = $gap_days == 1 ? '+1DAY' : "+{$gap_days}DAYS";
      $widget_config['dynamic_step'] = $reminder > 0 ? $reminder : $gap_days;
      $widget_config['dynamic_step_granularity_unit'] = 'days';
      $this->facet->getWidgetInstance()
        ->setConfiguration($widget_config);
      return [
        'granularity' => $granularity,
        'gap' => $gap_days,
      ] + $base_info;
    }

    $gap_months = intdiv($diff_in_months, $limit);
    $reminder = abs($diff_in_months % $limit);
    if ($reminder > $gap_months) { // means my last bucket does not span the full date range.
      $gap_months = $gap_months + 1;
      // Redefines limit (less values)
      $limit = intdiv($diff_in_months, $gap_months); // lower limit to fit an increased gap
      $reminder = abs($diff_in_months % $limit);
    }
    if ($gap_months < 60) { // 5 years
      $granularity = $gap_months == 1 ? '+1MONTH' : "+{$gap_months}MONTHS";
      $widget_config['dynamic_step'] = $reminder > 0 ? $reminder : $gap_months;
      $widget_config['dynamic_step_granularity_unit'] = 'months';
      $this->facet->getWidgetInstance()
        ->setConfiguration($widget_config);
      return [
        'granularity' => $granularity,
        'gap' => $gap_months,
      ] + $base_info;
    }

    $gap_years = intdiv($diff_in_years, $limit);
    $reminder  = abs($diff_in_years % $limit);
    if ($reminder > $gap_years) { // means my last bucket does not span the full date range.
      $gap_years = $gap_years + 1;
    // Redefines limit (less values)
      $limit = intdiv($diff_in_years, $gap_years); // lower limit to fit an increased gap
      $reminder = abs($diff_in_years % $limit);
    }
    $granularity = $gap_years == 1 ? '+1YEAR' : "+{$gap_years}YEARS";
    $widget_config['dynamic_step'] = $reminder > 0 ? $reminder : $gap_years;
    $widget_config['dynamic_step_granularity_unit'] = 'years';
    $this->facet->getWidgetInstance()
      ->setConfiguration($widget_config);
    return [
      'granularity' => $granularity,
      'gap' => $gap_years,
    ] + $base_info;
  }
}
