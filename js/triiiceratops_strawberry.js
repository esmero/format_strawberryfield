
(function ($, Drupal, once, drupalSettings, Triiiceratops) {

  'use strict';


  function CaptureAdoTriiiceratopsCanvasChange(miradorInstance, this_id ,e) {
    if (this_id === e.detail.caller_id) {
      // Ignore calls from itself! or we will have an eternal loop
      return;
    }

    for (const windowId of Object.keys(miradorInstance.store.getState()?.windows)) {
      const data = miradorInstance.store.getState().windows[windowId];
      let visiblecanvas_same_as_request = false;
      let canvasid = e.detail?.canvasid;
      if (data.visibleCanvases?.length && canvasid) {
        data.visibleCanvases.forEach(visiblecanvas => visiblecanvas_same_as_request = (visiblecanvas == canvasid))
      }

      if (e.detail.manifestid == data.manifestId && canvasid && !visiblecanvas_same_as_request) {
        const action = Mirador.actions.setCanvas(windowId, canvasid);
        miradorInstance.store.dispatch(action);
      }
    }
  }

  function CaptureAdoTriiiceratopsAdoChange(miradorInstance, this_id ,e) {
    if (this_id === e.detail.caller_id) {
      // Ignore calls from itself! or we will have an eternal loop
      return;
    }
    for (const windowId of Object.keys(miradorInstance.store.getState()?.windows)) {
      const data = miradorInstance.store.getState().windows[windowId];
      if (data.manifestId) {
        const manifest = miradorInstance.store.getState().manifests[data.manifestId]
        let canvasid = null;
        let currentDrupalNodeId = manifest.json.items.find(item => {
          let match = false;
          if (item.hasOwnProperty('sbf:ado:change:react')) {
            if (Array.isArray(e.detail.nodeid)) {
              e.detail.nodeid.forEach(nodeid => match = (item['sbf:ado:change:react'] == nodeid))
            }
          }
          if (match) {
            canvasid = item['id'];
          }
          return match;
        });
        // Check if the requested canvas is already visible
        let visiblecanvas_same_as_request = false;
        if (data.visibleCanvases?.length && canvasid) {
          data.visibleCanvases.forEach(visiblecanvas => visiblecanvas_same_as_request = (visiblecanvas == canvasid))
        }
        if (currentDrupalNodeId && canvasid && !visiblecanvas_same_as_request) {
          const action = Mirador.actions.setCanvas(windowId,canvasid);
          miradorInstance.store.dispatch(action);
        }
      }
    }
  }

  Drupal.behaviors.format_strawberryfield_triiiceratops_initiate = {
    attach: function(context, settings) {

      const elementsToAttach = once('attache_triiiceratops', '.strawberry-triiiceratops-item[data-iiif-infojson]', context);
      $(elementsToAttach).each(function (index, value) {
        // Get the node uuid for this element
        var element_id = $(this).attr("id");
        // Check if we got some data passed via Drupal settings.
        if (typeof(drupalSettings.format_strawberryfield.triiiceratops[element_id]) != 'undefined') {
          $(this).height(drupalSettings.format_strawberryfield.triiiceratops[element_id]['height']);
          if (drupalSettings.format_strawberryfield.triiiceratops[element_id]['width'] != '100%') {
            $(this).width(drupalSettings.format_strawberryfield.triiiceratops[element_id]['width']);
          }
          var $options = {
            "manifestId": drupalSettings.format_strawberryfield.triiiceratops[element_id]['manifesturl'],
            config: {},
            "themeConfig": {},
          };
          var $manifest = [drupalSettings.format_strawberryfield.triiiceratops[element_id]['manifesturl']];
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

          const search_string = readFragmentSearch();
          const page_string = readFragmentPage();
          if (search_string.length > 0 ) {
            // TODO we need to pass this to triii
          }
          // Note. if the setting "switchCanvasOnSearch": true is selected
          // And there is a search
          // And there is a hit, start canvas will have no effect. You are warned.
          if (parseInt(page_string) > 0 ) {
            // TODO we need an actual Canvas ID here. Not just a number.
          }

          // Allow last minute overrides. These are more complex bc we have windows as an array and window too.
          // Allow a last minute override, exclude main element manifest
          if (typeof drupalSettings.format_strawberryfield.triiiceratops[element_id]['viewer_overrides'] == 'object' &&
            !Array.isArray(drupalSettings.format_strawberryfield.triiiceratops[element_id]['viewer_overrides']) &&
            drupalSettings.format_strawberryfield.triiiceratops[element_id]['viewer_overrides'] !== null) {
            let viewer_override = drupalSettings.format_strawberryfield.triiiceratops[element_id]['viewer_overrides'];
            $options["config"] = viewer_override;
          }
          if (typeof drupalSettings.format_strawberryfield.triiiceratops[element_id]['viewer_overrides_theme'] == 'object' &&
            !Array.isArray(drupalSettings.format_strawberryfield.triiiceratops[element_id]['viewer_overrides_theme']) &&
            drupalSettings.format_strawberryfield.triiiceratops[element_id]['viewer_overrides_theme'] !== null) {
            let viewer_override_theme = drupalSettings.format_strawberryfield.triiiceratops[element_id]['viewer_overrides_theme'];
            $options["themeConfig"] = viewer_override_theme;
          }
          let plugins_dynamic = [];

          if (typeof drupalSettings.format_strawberryfield.triiiceratops[element_id]['enabled_plugins'] == 'object' &&
            !Array.isArray(drupalSettings.format_strawberryfield.triiiceratops[element_id]['enabled_plugins']) &&
            drupalSettings.format_strawberryfield.triiiceratops[element_id]['enabled_plugins'] !== null) {
            for (const [key, value] of Object.entries(drupalSettings.format_strawberryfield.triiiceratops[element_id]['enabled_plugins'])) {
              plugins_dynamic.push('@triiiceratops/'+key);
            }
          }

          const triii_viewer = document.querySelector('#'+element_id);
          if (triii_viewer) {
            triii_viewer.addEventListener('viewerstateavailable', (event) => {
              console.log(event);
              console.log('viewer state available');
            });
            console.log('initializing TriiiCeratops')
            for (const [key, value] of Object.entries($options)) {
              triii_viewer[key] = value;
            }
          }
          customElements.whenDefined('triiiceratops-viewer').then(() => {
            if (triii_viewer && plugins_dynamic.length > 0) {
              let $plugin_objects = [];
              plugins_dynamic.forEach( $plugin_key => {
                console.log('Adding ' + $plugin_key + ' Plugin');
                $plugin_objects.push(Triiiceratops.plugins.get($plugin_key));
              });
              if ($plugin_objects.length > 0) {
                triii_viewer.plugins = $plugin_objects;
              }
            }
            if (triii_viewer.viewerState) {
              console.log(triii_viewer.viewerState);
              const stop = triii_viewer.viewerState.subscribe(() => {
                console.log(triii_viewer.viewerState.canvases.length);
                console.log(triii_viewer.viewerState.canvases);
                console.log(triii_viewer.viewerState.currentCanvasIndex);
              });
              //stop();
            }

          });


        }
      })}}
})(jQuery, Drupal, once, drupalSettings, window.Triiiceratops);
