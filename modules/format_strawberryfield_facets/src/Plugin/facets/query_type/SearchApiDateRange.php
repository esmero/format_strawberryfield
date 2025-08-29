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
        // When set this will contain a 0, 1 with the real values and a min and max with the set values from the widget.
        $time_zone = Utility::getTimeZone($this->facet->getFacetSource()->getIndex());
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
                $reminder = $diff_years % $min_count_for_facet;

                // Reminder is unused for now, bc I can't request to solr that the last or first range covers more
                // via the search API (i could via custom code)
                $gap = (int) round($gap, 0);
                $gap = $gap < $base_gap ? $base_gap : $gap;
                  // Only way to communicate to the Widget itself. Setting it here only survives a single PHP call and does
                  // not permanently override the defaults. Which is what we need!
                $widget_config['dynamic_step'] = $gap;
                $this->facet->getWidgetInstance()->setConfiguration($widget_config);
                // Now, Solr Index might have dates offset. Bc Drupal will calculate a certain date based on its internal timezone
                // during "index" but Solr will get then another based on UTC
                // Basically If someone asks for 1911-1915 (and our date is 1911/1915) Solr will really have
                //" dm_date_created_original":["1911-01-01T05:00:00Z", "1916-01-01T04:59:59Z"]
                // Or drm_date_as_range":["[1911-01-01T05:00:00Z TO 1916-01-01T04:59:59Z]"],

                $dt_min_utc = new DateTime('@' . $min);
                $dt_min = new DateTime($dt_min_utc->format('Y-m-d\TH:i:s'), new DateTimeZone($time_zone));
                $dt_max_utc = new DateTime('@' . $max);
                $dt_max = new DateTime($dt_max_utc->format('Y-m-d\TH:i:s'), new DateTimeZone($time_zone));
                // Offset can be retrieved from both min or max.
                $offset = $dt_max->getOffset() / 3600;
                $offset = (int) $offset;
                if ($offset < 0) {
                  $min_value = $dt_min->add(new DateInterval('PT' . abs($offset) . 'H'))
                    ->format('Y-m-d\TH:i:s\Z');
                  $max_value = $dt_max->add(new DateInterval('PT' . abs($offset) . 'H'))
                    ->format('Y-m-d\TH:i:s\Z');
                }
                else {
                  $min_value = $dt_min->sub(new DateInterval('PT' . $offset . 'H'))
                    ->format('Y-m-d\TH:i:s\Z');
                  $max_value = $dt_max->sub(new DateInterval('PT' . $offset . 'H'))
                    ->format('Y-m-d\TH:i:s\Z');
                }

                $options['search_api_facets'][$field_identifier]['min_value'] = $min_value;
                $options['search_api_facets'][$field_identifier]['max_value'] = $max_value;

                $options['search_api_facets'][$field_identifier]['granularity'] = '+' . $gap . 'YEAR';
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
    $min = $widget_config['min_value'] ?? NULL;
    $max = $widget_config['max_value'] ?? NULL;
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
    return [
      'field' => $this->facet->getFieldIdentifier(),
      'limit' => $this->facet->getHardLimit(),
      'operator' => $this->facet->getQueryOperator(),
      'min_count' => $this->facet->getMinCount(),
      'missing' => $this->facet->isMissing(),
      'query_type' =>  'search_api_granular',
      'min_value' => gmdate('Y-m-d\TH:i:s\Z', mktime(0,0,0,1,1, $min)),
      'max_value' => gmdate('Y-m-d\TH:i:s\Z', mktime(0,0,0,12,31, $max)),
      'granularity' => '+1YEAR',
      'include_lower' => TRUE,
      'include_upper' => TRUE,
      'include_edges' => TRUE,
    ];
  }
}
