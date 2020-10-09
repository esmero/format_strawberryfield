(function ($, Drupal,  OpenSeadragonAnnotorious, drupalSettings) {

    'use strict';

    Drupal.behaviors.format_strawberryfield_openseadragon_initiate = {
        attach: function (context, settings) {
            var viewers = [];
            var current_openseadragon_tile = [];
            var annotorious = [];
            var groupsinfojsons =  {};
            var groupssettings = {};
            var groupsid =  {};
            var showthumbs = false
            var nodeuuid = null;
            var annotorious_annotations = [];
            var annotorious_current_tile = [];
            var current_user = null;
            $('.strawberry-media-item[data-iiif-infojson]').once('attache_osd')
                .each(function (index, value) {

                    // Get the node uuid for this element
                    var element_id = $(this).attr("id");
                    var default_width = drupalSettings.format_strawberryfield.openseadragon[element_id]['width'];
                    var default_height = drupalSettings.format_strawberryfield.openseadragon[element_id]['height'];
                    var annotations = drupalSettings.format_strawberryfield.openseadragon[element_id]['webannotations'];
                    var file_uuid = drupalSettings.format_strawberryfield.openseadragon[element_id]['dr:uuid'];
                    var keystoreid = drupalSettings.format_strawberryfield.openseadragon[element_id]['keystoreid'];
                    current_user = drupalSettings.format_strawberryfield.openseadragon[element_id]['user'];
                    console.log(current_user);

                    var group = $(this).data("iiif-group");
                    var infojson = $(this).data("iiif-infojson");
                    showthumbs = $(this).data("iiif-thumbnails");
                    if (!groupsinfojsons.hasOwnProperty(group)) {
                        groupsinfojsons[group] = [infojson];

                        groupssettings[group] = {
                            "default_width": default_width,
                            "default_height": default_height,
                            "webannotations" : false,
                            "nodeuuid" : settings.format_strawberryfield.openseadragon.innode[element_id],
                            "file_uuid" : file_uuid,
                            "keystoreid" : keystoreid
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

                });


            $.each(groupsid, function (group, element_id)  {

                var tiles = groupsinfojsons[group];
                var sequence = false;
                var thumbs = false
                if (tiles.length > 1) {
                    sequence = true;
                    thumbs = showthumbs;
                }
                if (tiles.length == 0) return false;

                current_openseadragon_tile[element_id] = tiles[0];

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
                if (typeof groupssettings[group].webannotations != "undefined" && groupssettings[group].webannotations == true) {
                    console.log("Attaching W3C Annotations");
                    var $readonly = true;
                    if (settings.user.uid != 0) {
                        $readonly = false;
                    }

                    var $config = {
                        "readOnly":$readonly
                    }

                    annotorious[element_id] = OpenSeadragonAnnotorious(viewers[element_id], $config);
                    annotorious_annotations[element_id] = [];
                    annotorious_current_tile[element_id] = 0;

                    // We always start with the first Sequence (0)

                    jQuery.ajax({
                        url: '/do/'+ groupssettings[group].nodeuuid + '/webannon/read',
                        type: "GET",
                        dataType: 'json',
                        element_id: element_id,
                        data: {
                            'target_resource': current_openseadragon_tile[element_id],
                            'keystoreid': groupssettings[group].keystoreid,
                        },
                        success:  function(pagedata){
                            console.log('Webannotations Loaded form Source');
                            annotorious[this.element_id].setAnnotations(pagedata);
                            annotorious_annotations[this.element_id] = [pagedata];
                            console.log(annotorious_annotations[this.element_id]);
                        }
                    });



                    var $container = '<div class="format_strawberryfield_annotation_savebutton" title = "Save Annotations" style="background-color: transparent; border: none; top:-1em; margin: 0px; padding: 0px; position: relative; touch-action: none; display: inline-block;">';
                    var $savebutton = $($container + '<input type="button" value="Save Annotations" />' + '</div>');
                    console.log(drupalSettings);
                    if (settings.user.uid > 0) {
                        annotorious[element_id].setAuthInfo({
                            id: current_user['url'],
                            displayName: current_user['name'],
                        });
                        // $savebutton.appendTo($('#' + element_id + '  div.openseadragon-container > div:nth-child(2) > div > div'));
                    }
                    /* Acts on page change. We need to load new annotations when that happens! */
                    viewers[element_id].addHandler("page", function (data) {
                        current_openseadragon_tile[element_id] = tiles[data.page];

                        console.log('previous page was'+ annotorious_current_tile[element_id]);
                        // This stores current page before the actual change happens.
                        annotorious_annotations[element_id][annotorious_current_tile[element_id]] = annotorious[element_id].getAnnotations();
                        console.log(annotorious_annotations[element_id]);
                        // Now set the current tile
                        annotorious_current_tile[element_id] = data.page;
                        if (typeof annotorious_annotations[element_id][data.page] == "undefined") {
                            annotorious[element_id].setAnnotations([]);
                            console.log('Reading annotations for sequence ' + data.page + ' from Live API data');
                            jQuery.ajax({
                                url: '/do/' + groupssettings[group].nodeuuid + '/webannon/read',
                                type: "GET",
                                page: data.page,
                                element_id: element_id,
                                dataType: 'json',
                                data: {
                                 'target_resource': current_openseadragon_tile[element_id],
                                 'keystoreid': groupssettings[group].keystoreid,
                                },
                                success: function (pagedata) {
                                    annotorious[this.element_id].setAnnotations(pagedata);
                                    console.log(this.page);
                                    annotorious_annotations[this.element_id][this.page] = pagedata;
                                 }
                            });
                        }
                        else {
                            // Reads from local copy
                            console.log('Reading annotations for sequence ' + data.page + ' from cached data');
                            annotorious[element_id].setAnnotations(annotorious_annotations[element_id][data.page]);
                        }
                    });

                    // Attach handlers to listen to events
                    annotorious[element_id].on('createAnnotation', function(a) {
                        jQuery.ajax({
                            url: '/do/'+ groupssettings[group].nodeuuid + '/webannon/post',
                            type: "POST",
                            dataType: 'json',
                            data: {
                                'data': a,
                                'target_resource': current_openseadragon_tile[element_id],
                                'keystoreid': groupssettings[group].keystoreid,
                            },
                            success:  function(data){
                                console.log(data);
                            }
                        });

                        console.log(annotorious[element_id].getAnnotations());
                    });
                    // Attach handlers to listen to events
                    annotorious[element_id].on('updateAnnotation', function(a,previous) {
                        console.log(a);
                        console.log(previous);
                        jQuery.ajax({
                            url: '/do/'+ groupssettings[group].nodeuuid + '/webannon/put',
                            type: "PUT",
                            dataType: 'json',
                            data: {
                                'data': a,
                                'target_resource': current_openseadragon_tile[element_id],
                                'keystoreid': groupssettings[group].keystoreid,
                            },
                            success:  function(data){
                                console.log(data);
                            }
                        });
                        console.log(annotorious[element_id].getAnnotations());
                    });
                    // Attach handlers to listen to events
                    annotorious[element_id].on('deleteAnnotation', function(a) {
                        jQuery.ajax({
                            url: '/do/'+ groupssettings[group].nodeuuid + '/webannon/delete',
                            type: "DELETE",
                            dataType: 'json',
                            data: {
                                'data': a,
                                'target_resource': current_openseadragon_tile[element_id],
                                'keystoreid': groupssettings[group].keystoreid,
                            },
                            success:  function(data){
                                console.log(data);
                            }
                        });
                        console.log(annotorious[element_id].getAnnotations());
                    });
                }
            });
        }
    };
})(jQuery, Drupal, OpenSeadragon.Annotorious, drupalSettings);

// override getTileUrl
OpenSeadragon.IIIFTileSource.prototype.getTileUrl = function( level, x, y ){
        if(this.emulateLegacyImagePyramid) {
            var url = null;
            if ( this.levels.length > 0 && level >= this.minLevel && level <= this.maxLevel ) {
                url = this.levels[ level ].url;
            }
            return url;
        }

        //# constants
        var IIIF_ROTATION = '0',
            //## get the scale (level as a decimal)
            scale = Math.pow( 0.5, this.maxLevel - level ),

            //# image dimensions at this level
            levelWidth = Math.ceil( this.width * scale ),
            levelHeight = Math.ceil( this.height * scale ),

            //## iiif region
            tileWidth,
            tileHeight,
            iiifTileSizeWidth,
            iiifTileSizeHeight,
            iiifRegion,
            iiifTileX,
            iiifTileY,
            iiifTileW,
            iiifTileH,
            iiifSize,
            iiifSizeW,
            iiifSizeH,
            iiifQuality,
            uri;

        tileWidth = this.getTileWidth(level);
        tileHeight = this.getTileHeight(level);
        iiifTileSizeWidth = Math.ceil( tileWidth / scale );
        iiifTileSizeHeight = Math.ceil( tileHeight / scale );
        if (this.version === 1) {
            iiifQuality = "native." + this.tileFormat;
        } else {
            iiifQuality = "default." + this.tileFormat;
        }
        if ( levelWidth < tileWidth && levelHeight < tileHeight ){
            if ( this.version === 2 && levelWidth === this.width ) {
                iiifSize = "full";
            } else if ( this.version === 3 && levelWidth === this.width && levelHeight === this.height ) {
                iiifSize = "max";
            } else if ( this.version === 3 ) {
                iiifSize = levelWidth + "," + levelHeight;
            } else {
                iiifSize = levelWidth + ",";
            }
            iiifRegion = 'full';
        } else {
            iiifTileX = x * iiifTileSizeWidth;
            iiifTileY = y * iiifTileSizeHeight;
            iiifTileW = Math.min( iiifTileSizeWidth, this.width - iiifTileX );
            iiifTileH = Math.min( iiifTileSizeHeight, this.height - iiifTileY );
            if ( x === 0 && y === 0 && iiifTileW === this.width && iiifTileH === this.height ) {
                iiifRegion = "full";
            } else {
                iiifRegion = [ iiifTileX, iiifTileY, iiifTileW, iiifTileH ].join( ',' );
            }
            iiifSizeW = Math.ceil( iiifTileW * scale );
            iiifSizeH = Math.ceil( iiifTileH * scale );
            if ( this.version === 2 && iiifSizeW === this.width ) {
                iiifSize = "full";
            } else if ( this.version === 3 && iiifSizeW === this.width && iiifSizeH === this.height ) {
                iiifSize = "max";
            } else if (this.version === 3) {
                iiifSize = iiifSizeW + "," + iiifSizeH;
            } else {
                iiifSize = iiifSizeW + ",";
            }
        }

        //OLD//uri = [ this['@id'], iiifRegion, iiifSize, IIIF_ROTATION, iiifQuality ].join( '/' );
        queryParams = this['@id'].match(/\?.*/);
        tilesUrl = this['@id'].replace(queryParams, '');
        uri = [ tilesUrl, iiifRegion, iiifSize, IIIF_ROTATION, iiifQuality ].join( '/' );
        if (queryParams) {
          uri += queryParams;
        }

        return uri;
    };

// override configure
OpenSeadragon.IIIFTileSource.prototype.configure = function( data, url ){
      // Try to deduce our version and fake it upwards if needed

        queryParams = url.match(/\?.*/);
        tilesUrl = url.replace(queryParams, '');

        if ( !$.isPlainObject(data) ) {
            var options = configureFromXml10( data );
            options['@context'] = "http://iiif.io/api/image/1.0/context.json";

            //OLD//options['@id'] = url.replace('/info.xml', '');
            options['@id'] = tilesUrl.replace('/info.xml', '');
            if (queryParams) {
              options['@id'] += queryParams;
            }

            options.version = 1;
            return options;
        } else {
            if ( !data['@context'] ) {
                data['@context'] = 'http://iiif.io/api/image/1.0/context.json';

                //OLD//data['@id'] = url.replace('/info.json', '');
                data['@id'] = tilesUrl.replace('/info.xml', '');
                if (queryParams) {
                  data['@id'] += queryParams;
                }

                data.version = 1;
            } else {
                var context = data['@context'];
                if (Array.isArray(context)) {
                    for (var i = 0; i < context.length; i++) {
                        if (typeof context[i] === 'string' &&
                            ( /^http:\/\/iiif\.io\/api\/image\/[1-3]\/context\.json$/.test(context[i]) ||
                            context[i] === 'http://library.stanford.edu/iiif/image-api/1.1/context.json' ) ) {
                            context = context[i];
                            break;
                        }
                    }
                }
                switch (context) {
                    case 'http://iiif.io/api/image/1/context.json':
                    case 'http://library.stanford.edu/iiif/image-api/1.1/context.json':
                        data.version = 1;
                        break;
                    case 'http://iiif.io/api/image/2/context.json':
                        data.version = 2;
                        break;
                    case 'http://iiif.io/api/image/3/context.json':
                        data.version = 3;
                        break;
                    default:
                        $.console.error('Data has a @context property which contains no known IIIF context URI.');
                }
            }
            if ( !data['@id'] && data['id'] ) {
                data['@id'] = data['id'];
            }

            if (queryParams) {
              data['@id'] += queryParams;
            }

            if(data.preferredFormats) {
                for (var f = 0; f < data.preferredFormats.length; f++ ) {
                    if ( OpenSeadragon.imageFormatSupported(data.preferredFormats[f]) ) {
                        data.tileFormat = data.preferredFormats[f];
                        break;
                    }
                }
            }
            return data;
        }
    };