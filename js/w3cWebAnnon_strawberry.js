(function ($, Drupal, drupalSettings, Annotorious) {
  'use strict';

  Drupal.behaviors.format_strawberryfield_w3cwebannon_initiate = {
    attach: function (context, settings) {
      $('.strawberry-w3cannon-item[data-iiif-infojson]').once('attache_w3cannon')
        .each(function (index, value) {
          // Get the node uuid for this element
          var element_id = $(this).attr("id");
          // Check if we got some data passed via Drupal settings.
        })
    }
  }
})(jQuery, Drupal, drupalSettings, OpenSeadragon.Annotorious);
