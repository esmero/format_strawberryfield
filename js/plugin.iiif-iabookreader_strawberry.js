/**
 * Strawberry Plugin which allows IIIF manifest parsing/OpenSeadragon Extensions
 * Used by Bookreader. OpenSeadragon.IIIFTileSource.* used to be inside js/iiif-openseadragon_strawberry
 * But because of cross dependencies we ended initializing a JS Web Worker Open CV on every book.
 */

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

/* Book Reader Overrides/extensions */

jQuery.extend(BookReader.defaultOptions, {
    iiifmanifesturl: '',
    iiifmanifest: null,
    iiifdefaultsequence: null,
    // Stores dimensions coming from a IIIF info.json
    dataDimensions: [],
    bookId: '',
    enableSearch: true,
    searchInsideUrl: '',
    initialSearchTerm: null,
    mobileNavTitle: '',
    hasCover: true
});

BookReader.prototype.setup = (function(super_) {
    return function (options) {
        super_.call(this, options);

        this.IIIFsequence = {
            title: null,
            imagesList: [],
            numPages: null,
            bookUrl: null
        };
        this.leafsToUUids = {};
        this.searchTerm = '';
        this.searchResults = null;
        this.searchInsideUrl = options.searchInsideUrl;
        this.enableSearch = options.enableSearch;
        this.goToFirstResult = false;
        this.hasCover = options.hasCover;

        // Base server used by some api calls
        this.bookId = options.bookId;
        if (this.searchView) { return; }
        this.searchView = new SearchView({
            br: this,
            selector: '#BRsearch_tray',
        });

    };
})(BookReader.prototype.setup);


BookReader.prototype.init = (function(super_) {
        return function (options) {
                super_.call(this, options)
        };
    }
)(BookReader.prototype.init);

/**
 * Return which side, left or right, that a given page should be
 * displayed on. override allows a no-cover situation.
 */
BookReader.prototype.getPageSide = function(index) {
    if ('rl' != this.pageProgression) {
        // If pageProgression is not set RTL we assume it is LTR
        if (0 == (index & 0x1)) {
            // Even-numbered page
            if (false === this.hasCover) {
                return 'L';
            }
            return 'R';
        }
        else {
            // Odd-numbered page
            if (false === this.hasCover) {
                return 'R';
            }
            return 'L';
        }
    }
    else {
        // RTL
        if (0 == (index & 0x1)) {
            if (false === this.hasCover) {
                return 'R';
            }
            return 'L';
        }
        else {
            if (false === this.hasCover) {
                return 'L';
            }
            return 'R';
        }
    }
}



BookReader.prototype.getApiVersion = function() {
    var self = this;

    if(!self.apiVersion) {
        var $apiVersion = "2.x";
        if (self.jsonLd["@context"].length > 0 && self.jsonLd["@context"].includes("http://iiif.io/api/presentation/3/context.json")) {
            $apiVersion = "3.x";
        }
        self.apiVersion = $apiVersion;
        return $apiVersion;
    }
    else {
        return self.apiVersion;
    }
}

BookReader.prototype.loadManifest = async function () {
    // NOT to be called anymore inside br.init(). TextSelection plugin
    // does not allow leafs/images to be initiated async and fails if this one does not
    // return in order. So we call it outside, async on
    var self = this;
    if (!this.options.iiifmanifesturl && !this.options.iiifmanifest) return false;
    // Simplest approach, we got a full manifest passed as an Object
    if (self.options.iiifmanifest != null) {
        self.jsonLd = self.options.iiifmanifest;
        self.bookTitle =
            self.getApiVersion() === "3.x"
            && Object.keys(self.jsonLd.label).length > 0
            && self.jsonLd.label[Object.keys(self.jsonLd.label)[0]].length > 0
                ? self.jsonLd.label[Object.keys(self.jsonLd.label)[0]].join("; ")
                : self.jsonLd.label;
        self.bookUrl = '#';
        // self.thumbnail = self.jsonLd.thumbnail['@id'];
        self.metadata = self.jsonLd.metadata;
        // Assuming we have no default sequence so first one is the good one.
        self.parseSequence(null);
        return true;
    }
    else if (this.options.iiifmanifesturl && typeof(this.options.iiifmanifesturl) == "string") {
        try {
            // Ok, no full manifest, now try with the remote URL
             const $iiifmanifest = Drupal.FormatStrawberryfieldIiifUtils.fetchIIIFManifest(this.options.iiifmanifesturl.replace(/^\s+|\s+$/g, ''));
             await $iiifmanifest.then(iiifmanifest_promise_resolved => {
                self.jsonLd = iiifmanifest_promise_resolved;
                self.bookTitle =
                    self.getApiVersion() === "3.x"
                    && Object.keys(self.jsonLd.label).length > 0
                    && self.jsonLd.label[Object.keys(self.jsonLd.label)[0]].length > 0
                        ? self.jsonLd.label[Object.keys(self.jsonLd.label)[0]].join("; ")
                        : self.jsonLd.label;
                self.bookUrl = '#';
                self.metadata = self.jsonLd.metadata;
                self.parseSequence(self.options.iiifdefaultsequence);
                return true;
            });
        }
        catch (error) {
            console.log('Failed loading ' + self.options.iiifmanifesturl);
            return false;
        }
    }
    return false;
}

