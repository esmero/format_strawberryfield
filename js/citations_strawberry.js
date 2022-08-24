(function ($, Drupal, drupalSettings) {
    'use strict';
  Drupal.behaviors.format_strawberryfield_citations = {
      attach: function (context, settings) {
        $('.bibliography').once('attach_citations')
          .each(function (index, value) {
            var theid = '#' + $(this).attr("id");
            var bibliographyContainer = document.querySelector(theid);
            var citationStyleSelector = bibliographyContainer.querySelector('.citation-style-selector');
            var styledBibs = bibliographyContainer.querySelectorAll(".csl-bib-body-container");
            citationStyleSelector.addEventListener("change", function() {
              var selectedStyle = this.value;
              styledBibs.forEach(function (el, i) {
                var elClassList = el.classList;
                if (elClassList.contains("hidden") && elClassList.contains(selectedStyle)) {
                  elClassList.remove("hidden");
                } else if (!elClassList.contains("hidden") && !elClassList.contains(selectedStyle)) {
                  elClassList.add("hidden");
                }
              });
            });
          });
      }
    };
})(jQuery, Drupal, drupalSettings);
