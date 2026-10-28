import {createAnnotationEditorPlugin} from './triiiceratops_modules/plugin-annotation-editor.js'
(function ($, Drupal, once, drupalSettings, Triiiceratops) {

  'use strict';

  class ArchipelagoAnnotationStorageAdapter {
    constructor(node_uuid, current_user,
      current_user_label, kid, stg) {
      this.baseUrl = window.location.origin + '/do/';
      this.node_uuid =  node_uuid;
      this.user_id = current_user;
      this.user_label = current_user_label;
      this.kid = kid;
      this.canvases = [];
      this.stg = stg;
    }

    setCanvases(canvases) {
      this.canvases = canvases;
    }

    valid_uuid(string) {
      const pattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
      return pattern.test(string);
    }

    uuid_from_iiif_id(iiifid) {

      if (iiifid.startsWith(window.location.origin)) {
        const url = new URL(iiifid);
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
        // External Canvases or pretty aliased IIIF manifests Annotations should always be stored at the Top level
        // Using the ADO where the viewer loaded
        return this.node_uuid;
      }
    }

    // Retrieve annotations from your database
    async load(manifestId, canvasId) {
      const idtoresolve = this.stg === 'single' ? manifestId : canvasId;
      const uuid = this.uuid_from_iiif_id(idtoresolve);
      const data  = {
        target_resource : canvasId,
        keystoreid: this.kid
      }
      const queryParams = new URLSearchParams(data).toString();
      const response = await fetch(`${this.baseUrl}${uuid}/webannon/read?${queryParams}`);
      if (!response.ok) throw new Error('Failed to load annotations');

      // Should return a valid IIIF/W3C AnnotationPage JSON object
      return await response.json();
    }

    // Save a new annotation created in the viewer
    async create(manifestId, canvasId, annotation) {
      const idtoresolve = this.stg === 'single' ? manifestId : canvasId;
      const uuid = this.uuid_from_iiif_id(idtoresolve);

      const data  = {
        target_resource : canvasId,
        keystoreid: this.kid,
        data: annotation,
        return: true
      }
      const response = await fetch(`${this.baseUrl}${uuid}/webannon/post`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      });
      if (!response.ok) throw new Error('Failed to create annotation');
      return await response.json();
    }

    // Update modified annotations
    async update(manifestId, canvasId, annotation) {
      const idtoresolve = this.stg === 'single' ? manifestId : canvasId;
      const uuid = this.uuid_from_iiif_id(idtoresolve);
      const data  = {
        target_resource : canvasId,
        keystoreid: this.kid,
        data: annotation,
        return: true
      }
      const response = await fetch(`${this.baseUrl}${uuid}/webannon/put`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      });
      if (!response.ok) throw new Error('Failed to update annotation');
      return await response.json();
    }

    // Remove an annotation
    async delete(manifestId, canvasId, annotationId) {
      const idtoresolve = this.stg === 'single' ? manifestId : canvasId;
      const uuid = this.uuid_from_iiif_id(idtoresolve);
      const annotation = {id:annotationId};
      const data  = {
        target_resource : canvasId,
        keystoreid: this.kid,
        data: annotation,
      }
      const response = await fetch(`${this.baseUrl}${uuid}/webannon/delete`, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      });
      if (!response.ok) throw new Error('Failed to delete annotation');
    }
  }


  function CaptureAdoTriiiceratopsCanvasChange(triiiceratopsElement,e) {
    let canvasid = e.detail?.canvasid;
    if ((triiiceratopsElement.id === e.detail.caller_id) || (triiiceratopsElement.viewerState?.canvasId === canvasid)) {
      // Ignore calls from itself or the requested canvasid is the same as the one already loaded
      return;
    }
    let found = false;
    if (triiiceratopsElement.viewerState.canvases.length && canvasid) {
      found = triiiceratopsElement.viewerState.canvases.find(canvas => canvas.id === canvasid);
      if (found !== false) {
        triiiceratopsElement.viewerState.setCanvas(canvasid)
      }
    }
  }


  function CaptureAdoTriiiceratopsAdoChange(triiiceratopsElement, e) {
    if (triiiceratopsElement.id === e.detail.caller_id)  {
      // Ignore calls from itself! or we will have an eternal loop
      return;
    }
    const nodeid = !Array.isArray(e.detail.nodeid) ? [e.detail.nodeid] : e.detail.nodeid;
    if (triiiceratopsElement.viewerState.canvases.length) {
      let found = triiiceratopsElement.viewerState.canvases.find((canvas) => {
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
        if (found.id !== triiiceratopsElement.viewerState?.canvasId) {
          triiiceratopsElement.viewerState.setCanvas(found.id);
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
          let initial_canvas_index = null;
          let initial_search_string = null;
          let prev_canvas_id = null;
          let prev_manifest_id = null;
          if (search_string.length > 0 ) {
            initial_search_string = search_string;
          }
          // Note. if the setting "switchCanvasOnSearch": true is selected
          // And there is a search
          // And there is a hit, start canvas will have no effect. You are warned.
          if (page_string > 0 ) {
            initial_canvas_index = page_string - 1;
          }
          let needs_navigation =  initial_canvas_index !== null || initial_search_string !== null;
          let fully_initialized = false;

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
            const stg = drupalSettings.format_strawberryfield.triiiceratops[element_id]['webannotations_storage'];
            const kid = drupalSettings.format_strawberryfield.triiiceratops[element_id]['keystoreid'];
            apAnnotationAdapter = new ArchipelagoAnnotationStorageAdapter(node_uuid, user_url, user_label, kid, stg);
          }

          const triii_viewer = document.querySelector('#'+element_id);
          let selectorFragment = '';
          if (triii_viewer) {
            for (const [key, value] of Object.entries($options)) {
              triii_viewer[key] = value;
            }
          }
          customElements.whenDefined('triiiceratops-viewer').then(() => {
            if (triii_viewer && plugins_dynamic.length > 0) {
              let $plugin_objects = [];
              plugins_dynamic.forEach( $plugin_key => {
                let plugin = Triiiceratops.plugins.get($plugin_key)
                if ($plugin_key == '@triiiceratops/plugin-annotation-editor' && apAnnotationAdapter) {
                  const plugin_annotation = createAnnotationEditorPlugin({
                    adapter: apAnnotationAdapter,
                    user: { id: apAnnotationAdapter.user_id, name: apAnnotationAdapter.user_label },
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
            }

            function throttle(func, limit) {
              let inThrottle;
              return function() {
                const args = arguments;
                const context = this;
                if (!inThrottle) {
                  func.apply(context, args);
                  inThrottle = true;
                  setTimeout(() => inThrottle = false, limit);
                }
              };
            }


            if (triii_viewer.viewerState) {
              const stop_frame = triii_viewer.viewerState.subscribeFrame(() => {
                if (fully_initialized) {
                  // This only works for Images and UI interactions of those
                  // AV Plugin needds to be read on the subscribeFrame() to generate that one
                  // TBD if i can even read this here if Video/Audio are playing.
                  const { x, y, width, height } = triii_viewer.viewerState.viewportBounds;
                  const roundedX = Math.round(x);
                  const roundedY = Math.round(y);
                  const roundedW = Math.round(width);
                  const roundedH = Math.round(height);
                  const selectorFragment_live = `#xywh=${roundedX},${roundedY},${roundedW},${roundedH}`;
                  async function checkForDiffDelayed() {
                    // Inline non-blocking wait
                    await new Promise(resolve => setTimeout(resolve, 5000));
                    if (selectorFragment_live !== selectorFragment || prev_canvas_id != triii_viewer.viewerState.canvasId) {
                      selectorFragment = selectorFragment_live;
                      Drupal.FormatStrawberryfieldIiifUtils.generateContentState(triii_viewer.viewerState.manifestId, triii_viewer.viewerState.canvasId, selectorFragment);
                    }
                  }
                  if (selectorFragment_live !== selectorFragment || prev_canvas_id != triii_viewer.viewerState.canvasId) {
                    throttle(checkForDiffDelayed, 5000)
                  }
                }
              });
              const stop_state = triii_viewer.viewerState.subscribe(() => {
                const canvas_index = triii_viewer.viewerState.currentCanvasIndex;
                if (fully_initialized && (prev_canvas_id != triii_viewer.viewerState.canvasId || prev_manifest_id != triii_viewer.viewerState.manifestId)) {
                  // We set this to avoid reacting to multiple calls to the same without an actual change in what was loaded before
                  // Given that there is no actual EVENT, but any change will call the subscribe method.
                  prev_canvas_id = triii_viewer.viewerState.canvasId;
                  prev_manifest_id = triii_viewer.viewerState.manifestId;

                  let current_canvas = triii_viewer.viewerState.canvases[triii_viewer.viewerState.currentCanvasIndex];
                  let currentDrupalNodeId = [];
                  let currentDrupalNodeForViews = [];
                  currentDrupalNodeId = 'sbf:ado:change' in current_canvas ? current_canvas['sbf:ado:change'] : currentDrupalNodeId;
                  currentDrupalNodeForViews = 'sbf:ado:view:change' in current_canvas ? current_canvas['sbf:ado:view:change'] : currentDrupalNodeForViews;
                  if (Array.isArray(currentDrupalNodeId) && Array.isArray(currentDrupalNodeId[0])) {
                    currentDrupalNodeId = currentDrupalNodeId[0];
                  }
                  if (Array.isArray(currentDrupalNodeForViews) && Array.isArray(currentDrupalNodeForViews[0])) {
                    currentDrupalNodeForViews = currentDrupalNodeForViews[0];
                  }
                  if (!Array.isArray(currentDrupalNodeId)) {
                    currentDrupalNodeId = [currentDrupalNodeId];
                  }
                  if (!Array.isArray(currentDrupalNodeForViews)) {
                    currentDrupalNodeForViews = [currentDrupalNodeForViews];
                  }
                  currentDrupalNodeId = currentDrupalNodeId.filter(n => n);
                  currentDrupalNodeForViews = currentDrupalNodeForViews.filter(n => n);
                  if (currentDrupalNodeId.length > 0) {
                    Drupal.FormatStrawberryfieldIiifUtils.dispatchAdoChange(triii_viewer, currentDrupalNodeId, triii_viewer.id);
                  }
                  if (currentDrupalNodeForViews.length > 0) {
                    Drupal.FormatStrawberryfieldIiifUtils.dispatchAdoViewChange(triii_viewer, currentDrupalNodeForViews);
                  }
                  Drupal.FormatStrawberryfieldIiifUtils.dispatchCanvasChange(triii_viewer, triii_viewer.viewerState.canvasId, triii_viewer.viewerState.manifestId, triii_viewer.id);
                }
                if (!fully_initialized && triii_viewer.viewerState.canvasId && triii_viewer.viewerState.canvases.length > 0 && canvas_index >=0) {
                  fully_initialized = true;
                  if (needs_navigation) {
                    // set to false so this only runs once.
                    needs_navigation = false;
                    if (initial_canvas_index <  triii_viewer.viewerState.canvases.length) {
                      triii_viewer.viewerState.setCanvas(triii_viewer.viewerState.canvases[initial_canvas_index]);
                    }
                    if (initial_search_string) {
                      triii_viewer.viewerState.search(initial_search_string);
                    }
                  }
                  // Bind event listeners to allow reactions triggered by events of other Viewers
                  document.addEventListener('sbf:canvas:change', CaptureAdoTriiiceratopsCanvasChange.bind(document, triii_viewer));
                  document.addEventListener('sbf:ado:change', CaptureAdoTriiiceratopsAdoChange.bind(document, triii_viewer));

                  apAnnotationAdapter.setCanvases(triii_viewer.viewerState.canvases);
                }
              });
            }
          });
        }
      })}}
})(jQuery, Drupal, once, drupalSettings, window.Triiiceratops);
