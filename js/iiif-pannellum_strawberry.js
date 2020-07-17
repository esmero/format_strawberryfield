(function ($, Drupal, drupalSettings, pannellum) {

    'use strict';

    function FormatStrawberryfieldPanoramas(panorama) {
        this.panorama = panorama;
    }

    function FormatStrawberryfieldhotspotPopUp(event, url) {
        if (url!== null) {
            var $myDialog = $('<div class="format-strawberryfield-hotspot-dialog"></div>').appendTo('body');
            var ajaxObject = Drupal.ajax({
                url: url,
                dialogType: 'modal',
                dialog: {width: '800px'},
                progress: {
                    type: 'fullscreen',
                    message: Drupal.t('Please wait...')
                }
            });
            ajaxObject.execute();
            event.preventDefault();
        }
    }


    Drupal.behaviors.format_strawberryfield_pannellum_initiate = {
        attach: function(context, settings) {
            $('.strawberry-panorama-item[data-iiif-image]').once('attache_pnl')
                .each(function (index, value) {
                    // New 2019.
                    // If value is an array then we have a multiscene panorama
                    // Mostlikely, if i coded this right, hotspots will also be arrays
                    var hotspots = [];
                    // Get the node uuid for this element
                    var element_id = $(this).attr("id");
                    var $multiscene = drupalSettings.format_strawberryfield.pannellum[element_id].hasOwnProperty('tour');
                    var default_width = drupalSettings.format_strawberryfield.pannellum[element_id]['width'];
                    var default_height = drupalSettings.format_strawberryfield.pannellum[element_id]['height'];
                    var externalConfigURL =  drupalSettings.format_strawberryfield.pannellum[element_id]['configjsonurl'];
                    if (externalConfigURL) {
                        $.getJSON('http://query.yahooapis.com/v1/public/yql?q=select%20%2a%20from%20yahoo.finance.quotes%20WHERE%20symbol%3D%27WRC%27&format=json&diagnostics=true&env=store://datatables.org/alltableswithkeys&callback', function(data) {
                           console.log(data);
                        });
                    }

                    const $haov = drupalSettings.format_strawberryfield.pannellum[element_id].settings?.haov;
                    const $vaov = drupalSettings.format_strawberryfield.pannellum[element_id].settings?.vaov;
                    const $minYaw = drupalSettings.format_strawberryfield.pannellum[element_id].settings?.minYaw;
                    const $maxYaw = drupalSettings.format_strawberryfield.pannellum[element_id].settings?.maxYaw;
                    const $vOffset = drupalSettings.format_strawberryfield.pannellum[element_id].settings?.vOffset;
                    const $maxPitch = drupalSettings.format_strawberryfield.pannellum[element_id].settings?.maxPitch;
                    const $minPitch = drupalSettings.format_strawberryfield.pannellum[element_id].settings?.minPitch;

                    // Check if we got some data passed via Drupal settings.
                    if (typeof(drupalSettings.format_strawberryfield.pannellum[element_id]) != 'undefined') {
                        if (drupalSettings.format_strawberryfield.pannellum[element_id].hasOwnProperty('hotspots') &&
                            !$multiscene
                        ) {
                            $.each(drupalSettings.format_strawberryfield.pannellum[element_id].hotspots, function (id, hotspotdata)  {
                                // Also add Popups for Standalone Panoramas if they have an URL.
                                if (hotspotdata.hasOwnProperty('URL')) {
                                    hotspotdata.clickHandlerFunc = Drupal.FormatStrawberryfieldhotspotPopUp;
                                    hotspotdata.clickHandlerArgs = hotspotdata.URL;
                                }
                                hotspots.push(hotspotdata);
                            });
                        }
                        $(this).height(default_height);
                        $(this).css("width",default_width);

                        console.log('Initializing Pannellum.')
                        // When loading a webform with an embeded Viewer
                        // The context of Pannellum is not global
                        // So we can't really use 'pannellum' directly

                        if (!$multiscene) {
                            var viewer = window.pannellum.viewer(element_id, {
                                "type": "equirectangular",
                                "panorama": $(value).data('iiifImage'),
                                "hotSpotDebug": drupalSettings.format_strawberryfield.pannellum[element_id].settings.hotSpotDebug,
                                "autoLoad": Boolean(drupalSettings.format_strawberryfield.pannellum[element_id].settings.autoLoad),
                                "hotSpots": hotspots,
                                "haov": $haov || 360,
                                "vaov": $vaov || 180,
                                "maxYaw": $maxYaw || 180,
                                "minYaw": $minYaw || -180,
                                "vOffset": $vOffset || 0,
                                "maxPitch": $maxPitch||undefined,
                                "minPitch": $minPitch|| undefined

                            });
                        }
                        else {
                            console.log('Pannellum Multiscene found.');
                            $.each(drupalSettings.format_strawberryfield.pannellum[element_id].tour.scenes, function (sceneid, data)  {
                                // Add Model Window Behaviour to hotSpots with Links
                                if (data.hasOwnProperty('hotSpots')) {
                                    $.each(data.hotSpots, function (hotspotid, hotspotdata) {
                                        if (hotspotdata.hasOwnProperty('URL')) {
                                            drupalSettings.format_strawberryfield.pannellum[element_id].tour.scenes[sceneid].hotSpots[hotspotid].clickHandlerFunc = Drupal.FormatStrawberryfieldhotspotPopUp;
                                            drupalSettings.format_strawberryfield.pannellum[element_id].tour.scenes[sceneid].hotSpots[hotspotid].clickHandlerArgs = hotspotdata.URL;
                                        }

                                    });

                                }
                            });
                            var viewer = window.pannellum.viewer(element_id, drupalSettings.format_strawberryfield.pannellum[element_id].tour);
                        }
                        FormatStrawberryfieldPanoramas.panoramas.set(element_id, new FormatStrawberryfieldPanoramas(viewer));


                    }

                })}}
    /**
     * Extend the FormatStrawberryfieldPanoramas.
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
    // Make the FormatStrawberryfieldhotspotPopUp function available in the Drupal namespace.
    Drupal.FormatStrawberryfieldhotspotPopUp = FormatStrawberryfieldhotspotPopUp;
})(jQuery, Drupal, drupalSettings, window.pannellum);
