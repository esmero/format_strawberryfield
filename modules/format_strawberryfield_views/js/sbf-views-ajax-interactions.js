(function ($, Drupal, drupalSettings) {

  function CaptureAdoViewChange(e) {
    let nodeid = null;
    if (Array.isArray(e.detail.nodeid)) {
      nodeid = e.detail.nodeid.join("+");
      console.log(nodeid)
    }
    else if (typeof e.detail.nodeid !== 'object') {
      nodeid = e.detail.nodeid;
    }
    if (nodeid) {
      console.log("node id correct");
      if (typeof drupalSettings['sbf_ajax_interactions'] === 'object') {
        console.log("sbf_ajax_interactions is present and an object");
        for (const property in drupalSettings['sbf_ajax_interactions']) {
          console.log(property);
          console.log(drupalSettings['sbf_ajax_interactions'][property]);
          if (typeof Drupal.views.instances["views_dom_id:" + property] !== "undefined") {
            console.log("Found the targeted View");
            // Means the view we configured is attached and can handle arguments.
            let view_instance = Drupal.views.instances["views_dom_id:" + property];
            // OK so views args can be multiple values separated by a / ? or an array?
            // We need to match only the one we know how to modify
            // I'm assuming or drupalSettings['sbf_ajax_interactions']['sbf_ajax_interactions_arguments'] list of arguments
            // Has the same order as the arguments passed. But if there are many and currently only one is assigned we
            // assume the one currently assigned is the first?
            if (view_instance?.settings?.view_args) {
              console.log("views arg present and" + view_instance?.settings?.view_args);
              view_instance.settings.view_args = nodeid;
              view_instance.$view.trigger("RefreshView");
            }
          }
        }
      }
    }
  };

  Drupal.behaviors.sbf_views_ajax_interactions = {
    attach: function (context, settings) {
      console.log(settings['sbf_ajax_interactions']);
      once('listen-ado-view-change', 'div.view', context).forEach(function (value, index) {
        console.log("initializing 'sbf:ado:view:change' event listener on ADO changes");
        document.addEventListener('sbf:ado:view:change', CaptureAdoViewChange);
      });
      // If the document already has this eventlistener then it won't be added again! Nice.
    }
  }

  // END jQuery
})(jQuery, Drupal, drupalSettings);

