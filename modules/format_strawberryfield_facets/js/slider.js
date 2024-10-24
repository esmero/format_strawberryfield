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

      var $slider = $('.sbf-date-facet-slider', widget).slider(defaults)
        .slider('pips', {
          prefix: slider_settings.prefix,
          suffix: slider_settings.suffix
        })
        .slider('float', {
          prefix: slider_settings.prefix,
          suffix: slider_settings.suffix,
          labels: slider_settings.labels
        });
      var changeHandlerInput = function (e) {
        //e.preventDefault();
        console.log(e);
        let error = Drupal.t("Wrong date");
        const slider = widget.querySelector('.sbf-date-facet-slider')
        let min_timestamp =  slider.dataset.min;
        let max_timestamp =  slider.dataset.max;
        let min = $slider.slider('option').min;
        let max = $slider.slider('option').max;
        let ui_min = $slider.slider('option').values[0];
        let ui_max = $slider.slider('option').values[1];
        if  (!e.target.checkValidity()) {
          e.target.reportValidity()
          return;
        }
        if (e.target.type == "number") {
          if (e.target.dataset?.type == "date-range-min") {
            min = ui_min = parseInt(e.target.value);
            min_timestamp =  toTimestamp(min);
          }
          else {
            max = ui_max = parseInt(e.target.value);
            max_timestamp =  toTimestamp(max);
          }
        }
        else if (e.target.type == "date") {
          if (e.target.dataset?.type == "date-range-min") {
            min = ui_min =new Date(e.target.value).getFullYear();
            min_timestamp = toTimestamp(e.target.value);
          }
          else {
            max = ui_max = new Date(e.target.value).getFullYear();
            max_timestamp = toTimestamp(e.target.value);
          }
        }
        if ($slider.slider('option').min > min || $slider.slider('option').max < max) {
          e.target.reportValidity()
        }
        else {
          $slider.slider('option', 'values', [ui_min, ui_max]).slider("pips", "refresh").slider("float", "refresh");
          slider.dataset.min = min_timestamp;
          slider.dataset.max = max_timestamp;
        }


        //const min = toTimestamp( ui.values[0]);
        //const max = toTimestamp( ui.values[1]);
        //slider.dataset.min = min;
        //slider.dataset.max = max;
      };
      $("input.facet-date-range.form-control", widget).on("change", changeHandlerInput);


    };

  })(jQuery);
