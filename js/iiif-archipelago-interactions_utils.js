(function ($, Drupal, drupalSettings, jmespath) {

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

    dispatchAdoChange: function(el, nodeids){
      /* el being a dom document via const el = document.getElementById(element_id);*/
      /* nodeid being the ADO Node ID */
      let nodeidsArray = [];
      if (!Array.isArray(nodeids)) {
        nodeidsArray = [nodeids]
      }
      else {
        nodeidsArray = nodeids
      }
      const event = new CustomEvent('sbf:ado:change', { bubbles: true, detail: {nodeid: nodeidsArray} });
      console.log(event);
      el.dispatchEvent(event);
      return this;
    },

    dispatchCanvasChange: function(el, canvasid, iiifmanifesturl){
      /* el being a dom document via const el = document.getElementById(element_id);*/
      /* canvasid being the ADO Node ID */
      /* iiifmanifestUrl the URL that contains the canvasid */
      const event = new CustomEvent('sbf:canvas:change', { bubbles: true, detail: {canvasid: canvasid, iiifmanifesturl: iiifmanifesturl} });
      console.log(event);
      el.dispatchEvent(event);
      return this;
    },
    fetchIIIFManifest: async function (iiifmanifesturl) {

      const response = await fetch(iiifmanifesturl)
        .then(res => res.json())
        .then(function (res) {
          return res;
        })
        .catch(function() {
          // Die as silent as possible but still log a thing.
          console.log("Error fetching IIIF Manifest " + iiifmanifesturl);
          return {};
        });
      return response;
    },

    calculateIIIFRegion: function(selector) {
      const IIIFragment = selector.split("=");
      let iiif_region = null;
      let clip_path = [];
      let clip_path_string = null;
      if (IIIFragment.length > 1) {
        if (IIIFragment[0].endsWith("xywh")) {
          const IIIFragmentCoords = IIIFragment[1].split(":");
          // @TODO what if using %percentage here?
          const IIIFragmentCoordsIndividual = IIIFragmentCoords[1].split(",");
          const iiif_coord_lx = Math.round(IIIFragmentCoordsIndividual[0]);
          const iiif_coord_ly = Math.round(IIIFragmentCoordsIndividual[1]);
          const iiif_coord_rx = Math.round(IIIFragmentCoordsIndividual[2]);
          const iiif_coord_ry = Math.round(IIIFragmentCoordsIndividual[3]);
          iiif_region = iiif_coord_lx + "," + iiif_coord_ly + "," + iiif_coord_rx + "," + iiif_coord_ry;
        } else if (IIIFragment[0].startsWith("<svg")) {
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
            const len = newpath.getTotalLength();
            let p = newpath.getPointAtLength(0);
            let seg = newpath.getPathSegAtLength(0);
            allpoints.push(DOMPoint.fromPoint({ x: p.x , y: p.y}));
            for(let i = 1; i < len; i++){
              p = newpath.getPointAtLength(i);
              if (newpath.getPathSegAtLength(i) > seg) {
                allpoints.push(DOMPoint.fromPoint({ x: p.x , y: p.y}));
                seg = newpath.getPathSegAtLength(i);
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
      }

      return {
        iiif_region: iiif_region,
        clip_path_string: clip_path_string,
      }
    },

    getGeoAnnotations: function (iiifmanifest) {
      // this.items[0].annotations[0].items[0].motivation
      /* you call this one
      Drupal.FormatStrawberryfieldIiifUtils.fetchIIIFManifest("http://localhost:8001/do/17355bdb-d784-4037-96fe-5c160296e639/metadata/iiifmanifest/default.jsonld").then(response => {Drupal.FormatStrawberryfieldIiifUtils.getGeoAnnotations(response)});
       */
      let body = {};
      let target = {};
      // See https://github.com/esmero/format_strawberryfield/pull/252/commits/81094b6cc1d7db6e12602022d7813e9361099595
      // @by awesome https://github.com/digitaldogsbody Mike Bennet
      const $geoannotations = jmespath.search(iiifmanifest, this.geoannotation_jmespath_pattern);
      /* if it worked will give you this
      [
  {
    "canvas_id": "http://localhost:8001/do/17355bdb-d784-4037-96fe-5c160296e639/iiif/canvas/p1",
    "annotations": [
      {
        "id": "http://localhost:8001/do/17355bdb-d784-4037-96fe-5c160296e639/iiif/comments/p1",
        "annotation": [
          {
            "features": [
              {
                "type": "Feature",
                "geometry": {
                  "type": "Point",
                  "coordinates": [
                    "-73.63037109375001",
                    "41.85319643776675"
                  ]
                },
       ....
            ],
            "target": "http://localhost:8001/do/17355bdb-d784-4037-96fe-5c160296e639/iiif/canvas/p1#xywh=1941.1666259765625,57.175926208496094,365.9259033203125,480.27774810791016"
          }
        ]
      }
    ]
  }
]
       */
      if (Array.isArray($geoannotations)) {
        $geoannotations.forEach($entry => {
          console.log($entry);
          if ($entry?.canvas_id) {
            let canvas_jmespath = this.JmesPathTemplate(this.images_jmespath_pattern_with_canvasids, {token1: $entry?.canvas_id});
            const $images = jmespath.search(iiifmanifest, canvas_jmespath);

            if (Array.isArray($entry?.annotations)) {
              $entry?.annotations.forEach(annotations_percanvas => {
                // @see https://www.w3.org/TR/annotation-model/#cardinality-of-bodies-and-targets
                if (Array.isArray(annotations_percanvas?.annotation)) {
                  annotations_percanvas?.annotation.forEach(body => {
                    let fragment = null;
                    console.log(body.features);
                    console.log(body.target);
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
                      console.log(this.calculateIIIFRegion(fragment));
                    }
                  });
                };
              });
            }
            console.log($images);
          }
        });
      }
      // Now let's bring Annotation, the target Image together to allow Leaflet to process these little beasts



      return $geoannotations;
      /*
       [
         {
           "canvas_id": "http://localhost:8001/do/22cea396-b4ec-11eb-8b96-9fa490fdda0b/iiif/canvas/p1",
           "annotations": [
             {
               "id": "http://localhost:8001/do/22cea396-b4ec-11eb-8b96-9fa490fdda0b/iiif/comments/p1",
               "features": [
                 ....
               ],
               "target": [
                 {
                   "source": "http://localhost:8001/do/22cea396-b4ec-11eb-8b96-9fa490fdda0b/iiif/canvas/p1",
                   "selector": {
                     "type": "SvgSelector",
                     "value": "<svg xmlns=\"http://www.w3.org/2000/svg\" xmlns:xlink=\"http://www.w3.org/1999/xlink\"><g><path d=\"M1016.273193359375,150.29629516601562L903.5509643554688,126.8125L729.7708740234375,183.17361450195312L574.77783203125,324.0763854980469L527.8101806640625,361.65045166015625L466.7523498535156,371.0439758300781L316.4560546875,638.75927734375L335.2430725097656,723.3009033203125L307.0625305175781,901.7777709960938L640.5324096679688,1418.4212646484375L692.19677734375,1526.44677734375L884.763916015625,1526.44677734375L1016.273193359375,1437.208251953125L1086.724609375,1545.2337646484375L1175.9630126953125,1352.6666259765625L988.0925903320312,681.0300903320312L898.8541870117188,774.9652709960938L706.2870483398438,638.75927734375L673.4097290039062,544.8240356445312L1072.63427734375,253.625L1053.8472900390625,187.870361328125L1053.8472900390625,187.870361328125 z\"/></g></svg>"
                   },
                   ....
                 }
               ]
             }
           ]
         },*/
      // Now i have to choices.
      // Fetch all images and then iterate over to match the canvas
      // Or make a very specific /canvas id targeted JMESPATH.
      if (iiifmanifest?.items) {
        iiifmanifest?.items.forEach(items => {
          if (Array.isArray(items?.annotations)) {
            items.annotations.find(function (b) {
              if (b?.motivation == 'georeferencing') {
                body = b?.body;
                target = b?.target; // will contain selector and source
              }
            });
          }
        });
      }
    }
  };

  /* Make it part of the Global Drupal Object */
  Drupal.FormatStrawberryfieldIiifUtils = FormatStrawberryfieldIiifUtils;

})(jQuery, Drupal, drupalSettings, jmespath);
