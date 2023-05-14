(function ($, Drupal, drupalSettings) {

  'use strict';
  var FormatStrawberryfieldIiifUtils = {

    wasConfirmed: false,

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
    }
  };

  /* Make it part of the Global Drupal Object */
  Drupal.FormatStrawberryfieldIiifUtils = FormatStrawberryfieldIiifUtils;


})(jQuery, Drupal, drupalSettings);
