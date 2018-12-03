(function ($, Drupal, drupalSettings) {

    'use strict';

    Drupal.behaviors.format_strawberryfield_openseadragon_initiate = {
        attach: function (context, settings) {
            var viewers = [];
            $('.strawberry-media-item[data-iiif-infojson]').once('attache_osd')
                .each(function (index, value) {
                    if ($(this).attr("height")>0)
                    {
                        $(this).height($(this).attr("height"));
                    }
                    if ($(this).attr("width")>0)
                    {
                        $(this).width($(this).attr("width"));
                    }
                    viewers[index] = OpenSeadragon({
                        debugMode: false,
                        id: $(this).attr("id"),
                        prefixUrl: "https://cdn.jsdelivr.net/npm/openseadragon@2.4/build/openseadragon/images/",
                        tileSources: $(this).data("iiif-infojson"),
                        showNavigator: true,
                        crossOriginPolicy: 'Anonymous',
                        ajaxWithCredentials: false
                    });
                });
        }
    };

})(jQuery, Drupal, drupalSettings);