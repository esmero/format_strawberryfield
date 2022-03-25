/**
 * Strawberry Plugin which allows IIIF manifest parsing
 */

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
    this.searchTerm = '';
    this.searchResults = null;
    this.searchInsideUrl = options.searchInsideUrl;
    this.enableSearch = options.enableSearch;
    this.goToFirstResult = false;

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
    this.loadManifest();
    super_.call(this, options);
  };
})(BookReader.prototype.init);

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

BookReader.prototype.loadManifest = function () {
  var self = this;

  if (!this.options.iiifmanifesturl && !this.options.iiifmanifest) return;
  // Simplest approach, we got a full manifest passed as an Object
  if (self.options.iiifmanifest != null) {
    self.jsonLd = self.options.iiifmanifest;
    self.bookTitle = self.getApiVersion() == "3.x" ? self.jsonLd.label[Object.keys(self.jsonLd.label)[0]].join("; ") : self.jsonLd.label;
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
        self.bookTitle = self.getApiVersion() == "3.x" ? self.jsonLd.label[Object.keys(self.jsonLd.label)[0]].join("; ") : self.jsonLd.label;
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
  if(self.getApiVersion() == "3.x") {
      // try with a specific sequenceID
      if (sequenceId!= null) {
        if (item['@id'] === sequenceId) {
          self.IIIFsequence.title = "Sequence";
          self.IIIFsequence.bookUrl = "http://iiif.io";
          self.IIIFsequence.imagesList = getImagesListApi3(self.jsonLd.items);
          self.numLeafs = self.IIIFsequence.imagesList.length;
        }
      } else {
        self.IIIFsequence.title = "Sequence";
        self.IIIFsequence.bookUrl = "http://iiif.io";
        self.IIIFsequence.imagesList = getImagesListApi3(self.jsonLd.items);
        self.numLeafs = self.IIIFsequence.imagesList.length;
        // return false;
        // Just take the first one if no default one set
      }
  }
  else {
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
        self.IIIFsequence.imagesList = getImagesListApi3(self.jsonLd);
        self.numLeafs = self.IIIFsequence.imagesList.length;
        return false;
        // Just take the first one if no default one set
      }
    });
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
      imageuri = image.serviceUrl + "/full/" + image.width + ",/0/default.jpg" + image.imageGetArgument;
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
            if (annotation.body.hasOwnProperty('service') && isValidHttpUrl(annotation.body.service['id'])) {
              imageObj.serviceUrl = annotation.body.service['id'].replace(/\/$/, '');
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
  if (infojsonurl == null) {
    console.log('Service Level 0, Defaulting to fixed Dimensions ' + self.options.maxWidth + ' px')
    console.log(this.getPageProp(index, 'width'));
    console.log(this.getPageProp(index, 'height'));
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
        searchInsideResults.matches.forEach(function(match,index,array) {
          match.par[0].boxes.forEach(function(box,index,array) {
            var pageindex = self.leafNumToIndex(array[index].page);
            array[index].l = Math.round(box.l * self.getPageWidth(pageindex));
            array[index].t = Math.round(box.t * self.getPageHeight(pageindex));
            array[index].r = Math.round(box.r * self.getPageWidth(pageindex));
            array[index].b = Math.round(box.b * self.getPageHeight(pageindex));
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



