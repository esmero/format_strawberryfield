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
          const elementsToAttach = once('js-facets-sbf-daterange-slider', '[data-drupal-facet-id="'+facet+'"]', context);
          const $dateRangeFacets = $(elementsToAttach);
          if ($dateRangeFacets.length > 0) {
            $dateRangeFacets.each(function (index, widget) {
                const $widget = widget;
                Drupal.facets.addSlider(widget, slider_settings);
              }
            );
          }
        });
      }
    }
  };

    Drupal.facets.addSlider = function (widget, slider_settings) {
      var defaults = {
        stop: function (event, ui) {
          if (slider_settings.range) {
            const slider = widget.querySelector('.sbf-date-facet-slider')
            const min = toTimestamp( ui.values[0]);
            const max = toTimestamp( ui.values[1]);
            slider.dataset.min = min;
            slider.dataset.max = max;
          }
          else {
            // Only autosumbit if a single slider.
            window.location.href = slider_settings.urls['f_' + ui.value];
          }
        }
      };




      function toTimestamp(strDate) {
        var datum = Date.parse(strDate);
        return datum / 1000;
      }

      function autoSubmit(widget) {
        const slider = widget.querySelector('.sbf-date-facet-slider')
        const url = slider.dataset.drupalUrl;
        return url.replace('__date_range_min__', slider.dataset.min).replace('__date_range_max__', slider.dataset.max);
      }

      // Click on link will call Facets JS API on widget element.
      var changeHandler = function (e) {
        //e.preventDefault();
        var $widget = $(widget);
        $widget.trigger('facets_filter', [autoSubmit(widget)]);
      };

      // Add handler for change on range inputs.
      $("input.facet-date-range-submit", widget).on("click", changeHandler);
      $("input.facet-date-range-submit", widget).on("keypress", function (e) {
        $(this).off("change blur");
        $(this).on("blur", changeHandler);
        if (e.keyCode === 13) {
          changeHandler();
        }
      });

      $.extend(defaults, slider_settings);

      $('.sbf-date-facet-slider', widget).slider(defaults)
        .slider('pips', {
          prefix: slider_settings.prefix,
          suffix: slider_settings.suffix
        })
        .slider('float', {
          prefix: slider_settings.prefix,
          suffix: slider_settings.suffix,
          labels: slider_settings.labels
        });
    };

  })(jQuery);
