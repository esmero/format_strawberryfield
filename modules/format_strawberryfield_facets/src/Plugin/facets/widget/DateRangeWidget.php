<?php

declare(strict_types = 1);

namespace Drupal\format_strawberryfield_facets\Plugin\facets\widget;

use Drupal\Core\Form\FormStateInterface;
use Drupal\facets\FacetInterface;
use Drupal\facets\Result\Result;
use Drupal\facets\Result\ResultInterface;
use Drupal\facets\Widget\WidgetPluginBase;

/**
 * The Date Range widget.
 *
 * @FacetsWidget(
 *   id = "sbf_date_range",
 *   label = @Translation("Format Strawberryfield Date Range Picker"),
 *   description = @Translation("A widget that shows a Date Range Picker."),
 * )
 */
class DateRangeWidget extends WidgetPluginBase {

  public function defaultConfiguration() {
    return [
        'show_reset_link' => FALSE,
        'hide_reset_when_no_selection' => FALSE,
        'reset_text' => $this->t('Show all'),
      ] + parent::defaultConfiguration();
  }
  /**
   * {@inheritdoc}
   */
  public function build(FacetInterface $facet): array {
    $build = parent::build($facet);
    $results = $facet->getResults();
    if (empty($results)) {
      return $build;
    }

    $range = $this->getRangeFromResults($results);


    ksort($results);

    $active = $facet->getActiveItems();
    $real_min = $range['min'] ?? NULL;
    $real_max = $range['max'] ?? NULL;

    $min = reset($active)['min'] ?? $real_min;
    $max = reset($active)['max'] ?? $real_max;
    if ($real_min && $this->getConfiguration()['set_defaults_from_results']) {
      $min = $min < $real_min ? $real_min : $min;
    }
    if ($real_max && $this->getConfiguration()['set_defaults_from_results']) {
      $max = $max > $real_max ? $real_max : $max;
    }

    if (isset($min) && !empty($min)) {
      $min = gmdate('Y-m-d', (int) $min);
    }
    if (isset($max) && !empty($max)) {
      $max = gmdate('Y-m-d', (int) $max);
    }

    $build['#items'] = [
      'min' => [
        '#type' => 'date',
        '#title' => $this->t('Date from'),
        '#value' => $min,
        '#attributes' => [
          'class' => ['facet-date-range'],
          'id' => $facet->id() . '_min',
          'name' => $facet->id() . '_min',
          'min' => $min,
          'max' => $max,
          'data-type' => 'date-range-min',
        ],
      ],
      'max' => [
        '#type' => 'date',
        '#title' => $this->t('Date to'),
        '#value' => $max,
        '#attributes' => [
          'class' => ['facet-date-range'],
          'id' => $facet->id() . '_max',
          'name' => $facet->id() . '_max',
          'min' => $min,
          'max' => $max,
          'data-type' => 'date-range-max',
        ],
      ],
    ];
    // We will reuse this for the form submit url
    $urlProcessorManager = \Drupal::service('plugin.manager.facets.url_processor');
    $url_processor = $urlProcessorManager->createInstance($facet->getFacetSourceConfig()->getUrlProcessorName(), ['facet' => $facet]);
    $urlGenerator = \Drupal::service('facets.utility.url_generator');
    $url = NULL;
    if ($this->getConfiguration()['show_reset_link'] && (!$this->getConfiguration()['hide_reset_when_no_selection'] || $facet->getActiveItems())) {
      // Add reset link even  if there are no results. Given that user might bypass completely the values
      // By using the GET arguments.
      $max_items = array_sum(array_map(function ($item) {
        return $item->getCount();
      }, $results));
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
      $item = $this->buildListItems($facet, $result_item);

      // Add a class for the reset link wrapper.
      $item['#wrapper_attributes']['class'][] = 'facets-reset';

      // Put reset facet link on first place.
      array_unshift($build['#items'], $item);
    }


    if (!empty($results) && count($results) > 0) {
      $result = array_shift($results);
      $url_for_js = $result->getUrl();
      if (!$url_for_js) {
        $url_for_js = $url;
      }
      if ($url_for_js && $url_for_js instanceof \Drupal\core\Url) {
        $url = $url_for_js->toString();
        $build['#items']['min']['#attributes']['data-drupal-url'] = $url;
        $build['#attached']['library'][]
          = 'format_strawberryfield_facets/date-range';
      }
    }

    // Drupal never updates the drupalSettings after the first load/after the Ajax call gets done.
    $build['#attributes']['class'][] = 'js-facets-links';
    $build['#attributes']['class'][] = 'js-facets-sbf-daterange';
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function isPropertyRequired($name, $type): bool {
    return $name === 'sbf_date_range' && $type === 'processors';
  }

  /**
   * {@inheritdoc}
   */
  public function getQueryType(): string {
    return 'range';
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, FacetInterface $facet): array {

    $form['set_defaults_from_results'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use results to set max/min date values'),
      '#description' => $this->t('When selected, show Reset will be always enabled'),
      '#default_value' => $this->getConfiguration()['set_defaults_from_results'],
    ];
    $form['show_reset_link'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show reset link'),
      '#default_value' => $this->getConfiguration()['show_reset_link'],
      '#states' => [
        'required' => [
          ':input[name="widget_config[set_defaults_from_results]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $form['reset_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Reset text'),
      '#default_value' => $this->getConfiguration()['reset_text'],
      '#states' => [
        'visible' => [
          ':input[name="widget_config[show_reset_link]"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="widget_config[show_reset_link]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $form['hide_reset_when_no_selection'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide reset link when no facet item is selected'),
      '#default_value' => $this->getConfiguration()['hide_reset_when_no_selection'],
    ];
    $form += parent::buildConfigurationForm($form, $form_state, $facet);

    $message = $this->t('The Format Strawberryfield Date Range Picker Widget requires you to check the facet setting below <em>"Format Strawberryfield Date Range Picker" and check "enabled" inside it.</em>.');
    $form['warning'] = [
      '#markup' => '<div class="messages messages--warning">' . $message . '</div>',
    ];

    return $form;
  }

  protected function getRangeFromResults(array $results) {
    /* @var \Drupal\facets\Result\ResultInterface[] $results */
    $min = NULL;
    $max = NULL;
    foreach ($results as $result) {
      if ($result->getRawValue() == 'summary_date_facet') {
        continue;
      }
      $min = $min ?? $result->getRawValue();
      $max = $max ?? $result->getRawValue();
      $min = $min < $result->getRawValue() ? $min : $result->getRawValue();
      $max = $max > $result->getRawValue() ? $max : $result->getRawValue();
    }
    return ['min' => $min, 'max' => $max];
  }

}