/* Bugs in the parent implementation */
BookReader.prototype.setupTooltips = function() {
};

function processBehaviorToIABookreaderModeV3(jsonLd) {
    // Bookreader supports only two modes but also a book cover or not
    // IIIF Presentation API V2 and V3 supports more
    // So we need to reduce/map back to only 2 options
    // V3 key at manifest level/and/or Sequence level is `behavior`
    // V2 key at manifest level/and/or Sequence is `viewingHint`
    if (typeof (jsonLd.behavior) !== "undefined") {
        if (typeof jsonLd.behavior === 'string') {
            if (jsonLd.behavior == 'paged') {
                return 2;
            }
            if (jsonLd.behavior == 'individuals') {
                return 1;
            }
        } else if (jsonLd.behavior.constructor.name == "Array") {
            if (jsonLd.behavior.includes("paged")) {
                return 2;
            }
            if (jsonLd.behavior.includes("individuals")) {
                return 1;
            }
        }
    }

    return 2;
}

function processBehaviorToIABookreaderModeV2(jsonLd) {
    // Bookreader supports only two modes but also a book cover or not
    // IIIF Presentation API V2 and V3 supports more
    // So we need to reduce/map back to only 2 options
    // V3 key at manifest level/and/or Sequence level is `behavior`
    // V2 key at manifest level/and/or Sequence is `viewingHint`
    // normally we would use https://github.com/internetarchive/bookreader/blob/4dab4cef30c0af05fa864e57578a834b89fbaba2/src/BookReader.js#L62
    // but these have not changed in 10 years ... passing this would be an overkill just to read that.
    if (typeof(jsonLd.viewingHint) !== "undefined") {
        if (jsonLd.viewingHint == 'paged') {
            return 2;
        }
        if (jsonLd.viewingHint == 'individuals') {
            return 1;
        }
    }
    return 0;
    // Why 0? because i will use a failure to try with a sequence instead
}



