/**
* @preserve
**/

(function ($) {
  Drupal.facets = Drupal.facets || {};

  Drupal.behaviors.sbfFacetsDateRange = {
    attach: function attach(context, settings) {
      var $dateRangeFacets = $('.js-facets-sbf-daterange', context)
        .once('js-facets-sbf-daterange-on-click');


      function toTimestamp(strDate) {
        var datum = Date.parse(strDate);
        return datum / 1000;
      }

      function autoSubmit($widget) {
        var facetId = $widget.attr("data-drupal-facet-id");
        var submiturl = $("input[id=".concat(facetId, "_min]")).attr("data-drupal-url");
        var min = toTimestamp($("input[id=".concat(facetId, "_min]")).val()) || "";
        var max = toTimestamp($("input[id=".concat(facetId, "_max]")).val()) || "";
        return submiturl.replace("__date_range_min__", min).replace("__date_range_max__", max);
      }

      if ($dateRangeFacets.length > 0) {
        $dateRangeFacets
          .each(function (index, widget) {
            var $widget = $(widget);
            // Click on link will call Facets JS API on widget element.
            var changeHandler = function (e) {
              //e.preventDefault();
              $widget.trigger('facets_filter', [autoSubmit($widget)]);
            };
            // Add correct CSS selector for the widget. The Facets JS API will
            // register handlers on that element.
            $widget.addClass('js-facets-widget');

            // Add handler for change on range inputs.
            $("input.facet-date-range", context).on("change", changeHandler);
            $("input.facet-date-range", context).on("keypress", function (e) {
              $(this).off("change blur");
              $(this).on("blur", changeHandler);
              if (e.keyCode === 13) {
                changeHandler();
              }
            });

            // We have to trigger attaching of behaviours, so that Facets JS API can
            // register handlers on link widgets.
            Drupal.attachBehaviors(this.parentNode, Drupal.settings);
          });
      }
    }
  };
})(jQuery);
