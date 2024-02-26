(function ($, Drupal, once, drupalSettings) {

  'use strict';

  Drupal.behaviors.format_strawberryfield_iabookreader_initiate = {
    attach: function(context, settings) {

      const elementsToAttach = once('attach_iab', '.strawberry-iabook-item[data-iiif-infojson]', context);
      $(elementsToAttach).each(function (index, value) {
        // Get the node uuid for this element
        var element_id = $(this).attr("id");
        var strawberrySettings = drupalSettings.format_strawberryfield.iabookreader[element_id];
        var server = window.location.origin + '/';
        let textselection = false;

        // Check if we got some data passed via Drupal settings.
        if (typeof(strawberrySettings) != 'undefined') {
          var node_uuid = strawberrySettings['nodeuuid'];
          if (typeof(strawberrySettings['server']) != 'undefined') {
            server = strawberrySettings['server'];
          }
          if (typeof(strawberrySettings['textselection']) != 'undefined') {
            textselection = strawberrySettings['textselection'];
          }
          $(this).height(strawberrySettings.height);
          $(this).css("width", strawberrySettings.width);

          // Defines our basic options for IIIF.

          // Check if Book has or not OCR using Ajax callback
          $.ajax({
            type: 'GET',
            url: '/do/' + node_uuid + '/flavorcount/ocr/',
            success: function (data) {
              {
                var options = {
                  ui: 'full', // embed, full (responsive)
                  el: '#' + element_id,
                  iiifmanifesturl: strawberrySettings['manifesturl'],
                  iiifmanifest: strawberrySettings['manifest'],
                  iiifdefaultsequence: null, //If null given will use the first sequence found.
                  maxWidth: 800,
                  imagesBaseURL: typeof(strawberrySettings['iareaderimagesbaseurl']) !== 'undefined' ? strawberrySettings['iareaderimagesbaseurl'] : 'https://cdn.jsdelivr.net/gh/internetarchive/bookreader@4.40.3/BookReader/images/',
                  server: server,
                  bookId: node_uuid,
                  enableSearch: true,
                  searchInsideUrl: '/do/' + node_uuid + '/flavorsearch/all/ocr/',
                  plugins: {
                    textSelection: {
                      enabled: (data.count == 0 ? false : textselection),
                      singlePageDjvuXmlUrl: '/do/' + node_uuid + '/flavorsearch/all/ocr/djvuxml/{{pageIndex}}',
                    },
                  },
                  padding: 11,
                };

                var br = new BookReader(options);
                br.init();
                if (data.count == 0) {
                  $('#' + element_id + ' .BRtoolbarSectionSearch').hide();
                }
              }
            }
          });

        }
      });
    }
  }
})(jQuery, Drupal, once, drupalSettings);

// override setupTooltips()
// to enable system tooltip
BookReader.prototype.setupTooltips = function() {
};

