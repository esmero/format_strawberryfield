(function ($, Drupal, drupalSettings) {

    'use strict';

    Drupal.behaviors.format_strawberryfield_openseadragon_initiate = {
        attach: function (context, settings) {
            var viewers = [];
            var groupsinfojsons =  {};
            var groupsid =  {};
            var showthumbs = false
            $('.strawberry-media-item[data-iiif-infojson]').once('attache_osd')
                .each(function (index, value) {

                    // Get the node uuid for this element
                    var element_id = $(this).attr("id");
                    var default_width = drupalSettings.format_strawberryfield.openseadragon[element_id]['width'];
                    var default_height = drupalSettings.format_strawberryfield.openseadragon[element_id]['height'];
                    var group = $(this).data("iiif-group");
                    var infojson = $(this).data("iiif-infojson");
                    showthumbs = $(this).data("iiif-thumbnails");
                    if (!groupsinfojsons.hasOwnProperty(group)) {
                        groupsinfojsons[group]= [infojson];
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
                    prefixUrl: "https://cdn.jsdelivr.net/npm/openseadragon@2.4/build/openseadragon/images/",
                    tileSources: tiles,
                    showNavigator: true,
                    navigatorAutoFade:  true,
                    crossOriginPolicy: 'Anonymous',
                    ajaxWithCredentials: false,
                    showReferenceStrip: thumbs,
                    referenceStripScroll: 'horizontal',
                });

            });
        }
    };

})(jQuery, Drupal, drupalSettings);

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
