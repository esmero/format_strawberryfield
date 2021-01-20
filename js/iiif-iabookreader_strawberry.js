(function ($, Drupal, drupalSettings) {

    'use strict';

    Drupal.behaviors.format_strawberryfield_iabookreader_initiate = {
        attach: function(context, settings) {
            $('.strawberry-iabook-item[data-iiif-infojson]').once('attache_iab')
                .each(function (index, value) {
                    // Get the node uuid for this element
                    var element_id = $(this).attr("id");
                    var server = window.location.origin + '/';
                    var default_width = drupalSettings.format_strawberryfield.iabookreader[element_id]['width'];
                    var default_height = drupalSettings.format_strawberryfield.iabookreader[element_id]['height'];
                    var node_uuid = drupalSettings.format_strawberryfield.iabookreader[element_id]['nodeuuid'];
                    if (typeof(drupalSettings.format_strawberryfield.iabookreader[element_id]['server']) != 'undefined') {
                      var server = drupalSettings.format_strawberryfield.iabookreader[element_id]['server'];
                    }

                    // Check if we got some data passed via Drupal settings.
                    if (typeof(drupalSettings.format_strawberryfield.iabookreader[element_id]) != 'undefined') {

                        $(this).height(default_height);
                        $(this).css("width",default_width);

                        // Defines our basic options for IIIF.
                        var options = {
                            ui: 'full', // embed, full (responsive)
                            el: '#' + element_id,
                            iiifmanifesturl: drupalSettings.format_strawberryfield.iabookreader[element_id]['manifesturl'],
                            iiifmanifest: drupalSettings.format_strawberryfield.iabookreader[element_id]['manifest'],
                            iiifdefaultsequence: null, //If null given will use the first sequence found.
                            maxWidth: 800,
                            imagesBaseURL: 'https://cdn.jsdelivr.net/gh/internetarchive/bookreader@4.21.0/BookReader/images/',
                            server: server,
                            bookId: node_uuid,
                            enableSearch: true,
                            searchInsideUrl: '/do/' + node_uuid + '/flavorsearch/all/ocr/',
			    padding: 11,
                        };
                        console.log('initializing IABookreader')
                        var br = new BookReader(options);
                        br.init();
                    }

                })}}
})(jQuery, Drupal, drupalSettings);

// override setupTooltips()
// to enable system tooltip
BookReader.prototype.setupTooltips = function() {
};

// Extend buildToolbarElement: add ZoomPage button
BookReader.prototype.buildToolbarElement = (function (super_) {
  return function () {
      var $el = super_.call(this);
      var readIcon = '';
      $el.find('.BRtoolbarRight').append("<span class='BRtoolbarSection Islandora'>"
		  	+ "<button class='BRpill zoomPage js-tooltip' title='Fine zoom'>ZOOM</button>"
		  	+ "</span>");
			//set div class to render osd
			$('<div style="display: none;"></div>').append('<div class="BRfloat" id="BRviewpage"></div>').appendTo($('body'));
    	return $el;
	};
})(BookReader.prototype.buildToolbarElement);

// Extend initToolbar: add ZoomPage button click code
BookReader.prototype.initToolbar = (function (super_) {
  return function (mode, ui) {
    super_.apply(this, arguments);
		var self = this;

			this.refs.$BRtoolbar.find('.zoomPage').colorbox({
				inline: true,
				opacity: "0.5",
				href: "#BRviewpage",
				width: "90%",
				height: "90%",
				fastIframe: false,
				reposition: false,
				onOpen: function() {
					if (1 == self.mode) {
					      	$('#BRviewpage').html('<div class="textTop loader"></div>');
					} else if (2 == self.mode) {
					      	$('#BRviewpage').html('<div class="viewpLeft loader"></div><div class="viewpRight loader"></div>');
					};
				},
				onLoad: function() {
				    	self.trigger('stop');
				},
				onComplete: function() {
					self.buildViewpageDiv($('#BRviewpage'));
					self.modeBeforePageview = self.mode;
					if (1 == self.mode) {
						self.indexBeforePageview = self.currentIndex();
						self.switchMode(2);
					};
				},
				onClosed: function() {
					self.resize()
					if (1 == self.modeBeforePageview) {
						self.switchMode(1);
						self.jumpToIndex(self.indexBeforePageview);
					};
				},
			});

  };
})(BookReader.prototype.initToolbar);

