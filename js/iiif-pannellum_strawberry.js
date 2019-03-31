(function ($, Drupal, drupalSettings, pannellum) {

    'use strict';

    Drupal.behaviors.format_strawberryfield_pannellum_initiate = {
        attach: function(context, settings) {
            $('.strawberry-panorama-item[data-iiif-image]').once('attache_pnl')
                .each(function (index, value) {
                    var hotspots = [];
                    // Get the node uuid for this element
                    var element_id = $(this).attr("id");
                    // Check if we got some data passed via Drupal settings.
                    if (typeof(drupalSettings.format_strawberryfield.pannellum[element_id]) != 'undefined') {
                        if (drupalSettings.format_strawberryfield.pannellum[element_id].hasOwnProperty('hotspots')) {
                            $.each(drupalSettings.format_strawberryfield.pannellum[element_id].hotspots, function (id, hotspotdata)  {
                                hotspots.push(hotspotdata);
                        });
                        }
                       $(this).height(520);
                       $(this).width('100%');

                        console.log('initializing Pannellum')
                        pannellum.viewer(element_id, {
                            "type": "equirectangular",
                            "panorama": $(value).data('iiifImage'),
                            "hotSpotDebug": true,
                            "hotSpots": hotspots,
                        });

                    }

                })}}
})(jQuery, Drupal, drupalSettings, pannellum);