BookReader.prototype.buildShareDiv = function(e) {
  var bookTitle = this.bookTitle;
  var sharePageLocation = document.URL;
  var shareBookLocation = sharePageLocation.replace(/#.*/, "");
  var mobileId = '';
  if (e[0].classList.contains('BRmobileShare')) {
    mobileId = '-mm';
  }
  var shareTitle = document.createElement('div');
  shareTitle.classList.add('share-title');
  shareTitle.textContent = 'Share this book';

  var shareBody = document.createElement('div');
  shareBody.classList.add('share-social');

  var shareCheck = document.createElement('div');
  shareCheck.classList.add('form-check');
  var shareCheckInput = document.createElement('input');
  shareCheckInput.type = 'checkbox';
  var shareCheckInputId = 'page-book-check' + mobileId;
  shareCheckInput.id = shareCheckInputId;
  shareCheckInput.classList.add('thispage-social', 'form-check-input');
  var shareUrl = shareBookLocation;
  shareCheckInput.addEventListener('click', function () {
    if (this.checked) {
      shareUrl = sharePageLocation;
    } else {
      shareUrl = shareBookLocation;
    }
    var shareLinks = document.getElementsByClassName('page-book-update');
    for (var i = 0; i < shareLinks.length; i++) {
      var shareLink = shareLinks[i];
      var shareLinkUrl = new URL(shareLink.href);
      var shareLinksearchParams = shareLinkUrl.searchParams;
      if (shareLink.classList.contains('share-twitter')) {
        shareLinksearchParams.set('url', shareUrl);
      } else if (shareLink.classList.contains('share-facebook')) {
        shareLinksearchParams.set('u', shareUrl);
      } else if (shareLink.classList.contains('share-email')) {
        shareLinksearchParams.set('body', encodeURI(bookTitle + '\n\n' + shareUrl));
      }
      shareLinkUrl.search = shareLinksearchParams.toString();
      shareLink.href = shareLinkUrl.toString();
    }
  });
  var shareCheckLabel = document.createElement('label');
  shareCheckLabel.classList.add('sub', 'open-to-this-page', 'form-check-label');
  shareCheckLabel.htmlFor = shareCheckInputId;
  shareCheckLabel.textContent = 'Open to this page?';
  shareCheck.append(shareCheckInput, shareCheckLabel);

  var shareButtonContainer = document.createElement('div');
  shareButtonContainer.classList.add('container', 'share-buttons');
  var shareButtonRow = document.createElement('div');
  shareButtonRow.classList.add('row');

  var shareTwitterContainer = document.createElement('div');
  shareTwitterContainer.classList.add('col-sm-12', 'col-md-4');
  var shareTwitterButton = document.createElement('a')
  shareTwitterButton.target = '_blank';
  shareTwitterButton.rel = 'noopener noreferrer';
  shareTwitterButton.classList.add('btn', 'btn-primary', 'btn-sm', 'share-twitter', 'page-book-update');
  var twitterShareUrl = 'https://twitter.com/intent/tweet?text=' + bookTitle + '&url=';
  shareTwitterButton.href = encodeURI(twitterShareUrl + shareUrl);
  var shareTwitterIcon = document.createElement('i');
  shareTwitterIcon.classList.add('BRicon', 'twitter');
  shareTwitterButton.append(shareTwitterIcon, 'Twitter');
  shareTwitterContainer.append(shareTwitterButton);

  var shareFacebookContainer = document.createElement('div');
  shareFacebookContainer.classList.add('col-sm-12', 'col-md-4');
  var shareFacebookButton = document.createElement('a')
  shareFacebookButton.target = '_blank';
  shareFacebookButton.rel = 'noopener noreferrer';
  shareFacebookButton.classList.add('btn', 'btn-primary', 'btn-sm', 'share-facebook', 'page-book-update');
  var FacebookShareUrl = 'https://www.facebook.com/sharer.php?u=';
  shareFacebookButton.href = FacebookShareUrl + shareUrl;
  var shareFacebookIcon = document.createElement('i');
  shareFacebookIcon.classList.add('BRicon', 'fb');
  shareFacebookButton.append(shareFacebookIcon, 'Facebook');
  shareFacebookContainer.append(shareFacebookButton);

  var shareEmailContainer = document.createElement('div');
  shareEmailContainer.classList.add('col-sm-12', 'col-md-4');
  var shareEmailButton = document.createElement('a')
  shareEmailButton.classList.add('btn', 'btn-primary', 'btn-sm', 'share-email', 'page-book-update');
  shareEmailButton.target = '_blank';
  shareEmailButton.rel = 'noopener noreferrer';
  var EmailShareUrl = 'mailto:?subject=' + encodeURI(bookTitle) + '&body=' + encodeURI(bookTitle + '\n\n' + shareUrl);
  shareEmailButton.href = EmailShareUrl;
  var shareEmailIcon = document.createElement('i');
  shareEmailIcon.classList.add('BRicon', 'email');
  shareEmailButton.append(shareEmailIcon, 'Email');
  shareEmailContainer.append(shareEmailButton);

  var sharePage = document.createElement('div');
  sharePage.id = 'share-social-page' + mobileId;
  sharePage.classList.add('clipboard-copy-data', 'hidden');
  sharePage.dataset.clipboardCopyContent = 'copy-content-page' + mobileId;
  sharePage.dataset.clipboardCopyButton = 'btn';
  sharePage.dataset.clipboardCopyButtonText = 'Copy Link to Page';

  var sharePageInput = document.createElement('input');
  sharePageInput.id = 'share-social-page-copy' + mobileId;
  sharePageInput.type = 'text';
  sharePageInput.name = 'pageview';
  sharePageInput.classList.add('BRpageviewValue', 'copy-content-page' + mobileId, 'form-control');
  sharePageInput.readOnly = true;
  sharePageInput.value = sharePageLocation;

  var sharePageContainer = document.createElement('div');
  sharePageContainer.classList.add('col-12');
  sharePageContainer.append(sharePage, sharePageInput);

  var sharePageRow = document.createElement('div');
  sharePageRow.classList.add('row');
  sharePageRow.append(sharePageContainer);

  var shareBook = document.createElement('div');
  shareBook.id = 'share-social-book' + mobileId;
  shareBook.classList.add('clipboard-copy-data', 'hidden');
  shareBook.dataset.clipboardCopyContent = 'copy-content-book' + mobileId;
  shareBook.dataset.clipboardCopyButton = 'btn';
  shareBook.dataset.clipboardCopyButtonText = 'Copy Link to Book';

  var shareBookInput = document.createElement('input');
  shareBookInput.id = 'share-social-book-copy' + mobileId;
  shareBookInput.type = 'text';
  shareBookInput.name = 'booklink';
  shareBookInput.classList.add('BRbooklinkValue', 'copy-content-book' + mobileId, 'form-control');
  shareBookInput.readOnly = true;
  shareBookInput.value = shareBookLocation;

  var shareBookContainer = document.createElement('div');
  shareBookContainer.classList.add('col-12');
  shareBookContainer.append(shareBook, shareBookInput);

  var shareBookRow = document.createElement('div');
  shareBookRow.classList.add('row');
  shareBookRow.append(shareBookContainer);

  shareButtonRow.append(shareTwitterContainer, shareFacebookContainer, shareEmailContainer);
  shareButtonContainer.append(shareButtonRow, sharePageRow, shareBookRow);
  shareBody.append(shareCheck, shareButtonContainer);
  e.append(shareTitle, shareBody);
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
