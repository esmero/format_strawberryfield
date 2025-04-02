<?php

namespace Drupal\format_strawberryfield_facets\Plugin\facets\widget;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\facets\FacetInterface;
use Drupal\facets\Result\Result;
use Drupal\facets\Widget\WidgetPluginBase;
use DateTime;
use DateTimeZone;
/**
 * The slider widget.
 *
 * @FacetsWidget(
 *   id = "sbf_date_slider",
 *   label = @Translation("Format Strawberryfield Date slider"),
 *   description = @Translation("A widget that shows a slider for Dates."),
 * )
 */
class DateSliderWidget extends WidgetPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
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
        'restrict_frequency_to_range' => TRUE,
        'reset_text' => $this->t('Reset'),
        'submit_text' => $this->t('Update'),
        'max_value' => gmdate('Y', time()),
        'step_variable_granularity' => TRUE,
        'step' => 1,
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function build(FacetInterface $facet) {
    $build = parent::build($facet);

    $results = $facet->getResults();
    if (empty($results)) {
      return $build;
    }

    $show_numbers = $facet->getWidgetInstance()->getConfiguration()['show_numbers'];
    $range = $this->getRangeFromResults($results);

    // Capping needs to happen if
    // - restrict_frequency_to_range -> only if restrict_to_fixed  and max_value and max_values are set/
    // OR/AND restrict_to_fixed  and max_value and max_values are set.

    ksort($results);
    $active = $facet->getActiveItems();

    $real_min = $range['min'] ?? NULL;
    $real_max = $range['max'] ?? NULL;

    $active_min = reset($active)['min'] ?? $real_min;
    $active_max = reset($active)['max'] ?? $real_max;

    // Give some defaults
    $year_max = gmdate('Y', (int) $active_max);
    $year_min = gmdate('Y', (int) $active_min);
    $unix_max = $active_max;
    $unix_min = $active_min;

    if ($real_min && $this->getConfiguration()['min_type'] == 'search_result') {
      $min = $active_min < $real_min ? $real_min : $active_min;
    }
    if ($real_max && $this->getConfiguration()['max_type'] == 'search_result') {
      $max = $active_max > $real_max ? $real_max : $active_max;
    }

    if (isset($min) && !empty($min)) {
      $year_min = gmdate('Y', (int) $min);
      $unix_min = (int) $min;
      $min = gmdate('Y-m-d', (int) $min);
    }
    if (isset($max) && !empty($max)) {
      $year_max = gmdate('Y', (int) $max);
      $unix_max = $max;
      $max = gmdate('Y-m-d', (int) $max);
    }

    $labels = [];
    $js_values = [];

    $max_items = 0;
    foreach ($results as $result) {
      if ($result->getRawValue() != 'summary_date_facet') {
        $dt = new DateTime('@'.$result->getRawValue());
        $js_year = $dt->format('Y');
        $dt->setTimezone(new DateTimeZone('America/New_York'));
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
      $chart_data[] = $js_value['count'];
      $chart_labels[] = $js_value['label'];
    }

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
      $build['#items'] = [
        [
          '#type' => 'container',
          '#attributes' => [
            'style' => 'width:100%;max-height:12rem',
            'class' => ['sbf-date-facet-slider-chart'],
          ],
          'chart' => [
          '#type' => 'html_tag',
            '#tag' => 'canvas',
            '#theme' => 'html_tag',
            '#attributes' => [
              'id' => $id.'-chart',
            ],
          ]
        ],
        [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#theme' => 'html_tag',
        '#attributes' => [
          'class' => ['sbf-date-facet-slider'],
          'data-min' => $unix_min,
          'data-max' => $unix_max,
          'id' =>  $id,
          'data-drupal-url' => $url
        ]
      ]
      ];
    }
    else {
      // If we could not get a URL we can build a widget here. Return the original Build.
      return $build;
    }


    $build['#attached']['library'][] = 'format_strawberryfield_facets/slider';
    $build['#attached']['library'][] = 'core/drupal.dialog';

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

    $build['#attached']['drupalSettings']['facets']['sliders'][$facet->id()] = [
      'htmlid' => $id,
      'min' => (int)$selected_minmax[0],
      'max' => (int)$selected_minmax[1],
      'values' => $values,
      'real_minmax' => $real_minmax,
      'value' => isset($active[0]) ? (float) $active[0] : '',
      'prefix' => $this->getConfiguration()['prefix'],
      'suffix' => $this->getConfiguration()['suffix'],
      'step' => $this->getConfiguration()['step'],
      'labels' => $labels,
      'chart_data' => $chart_data,
      'chart_labels' => $chart_labels,
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
    ];

    $form['max_type'] = [
      '#type' => 'radios',
      '#options' => [
        'fixed' => $this->t('Fixed value'),
        'search_result' => $this->t('Based on search result'),
      ],
      '#title' => $this->t('Maximum value type'),
      '#default_value' => $config['max_type'],
    ];

    $form['max_value'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum value (in Years)'),
      '#default_value' => $config['max_value'],
      '#description' => t('Used as hard maximum limit if "Maximum value type" is set "Fixed value" or if set to "Based on search result" and also "Truncate Facet results outside of the user selected range" is checked. Any valued larger than the current YEAR will be capped to NOW().No metadata futurism for the sake of performance.'),
      '#size' => 5,
    ];
    $form['restrict_to_fixed'] =  [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Cap result based max/min to Fixed Ranges.'),
      '#description'        => $this->t('Even if Results are used as max/min, cap max and min to the Fixed values. Requires Fixed values to be set. This allows Outliers and wrong metadata to be not taking in account.'),
      '#default_value' => $config['restrict_to_fixed'] ?? $this->defaultConfiguration()['restrict_to_fixed'],
    ];
    $form['restrict_frequency_to_range'] =  [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Truncate Facet results outside of the user selected range'),
      '#description'         => $this->t('Matches might bring dates (e.g an ADO has multiple dates) that fall outside of the user selected range. e.g one date matches, but facet of the complete results might include a non matching too. If this is selected those values will be ignored in the slider and Frequency Graph'),
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
      '#title' => $this->t('Base slider step in years'),
      '#default_value' => $config['step'],
      '#size' => 2,
    ];

    $form['step_variable_granularity'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Variable date Granularity based on results'),
      '#default_value' => $config['step_variable_granularity'],
      '#description'   => $this->t(
        'When enabled, sliders steps will vary based on the min/max facet values. By default base slider step in years will be used.'
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

}
