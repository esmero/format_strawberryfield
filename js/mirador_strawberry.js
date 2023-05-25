(function ($, Drupal, drupalSettings, Mirador) {

  'use strict';
  const ActionTypes = {
    ADD_COMPANION_WINDOW: 'mirador/ADD_COMPANION_WINDOW',
    UPDATE_COMPANION_WINDOW: 'mirador/UPDATE_COMPANION_WINDOW',
    REMOVE_COMPANION_WINDOW: 'mirador/REMOVE_COMPANION_WINDOW',
    TOGGLE_TOC_NODE: 'mirador/TOGGLE_TOC_NODE',
    UPDATE_WINDOW: 'mirador/UPDATE_WINDOW',
    REQUEST_CANVAS_ANNOTATIONS: 'mirador/REQUEST_CANVAS_ANNOTATIONS',
    HOVER_ANNOTATION: 'mirador/HOVER_ANNOTATION',
    REQUEST_ANNOTATION: 'mirador/REQUEST_ANNOTATION',
    RECEIVE_ANNOTATION: 'mirador/RECEIVE_ANNOTATION',
    RECEIVE_ANNOTATION_FAILURE: 'mirador/RECEIVE_ANNOTATION_FAILURE',
    DESELECT_ANNOTATION: 'mirador/DESELECT_ANNOTATION',
    SELECT_ANNOTATION: 'mirador/SELECT_ANNOTATION',
    TOGGLE_ANNOTATION_DISPLAY: 'mirador/TOGGLE_ANNOTATION_DISPLAY',
    FOCUS_WINDOW: 'mirador/FOCUS_WINDOW',
    SET_WORKSPACE_FULLSCREEN: 'mirador/SET_WORKSPACE_FULLSCREEN',
    SET_WORKSPACE_VIEWPORT_POSITION: 'mirador/SET_WORKSPACE_VIEWPORT_POSITION',
    ADD_MANIFEST: 'mirador/ADD_MANIFEST',
    ADD_WINDOW: 'mirador/ADD_WINDOW',
    ADD_ERROR: 'mirador/ADD_ERROR',
    IMPORT_CONFIG: 'mirador/IMPORT_CONFIG',
    IMPORT_MIRADOR_STATE: 'mirador/IMPORT_MIRADOR_STATE',
    SET_CANVAS: 'mirador/SET_CANVAS',
    MAXIMIZE_WINDOW: 'mirador/MAXIMIZE_WINDOW',
    MINIMIZE_WINDOW: 'mirador/MINIMIZE_WINDOW',
    UPDATE_WINDOW_POSITION: 'mirador/UPDATE_WINDOW_POSITION',
    SET_WINDOW_SIZE: 'mirador/SET_WINDOW_SIZE',
    REMOVE_WINDOW: 'mirador/REMOVE_WINDOW',
    PICK_WINDOWING_SYSTEM: 'mirador/PICK_WINDOWING_SYSTEM',
    REQUEST_MANIFEST: 'mirador/REQUEST_MANIFEST',
    RECEIVE_MANIFEST: 'mirador/RECEIVE_MANIFEST',
    RECEIVE_MANIFEST_FAILURE: 'mirador/RECEIVE_MANIFEST_FAILURE',
    REMOVE_ERROR: 'mirador/REMOVE_ERROR',
    SET_CONFIG: 'mirador/SET_CONFIG',
    UPDATE_WORKSPACE: 'mirador/UPDATE_WORKSPACE',
    SET_WINDOW_THUMBNAIL_POSITION: 'mirador/SET_WINDOW_THUMBNAIL_POSITION',
    SET_WINDOW_VIEW_TYPE: 'mirador/SET_WINDOW_VIEW_TYPE',
    SET_WORKSPACE_ADD_VISIBILITY: 'mirador/SET_WORKSPACE_ADD_VISIBILITY',
    TOGGLE_WINDOW_SIDE_BAR: 'mirador/TOGGLE_WINDOW_SIDE_BAR',
    TOGGLE_DRAGGING: 'mirador/TOGGLE_DRAGGING',
    TOGGLE_ZOOM_CONTROLS: 'mirador/TOGGLE_ZOOM_CONTROLS',
    UPDATE_CONFIG: 'mirador/UPDATE_CONFIG',
    REMOVE_MANIFEST: 'mirador/REMOVE_MANIFEST',
    REQUEST_INFO_RESPONSE: 'mirador/REQUEST_INFO_RESPONSE',
    RECEIVE_INFO_RESPONSE: 'mirador/RECEIVE_INFO_RESPONSE',
    RECEIVE_DEGRADED_INFO_RESPONSE: 'mirador/RECEIVE_DEGRADED_INFO_RESPONSE',
    RECEIVE_INFO_RESPONSE_FAILURE: 'mirador/RECEIVE_INFO_RESPONSE_FAILURE',
    REMOVE_INFO_RESPONSE: 'mirador/REMOVE_INFO_RESPONSE',
    UPDATE_WORKSPACE_MOSAIC_LAYOUT: 'mirador/UPDATE_WORKSPACE_MOSAIC_LAYOUT',
    UPDATE_VIEWPORT: 'mirador/UPDATE_VIEWPORT',
    UPDATE_ELASTIC_WINDOW_LAYOUT: 'mirador/UPDATE_ELASTIC_WINDOW_LAYOUT',
    ADD_AUTHENTICATION_REQUEST: 'mirador/ADD_AUTHENTICATION_REQUEST',
    RESOLVE_AUTHENTICATION_REQUEST: 'mirador/RESOLVE_AUTHENTICATION_REQUEST',
    REQUEST_ACCESS_TOKEN: 'mirador/REQUEST_ACCESS_TOKEN',
    RECEIVE_ACCESS_TOKEN: 'mirador/RECEIVE_ACCESS_TOKEN',
    RECEIVE_ACCESS_TOKEN_FAILURE: 'mirador/RECEIVE_ACCESS_TOKEN_FAILURE',
    RESET_AUTHENTICATION_STATE: 'mirador/RESET_AUTHENTICATION_STATE',
    CLEAR_ACCESS_TOKEN_QUEUE: 'mirador/CLEAR_ACCESS_TOKEN_QUEUE',
    REQUEST_SEARCH: 'mirador/REQUEST_SEARCH',
    RECEIVE_SEARCH: 'mirador/RECEIVE_SEARCH',
    RECEIVE_SEARCH_FAILURE: 'mirador/RECEIVE_SEARCH_FAILURE',
    REMOVE_SEARCH: 'mirador/REMOVE_SEARCH',
    SET_CONTENT_SEARCH_CURRENT_ANNOTATIONS: 'mirador/SET_CONTENT_SEARCH_CURRENT_ANNOTATIONS',
    UPDATE_LAYERS: 'mirador/UPDATE_LAYERS',
    ADD_RESOURCE: 'mirador/ADD_RESOURCE',
    REMOVE_RESOURCE: 'mirador/REMOVE_RESOURCE',
    SHOW_COLLECTION_DIALOG: 'mirador/SHOW_COLLECTION_DIALOG',
    HIDE_COLLECTION_DIALOG: 'mirador/HIDE_COLLECTION_DIALOG',
  };

  function CaptureAdoMiradorCanvasChange(miradorInstance, this_id ,e) {
    if (this_id === e.detail.caller_id) {
      // Ignore calls from itself! or we will have an eternal loop
      return;
    }

    for (const windowId of Object.keys(miradorInstance.store.getState()?.windows)) {
      const data = miradorInstance.store.getState().windows[windowId];
      if (e.detail.manifestid == data.manifestId && e.detail?.canvasid) {
        const action = Mirador.actions.setCanvas(windowId, e.detail.canvasid);
        miradorInstance.store.dispatch(action);
      }
    }
  }

  function CaptureAdoMiradorAdoChange(miradorInstance, this_id ,e) {
    if (this_id === e.detail.caller_id) {
      // Ignore calls from itself! or we will have an eternal loop
      return;
    }
    for (const windowId of Object.keys(miradorInstance.store.getState()?.windows)) {
      const data = miradorInstance.store.getState().windows[windowId];
      if (data.manifestId) {
        const manifest = miradorInstance.store.getState().manifests[data.manifestId]
        let currentDrupalNodeId = manifest.json.items.find(item => {
          let match = false;
          if (item.hasOwnProperty('sbf:ado:change:react')) {
            if (Array.isArray(e.detail.nodeid)) {
              e.detail.nodeid.forEach(nodeid => match = (item['sbf:ado:change:react'] == nodeid))
            }
          }
          return match;
        });
        console.log(currentDrupalNodeId);
      }
    }
  }

  Drupal.behaviors.format_strawberryfield_mirador_initiate = {
    attach: function(context, settings) {
      const effects = ReduxSaga.effects;

      /* function* is a generator function thus the yield */
      function* formatStrawverryFieldReact(action) {

        const state = yield effects.select(Mirador.actions.getState);
        const newParams = Object.fromEntries(new URLSearchParams(location.search))
        if (
          action.type === ActionTypes.SET_CANVAS ||
          action.type === ActionTypes.SET_WINDOW_VIEW_TYPE
        ) {
          const { windowId } = action
          let { visibleCanvases, view, canvasId } = action
          const el = document.getElementById(windowId);
          if (
            !visibleCanvases &&
            (action.type === ActionTypes.SET_WINDOW_VIEW_TYPE
            )) {
            // For view changes, get the visible canvases from the next updateWindow action
            visibleCanvases = (yield effects.take(ActionTypes.UPDATE_WINDOW)).payload
              .visibleCanvases
          }
          const manifest = yield effects.select(Mirador.selectors.getManifest, { windowId });
          const manifestUrl = manifest.id;
          if (!manifest.json) {
            return
          }
          if (!view) {
            view = (yield effects.select(Mirador.selectors.getWindowConfig, {
              windowId,
              manifestId: manifest.id,
            })).view
          }
          let canvasIds = [];
          let currentDrupalNodeId = [];
          // This will depend on IIIF v2 versus V3.
          if (manifest.json["@context"].includes('http://iiif.io/api/presentation/3/context.json')) {
            console.log('IIIF Presentation Manifest V3');
            canvasIds = manifest.json.items.map(
              item => item['id']
            );
            currentDrupalNodeId = manifest.json.items.filter(item => {
              return item['id'] === canvasId
            }).map(item => {
              if (item.hasOwnProperty('sbf:ado:change')) {
                return item['sbf:ado:change']
              }
              else {
                return null;
              }
            });
            // Check if currentCanvasMetadata has `dr:nid` could be a single value or an array
            if (currentDrupalNodeId !== null) {
              Drupal.FormatStrawberryfieldIiifUtils.dispatchAdoChange(el, currentDrupalNodeId, state.config.id);
            }
            Drupal.FormatStrawberryfieldIiifUtils.dispatchCanvasChange(el, canvasId, manifestUrl, state.config.id);
          }
          else {
            console.log('IIIF Presentation Manifest V2');
            canvasIds = manifest.json.sequences[0].canvases.map(
              canvas => canvas['@id']
            );
          }

          // Build page parameter
          const canvasIndices = visibleCanvases.map(c => canvasIds.indexOf(c) + 1)
          if (view === 'single' || canvasIndices.length == 1) {
            newParams.page = canvasIndices[0]
          } else if (view === 'book') {
            newParams.page = canvasIndices.find(e => !!e).join(',')
          }
        }
        else if (action.type === ActionTypes.RECEIVE_SEARCH) {
          const { windowId, companionWindowId } = action
          const query = yield effects.select(getSearchQuery, { companionWindowId, windowId })
          newParams.search = query
        }
        else if (action.type === ActionTypes.REMOVE_SEARCH) {
          delete newParams.search
        }

        let $fragment = '';
        for (const [p, val] of new URLSearchParams(newParams).entries()) {
          $fragment += `${p}/${val}/`;
        };
        $fragment = $fragment.slice(0, -1);

        history.replaceState(
          { searchParams: newParams },
          '',
          `${window.location.pathname}#${$fragment}`
        );
      }
      function* rootSaga() {
        yield effects.takeEvery(
          [
            ActionTypes.SET_CANVAS,
            ActionTypes.RECEIVE_SEARCH,
            ActionTypes.REMOVE_SEARCH,
            ActionTypes.SET_WINDOW_VIEW_TYPE,
          ],
          formatStrawverryFieldReact
        )
      }

      const formatStrawverryFieldReactPlugin = {
        component: () => null,
        saga: rootSaga,
      };

      $('.strawberry-mirador-item[data-iiif-infojson]').once('attache_mirador')
        .each(function (index, value) {
          // Get the node uuid for this element
          var element_id = $(this).attr("id");
          // Check if we got some data passed via Drupal settings.
          if (typeof(drupalSettings.format_strawberryfield.mirador[element_id]) != 'undefined') {
            $(this).height(drupalSettings.format_strawberryfield.mirador[element_id]['height']);
            if (drupalSettings.format_strawberryfield.mirador[element_id]['width'] != '100%') {
              $(this).width(drupalSettings.format_strawberryfield.mirador[element_id]['width']);
            }
            // Defines our basic options for Mirador IIIF.
            var $options = {
              id: element_id,
              windows: [{
                manifestId: drupalSettings.format_strawberryfield.mirador[element_id]['manifesturl'],
                thumbnailNavigationPosition: 'far-bottom',
              }]
            };

            if (drupalSettings.format_strawberryfield.mirador[element_id]['custom_js'] == true) {
              $options.window = {
                workspaceControlPanel: {
                  enabled: false
                },
                allowClose: false,
                imageToolsEnabled: true,
                imageToolsOpen: true,
                views: [
                  { key: 'single', behaviors: [null, 'individuals'] },
                  { key: 'book', behaviors: [null, 'paged'] },
                  { key: 'scroll', behaviors: ['continuous'] },
                  { key: 'gallery' },
                ],
              };
              $options.windows[0].workspaceControlPanel = {
                enabled: false
              };
              $options.windows[0].workspace = {
                isWorkspaceAddVisible: false,
                allowNewWindows: true,
              };
            }

            var $firstmanifest = [drupalSettings.format_strawberryfield.mirador[element_id]['manifesturl']];
            var $allmanifests = $firstmanifest.concat(drupalSettings.format_strawberryfield.mirador[element_id]['manifestother']);
            var $secondmanifest = drupalSettings.format_strawberryfield.mirador[element_id]['manifestother'].find(x=>x!==undefined);

            if (Array.isArray($allmanifests) && $allmanifests.length && typeof($secondmanifest) != 'undefined') {
              var $secondwindow = new Object();
              $secondwindow.manifestId = $secondmanifest;
              $secondwindow.thumbnailNavigationPosition = 'far-bottom';
              $options.windows.push($secondwindow);
              var $manifests = new Object();
              $allmanifests.forEach(manifestURL => {
                // TODO Provider should be passed by metadata at
                // \Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryMiradorFormatter::viewElements
                // Deal with this for Beta3
                $manifests[manifestURL] = new Object({'provider':'See Metadata'});
              })
              $options.manifests = $manifests;
            }
            //@TODO add an extra Manifests key with every other one so people can select the others.
            if (drupalSettings.format_strawberryfield.mirador[element_id]['custom_js'] == true) {
              const miradorInstance = renderMirador($options);
              console.log('initializing Custom Mirador 3.3.0')
            }
            else {
              const miradorInstance = Mirador.viewer($options, [formatStrawverryFieldReactPlugin]);
              console.log('initializing Mirador 3.1.1')
              if (miradorInstance) {
                // To allow bubling up we need to add this one to the document
                // Multiple Miradors will replace each other?
                // @TODO check on that diego..
                document.addEventListener('sbf:canvas:change', CaptureAdoMiradorCanvasChange.bind(document, miradorInstance, element_id));
                document.addEventListener('sbf:ado:change', CaptureAdoMiradorAdoChange.bind(document, miradorInstance, element_id));
              }
            }

            // Work around https://github.com/ProjectMirador/mirador/issues/3486
            const mirador_window = document.getElementById(element_id);
            var observer = new MutationObserver(function(mutations) {
              let mirador_videos = document.querySelectorAll(".mirador-viewer video source");
              if (mirador_videos.length) {
                mutations.forEach(function (mutation) {
                  if ((mutation.target.localName == "video") && (mutation.addedNodes.length > 0) && (typeof(mutation.target.lastChild.src) != "undefined" )) {
                    mutation.target.src = mutation.target.lastChild.getAttribute('src');
                  }
                });
              }
              let mirador_audios = document.querySelectorAll(".mirador-viewer audio source");
              if (mirador_audios.length) {
                mutations.forEach(function (mutation) {
                  if ((mutation.target.localName == "audio") && (mutation.addedNodes.length > 0) && (typeof(mutation.target.lastChild.src) != "undefined" )) {
                    mutation.target.src = mutation.target.lastChild.getAttribute('src');
                  }
                });
              }
            });
            observer.observe(mirador_window, {
              childList: true,
              subtree: true,
            });
          }
        })}}
})(jQuery, Drupal, drupalSettings, window.Mirador, ReduxSaga);
