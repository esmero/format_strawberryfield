(function ($, Drupal, drupalSettings) {
    'use strict';
  Drupal.behaviors.format_strawberryfield_citations = {
      attach: function (context, settings) {
        $('.bibliography').once('attach_citations')
          .each(function (index, value) {
            var citationStyleSelector = document.querySelector("#citation-styles");
            var styledBibs = document.querySelectorAll(".bib-style");
            citationStyleSelector.addEventListener("change", function() {
              var selectedStyle = this.value;
              styledBibs.forEach(function (el, i) {
                if (el.classList.contains("hidden") && el.id == selectedStyle) {
                  el.classList.remove("hidden");
                } else if (!el.classList.contains("hidden")) {
                  el.classList.add("hidden");
                }
              });
            });
          });
      }
    };
})(jQuery, Drupal, drupalSettings);
