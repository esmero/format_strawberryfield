
(function ($, Drupal, once, drupalSettings, initCloverI18n) {

  'use strict';

  function CaptureAdoCloverCanvasChange(cloverElement,e) {
    let canvasid = e.detail?.canvasid;
    if ((cloverElement.id === e.detail.caller_id) || (cloverElement.viewerState?.canvasId === canvasid)) {
      // Ignore calls from itself or the requested canvasid is the same as the one already loaded
      return;
    }
    let found = false;
    if (cloverElement.viewerState.canvases.length && canvasid) {
      found = cloverElement.viewerState.canvases.find(canvas => canvas.id === canvasid);
      if (found !== false) {
        cloverElement.viewerState.setCanvas(canvasid)
      }
    }
  }


  function CaptureAdoCloverAdoChange(cloverElement, e) {
    if (cloverElement.id === e.detail.caller_id)  {
      // Ignore calls from itself! or we will have an eternal loop
      return;
    }
    const nodeid = !Array.isArray(e.detail.nodeid) ? [e.detail.nodeid] : e.detail.nodeid;
    if (cloverElement.viewerState.canvases.length) {
      let found = cloverElement.viewerState.canvases.find((canvas) => {
        if (canvas.hasOwnProperty('sbf:ado:change:react')) {
          if (Array.isArray(e.detail.nodeid)) {
            if (nodeid.some(id => Number(id) === Number(canvas['sbf:ado:change:react']))) {
              return true;
            }
          }
        }
        return false;
      });
      if (found !== undefined) {
        if (found.id !== cloverElement.viewerState?.canvasId) {
          cloverElement.viewerState.setCanvas(found.id);
        }
      }
    }
  }

  Drupal.behaviors.format_strawberryfield_clover_initiate = {
    attach: function(context, settings) {

      const elementsToAttach = once('attache_clover', '.strawberry-clover-item[data-iiif-infojson]', context);
      $(elementsToAttach).each(function (index, value) {

        // Get the node uuid for this element
        var element_id = $(this).attr("id");
        let content_state_from_url = false;
        let content_state_to_url = drupalSettings.format_strawberryfield.clover[element_id]['content_state_drop'] ?? false;
        // Check if we got some data passed via Drupal settings.
        if (typeof(drupalSettings.format_strawberryfield.clover[element_id]) != 'undefined') {
          // Check if we should attempt to pre-parse any IIIF Content State.
          if (drupalSettings.format_strawberryfield.clover[element_id]['content_state_drop']) {
            const param = new URLSearchParams(location.search).get('iiif-content');
            if (param) {
              try {
                content_state_from_url = Drupal.FormatStrawberryfieldIiifUtils.processIIIFContentState(param);
                if (content_state_from_url.manifestid && content_state_from_url.canvasid) {
                  content_state_from_url = true;
                }
                else {
                  content_state_from_url = false;
                }
              }
              catch {
                console.log('Invalid IIIF Content State');
              }
            }
          }


          // Base ADO UUID. IN case of a CWS each canvas ID, if formed as might have a saying about this.
          const node_uuid = drupalSettings.format_strawberryfield.clover[element_id]['nodeuuid'];

          $(this).height(drupalSettings.format_strawberryfield.clover[element_id]['height']);
          if (drupalSettings.format_strawberryfield.clover[element_id]['width'] != '100%') {
            $(this).width(drupalSettings.format_strawberryfield.clover[element_id]['width']);
          }
          var $options = {
          };
          if (!content_state_from_url) {
            // The options manifestid will always win. So if we have content_state_drop enabled
            // and the URL holds a valid contentstate url, we allow that but do not initialize the manifestid here.
            $options["iiif-content"] = drupalSettings.format_strawberryfield.clover[element_id]['manifesturl']
          }
          const readFragmentSearch = function() {
            const urlArray = window.location.hash.replace('#','').split('/');
            const urlHash = {};
            for (let i = 0; i < urlArray.length; i += 2) {
              urlHash[urlArray[i]] = urlArray[i + 1];
            }
            if (urlHash['search'] != undefined) {
              return decodeURIComponent(urlHash['search'].replace(/\+/g, " "));
            }
            else {
              return '';
            }
          };

          const readFragmentPage = function() {
            const urlArray = window.location.hash.replace('#','').split('/');
            const urlHash = {};
            for (let i = 0; i < urlArray.length; i += 2) {
              urlHash[urlArray[i]] = urlArray[i + 1];
            }
            if (urlHash['page'] != undefined) {
              return parseInt(decodeURIComponent(urlHash['page'].replace(/\+/g, " ")));
            }
            else {
              return 0;
            }
          };

          let isFirstUpdateOfUrl = true;
          let debounceTimeout;
          const updateURL = function(newURL) {
            if (isFirstUpdateOfUrl) {
              window.history.pushState(null, '', newURL);
              isFirstUpdateOfUrl = false;
            } else {
              // Group all updates within 150ms into a single call
              clearTimeout(debounceTimeout);
              debounceTimeout = setTimeout(() => {
                window.history.replaceState(null, '', newURL);
              }, 500); // Group all updates within 150ms into a single call
            }
          };

          const search_string = readFragmentSearch();
          const page_string = readFragmentPage();
          let initial_canvas_index = null;
          let initial_search_string = null;
          let prev_canvas_id = null;
          let prev_manifest_id = null;
          if (search_string.length > 0 && !content_state_from_url) {
            initial_search_string = search_string;
          }
          // Note. if the setting "switchCanvasOnSearch": true is selected
          // And there is a search
          // And there is a hit, start canvas will have no effect. You are warned.
          if (page_string > 0 && !content_state_from_url) {
            initial_canvas_index = page_string - 1;
          }
          let needs_navigation =  initial_canvas_index !== null || initial_search_string !== null;
          let fully_initialized = false;

          let annotations_enabled = false;
          if (drupalSettings.format_strawberryfield.clover[element_id]['webannotations'] !== null) {
            annotations_enabled = true;
            const user_url = drupalSettings.format_strawberryfield.clover[element_id]['user']['url'];
            const user_label = drupalSettings.format_strawberryfield.clover[element_id]['user']['name'];
            const kid = drupalSettings.format_strawberryfield.clover[element_id]['keystoreid'];
          }

          const clover_viewer = document.querySelector('#'+element_id);
          let selectorFragment = '';
          if (clover_viewer) {
            for (const [key, value] of Object.entries($options)) {
              clover_viewer[key] = value;
            }
          }
          // TODO: Depending on the type, i need to check on clover-root.
          customElements.whenDefined('clover-viewer').then(() => {
            if (clover_viewer) {
            }
            let contentState = null;

          });
        }
      })}}
})(jQuery, Drupal, once, drupalSettings, window.initCloverI18n);
