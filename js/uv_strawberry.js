(function ($, Drupal, drupalSettings, UV) {

    'use strict';

    Drupal.behaviors.format_strawberryfield_uv_initiate = {
        attach: function(context, settings) {
            $('.strawberry-uv-item[data-iiif-infojson]').once('attache_uv')
                .each(function (index, value) {
                    // Get the node uuid for this element
                    var element_id = $(this).attr("id");
                    // Check if we got some data passed via Drupal settings.
                    if (typeof(drupalSettings.format_strawberryfield.uv[element_id]) != 'undefined') {

                        $(this).height(drupalSettings.format_strawberryfield.uv[element_id]['height']);
                        if (drupalSettings.format_strawberryfield.uv[element_id]['width'] != '100%') {
                            $(this).width(drupalSettings.format_strawberryfield.uv[element_id]['width']);
                        }
                        // Defines our basic options for UV IIIF.
                        // UV is funny. It uses a STATIC!! (gosh) config.json for its settings.
                        // Not sure why. Anyways.

                        var $options = {
                          //root: './uv',
                          manifestUri: drupalSettings.format_strawberryfield.uv[element_id]['manifesturl'],
                          collectionIndex: 0,
                          manifestIndex: 0,
                          sequenceIndex: 0,
                          canvasIndex: 0,
                          locales: [
                            {
                              name: 'en-GB'
                            }
                          ]
                        };

                        var urlDataProvider = new UV.URLDataProvider();
                        var uvInstance = UV.init(
                          element_id,
                          $options,
                          urlDataProvider
                      );
                        console.log('initializing Universal Viewer 4')
                    }
                })}}
})(jQuery, Drupal, drupalSettings, window.UV);
