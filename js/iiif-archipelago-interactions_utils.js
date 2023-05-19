(function ($, Drupal, drupalSettings, jmespath) {

  'use strict';
  var FormatStrawberryfieldIiifUtils = {

    images_jmespath_pattern: "items[?type == 'Canvas'].{\"canvas_id\":id ,\"items\": items[?type == 'AnnotationPage'].{\"id\":id,\"image_ids\": items[?motivation == 'painting'].body.id, \"service_ids\": items[?motivation == 'painting'].body.service[].{type: not_null(type, \"@type\"), id: not_null(id, \"@id\")}[?starts_with(type, 'ImageService')].id }}";
    geoannotation_jmespath_pattern: "items[?type == 'Canvas'].{\"canvas_id\":id ,\"annotations\": annotations[?type == 'AnnotationPage'].{\"id\":id,\"features\": items[?motivation == 'georeferencing'].body[].features, \"target\": items[?motivation == 'georeferencing'].target}}";

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
    fetchIIIFManifest: function (iiifmanifesturl) {
      fetch(iiifmanifesturl)
        .then(res => res.json())
        .then(function (res) {
          return res;
        })
        .catch(function() {
          // Die as silent as possible but still log a thing.
          console.log("Error fetching IIIF Manifest " + iiifmanifesturl);
          return {};
        });
    },
    getGeoAnnotations: function (iiifmanifest) {
      // this.items[0].annotations[0].items[0].motivation
      let body = {};
      target = {};
      // See https://github.com/esmero/format_strawberryfield/pull/252/commits/81094b6cc1d7db6e12602022d7813e9361099595
      // @by awesome https://github.com/digitaldogsbody Mike Bennet
      $geoannotations = jmespath.search(iiifmanifest, geoannotation_jmespath_pattern);
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
