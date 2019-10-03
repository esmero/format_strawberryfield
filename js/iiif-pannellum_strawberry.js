(function ($, Drupal, drupalSettings, pannellum) {

    'use strict';

    function FormatStrawberryfieldPanoramas(panorama) {
        this.panorama = panorama;
        console.log('new viewer!');
    }

    Drupal.behaviors.format_strawberryfield_pannellum_initiate = {
        attach: function(context, settings) {
            $('.strawberry-panorama-item[data-iiif-image]').once('attache_pnl')
                .each(function (index, value) {
                    // New 2019.
                    // If value is a an array then we have a multiscene panorama
                    // Mostlikely, if i coded this right, hotspots will also be arrays
                    var hotspots = [];
                    // Get the node uuid for this element
                    var element_id = $(this).attr("id");
                    var $multiscene = drupalSettings.format_strawberryfield.pannellum[element_id].hasOwnProperty('tour');

                    // Check if we got some data passed via Drupal settings.
                    if (typeof(drupalSettings.format_strawberryfield.pannellum[element_id]) != 'undefined') {
                        if (drupalSettings.format_strawberryfield.pannellum[element_id].hasOwnProperty('hotspots') &&
                            !$multiscene
                        ) {
                            $.each(drupalSettings.format_strawberryfield.pannellum[element_id].hotspots, function (id, hotspotdata)  {
                                hotspots.push(hotspotdata);
                        });
                        }
                       $(this).height(520); //@TODO this needs to be a setting. C'mon
                       $(this).css("width","100%");

                        console.log('initializing Pannellum')
                        // When loading a webform with an embeded Viewer
                        // The context of Pannellum is not global
                        // So we can't really use 'pannellum' directly


                        if (!$multiscene) {
                            var viewer = window.pannellum.viewer(element_id, {
                                "type": "equirectangular",
                                "panorama": $(value).data('iiifImage'),
                                "hotSpotDebug": true,
                                "hotSpots": hotspots,
                            });
                        }
                        else {
                            console.log('multiscene!');
                            var viewer = window.pannellum.viewer(element_id, drupalSettings.format_strawberryfield.pannellum[element_id].tour);
                        }
                        FormatStrawberryfieldPanoramas.panoramas.set(element_id, new FormatStrawberryfieldPanoramas(viewer));

                    }

                })}}
    /**
     * Extend the TableResponsive function with a list of managed tables.
     */
    $.extend(
        FormatStrawberryfieldPanoramas,
        /** @lends Drupal.FormatStrawberryfieldPanoramas */ {
            /**
             * Store all created Panorama Viewer Instances.
             *
             * @type {Array.<Drupal.FormatStrawberryfieldPanoramas>}
             */
            panoramas: new Map(),
            hotspots:  new Map(),
        },
    );
    // Make the FormatStrawberryfieldPanoramas object available in the Drupal namespace.
    Drupal.FormatStrawberryfieldPanoramas = FormatStrawberryfieldPanoramas;
})(jQuery, Drupal, drupalSettings, window.pannellum);