BookReader.prototype.parseSequence = function (sequenceId) {
    var self = this;

    // viewingDirection is the same in V2 and V3 BUT on V2 it can be inside a sequence ..
    if (typeof(self.jsonLd.viewingDirection) !== "undefined") {
        if (self.jsonLd.viewingDirection == "right-to-left") {
            self.pageProgression = 'rl';
        }
        if (self.jsonLd.viewingDirection == "left-to-right") {
            self.pageProgression = 'lr';
        }
    }

    if(self.getApiVersion() == "3.x") {
        //  WE can't try with a specific sequenceID, bc that is not a V3 thing at all
        self.defaults = "mode/" + processBehaviorToIABookreaderModeV3(self.jsonLd) + 'up';
        self.IIIFsequence.title = "Sequence";
        self.IIIFsequence.bookUrl = "http://iiif.io";
        self.IIIFsequence.imagesList = getImagesListApi3(self.jsonLd.items);
        self.numLeafs = self.IIIFsequence.imagesList.length;
    }
    else {
        let top_level_behavior = processBehaviorToIABookreaderModeV2(self.jsonLd);
        let sequence_level_behavior = 0;

        jQuery.each(self.jsonLd.sequences, function(index, sequence) {
            // try with a specific sequenceID
            if (sequenceId!= null) {
                if (sequence['@id'] === sequenceId) {
                    self.IIIFsequence.title = "Sequence";
                    self.IIIFsequence.bookUrl = "http://iiif.io";
                    self.IIIFsequence.imagesList = getImagesList(sequence);
                    self.numLeafs = self.IIIFsequence.imagesList.length;
                    sequence_level_behavior = processBehaviorToIABookreaderModeV2(sequence);
                    // In case the sequence has the hit, it wins over the manifest one.
                    if (typeof(sequence.viewingDirection) !== "undefined") {
                        if (sequence.viewingDirection == "right-to-left") {
                            self.pageProgression = 'rl';
                        }
                        if (sequence.viewingDirection == "left-to-right") {
                            self.pageProgression = 'lr';
                        }
                    }
                }
            } else {
                self.IIIFsequence.title = "Sequence";
                self.IIIFsequence.bookUrl = "http://iiif.io";
                self.IIIFsequence.imagesList = getImagesList(sequence);
                self.numLeafs = self.IIIFsequence.imagesList.length;
                sequence_level_behavior = processBehaviorToIABookreaderModeV2(sequence);
                // In case the sequence has the hit, it wins over the manifest one.
                if (typeof(sequence.viewingDirection) !== "undefined") {
                    if (sequence.viewingDirection == "right-to-left") {
                        self.pageProgression = 'rl';
                    }
                    if (sequence.viewingDirection == "left-to-right") {
                        self.pageProgression = 'lr';
                    }
                }
            }
        });
        if (top_level_behavior != 0) {
            if (sequence_level_behavior == 0) {
                self.defaults = "mode/" + top_level_behavior + 'up';
            }
            else {
                self.defaults = "mode/" + sequence_level_behavior + 'up';
            }
        }
        else if (top_level_behavior == 0) {
            if (sequence_level_behavior == 0) {
                self.defaults = "mode/" + 2 + 'up';
            }
            else {
                self.defaults = "mode/" + sequence_level_behavior + 'up';
            }
        }
    }

    var tmpdata = [];
    jQuery.each(self.IIIFsequence.imagesList, function(index,image) {
        var imageuri = null;
        var infojson = null;

        // If serviceURL is null then we can not call infoJSON which also means, if width and height are not
        // present, render will fail
        // Options we have here
        // If either width or height is missing, use canvas ratio, which will have to require if all fails then simply
        // use the default 4:3 and log into console so the user/admin/webmaster knows this is happening
        // Example of what can happen with Service Level 0 and no correct dimensions
        /*
        aspectRatio: Infinity // HAHAHA
        canvasHeight: 4
        canvasWidth: 3
        height: 0
        imageGetArgument: "?page=2"
        imageUrl: "http://localhost:8183/iiif/2/65f%2Fapplication-williams-college-yearbook-01-d708c989-229b-4ba9-a547-ed2faf568e0f.pdf/full/full/0/default.jpg…"
        serviceUrl: null
        width: 800
        */

        if (image.width == 0 || image.width == null) {
            if ((image.canvasWidth != 0 && image.canvasWidth != null) &&
                (image.canvasHeight != 0 && image.canvasHeight != null)
            ) {
                // We have a full ration
                var aspectRatio = (image.canvasWidth / image.canvasHeight) || 0.75;
                image.width = Math.round(image.height * aspectRatio);
            } else {
                console.log('canvas is incorrect and has no ratio for ' + image.imageUrl);
                console.log('usign a fallback of 3:4, please correct your manifest');
                image.width = Math.round(image.height * 0.75);
            }
        } else if (image.height == 0 || image.height == null) {
            if ((image.canvasWidth != 0 && image.canvasWidth != null) &&
                (image.canvasHeight != 0 && image.canvasHeight != null)
            ) {
                // We have a full ration
                var aspectRatio = (image.canvasWidth / image.canvasHeight) || 0.75;
                image.height = Math.round(image.width / aspectRatio);
            } else {
                image.height = Math.round(image.width / 0.75);
                console.log('canvas is incorrect and has no ratio for ' + image.imageUrl);
                console.log('usign a fallback of 3:4, please correct your manifest');
            }
        }

        // infojson will be empty if service URL is empty

        if (image.serviceUrl != null) {
            // Pass also the imageGerArgument to the info.json -- Cantaloupe 4.1.6
            infojson = image.serviceUrl + "/info.json" + image.imageGetArgument;
            // If we don't have image.width at this stage we will check if we have an image.imageUrl already
            if (image.width == 0) {
                if (image.imageUrl != "") {
                    imageuri = image.imageUrl;
                }
                else {
                    // We have no image url, we have no width, try with full
                    imageuri = image.serviceUrl + "/full/full/0/default.jpg" + image.imageGetArgument;
                }
            }
            else {
                imageuri = image.serviceUrl + "/full/" + image.width + ",/0/default.jpg" + image.imageGetArgument;
            }
        } else {
            imageuri = image.imageUrl;
        }

        tmpdata.push(
            {
                width: image.width,
                height: image.height,
                uri: imageuri,
                pageNum: index+1,
                infojson: infojson,
            });
    });
    self.options.data.push(tmpdata);
    // Generate cache now.
    self._getDataFlattened();

    delete self.jsonLd;

    function getImagesList(sequence) {
        var imagesList = [];

        jQuery.each(sequence.canvases, function(index, canvas) {
            var imageObj;

            if (canvas['@type'] === 'sc:Canvas') {
                var images = canvas.resources || canvas.images;

                jQuery.each(images, function(index, image) {
                    if (image['@type'] === 'oa:Annotation') {
                        imageObj = getImageObject(image);

                        imageObj.canvasWidth = canvas.width;
                        imageObj.canvasHeight = canvas.height;

                        if (!(/#xywh/).test(image.on)) {
                          imagesList.push(imageObj);
                          const UUIDandSequence = self.getUUIDAndSequencefromCanvas(canvas['@id']);
                          if (UUIDandSequence) {
                            self.leafsToUUids[UUIDandSequence] = imagesList.length;
                          }
                        }
                    }
                });
            }
        });

        return imagesList;
    }

    function getImagesListApi3(items) {
        var imagesList = [];

        jQuery.each(items, function (index, item) {
            if (item['type'] === 'Canvas') {
                let imageObj = {
                    canvasHeight: item.height || 0,
                    canvasWidth: item.width || 0,
                };
                let annotationpages = item.items;
                jQuery.each(annotationpages, function (index, annotationpage) {
                    if ((annotationpage['type'] === 'AnnotationPage') && (annotationpage['items'][0]['type'] === 'Annotation') && (annotationpage['items'][0]['body'])) {
                        let annotation = annotationpage['items'][0];
                        imageObj.serviceUrl = null;
                        if (annotation.body.hasOwnProperty('service') && annotation.body.service[0]['id'] && isValidHttpUrl(annotation.body.service[0]['id'])) {
                            imageObj.serviceUrl = annotation.body.service[0]['id'].replace(/\/$/, '');
                        }
                        imageObj.imageUrl = annotation.body.id || "";
                        // imageObj.imageUrl = imageObj.imageUrl.replace(/\/full\/full\/0\/default.jpg/, '/full/'+ imageObj.canvasWidth + ',/0/default.jpg');
                        imageObj.width = annotation.body.width || 0;
                        imageObj.height = annotation.body.height || 0;
                        imageObj.aspectRatio = (imageObj.width / imageObj.height) || 1;
                        imageObj.imageGetArgument = getURLArgument(annotation.body.id);

                        // Add it to the images list
                        if (!(/#xywh/).test(annotation.target)) {
                            imagesList.push(imageObj);
                            const UUIDandSequence = self.getUUIDAndSequencefromCanvas(item.id);
                            if (UUIDandSequence) {
                              self.leafsToUUids[UUIDandSequence] = imagesList.length;
                            }
                        }
                    }
                });

            }

        });

        return imagesList;
    }

    function getImageObject (image) {
        var resource = image.resource;

        if (resource.hasOwnProperty('@type') && resource['@type'] === 'oa:Choice') {
            var imageObj = getImageProperties(resource.default);
        } else {
            imageObj = getImageProperties(resource);
        }

        return(imageObj);
    }

    function getImageProperties(image) {
        var serviceUrl = null;
        if (image.hasOwnProperty('service') && isValidHttpUrl(image.service['@id'])) {
            serviceUrl = image.service['@id'].replace(/\/$/, '');
        }
        else {
            serviceUrl = null;
        }

        var imageObj = {
            height:       image.height || 0,
            width:        image.width || 0,
            imageUrl:     image['@id'],
            imageGetArgument: getURLArgument(image['@id']),
            serviceUrl:   serviceUrl,
        };

        imageObj.aspectRatio  = (imageObj.width / imageObj.height) || 1;

        return imageObj;
    }

    /* Check if a string can be converted into an URL object */
    function isValidHttpUrl(string) {
        let url;

        try {
            url = new URL(string);
        } catch (_) {
            return false;
        }

        return url.protocol === "http:" || url.protocol === "https:";
    }

    /* returns the search string */
    function getURLArgument(string) {
        let url;

        try {
            url = new URL(string);
        } catch (_) {
            return '';
        }

        return url.search
    }
};



BookReader.prototype.getIIIFInfoJsonFromURL = function(string){
  let url;

  try {
    url = new URL(string);
  } catch (_) {
    return '';
  }
  let path = url.pathname;
  // IIIF will have  id/crop/size/rotation/filename So we will split and reverse
  if (path !== '/') {
    let path_parts = path.split("/");
    if (path_parts.length >= 5) {
      path_parts = path_parts.reverse();
      path_parts = path_parts.slice(4);
      //reverse again
      path_parts = path_parts.reverse();
      path =  path_parts.join('/') + '/info.json';
      url.pathname = path;
      return url.toString();
    }
    else {
      // Might be non IIIF, so we return the Image itself.
      return string;
    }
  }
  else {
    return string;
  }
}

BookReader.prototype.getUUIDAndSequencefromCanvas = function(string){
  let url;

  try {
    url = new URL(string);
  } catch (_) {
    return '';
  }
  let path = url.pathname;
  // IIIF will have  id/crop/size/rotation/filename So we will split and reverse
  if (path !== '/') {
    // Assumes /somestuff/84e618e0-3456-4edd-841e-5b4c9e8e0490/canvas/p1
    let path_parts = path.split("/");
    if (path_parts.length >= 5) {
      path_parts = path_parts.reverse();
      path_parts = path_parts.slice(0,3);
      if (path_parts.length == 3) {
        const page = path_parts[0].replace(/\D/g,'');
        const uuid = path_parts[2];
        // We will use this as an index to match File UUIDs and their internal Sequence IDs to IABookreader pages.
        return uuid + '/' + page;
      }
    }
    else {
      // Might be non IIIF, so we return the Image itself.
      return null;
    }
  }
  else {
    return null;
  }
}



/**
 * @param  {number} index
 * @return {Number|undefined}
 */
BookReader.prototype.getPageWidth = function(index) {
    var self = this;
    if (isNaN(index)) return;

    const pagewidth = self.getPageProp(index, 'width');
    if (pagewidth == 0 || pagewidth == null || typeof pagewidth === "undefined") {
        if (typeof self.options.dataDimensions[index] === "undefined") {
            const imageInfo = this.getRemoteInfoJson(index);
            self.options.dataDimensions[index] = imageInfo;
            // Means computed height is different to one set by the manifest in the resource
            // Here self.getPageHeight should never return cero, if so means all is bad, bad
            const height = self.getPageHeight(index);
            if (height != imageInfo.height) {
                scale = height/imageInfo.height || 1;
                return Math.round(imageInfo.width * scale);

            }

            return self.options.dataDimensions[index].width;
        }
        else {

            return self.options.dataDimensions[index].width;
        }

    }
    else {
        return pagewidth;
    }
};

/**
 * @param  {number} index
 * @return {Number|undefined}
 */
BookReader.prototype.getPageHeight = function(index) {

    if (isNaN(index)) return;
    var self = this;
    var pageheight = this.getPageProp(index, 'height');

    // Note: once we get info.json we don't repopulate options.data[]
    // Reasons is that array is cached and flattened, so no use to put our
    // Recently fetched real dimensions from there and it can't be trusted
    // that this structue will stay around on version changes.
    if (pageheight == 0 || pageheight == null || typeof pageheight === "undefined") {
        if (typeof self.options.dataDimensions[index] === "undefined") {
            var imageInfo = this.getRemoteInfoJson(index);

            self.options.dataDimensions[index] = imageInfo;
            // Means computed width is different to one set by the manifest in the resource
            // Here self.getPageHeight should never return cero, if so means all is bad, bad
            var width = self.getPageWidth(index);
            if (width != imageInfo.width) {

                var scale = width/imageInfo.width || 1;

                self.options.dataDimensions[index].height = Math.round(imageInfo.height * scale);

            }

            return self.options.dataDimensions[index].height;
        }
        else {

            return self.options.dataDimensions[index].height;
        }

    } else {

        return pageheight;
    }
};


BookReader.prototype.getRemoteInfoJson = function(index) {

    var self = this;
    let remotedimensions = {};

    var infojsonurl = this.getPageProp(index, 'infojson');
    if (infojsonurl == null) {
        console.log('Service Level 0, Defaulting to fixed Dimensions ' + self.options.maxWidth + ' px')
        remotedimensions.width = self.options.maxWidth;
        remotedimensions.height = self.options.maxWidth;
    } else {
        jQuery.ajax({
            url: infojsonurl.replace(/^\s+|\s+$/g, ''),
            dataType: 'json',
            async: false,
            success: function (infojson) {
                remotedimensions.width = infojson.width;
                remotedimensions.height = infojson.height;
            },

            error: function () {
                console.log('Failed loading ' + infojsonurl);
                // default to our only known number
                // Chances whole manifest is wrong
                console.log('Defaulting to fixed Dimensions ' + self.options.maxWidth + ' px')
                remotedimensions.width = self.options.maxWidth;
                remotedimensions.height = self.options.maxWidth;
            }
        });
    }

    return remotedimensions;
}

/**
 * @typedef {object} SearchOptions
 * @property {boolean} goToFirstResult
 * @property {boolean} disablePopup
 * @property {(null|function)} error - @deprecated at v.5.0
 * @property {(null|function)} success - @deprecated at v.5.0
 */

/**
 * Submits search request
 *
 * @param {string} term
 * @param {SearchOptions} overrides
 */
BookReader.prototype.search =  (function(super_) {

    return function(term = '', overrides = {}) {
        /** @type {SearchOptions} */
        const defaultOptions = {
            goToFirstResult: false, /* jump to the first result (default=false) */
            disablePopup: false,    /* don't show the modal progress (default=false) */
            suppressFragmentChange: false, /* don't change the URL on initial load */
            error: null,            /* optional error handler (default=null) */
            success: null,          /* optional success handler (default=null) */

        };
        const options = jQuery.extend({}, defaultOptions, overrides);
        this.suppressFragmentChange = options.suppressFragmentChange;

        // strip slashes, since this goes in the url
        this.searchTerm = term.replace(/\//g, ' ');

        if (!options.suppressFragmentChange) {
            this.trigger(BookReader.eventNames.fragmentChange);
        }
        self = this;
        // Add quotes to the term. This is to compenstate for the backends default OR query
        // term = term.replace(/['"]+/g, '');
        // term = '"' + term + '"';

        // Remove the port and userdir
        const serverPath = this.server;
        const baseUrl = `${serverPath}${this.searchInsideUrl}?`;

        // Remove subPrefix from end of path
        let path = this.bookPath;
        const subPrefixWithSlash = `/${this.subPrefix}`;
        if (this.bookPath.length - this.bookPath.lastIndexOf(subPrefixWithSlash) == subPrefixWithSlash.length) {
            path = this.bookPath.substr(0, this.bookPath.length - subPrefixWithSlash.length);
        }

        const urlParams = {
            q: term,
        };

        // NOTE that the API does not expect / (slashes) to be encoded. (%2F) won't work
        const paramStr = $.param(urlParams).replace(/%2F/g, '/');

        const url = `${baseUrl}${paramStr}`;

        const processSearchResults = (searchInsideResults) => {
            const responseHasError = searchInsideResults.error || !searchInsideResults.matches.length;
            const hasCustomError = typeof options.error === 'function';
            const hasCustomSuccess = typeof options.success === 'function';

            if (responseHasError) {
                hasCustomError
                    ? options.error.call(this, searchInsideResults, options)
                    : this.BRSearchCallbackError(searchInsideResults, options);
            } else {
                if (null == searchInsideResults) return;

                var searchInsideResultsScale = {};
                searchInsideResults.matches = searchInsideResults.matches.filter(function(a){
                        return typeof a === 'object' && !Array.isArray(a) && a !== null
                    }
                );

                searchInsideResults.matches.forEach(function(match,index,array) {
                    let uuid = match?.sbf_metadata.file_uuid;
                    match.par[0].boxes.forEach(function(box,index,array) {
                        let page = array[index].page;
                        if (self.leafsToUUids.hasOwnProperty(uuid+'/'+(parseInt(page)+1))) {
                          page = self.leafsToUUids[uuid+'/'+(parseInt(page)+1)];
                          page = page - 1;
                        }
                        var pageindex = self.leafNumToIndex(page);
                        array[index].l = Math.round(box.l * self.getPageWidth(pageindex));
                        array[index].t = Math.round(box.t * self.getPageHeight(pageindex));
                        array[index].r = Math.round(box.r * self.getPageWidth(pageindex));
                        array[index].b = Math.round(box.b * self.getPageHeight(pageindex));
                        array[index].page = page;
                        match.par[0].page = page;
                    })
                });
                hasCustomSuccess
                    ? options.success.call(this, searchInsideResults, options)
                    : self.BRSearchCallback(searchInsideResults, options);
            }
        };

        this.trigger('SearchStarted', { term: this.searchTerm });
        return $.ajax({
            url: url,
            dataType: 'jsonp'
        }).then(processSearchResults);
    }
})(BookReader.prototype.search);