//add buildViewpageDiv
BookReader.prototype.buildViewpageDiv = function(jViewpageDiv) {

  var osd_common = '<div id=[ID] allowfullscreen style="height: 100%; width: 100%; display: inline-block;"></div>';
    osd_common += '<script type="text/javascript">';
    osd_common += 'var viewer = OpenSeadragon({';
    osd_common += 'id: "[ID]",';
    osd_common += 'prefixUrl: "https://cdn.jsdelivr.net/npm/openseadragon@2.4/build/openseadragon/images/",';
    osd_common += 'homeFillsViewer: false,';
    osd_common += 'showZoomControl: true,';
    osd_common += 'showNavigator:  false,';
    osd_common += 'showHomeControl: false,';
    osd_common += 'showFullPageControl: true,';
    osd_common += 'showRotationControl: true,';
    osd_common += 'navigatorPosition: 0,';
    osd_common += 'navigationControlAnchor: 2,';
    osd_common += 'sequenceMode: false,';
    osd_common += 'preserveViewport: true,';
    osd_common += 'defaultZoomLevel: 0,';
    osd_common += 'constrainDuringPan: false,';
    osd_common += 'visibilityRatio: 1,';
    osd_common += 'maxZoomPixelRatio: 2,';
    osd_common += 'minZoomImageRatio: 0.9,';
    osd_common += 'tileSources: "[TS]",';
    osd_common += '});';
    osd_common += '</script>';

  if (1 == this.mode) {
    var index = this.currentIndex();
    //OLD//var tilesourceUri = this.getPageURI(index, 1, 0).replace(/full.*/, "info.json");
    //var tilesourceUri = this.getPageProp(index, 'infojson');
    var tilesourceUri = this.getPageURI(index, 1, 0).replace(/full.*/, "info.json") + getURLArgument(this.getPageURI(index, 1, 0));
    var dosd = $(osd_common.replace(/\[ID\]/g, "osd_s").replace('[TS]', tilesourceUri));
    jViewpageDiv.html(dosd);
  } else if (3 == this.mode) {
    var osd = $('<div style="color: black;"><strong>' + Drupal.t('View page not supported for this view.') + '</strong></div>');
    jViewpageDiv.html(osd);
  } else {
    var indices = this.getSpreadIndices(this.currentIndex());

    // is left page blank?
    if (typeof this.getPageURI(indices[0], 1, 0) != 'undefined') {
      //OLD//var tilesourceUri_left = this.getPageURI(indices[0], 1, 0).replace(/full.*/, "info.json");
      //var tilesourceUri_left = this.getPageProp(indices[0], 'infojson');
      var tilesourceUri_left = this.getPageURI(indices[0], 1, 0).replace(/full.*/, "info.json") + getURLArgument(this.getPageURI(indices[0], 1, 0));
      var osd_left = osd_common.replace(/\[ID\]/g, "osd_l").replace('[TS]', tilesourceUri_left);
		} else {
      var osd_left = '<div id=osd_l allowfullscreen style="height: 100%; width: 100%; display: inline-block;"></div>';
    }
    var dosd_left = $(osd_left);

    // is right page blank?
    if (typeof this.getPageURI(indices[1], 1, 0) != 'undefined') {
      //OLD//var tilesourceUri_right = this.getPageURI(indices[1], 1, 0).replace(/full.*/, "info.json");
      //var tilesourceUri_right = this.getPageProp(indices[1], 'infojson');
      var tilesourceUri_right = this.getPageURI(indices[1], 1, 0).replace(/full.*/, "info.json") + getURLArgument(this.getPageURI(indices[1], 1, 0));
      var osd_right = osd_common.replace(/\[ID\]/g, "osd_r").replace('[TS]', tilesourceUri_right);
		} else {
      var osd_right = '<div id=osd_r allowfullscreen style="height: 100%; width: 100%; display: inline-block;"></div>';
    }
    var dosd_right = $(osd_right);

    jViewpageDiv.find('.viewpLeft').html(dosd_left);
    jViewpageDiv.find('.viewpLeft').removeClass('loader');

    jViewpageDiv.find('.viewpRight').html(dosd_right);
    jViewpageDiv.find('.viewpRight').removeClass('loader');

  }
};
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
