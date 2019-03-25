/**
 * Strawberry Plugin which allows IIIF manifest parsing
 */

jQuery.extend(BookReader.defaultOptions, {
    iiifmanifesturl: '',
    iiifmanifest: null,
    iiifdefaultsequence: null,
    // Stores dimensions coming from a IIIF info.json
    dataDimensions: []
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
    };
})(BookReader.prototype.setup);


BookReader.prototype.init = (function(super_) {
    return function (options) {
        this.loadManifest();
        super_.call(this, options);

    };
})(BookReader.prototype.init);


BookReader.prototype.loadManifest = function () {
    var self = this;

    if (!this.options.iiifmanifesturl && !this.options.iiifmanifest) return;
    // Simplest approach, we got a full manifest passed as an Object
    if (self.options.iiifmanifest != null) {
        self.jsonLd = self.options.iiifmanifest;
        self.bookTitle = self.jsonLd.label;
        self.bookUrl = '#';
        // self.thumbnail = self.jsonLd.thumbnail['@id'];
        self.metadata = self.jsonLd.metadata;
        // Assuming i have no default sequence so first one is the good one.
        self.parseSequence(null);
    }
    else {
        // Ok, no full manifest, now try with the remote URL
        jQuery.ajax({
            url: this.options.iiifmanifesturl.replace(/^\s+|\s+$/g, ''),
            dataType: 'json',
            async: false,
            success: function (jsonLd) {
                self.jsonLd = jsonLd;
                self.bookTitle = jsonLd.label;
                self.bookUrl = '#';
                self.thumbnail = jsonLd.thumbnail['@id'];
                self.metadata = jsonLd.metadata;
                self.parseSequence(self.options.iiifdefaultsequence);
            },

            error: function () {
                console.log('Failed loading ' + self.options.iiifmanifesturl);
                return;
            }

        });
    }
}

/* Bugs in the parent implementation */
BookReader.prototype.setupTooltips = function() {
};

BookReader.prototype.parseSequence = function (sequenceId) {
    var self = this;

    jQuery.each(self.jsonLd.sequences, function(index, sequence) {
        // try with a specific sequenceID
        if (sequenceId!= null) {
            if (sequence['@id'] === sequenceId) {
                self.IIIFsequence.title = "Sequence";
                self.IIIFsequence.bookUrl = "http://iiif.io";
                self.IIIFsequence.imagesList = getImagesList(sequence);
                self.numLeafs = self.IIIFsequence.imagesList.length;
            }
        } else {
            self.IIIFsequence.title = "Sequence";
            self.IIIFsequence.bookUrl = "http://iiif.io";
            self.IIIFsequence.imagesList = getImagesList(sequence);
            self.numLeafs = self.IIIFsequence.imagesList.length;
            return false;
            // Just take the first one if no default one set
        }
    });

    var tmpdata = [];
    jQuery.each(self.IIIFsequence.imagesList, function(index,image) {
        tmpdata.push(
            {
                width: image.width,
                height: image.height,
                uri: image.imageUrl + "/full/" + image.width + ",/0/default.jpg",
                pageNum: index+1,
                infojson: image.imageUrl + "/info.json",
            });

    });
    self.options.data.push(tmpdata);
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
        var imageObj = {
            height:       image.height || 0,
            width:        image.width || 0,
            imageUrl:     image.service['@id'].replace(/\/$/, ''),
        };

        imageObj.aspectRatio  = (imageObj.width / imageObj.height) || 1;

        return imageObj;
    }


};

/**
 * @param  {number} index
 * @return {Number|undefined}
 */
BookReader.prototype.getPageWidth = function(index) {
    var self = this;
    if (isNaN(index)) return;

    var pagewidth = this.getPageProp(index, 'width');
    if (pagewidth == 0 || pagewidth == null || typeof pagewidth === "undefined") {
        if (typeof self.options.dataDimensions[index] === "undefined") {
            var imageInfo = this.getRemoteInfoJson(index);

            self.options.dataDimensions[index] = imageInfo;
            // Means computed height is different to one set by the manifest in the resource
            // Here self.getPageHeight should never return cero, if so means all is bad, bad
            var height = self.getPageHeight(index);
            if (height != imageInfo.height) {
                scale = height/imageInfo.height || 1;
                return Math.round(imageInfo.width * scale);

            }

            return self.options.dataDimensions[index].width;
        }
        else {

            return self.options.dataDimensions[index].width;
        }

    } else {

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
    var remotedimensions = {};

    var infojsonurl = this.getPageProp(index, 'infojson');

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

    return remotedimensions;
}