(function ($, Drupal, drupalSettings, jmespath, once) {

  'use strict';
  var FormatStrawberryfieldIiifUtils = {

    images_jmespath_pattern: "items[?type == 'Canvas'].{\"canvas_id\":id ,\"items\": items[?type == 'AnnotationPage'].{\"id\":id,\"image_ids\": items[?motivation == 'painting'].body.id, \"service_ids\": items[?motivation == 'painting'].body.service[].{type: not_null(type, \"@type\"), id: not_null(id, \"@id\")}[?starts_with(type, 'ImageService')].id }}",
    geoannotation_jmespath_pattern: "items[?type == 'Canvas'].{\"canvas_id\":id ,\"annotations\": annotations[?type == 'AnnotationPage'].{\"id\":id, \"annotation\": items[?motivation == 'georeferencing'].{\"features\": not_null(body[].features[], body.features), \"target\": target}}}",
    geoannotation_jmespath_pattern_split: "items[?type == 'Canvas'].{\"canvas_id\":id ,\"annotations\": annotations[?type == 'AnnotationPage'].{\"id\":id,\"features\": items[?motivation == 'georeferencing'].body[].features, \"target\": items[?motivation == 'georeferencing'].target}}",
    images_jmespath_pattern_with_canvasids: "items[?type == 'Canvas' && id=='{{token1}}'].{\"canvas_id\":id ,\"items\": items[?type == 'AnnotationPage'].{\"id\":id,\"image_ids\": items[?motivation == 'painting'].body.id, \"service_ids\": items[?motivation == 'painting'].body.service[].{type: not_null(type, \"@type\"), id: not_null(id, \"@id\")}[?starts_with(type, 'ImageService')].id }}",

    JmesPathTemplate: function (jmespattern, obj) {
      let s = jmespattern;
      for(var prop in obj) {
        s = s.replace(new RegExp('{{'+ prop +'}}','g'), obj[prop]);
      }
      return s;
    },

    dispatchAdoChange: function(el, nodeids, caller_id){
      /* el being a dom document via const el = document.getElementById(element_id);*/
      /* nodeid being the ADO Node ID */
      let nodeidsArray = [];
      if (!Array.isArray(nodeids)) {
        nodeidsArray = [nodeids]
      }
      else {
        nodeidsArray = nodeids
      }
      const event = new CustomEvent('sbf:ado:change', { bubbles: true, detail: {nodeid: nodeidsArray, caller_id:caller_id} });
      el.dispatchEvent(event);
      return this;
    },

    dispatchAdoViewChange: function(el, nodeids){
      // We don't need the caller here.
      // we will use the element itself to fetch who called.
      /* el being a dom document via const el = document.getElementById(element_id);*/
      /* nodeid being the ADO Node ID */
      let nodeidsArray = [];
      if (!Array.isArray(nodeids)) {
        nodeidsArray = [nodeids]
      }
      else {
        nodeidsArray = nodeids
      }
      const event = new CustomEvent('sbf:ado:view:change', { bubbles: true, detail: {nodeid: nodeidsArray} });
      // A view might/might not have yet attach itself to listen.
      // We have no control on Behavior attachment order in Drupal
      // And can also not make this library depend at all on sbf-views-ajax-interactions.
      // But we can check if it is already attached but just using once()
      // and if not, delay with a future timeout the dispatching in the hope it finds its way.
      // Literally give it a second after sync code has run.
      // See  Drupal.behaviors.sbf_views_ajax_interactions
      const viewEventListenerInit = once.filter('listen-ado-view-change', 'body')
      if (!viewEventListenerInit?.length) {
        setTimeout(() => {
           el.dispatchEvent(event);
         }
         , 1000);
     }
     else {
       el.dispatchEvent(event);
     }
      return this;
    },

    dispatchImageViewChange: function(el, encodedImageAnnotation){
      // We don't need the caller here.
      // we will use the element itself to fetch who called.
      /* el being a dom document via const el = document.getElementById(element_id);*/
      /* nodeid being the ADO Node ID */
      let encodedImageAnnotationOne = '';
      if (Array.isArray(encodedImageAnnotation)) {
        encodedImageAnnotationOne = encodedImageAnnotation[0];
      }
      else {
        encodedImageAnnotationOne = encodedImageAnnotation
      }
      const event = new CustomEvent('sbf:ado:view:change', { bubbles: true, detail: {image_annotation: encodedImageAnnotationOne} });
      const viewEventListenerInit = once.filter('listen-ado-view-change', 'body')
      if (!viewEventListenerInit?.length) {
        setTimeout(() => {
            el.dispatchEvent(event);
          }
          , 1000);
      }
      else {
        el.dispatchEvent(event);
      }
      return this;
    },


    dispatchCanvasChange: function(el, canvasid, manifestid, caller_id){
      /* el being a dom document via const el = document.getElementById(element_id);*/
      /* canvasid being the ADO Node ID */
      /* iiifmanifestUrl the URL that contains the canvasid */
      const event = new CustomEvent('sbf:canvas:change', { bubbles: true, detail: {canvasid: canvasid, manifestid: manifestid, caller_id: caller_id} });
      el.dispatchEvent(event);
      return this;
    },
    fetchIIIFManifest: async function (iiifmanifesturl) {
      const response = await fetch(iiifmanifesturl);
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      const data = await response.json();
      return data;
    },

    calculateIIIFRegion: function(selector) {
      // Selectors can be many. How to fetch/divide is a mess (yes a mess)
      /*
      1- A URL with a fragment
      2.- an SVG with polygon
      3.- an SVG with paths
       */
      let IIIFragmentCoords = null;
      let IIIFragmentCoordsIndividual = null;
      const IIIFragment = selector.split("=");
      if (IIIFragment.length > 1 && IIIFragment[0].endsWith("xywh")) {
        if (IIIFragment[1].startsWith("pixel:")) {
          IIIFragmentCoords = IIIFragment[1].split(":");
          IIIFragmentCoordsIndividual = IIIFragmentCoords[1].split(",");
        }
        else {
          IIIFragmentCoordsIndividual = IIIFragment[1].split(",");
        }
      };
      let iiif_region = null;
      let clip_path = [];
      let clip_path_string = null;
      if (IIIFragmentCoordsIndividual) {
        // @TODO what if using %percentage here?
        const iiif_coord_lx = Math.round(IIIFragmentCoordsIndividual[0]);
        const iiif_coord_ly = Math.round(IIIFragmentCoordsIndividual[1]);
        const iiif_coord_rx = Math.round(IIIFragmentCoordsIndividual[2]);
        const iiif_coord_ry = Math.round(IIIFragmentCoordsIndividual[3]);
        iiif_region = iiif_coord_lx + "," + iiif_coord_ly + "," + iiif_coord_rx + "," + iiif_coord_ry;
      } else if (selector.startsWith("<svg")) {
        // '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><g><path d="' ~ mirador_path ~ '"/></g></svg>'
        // or <svg><something>
        // basically we have no idea if this is a polygon or a path. Damn Diego.
        let svgElement = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        let svgElementNoNamespace = new DOMParser().parseFromString(selector, 'text/xml').documentElement;
        // try first with path
        let allpoints = [];
        const svg_children_path = svgElementNoNamespace.getElementsByTagName('path');
        for (const svg_child of svg_children_path) {
          const path_d = svg_child.getAttribute('d');
          let newpath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
          newpath.setAttribute("d", path_d);
          let control_points = Math.floor((path_d.split(",").length * 2) + 1);
          const len = newpath.getTotalLength();
          if (len > 0) {
       /*     for (let i = 0; i < control_points; i++) {
              var p = newpath.getPointAtLength(i * len / (control_points - 1));
              allpoints.push(DOMPoint.fromPoint({x: p.x, y: p.y}));
              //points.push([pt.x, pt.y]);
            }
*/

            let p = newpath.getPointAtLength(0);
            let seg = newpath.getPathSegAtLength(0);
            allpoints.push(DOMPoint.fromPoint({x: p.x, y: p.y}));
            for (let i = 1; i < len; i++) {
              p = newpath.getPointAtLength(i);
              if (newpath.getPathSegAtLength(i) > seg) {
                allpoints.push(DOMPoint.fromPoint({x: p.x, y: p.y}));
                seg = newpath.getPathSegAtLength(i);
              }
            }
          }
          allpoints = allpoints.concat(allpoints);
          svgElement.appendChild(newpath);
        }
        const svg_children_polygon = svgElementNoNamespace.getElementsByTagName('polygon');
        for (const svg_child of svg_children_polygon) {
          const points = svg_child.getAttribute('points');
          let newpolygon = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
          newpolygon.setAttribute("points",points);
          svgElement.appendChild(newpolygon);
          for (let step = 0; step < newpolygon.points.length; step++) {
            const point = newpolygon.points.getItem(step);
            allpoints.push(DOMPoint.fromPoint({ x: point.x , y: point.x}))
          }
        }
        const toRemove = document.body.insertAdjacentElement("beforeend", svgElement);
        const bounds = toRemove.firstChild.getBBox();
        toRemove?.remove();
        iiif_region = Math.floor(bounds.x) + "," + Math.floor(bounds.y) + "," + Math.floor(bounds.width) + "," + Math.floor(bounds.height);
        allpoints.forEach(point => {
          let percentx = ((point.x - bounds.x) / bounds.width) * 100;
          let percenty = ((point.y - bounds.y) / bounds.height) * 100
          if (percentx !== 0) {
            percentx = percentx + "%"
          }
          if (percenty !== 0) {
            percenty = percenty + "%"
          }
          clip_path.push(percentx + " " + percenty);
        });

        if (clip_path.length) {
          clip_path_string = "polygon(" + clip_path.join(",") + ")";
        }
      }

      return {
        iiif_region: iiif_region,
        clip_path_string: clip_path_string,
      }
    },

    getGeoAnnotations: function (iiifmanifest) {
      let leaflet_overlays = [];

      // See https://github.com/esmero/format_strawberryfield/pull/252/commits/81094b6cc1d7db6e12602022d7813e9361099595
      // @by awesome https://github.com/digitaldogsbody Mike Bennet
      const $geoannotations = jmespath.search(iiifmanifest, this.geoannotation_jmespath_pattern);
      if (Array.isArray($geoannotations)) {
        $geoannotations.forEach($entry => {
          if ($entry?.canvas_id) {
            let canvas_jmespath = this.JmesPathTemplate(this.images_jmespath_pattern_with_canvasids, {token1: $entry?.canvas_id});
            const $imagesObjectArray = jmespath.search(iiifmanifest, canvas_jmespath);
            const $imageObject = $imagesObjectArray.find(e => !!e);
            let image_service_for_canvas = null;
            if (Array.isArray($imageObject?.items)) {
              $imageObject?.items.forEach($item => {
                // WE can only for now deal with a single image
                // @TODO we need a way of calculating Canvas offsets based on multi painting annotation on a single Canvas
                image_service_for_canvas = $item?.service_ids.find(e => !!e);
              });
            };
            if (Array.isArray($entry?.annotations) && image_service_for_canvas) {
              $entry?.annotations.forEach(annotations_percanvas => {
                // @see https://www.w3.org/TR/annotation-model/#cardinality-of-bodies-and-targets
                if (Array.isArray(annotations_percanvas?.annotation)) {
                  annotations_percanvas?.annotation.forEach(body => {
                    let fragment = null;
                    // Target can be so many
                    // A direct fragment URL to the Canvas
                    // An Object with type/source/selector. Oh JS.. checking if it is a string!
                    if (body?.target) {
                      if (Object.prototype.toString.call(body?.target) === "[object String]") {
                        fragment = body?.target
                      } else {
                        fragment = body?.target?.selector?.value;
                      }
                    }
                    if (fragment) {
                      // Now let's bring Annotation, the target Image together to allow Leaflet to process these little beasts
                      leaflet_overlays.push({
                        source: image_service_for_canvas,
                        region: this.calculateIIIFRegion(fragment),
                        bounds: body?.features.map(entry => { return entry?.geometry?.coordinates })
                      });
                    }
                  });
                }
                ;
              });
            }
          }
        });
      }
      /* If working leaflet_overlays is
       [{
          region: {iiif_region: "231,268,1658,1377", clip_path_string: "polygon(4.482759731344009% 23.651453927119423%,4.5…91295%,0.015004781652589198% 41.434052619917836%)"}
          source: "http://localhost:8183/iiif/2/dc1%2Fimage-a49b6c9a0442084d577f7594775b4e6d-view-e89ae3c9-d0fc-4cb7-a6e1-9120975248c6.jpeg",
          bounds: [[long,lat],...]
        }]
        */
      return leaflet_overlays;
    },


  getIIIFServices: function (iiifmanifest) {

    // See https://github.com/esmero/format_strawberryfield/pull/252/commits/81094b6cc1d7db6e12602022d7813e9361099595
    // @by awesome https://github.com/digitaldogsbody Mike Bennet
    const image_services = jmespath.search(iiifmanifest, this.images_jmespath_pattern);
    return image_services;
    }
  };

  /* Make it part of the Global Drupal Object */
  Drupal.FormatStrawberryfieldIiifUtils = FormatStrawberryfieldIiifUtils;

})(jQuery, Drupal, drupalSettings, jmespath, once);
