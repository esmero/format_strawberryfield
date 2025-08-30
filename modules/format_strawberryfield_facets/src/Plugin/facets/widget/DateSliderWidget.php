<?php

namespace Drupal\format_strawberryfield_facets\Plugin\facets\widget;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\facets\FacetInterface;
use Drupal\facets\Result\Result;
use Drupal\facets\Widget\WidgetPluginBase;
use DateTime;
use DateTimeZone;
use DateInterval;
use Drupal\search_api_solr\Utility\Utility;
use Drupal\format_strawberryfield_facets\Plugin\facets\processor\DateRangeProcessor;


/**
 * The slider widget.
 *
 * @FacetsWidget(
 *   id = "sbf_date_slider",
 *   label = @Translation("Format Strawberryfield Date Slider"),
 *   description = @Translation("A widget that shows a slider for Dates."),
 * )
 */
class DateSliderWidget extends WidgetPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    // Note. dynamic_step is only used
    // to communicate dynamic step calculated
    // during the query. Can't be set up by the user.
    return [
        'prefix' => '',
        'suffix' => '',
        'min_type' => 'search_result',
        'min_value' => 1977,
        'max_type' => 'search_result',
        'restrict_to_fixed' => TRUE,
        'allow_full_entry' => TRUE,
        'allow_year_entry' => TRUE,
        'show_reset_link' => FALSE,
        'hide_reset_when_no_selection' => FALSE,
        'show_histogram' => TRUE,
        'histogram_bg_color' => 'CornflowerBlue',
        'histogram_color' => 'LightSkyBlue',
        'restrict_frequency_to_range' => TRUE,
        'reset_text' => $this->t('Reset'),
        'submit_text' => $this->t('Update'),
        'max_value' => gmdate('Y', time()),
        'step_variable_granularity' => TRUE,
        'step' => 1,
        'dynamic_step' => NULL,
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function build(FacetInterface $facet) {
    $build = parent::build($facet);

    $results = $facet->getResults();
    // Need to reflect if we really return if there are no results or we
    // at least show the "reset" link?
    if (empty($results)) {
      return $build;
    }

    $show_numbers = $facet->getWidgetInstance()->getConfiguration()['show_numbers'];
    // Range is Unixtime array after normalization.
    $active = $facet->getActiveItems();
    $active_min = reset($active)['min'] ?? NULL;
    $active_max = reset($active)['max'] ?? NULL;
    $range = $this->getRangeFromResults($results, $active_max);
    // Give $min a $max a default
    $min = $this->getConfiguration()['min_value'] ?? 0;
    $max = $this->getConfiguration()['max_value'] ?? date('Y');
    $min = strtotime($min.'-01-01');
    // add the last day bc we want to consider the complete year.
    $max = strtotime($max.'-12-31');
    ksort($results);

    // UnixTime
    $real_min = $range['min'] ?? NULL;
    $real_max = $range['max'] ?? NULL;

    // A gap override might have been set by \Drupal\format_strawberryfield_facets\Plugin\facets\query_type\SearchApiDateRange::execute
    // (means us)
    // Which is great bc it reflects the actual gap requested to the backend.
    $gap_in_years = $this->getConfiguration()['dynamic_step'] ?? $this->getConfiguration()['step'] ?? 1;
    // UnixTime
    $active_min = $active_min ?? $real_min;
    $active_max = $active_max ?? $real_max;
    $active_min = (int) $active_min;
    $active_max = (int) $active_max;
    // Give some defaults
    $year_max = gmdate('Y', (int) $active_max);
    $year_min = gmdate('Y', (int) $active_min);

    // UnixTime
    $unix_max = $active_max;
    $unix_min = $active_min;


    // Chances are we will always a real_min being capped down (bc of Face Range queries)
    // So we only opt for $real_min IF $active and real compared by YEAR are different.
    if (is_numeric($real_min) && $this->getConfiguration()['min_type'] == 'search_result') {
      $min = (int)gmdate('Y', $active_min) < (int)gmdate('Y', $real_min) ? $real_min : $active_min;
    }
    if (is_numeric($real_max) && $this->getConfiguration()['max_type'] == 'search_result') {
      $max = (int)gmdate('Y', $active_max) > (int)gmdate('Y',$real_max) ? $real_max : $active_max;
    }

    if (!empty($min)) {
      $year_min = gmdate('Y', (int) $min);
      $unix_min = (int) $min;
      // But here we move to formatted
      $min = gmdate('Y-m-d', (int) $min);
    }
    if (!empty($max)) {
      $year_max = gmdate('Y', (int) $max);
      $unix_max = $max;
      // But here we move to formatted
      $max = gmdate('Y-12-31', (int) $max);
      // for $max we literally need the last day of the year.
    }
    $time_zone = Utility::getTimeZone($facet->getFacetSource()->getIndex());

    $labels = [];
    $js_values = [];

    $max_items = 0;
    // Get the Timezone from Drupal or the Index.
    foreach ($results as $result) {
      if ($result->getRawValue() != 'summary_date_facet') {
        $dt = new DateTime();
        $dt->setTimestamp(intval(DateRangeProcessor::DateToUnix($result->getRawValue())));
        $dt->setTimezone(new DateTimeZone($time_zone));
        $offset = $dt->getOffset() / 3600;
        $offset = (int) $offset;
        if ($offset < 0) {
          $js_year = $dt->add(new DateInterval('PT' . abs($offset) . 'H'))
            ->format('Y');
        }
        else {
          $js_year = $dt->sub(new DateInterval('PT' . $offset . 'H'))
            ->format('Y');
        }

        $previous_count =  isset($js_values[$js_year]) ? $js_values[$js_year]['count'] : 0;
        $max_items = $max_items + $result->getCount();
        $js_values[$js_year] = [
          'count' => ((int) $result->getCount() + $previous_count),
          'label' =>  $js_year,
        ];
      }
    }
    ksort($js_values);
    // The results set on the facet are sorted where the minimum is the first
    // item and the last one is the one with the highest results, so it's safe
    // to use min/max.
    $chart_data = [];
    $chart_labels = [];
    foreach($js_values as $key => $js_value) {
      $labels[$key] = $js_value['label']. ($show_numbers ? ' (' . $js_value['count'] . ')' : '');
      if ($this->getConfiguration()['show_histogram']) {
        $chart_data[$key] = $js_value['count'];
        $chart_labels[$key] = $js_value['label'];
      }
    }
    $chart_data = array_values($chart_data);
    $chart_labels = array_values($chart_labels);

    // Independently if the max/min are set from search or from fixed values
    // these here need to be min/max we have either from search/or fixed if no query yet
    // Also, we need to check if we need to cap these based on the widget settings

    $values = [$year_min, $year_max];


    $selected_minmax = [gmdate('Y', (int) $active_min), gmdate('Y', (int) $active_max)];
    $real_minmax = [$min, $max];
    $id =  Html::getUniqueId('facet-sbf-slider-'.$facet->id());

    // We will reuse this for the form submit url
    $urlProcessorManager = \Drupal::service('plugin.manager.facets.url_processor');
    $url_processor = $urlProcessorManager->createInstance($facet->getFacetSourceConfig()->getUrlProcessorName(), ['facet' => $facet]);
    $urlGenerator = \Drupal::service('facets.utility.url_generator');
    $url = NULL;
    $reset_link = [];
    if ($this->getConfiguration()['show_reset_link'] && (!$this->getConfiguration()['hide_reset_when_no_selection'] || $facet->getActiveItems())) {
      // Add reset link even  if there are no results. Given that user might bypass completely the values
      // By using the GET arguments.
      $active_filters = $url_processor->getActiveFilters();
      unset($active_filters[$facet->id()]);
      if ($active_filters) {
        $url = $urlGenerator->getUrl($active_filters, FALSE);
      }
      else {
        $request = \Drupal::request();
        $facet_source = $facet->getFacetSource();
        $url = $urlGenerator->getUrlForRequest($request, $facet_source ? $facet_source->getPath() : NULL);
        $params = $request->query->all();
        unset($params[$url_processor->getFilterKey()]);
        if (\array_key_exists('page', $params)) {
          // Go back to the first page on reset.
          unset($params['page']);
        }
        $url->setRouteParameter('facets_query', '');
        $url->setOption('query', $params);
      }
      // Revisit $max_items; The count is correct, but it is the "date" values count ... not the actual results
      // So confusing.
      $result_item = new Result($facet, 'reset_all', $this->getConfiguration()['reset_text'], $max_items);
      $result_item->setActiveState(FALSE);
      $result_item->setUrl($url);

      // Check if any other facet is in use.
      $none_active = TRUE;
      foreach ($results as $result) {
        if ($result->isActive() || $result->hasActiveChildren()) {
          $none_active = FALSE;
          break;
        }
      }

      // Add an is-active class when no other facet is in use.
      if ($none_active) {
        $result_item->setActiveState(TRUE);
      }

      // Build item.
      $reset_link = $this->buildListItems($facet, $result_item);
      // Don't show the count. That might confuse users
      $reset_link['#title']['#show_count'] =  FALSE;

      // Add a class for the reset link wrapper.
      $reset_link['#wrapper_attributes']['class'][] = 'facets-reset';

      // Put reset facet link on first place.

    }


    $result = array_shift($results);
    $url_for_js = $result->getUrl();
    if (!$url_for_js) {
      $url_for_js = $url;
    }
    if ($url_for_js && $url_for_js instanceof \Drupal\core\Url) {
      $url = $url_for_js->toString();
      // We need to add the #theme key to avoid having \template_preprocess_item_list()
      // Assume the theme from the main facet needs to be inherited
      // Deleting all classes.
      $build['#items'] = [];
      if ( $this->getConfiguration()['show_histogram'] ) {
        $build['#items'][] =
          [
            '#type' => 'container',
            '#attributes' => [
              'style' => 'width:100%;max-height:12rem',
              'class' => ['sbf-date-facet-slider-chart'],
            ],
            'chart' => [
              '#type' => 'html_tag',
              '#tag' => 'canvas',
              '#attributes' => [
                'id' => $id . '-chart',
              ],
            ]
          ];
      }
      $build['#items'][] =
        [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'class' => ['sbf-date-facet-slider'],
          'data-min' => $unix_min,
          'data-max' => $unix_max,
          'id' =>  $id,
          'data-drupal-url' => $url
        ]
      ];

    }
    else {
      // If we could not get a URL we can build a widget here. Return the original Build.
      return $build;
    }


    $build['#attached']['library'][] = 'format_strawberryfield_facets/slider';

   if ($this->getConfiguration()['show_histogram'] ?? NULL && !empty($chart_labels) && !empty($chart_data)) {
     // For the chart.
     if ($chart_labels[0] != $year_min) {
       array_unshift($chart_labels, $year_min);
       array_unshift($chart_data, 0);
     }
     // For the chart.
     if (end($chart_labels) != $year_max) {
       $chart_labels[] = $year_max;
       $chart_data[] = 0;
     }
   }

    // WE need to define here if we are going for the "selected minmax"
    // Or for the minmax that actually fit the range/from facets
    // Or for the Fixed min max
    // All depends on settings.
    if ($this->getConfiguration()['restrict_frequency_to_range'] ?? NULL) {
      $slider_min = (int)$year_min;
      $slider_max = (int)$year_max;
    }
    else {
      $slider_min = (int)$selected_minmax[0];
      $slider_max = (int)$selected_minmax[1];
    }

    // because of settings deep merging we need to encode any data passed as
    // variable sized array and decode in JS back

    $build['#attached']['drupalSettings']['facets']['sliders'][$facet->id()] = [
      'htmlid' => $id,
      'min' => $slider_min,
      'max' => $slider_max,
      'values' => $values,
      'real_minmax' => $real_minmax,
      'time_zone' => $time_zone,
      'value' => isset($active[0]) ? (float) $active[0] : '',
      'step' => $this->getConfiguration()['step'],
      'labels' => json_encode($labels),
      'chart_data' => json_encode($chart_data),
      'chart_labels' => json_encode($chart_labels),
      'chart_color' => $this->getConfiguration()['histogram_color'],
      'chart_bg_color' => $this->getConfiguration()['histogram_bg_color']
    ];
    $build['#attributes']['class'][] = 'js-facets-widget';
    if (!empty($reset_link)) {
      array_unshift($build['#items'], $reset_link);
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, FacetInterface $facet) {
    $config = $this->getConfiguration();
    $form = parent::buildConfigurationForm($form, $form_state, $facet);

    $form['min_type'] = [
      '#type' => 'radios',
      '#options' => [
        'fixed' => $this->t('Fixed value'),
        'search_result' => $this->t('Based on search result'),
      ],
      '#title' => $this->t('Minimum value type'),
      '#default_value' => $config['min_type'],
    ];

    $form['min_value'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum value (in Years)'),
      '#description' => t('Used as hard minimum limit if "Minimum value type" is set "Fixed value" or if set to "Based on search result" and also "Truncate Facet results outside of the user selected range" is checked'),
      '#default_value' => $config['min_value'],
      '#size' => 10,
      '#required' => TRUE,
    ];

    $form['max_type'] = [
      '#type' => 'radios',
      '#options' => [
        'fixed' => $this->t('Fixed value'),
        'search_result' => $this->t('Based on search results'),
      ],
      '#title' => $this->t('Maximum value type'),
      '#default_value' => $config['max_type'],
    ];

    $form['max_value'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum value (in Years)'),
      '#default_value' => $config['max_value'],
      '#description' => t('Used as hard maximum limit if "Maximum value type" is set "Fixed value" or if set to "Based on search result" and also "Truncate Facet results outside of the user selected range" is checked. Any valued larger than the current YEAR will be capped to NOW().No metadata futurism for the sake of performance.'),
      '#required' => TRUE,
      '#size' => 5,
    ];
    $form['restrict_to_fixed'] =  [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Cap result based max/min to Fixed Ranges.'),
      '#description'        => $this->t('Even if max and min are "Based on search results", max and min will be capped to the Fixed values. This allows Outliers and wrong metadata to be never be taken in account.'),
      '#default_value' => $config['restrict_to_fixed'] ?? $this->defaultConfiguration()['restrict_to_fixed'],
    ];
    $form['restrict_frequency_to_range'] =  [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Use the real facet max/min instead of the User selected input'),
      '#description'         => $this->t('When checked, the user input will not be used in the Widget, but the actual max/min from the facets'),
      '#default_value' => $config['restrict_frequency_to_range'] ?? $this->defaultConfiguration()['restrict_frequency_to_range'],
    ];

    $form['reset_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Text for the reset link, if enabled'),
      '#size' => 128,
      '#default_value' => $config['reset_text'] ?? $this->defaultConfiguration()['reset_text'],
    ];

    $form['submit_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Update Filter button Text.'),
      '#size' => 128,
      '#default_value' => $config['submit_text'] ?? $this->defaultConfiguration()['submit_text'],
    ];

    $form['show_reset_link'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable Reset Link. It not present user needs to either reset the complete Search or use Facet Summaries, if enabled.'),
      '#default_value' => $config['show_reset_link'],
    ];

    $form['hide_reset_when_no_selection'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Hide Reset Link if the facet has no active values.'),
      '#default_value' => $config['hide_reset_when_no_selection'],
    ];

    $form['step'] = [
      '#type' => 'number',
      '#step' => 1,
      '#title' => $this->t('Base slider Step and Date range Facet Query "gap" in years'),
      '#default_value' => $config['step'],
      '#size' => 2,
    ];

    $form['step_variable_granularity'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Variable date Granularity and Date range Facet Query "gap", based on results'),
      '#default_value' => $config['step_variable_granularity'],
      '#description'   => $this->t(
        'When enabled, sliders steps will vary based on the min/max facet values (divided by the the hard limit of this facet). By default, e.g when no active values or no hard limit, base slider "step" setting in years will be used.'
      ),
    ];
    $form['allow_full_entry'] =  [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Allow manual Full DD/MM/YYYY entry'),
      '#default_value' => $config['allow_full_entry'],
    ];
    $form['allow_year_entry'] =  [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Allow manual Full YYYY entry'),
      '#default_value' => $config['allow_year_entry'],
    ];
    $form['show_histogram'] =  [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Display Histogram'),
      '#default_value' => $config['show_histogram'] ?? $this->defaultConfiguration()['show_histogram'],
    ];
    $form['histogram_color'] =  [
      '#type' => 'textfield',
      '#title' => $this->t('Histogram Line Color.'),
      '#size' => 128,
      '#default_value' => $config['histogram_color'] ?? $this->defaultConfiguration()['histogram_color'],
      '#states' => [
        'visible' => [
          ':input[name="widget_config[show_histogram]"]' => ['checked' => TRUE],
        ],
      ]
    ];
    $form['histogram_bg_color'] =  [
      '#type' => 'textfield',
      '#title' => $this->t('Histogram Background Color.'),
      '#size' => 128,
      '#default_value' => $config['histogram_bg_color'] ?? $this->defaultConfiguration()['histogram_bg_color'],
      '#states' => [
        'visible' => [
          ':input[name="widget_config[show_histogram]"]' => ['checked' => TRUE],
        ],
      ]
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function isPropertyRequired($name, $type) {
    if ($name === 'sbf_date_slider' && $type === 'processors') {
      return TRUE;
    }
    if ($name === 'show_only_one_result' && $type === 'settings') {
      return TRUE;
    }

    return FALSE;
  }
  protected function getRangeFromResults(array $results, $active_max) {
    /* @var \Drupal\facets\Result\ResultInterface[] $results */
    $min = NULL;
    $max = NULL;
    foreach ($results as $result) {
      if ($result->getRawValue() == 'summary_date_facet') {
        continue;
      }
      // Warning. Depending on the "field" (normal data v/s date range) type RAW values might be UNIX Time stamps or actual
      // ISO Dates. Normalize to unix.

      $raw = DateRangeProcessor::DateToUnix($result->getRawValue());
      $min = $min ?? $raw;
      $max = $max ?? $raw;
      $min = $min < $raw ? $min : $raw;
      $max = $max > $raw ? $max : $raw;
    }
    $dynamic_step = $this->getConfiguration()['dynamic_step'];
    // Check for explicit NULL bc $active_max Might be a 0.
    error_log('step '.$dynamic_step);
    // NOTE for tomorrow (will remove later)
    // Because we have to use Time Zone offsets on the query
    // The buckets from the date range (not the ones from the normal dates)
    // Are already offset. So what happens is if we take that value as the max and min
    // And query again, we will YET again offset by time zone
    // That has really NO effect when the dynamic step is large, but if it is
    // One year, then we have a back and forth jump.
    // Solution, the facets here need to have timezone yet again removed!
    if ($dynamic_step && $dynamic_step > 1 && $active_max!== NULL  ) {
      // check if $max + step is IN active value, if any.
      $next_range = strtotime("+{$dynamic_step} years", $max);
      error_log('step '.$dynamic_step);
      error_log('active '.gmdate(DATE_ATOM, $active_max));
      error_log('max_from results '.gmdate(DATE_ATOM, $max));
      error_log('Next range'. gmdate(DATE_ATOM, $next_range));
      if ($active_max < $next_range) {
        $max = $active_max;
        error_log('adjusting to active');
      }
    }


    return ['min' => $min, 'max' => $max];
  }


}
