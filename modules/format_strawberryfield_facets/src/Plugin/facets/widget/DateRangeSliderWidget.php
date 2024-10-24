<?php

namespace Drupal\format_strawberryfield_facets\Plugin\facets\widget;

use Drupal\Component\Utility\Html;
use Drupal\facets\FacetInterface;

/**
 * The range slider widget.
 *
 * @FacetsWidget(
 *   id = "sbf_range_date_slider",
 *   label = @Translation("Format Strawberryfield Date Range slider"),
 *   description = @Translation("A widget that shows a range slider for Dates."),
 * )
 */
class DateRangeSliderWidget extends DateSliderWidget {

  /**
   * {@inheritdoc}
   */
  public function build(FacetInterface $facet) {
    $build = parent::build($facet);
    $results = $facet->getResults();
    if (empty($results)) {
      return $build;
    }

    $facet_settings = &$build['#attached']['drupalSettings']['facets']['sliders'][$facet->id()];
    $id = Html::getUniqueId('facet-sbf-slider-'.$facet->id().'-manual-input');
    if ( $this->getConfiguration()['allow_full_entry'] || $this->getConfiguration()['allow_year_entry']) {
      $build['#items']['manual_input'] = [
        '#type' => 'details',
        '#title' => t('More Options'),
        '#collapsible' => TRUE,
        '#collapsed' => TRUE,
        '#id' => $id,
        '#title_display' => 'before',
      ];
    }

    if (($this->getConfiguration()['allow_full_entry'] ?? FALSE) && ($this->getConfiguration()['allow_year_entry'] ?? FALSE)) {
      $build['#items']['manual_input']['select_input'] = [
          '#type' => 'checkbox',
          '#title' => t('Full Date entry'),
          '#default_value' => FALSE,
          '#attributes' => [
          'data-date-entry-selector' => $id.'-fulldate'
        ]
      ];
    }

    if ($this->getConfiguration()['allow_full_entry']) {
      $build['#items']['manual_input']['manual_input_full'] = [
        'min_full' => [
          '#type' => 'date',
          '#title' => $this->t('Date from'),
          '#value' => $facet_settings['real_minmax'][0],
          '#date_date_element' => 'datetime',
          '#date_date_format' => 'mm-dd-Y',
          '#date_date_element' => 'date',
          '#date_time_element' => 'none',
          '#date_year_range' => $facet_settings['min'].':'.$facet_settings['max'],
          '#date_timezone' => 'Asia/Kolkata',
          '#attributes' => [
            'class' => ['facet-date-range'],
            'id' => $facet->id() . '_min',
            'name' => $facet->id() . '_min',
            'min' => $facet_settings['real_minmax'][0],
            'max' => $facet_settings['real_minmax'][1],
            'data-type' => 'date-range-min',
          ],
        ],
        'max_full' => [
          '#type' => 'date',
          '#title' => $this->t('Date to'),
          '#value' => $facet_settings['real_minmax'][1],
          '#date_date_element' => 'datetime',
          '#date_date_format' => 'mm-dd-Y',
          '#date_date_element' => 'date',
          '#date_time_element' => 'none',
          '#date_year_range' => $facet_settings['min'].':'.$facet_settings['max'],
          '#date_timezone' => 'Asia/Kolkata',
          '#attributes' => [
            'class' => ['facet-date-range'],
            'id' => $facet->id() . '_max',
            'name' => $facet->id() . '_max',
            'min' => $facet_settings['real_minmax'][0],
            'max' => $facet_settings['real_minmax'][1],
            'data-type' => 'date-range-max',
          ],
        ]];
    }
    if ($this->getConfiguration()['allow_year_entry']) {
      $build['#items']['manual_input']['manual_input_year'] = [
        'min_year' => [
          '#type' => 'number',
          '#title' => $this->t('Year from'),
          '#value' => $facet_settings['min'],
          '#min' => $facet_settings['min'],
          '#max' => $facet_settings['max'],
          '#step' => 1,
          '#attributes' => [
            'class' => ['facet-date-range'],
            'id' => $facet->id() . '_year_min',
            'name' => $facet->id() . '_year_min',
            'min' =>  $facet_settings['min'],
            'max' => $facet_settings['max'],
            'data-type' => 'date-range-min',
          ],
        ],
        'max_year' => [
          '#type' => 'number',
          '#title' => $this->t('Year to'),
          '#value' => $facet_settings['max'],
          '#min' => $facet_settings['min'],
          '#max' => $facet_settings['max'],
          '#step' => 1,
          '#attributes' => [
            'class' => ['facet-date-range'],
            'id' => $facet->id() . '_year_max',
            'name' => $facet->id() . '_year_max',
            'min' => $facet_settings['min'],
            'max' => $facet_settings['max'],
            'data-type' => 'date-range-max',
          ],
        ]];
    }

    if (isset($build['#items']['manual_input']['manual_input_full']) &&  ($this->getConfiguration()['allow_full_entry'] ?? FALSE) && ($this->getConfiguration()['allow_year_entry'] ?? FALSE)) {
      $build['#items']['manual_input']['manual_input_full']['min_full']['#states'] = [
        'visible' => [
          ':input[data-date-entry-selector="'.$id.'-fulldate'.'"]' => ['checked' => TRUE],
        ],
      ];
      $build['#items']['manual_input']['manual_input_full']['max_full']['#states'] = [
        'visible' => [
          ':input[data-date-entry-selector="'.$id.'-fulldate'.'"]' => ['checked' => TRUE],
        ],
      ];
      $build['#items']['manual_input']['manual_input_year']['min_year']['#states'] = [
        'visible' => [
          ':input[data-date-entry-selector="'.$id.'-fulldate'.'"]' => ['checked' => FALSE],
        ],
      ];
      $build['#items']['manual_input']['manual_input_year']['max_year']['#states'] = [
        'visible' => [
          ':input[data-date-entry-selector="'.$id.'-fulldate'.'"]' => ['checked' => FALSE],
        ],
      ];
    }

    $build['#items']['filter'] = [
      '#type' => 'button',
      '#attributes' => [
        'class' => ['facet-date-range-submit'],
      ],
      '#prefix' => '<div>',
      '#suffix' => '</div>',
      '#value' => $this->getConfiguration()['submit_text'] ?? $this->defaultConfiguration()['submit_text'],
    ];

    $facet_settings['range'] = TRUE;
    unset($facet_settings['value']);

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function isPropertyRequired($name, $type) {
    if ($name === 'sbf_date_range_slider' && $type === 'processors') {
      return TRUE;
    }
    if ($name === 'show_only_one_result' && $type === 'settings') {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getQueryType() {
    //* See \Drupal\facets\Plugin\facets\facet_source\SearchApiDisplay::getQueryTypesForDataType
    return 'date_range';
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
