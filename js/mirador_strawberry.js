(function ($, Drupal, drupalSettings, Mirador) {

    'use strict';

    Drupal.behaviors.format_strawberryfield_mirador_initiate = {
        attach: function(context, settings) {
            $('.strawberry-mirador-item[data-iiif-infojson]').once('attache_mirador')
                .each(function (index, value) {
                    // Get the node uuid for this element
                    var element_id = $(this).attr("id");
                    // Check if we got some data passed via Drupal settings.
                    if (typeof(drupalSettings.format_strawberryfield.mirador[element_id]) != 'undefined') {

                        $(this).height(drupalSettings.format_strawberryfield.mirador[element_id]['height']);
                        $(this).width(drupalSettings.format_strawberryfield.mirador[element_id]['width']);
                        // Defines our basic options for IIIF.
                        var $options = {
                            id: element_id,
                            windows: [{
                                manifestId: drupalSettings.format_strawberryfield.mirador[element_id]['manifesturl'],
                                thumbnailNavigationPosition: 'far-bottom',
                            }]
                        };

                        var miradorInstance = Mirador.viewer($options);
                        console.log('initializing Mirador 3.0.0')
                    }
                })}}
})(jQuery, Drupal, drupalSettings, window.Mirador);