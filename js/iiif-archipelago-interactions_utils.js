(function ($, Drupal, drupalSettings) {

  'use strict';
  var FormatStrawberryfieldIiifUtils = {

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


})(jQuery, Drupal, drupalSettings);
