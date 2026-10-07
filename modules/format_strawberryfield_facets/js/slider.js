/**
 * @file
 * Provides the slider functionality.
 */

(function ($) {

  'use strict';

  Drupal.facets = Drupal.facets || {};

  Drupal.behaviors.facet_slider = {
    attach: function (context, settings) {
      if (settings.facets !== 'undefined' && settings.facets.sliders !== 'undefined') {
        $.each(settings.facets.sliders, function (facet, slider_settings) {
          const $dateRangeFacets = once('js-facets-sbf-daterange-slider', '[data-drupal-facet-id="'+facet+'"]', context);
          if ($dateRangeFacets.length > 0) {
            $dateRangeFacets.forEach((widget) => {
                const slider = widget.querySelector(".sbf-date-facet-slider");
                if (slider) {
                  const svg = context.querySelector("#" + slider.id + "-chart");
                  if (slider_settings?.chart_data && svg) {
                    Drupal.facets.addChart(svg, slider_settings)
                  }
                  Drupal.facets.addSlider(widget, slider_settings);
                }
              }
            );
          }
        });
      }
    }
  };

    Drupal.facets.addChart = function (element, settings ) {
      let ctx = element.getContext('2d');
      let chart_data  = JSON.parse(settings.chart_data);
      let chart_labels = JSON.parse(settings.chart_labels);
      let chart_type = "line";
      if (chart_data.length == 1) {
        chart_type = "bar"
      }
      let histogram = new Chart(ctx, {
        type: chart_type,
        data: {
          labels: chart_labels,
          datasets: [
            {
              data: chart_data,
              borderColor: settings?.chart_color || 'blue',
              backgroundColor: settings?.chart_bg_color || 'green',
              borderWidth: 1,
              fill: true,
              stepped: true,
              showLine: true,
              tension: 0.4
            },
          ]
        },
        options: {
          plugins: {
            legend: {
              display: false
            },
          },
          pointRadius: 0,
          responsive: true,
          scales: {
            x: {
              title: {
                display: false,
                text: 'Year',
                font: {
                  padding: 1,
                  size: 8,
                  weight: 'bold',
                  family: 'Arial'
                },
                color: 'black'
              }
            },
            y: {
              title: {
                display: false,
                text: 'Count',
                font: {
                  size: 8,
                  weight: 'bold',
                  family: 'Arial'
                },
                ticks: {
                  stepSize: 10
                },
                color: 'black'
              },
              beginAtZero: true,
            }
          }
        }
      });
    }

    Drupal.facets.addSlider = function (widget, slider_settings) {
      var defaults = {
        stop: function (event, ui) {
          if (slider_settings.range) {
            const slider = widget.querySelector('.sbf-date-facet-slider')
            const min = toTimestamp( ui.values[0], 1, 1);
            // for max, we will add the last day of the year.
            const max = toTimestamp(ui.values[1], 12, 31);
            slider.dataset.min = min;
            slider.dataset.max = max;
          }
          else {
            // Only auto submit if a single slider.
            window.location.href = slider_settings.urls['f_' + ui.value];
          }
        }
      };

      // Convert the normal date into Cleave.
      const $date_inputs = widget.querySelectorAll('input[type="date"]');
      const $cleaveInstances = new WeakMap();
      $date_inputs.forEach((input_element) => {
        let min = input_element.min;
        let max = input_element.max;
        // datePattern needs to match the pre-set value though
        // Or we can flip the value here?
        input_element.type = 'text';
        const instance = new Cleave(input_element, {
          date: true,
          datePattern: ['Y', 'm', 'd'],
          delimiter: '-',
          dateMax: max,
          dateMin: min
        });
        $cleaveInstances.set(input_element, instance);
      });

      function toTimestamp(year, month, day, hour = 0, minute = 0, second = 0, millisecond = 0) {
        // Date.parse works only on safari for BCE!
        // New approach. Date Constructor
        let date_from_parts = new Date();
        date_from_parts.setFullYear(parseInt(year));
        date_from_parts.setMonth(parseInt(month)-1);
        date_from_parts.setDate(parseInt(day));
        date_from_parts.setHours(hour,minute,second,millisecond);
        const datum = date_from_parts.getTime();
        if (Number.isNaN(datum)) {
          return Math.round(Date.now() / 1000);
        }
        return Math.round(datum / 1000);
      }

      function autoSubmit(widget) {
        const slider = widget.querySelector('.sbf-date-facet-slider')
        const url = slider.dataset.drupalUrl;
        return url.replace('__date_range_min__', slider.dataset.min).replace('__date_range_max__', slider.dataset.max);
      }

      // Click on link will call Facets JS API on widget element.
      var changeHandler = function (e) {
        // New to 2.2.0. We use our vanilla EventListener
        // Troubles with JQUERY 4.0.0 trigger
        // @See Drupal.AjaxFacetsView.facets_filter
        let $widget = $(e.target).closest('.js-facets-widget');
        let $url = autoSubmit($widget[0]);
        if ($widget) {
          // trigger the default, in case we are not in AJAX mode.
          // the facets_filter_sbf won't exist so will be OK
          // The opposite will be true under ajax.
          $widget.trigger('facets_filter.facets', [$url]);
          const facetsFilterventSbf = new CustomEvent('facets_filter_sbf', {
            bubbles: true,
            detail: [$url]
          });
          $widget[0].dispatchEvent(facetsFilterventSbf);
        }
      };

      $("input.facet-date-range-submit", widget).on("click", changeHandler);

      $.extend(defaults, slider_settings);

      var $slider = $('.sbf-date-facet-slider', widget).slider(defaults)
        .slider('pips', {
          prefix: slider_settings.prefix,
          suffix: slider_settings.suffix
        })
        .slider('float', {
          prefix: slider_settings.prefix,
          suffix: slider_settings.suffix,
          labels: JSON.parse(slider_settings.labels)
        });
      var changeHandlerInput = function (e) {
        const slider = widget.querySelector('.sbf-date-facet-slider')
        let min_timestamp =  slider.dataset.min;
        let max_timestamp =  slider.dataset.max;
        let min = $slider.slider('option').min;
        let max = $slider.slider('option').max;
        let ui_min = $slider.slider('option').values[0];
        let ui_max = $slider.slider('option').values[1];
        if  (!e.target.checkValidity()) {
          e.target.setCustomValidity("The entered date is not in range.");
          e.target.reportValidity()
          return;
        }
        if (e.target.type == "number") {
          if (e.target.dataset?.type == "date-range-min") {
            min = ui_min = parseInt(e.target.value);
            min_timestamp =  toTimestamp(min, 1, 1, 0, 0);
          }
          else {
            max = ui_max = parseInt(e.target.value);
            max_timestamp =  toTimestamp(max, 12, 31, 0, 0, 0, 0);
          }
        }
        else if (e.target.type == "text") {
          // get the cleave instance
          if (e.target.dataset?.type == "date-range-min") {
            let date_from_input = new Date(e.target.value);
            if (date_from_input instanceof Date) {
              min = ui_min = date_from_input.getFullYear();
              let month = date_from_input.getMonth();
              let day = date_from_input.getDay();
              min_timestamp = toTimestamp(min, month, day);
            }
          } else {
            let date_from_input = new Date(e.target.value);
            if (date_from_input instanceof Date) {
              max = ui_max = date_from_input.getFullYear();
              let month = date_from_input.getMonth();
              let day = date_from_input.getDay();
              max_timestamp = toTimestamp(max, month, day, 0, 0, 0, 0);
            }
          }
        }
        if ($slider.slider('option').min > min || $slider.slider('option').max < max) {
          e.target.setCustomValidity("The entered date is not in range.");
          e.target.reportValidity()
        }
        else {
          $slider.slider('option', 'values', [ui_min, ui_max]).slider("pips", "refresh").slider("float", "refresh");
          slider.dataset.min = min_timestamp;
          slider.dataset.max = max_timestamp;
        }
      };
      $('input[type="number"].facet-date-range.form-control', widget).on("change", changeHandlerInput);
      $('input[type="text"].facet-date-range.form-control', widget).on("blur", changeHandlerInput);
      $('input.facet-date-range.form-control', widget).on("keydown", function(e) {
        if (e.key === "Enter") {
          $(e.target).trigger('blur');
          e.preventDefault();
          $("input.facet-date-range-submit", widget).trigger('click');
        }
      });

    };

  })(jQuery, Chart, Cleave);
