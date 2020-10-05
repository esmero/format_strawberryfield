(function ($, Drupal,  OpenSeadragonAnnotorious, drupalSettings) {

    'use strict';

    Drupal.behaviors.format_strawberryfield_openseadragon_initiate = {
        attach: function (context, settings) {
            var viewers = [];
            var annotorious = [];
            var groupsinfojsons =  {};
            var groupssettings = {};
            var groupsid =  {};
            var showthumbs = false
            $('.strawberry-media-item[data-iiif-infojson]').once('attache_osd')
                .each(function (index, value) {

                    // Get the node uuid for this element
                    var element_id = $(this).attr("id");
                    var default_width = drupalSettings.format_strawberryfield.openseadragon[element_id]['width'];
                    var default_height = drupalSettings.format_strawberryfield.openseadragon[element_id]['height'];
                    var annotations = drupalSettings.format_strawberryfield.openseadragon[element_id]['webannotations'];
                    console.log(annotations);
                    var group = $(this).data("iiif-group");
                    var infojson = $(this).data("iiif-infojson");

                    showthumbs = $(this).data("iiif-thumbnails");
                    if (!groupsinfojsons.hasOwnProperty(group)) {
                        groupsinfojsons[group] = [infojson];
                        groupssettings[group]
                        groupssettings[group] = {
                            "default_width": default_width,
                            "default_height": default_height,
                            "webannotations" : false
                        }

                        if (typeof annotations != "undefined" && annotations == true) {
                            groupssettings[group].webannotations = true;
                        }

                        // We only need a single css id per group
                        groupsid[group] = element_id;

                        $(this).height(default_height);
                        $(this).css("width",default_width);

                    }
                    else {
                        groupsinfojsons[group].push(infojson);
                        // hide other strawberry-media-items
                        $(this).height(0);
                        $(this).width(0);
                    }
                    var nodeuuid = settings.format_strawberryfield.openseadragon.innode[element_id];
                });

            $.each(groupsid, function (group, element_id)  {
                var tiles = groupsinfojsons[group];
                var sequence = false;
                var thumbs = false
                if (tiles.length > 1) {
                    sequence = true;
                    thumbs = showthumbs;
                }
                viewers[element_id] = OpenSeadragon({
                    showRotationControl: true,
                    gestureSettingsTouch: {
                        pinchRotate: true
                    },
                    debugMode: false,
                    preserveViewport: true,
                    id: element_id,
                    sequenceMode: sequence,
                    prefixUrl: "https://cdn.jsdelivr.net/npm/openseadragon@2.4.2/build/openseadragon/images/",
                    tileSources: tiles,
                    showNavigator: true,
                    navigatorAutoFade:  true,
                    crossOriginPolicy: 'Anonymous',
                    ajaxWithCredentials: false,
                    showReferenceStrip: thumbs,
                    referenceStripScroll: 'horizontal',
                });
                if (typeof  groupssettings[group].webannotations != "undefined" && groupssettings[group].webannotations == true) {
                    console.log("Attaching W3C Annotations");
                    var $readonly = true;
                    if (settings.user.uid != 0) {
                        $readonly = false;
                    }

                    var $config = {
                        "readOnly":$readonly
                    }

                    annotorious[element_id] = OpenSeadragonAnnotorious(viewers[element_id], $config);
                    var $container = '<div class="format_strawberryfield_annotation_savebutton" title = "Save Annotations" style="background-color: transparent; border: none; top:-1em; margin: 0px; padding: 0px; position: relative; touch-action: none; display: inline-block;">';
                    var $savebutton = $($container + '<input type="button" value="Save Annotations" />' + '</div>');
                    $savebutton.appendTo(  $('#'+element_id + '  div.openseadragon-container > div:nth-child(2) > div > div'));
                    console.log(settings.user.uid);
                    // Attach handlers to listen to events
                    annotorious[element_id].on('createAnnotation', function(a) {
                        console.log(a);
                        console.log( annotorious[element_id].getAnnotations());
                    });
                }
            });
        }
    };

})(jQuery, Drupal, OpenSeadragon.Annotorious, drupalSettings);