import {createAnnotationEditorPlugin} from './triiiceratops_modules/plugin-annotation-editor.js'
(function ($, Drupal, once, drupalSettings, Triiiceratops) {

  'use strict';

  class ArchipelagoAnnotationStorageAdapter {
    constructor(node_uuid, current_user,
     current_user_label, kid) {
      this.baseUrl = window.location.origin + '/do/';
      this.node_uuid =  node_uuid;
      this.user_id = current_user;
      this.user_label = current_user_label;
      this.kid = kid;
    }

    valid_uuid(string) {
      const pattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
      return pattern.test(string);
    }

    uuid_from_canvas_id(canvasid) {

      if (canvasid.startsWith(window.location.origin)) {
        const url = new URL(canvasid);
        // Means the canvas ID could be locally generated
        const pieces = url.pathname.split('/').filter(part => part !== "");
        if (pieces[0] == 'do' && this.valid_uuid(pieces[1])) {
          return pieces[1];
        }
        else {
          return this.node_uuid;
        }
      }
      else {
        // External Canvases can be stored in the Top level UUID
        return this.node_uuid;
      }
    }

    // Retrieve annotations from your database
    async load(manifestId, canvasId) {
      const uuid = this.uuid_from_canvas_id(canvasId);
      const data  = {
        target_resource : canvasId,
        keystoreid: this.kid
      }
      const queryParams = new URLSearchParams(data).toString();
      const response = await fetch(`${this.baseUrl}/${uuid}/webannon/read?${queryParams}`);
      if (!response.ok) throw new Error('Failed to load annotations');

      // Should return a valid IIIF/W3C AnnotationPage JSON object
      return await response.json();
    }

    // Save a new annotation created in the viewer
    async create(manifestId, canvasId, annotation) {
      const response = await fetch(`${this.baseUrl}/${uuid}/webannon/post`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ canvasId, annotation })
      });
      if (!response.ok) throw new Error('Failed to create annotation');
      return await response.json();
    }

    // Update modified annotations
    async update(manifestId, canvasId, annotation) {
      const response = await fetch(`${this.baseUrl}/${uuid}/webannon/put`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ canvasId, annotation })
      });
      if (!response.ok) throw new Error('Failed to update annotation');
      return await response.json();
    }

    // Remove an annotation
    async delete(manifestId, canvasId, annotationId) {
      const response = await fetch(`${this.baseUrl}/${uuid}/webannon/delete}`, {
        method: 'DELETE'
      });
      if (!response.ok) throw new Error('Failed to delete annotation');
    }
  }


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
          // Base ADO UUID. IN case of a CWS each canvas ID, if formed as might have a saying about this.
          const node_uuid = drupalSettings.format_strawberryfield.triiiceratops[element_id]['nodeuuid'];

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
          let annotations_enabled = false;
          let apAnnotationAdapter = null;
          if (drupalSettings.format_strawberryfield.triiiceratops[element_id]['webannotations'] !== null) {
            annotations_enabled = true;
            const user_url = drupalSettings.format_strawberryfield.triiiceratops[element_id]['user']['url'];
            const user_label = drupalSettings.format_strawberryfield.triiiceratops[element_id]['user']['name'];
            const kid = drupalSettings.format_strawberryfield.triiiceratops[element_id]['keystoreid'];
            apAnnotationAdapter = new ArchipelagoAnnotationStorageAdapter(node_uuid, user_url, user_label, kid);
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
                let plugin = Triiiceratops.plugins.get($plugin_key)
                if ($plugin_key == '@triiiceratops/plugin-annotation-editor' && apAnnotationAdapter) {
                  const plugin_annotation = createAnnotationEditorPlugin({
                    adapter: apAnnotationAdapter,
                    user: { id: apAnnotationAdapter.user_id, name: apAnnotationAdapter.user_label },
                    //tools: ['rectangle', 'polygon', 'point'],
                  });
                  $plugin_objects.push(plugin_annotation);
                }
                else {
                  $plugin_objects.push(plugin);
                }
              });
              if ($plugin_objects.length > 0) {
                triii_viewer.plugins = $plugin_objects;
              }
              // Once we have all plugins, we check if annotations can be enabled
              // and if we can use the custom storage or not
              console.log(triii_viewer.plugins);

            }
            if (triii_viewer.viewerState) {
              console.log(triii_viewer.viewerState);
              const stop = triii_viewer.viewerState.subscribe(() => {
                console.log(triii_viewer.viewerState.canvases.length);
                console.log(triii_viewer.viewerState.canvases);
                console.log(triii_viewer.viewerState.currentCanvasIndex);
              });
            }

          });


        }
      })}}
})(jQuery, Drupal, once, drupalSettings, window.Triiiceratops);
