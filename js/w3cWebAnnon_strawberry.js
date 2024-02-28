(function ($, Drupal, once, drupalSettings, Annotorious) {

  'use strict';

  Drupal.behaviors.format_strawberryfield_w3cwebannon_initiate = {
    attach: function(context, settings) {
      // Can not remember why i still have this here
      // @TODO remove. Not needed. or... we can reuse for KNN/Vector search?
      const elementsToAttach = once('attache_w3cannon', '.strawberry-w3cannon-item[data-iiif-infojson]', context);
      $(elementsToAttach).each(function (index, value) {
        // Get the node uuid for this element
        var element_id = $(this).attr("id");
        // Check if we got some data passed via Drupal settings.
      })}}
})(jQuery, Drupal, once, drupalSettings, OpenSeadragon.Annotorious);
