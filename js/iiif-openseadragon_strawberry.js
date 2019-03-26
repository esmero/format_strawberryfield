(function ($, Drupal, drupalSettings) {

    'use strict';

    Drupal.behaviors.format_strawberryfield_openseadragon_initiate = {
        attach: function (context, settings) {
            var viewers = [];
            var groupsinfojsons =  {};
            var groupsid =  {};

            $('.strawberry-media-item[data-iiif-infojson]').once('attache_osd')
                .each(function (index, value) {
                    var default_width =  $(this).attr("width")>0 ? $(this).attr("width"): 320;
                    var default_height = $(this).attr("height")>0 ? $(this).attr("height"): Math.round((default_width/4)*3);
                    // Get the node uuid for this element
                    var element_id = $(this).attr("id");
                    var group = $(this).data("iiif-group");
                    var infojson = $(this).data("iiif-infojson");
                    if (!groupsinfojsons.hasOwnProperty(group)) {
                        groupsinfojsons[group]= [infojson];
                        // We only need a single css id per group
                        groupsid[group] = element_id;

                        $(this).height(default_height);
                        $(this).width(default_width);


                    }
                    else {
                        groupsinfojsons[group].push(infojson);
                        // hide other strawberry-media-items
                        $(this).height(0);
                        $(this).width(0);

                    }
                    var nodeuuid = settings.format_strawberryfield.openseadragon.innode[element_id];
                });

            console.log(groupsinfojsons);
            console.log(groupsid);
            $.each(groupsid, function (group, element_id)  {
                var tiles = groupsinfojsons[group];
                var sequence = false;
                if (tiles.length > 1) {sequence = true}
                console.log(element_id);
                viewers[element_id] = OpenSeadragon({
                    showRotationControl: true,
                    gestureSettingsTouch: {
                        pinchRotate: true
                    },
                    debugMode: false,
                    preserveViewport: true,
                    id: element_id,
                    sequenceMode: sequence,
                    prefixUrl: "https://cdn.jsdelivr.net/npm/openseadragon@2.4/build/openseadragon/images/",
                    tileSources: tiles,
                    showNavigator: true,
                    navigatorAutoFade:  true,
                    crossOriginPolicy: 'Anonymous',
                    ajaxWithCredentials: false
                });

            });
        }
    };

})(jQuery, Drupal, drupalSettings);