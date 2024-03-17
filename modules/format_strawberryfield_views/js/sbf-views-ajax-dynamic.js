(function ($, Drupal, drupalSettings) {

  function loadViewOnClickEvent(e) {
    // If using the load even we can't relay on the target anymore because
    // it is bound to the document/window.

    let base = e.target.id;
    // Retrieve the path to use for views' ajax.
    let ajaxPath = drupalSettings?.views?.ajax_path ?? '/views/ajax' ;

    // If there are multiple views this might've ended up showing up multiple
    // times.
    if (ajaxPath.constructor.toString().indexOf('Array') !== -1) {
      ajaxPath = ajaxPath[0];
    }

    // Check if there are any GET parameters to send to views.
    let queryString = window.location.search || '';
    if (queryString !== '') {
      // Remove the question mark and Drupal path component if any.
      queryString = queryString
        .slice(1)
        .replace(/q=[^&]+&?|&?render=[^&]+/, '');
      if (queryString !== '') {
        // If there is a '?' in ajaxPath, clean URL are on and & should be
        // used to add parameters.
        queryString = (/\?/.test(ajaxPath) ? '&' : '?') + queryString;
      }
    }

    const base_views_settings =  {
      view_name: e.currentTarget.dataset.sbfViewId,
      view_display_id: e.currentTarget.dataset.sbfViewDisplayId,
      //@TODO use the argument separator settings here.
      view_args: e.currentTarget.dataset.sbfViewArguments,
      view_dom_id: e.currentTarget.dataset.sbfViewRenderTarget,
      views_path: window.location.pathname,
    };
    let submit_settings =
      $.extend(
        {},
        base_views_settings,
      );

    const element_settings = {
      url: drupalSettings.path.baseUrl + drupalSettings.path.pathPrefix + 'sbf/views-ajax' + queryString,
      event: 'loadView',
      base: base,
      element: e.currentTarget,
      httpMethod: "GET",
      progress: {
        type: 'throbber'
      },
      submit: submit_settings
      /* {
       view_name: "solr_search_content",
       view_display_id: "grid",
       view_args: "",
       view_path: "/search_grid",
       view_base_path: "search_grid"
     }*/

    };
    /*
        event: "RefreshView"

        httpMethod: "GET"

        progress: {type: "fullscreen"}

        selector: ".js-view-dom-id-528e1cb3d57bc28e7da980750fff6a29c3da664d1370e9aca859ac188823b32d"

        setClick: true

        submit: {view_name: "solr_search_content", view_display_id: "grid", view_args: "", view_path: "/search_grid", view_base_path: "search_grid", …}

        url: "/views/ajax?search_api_fulltext=&op=Search"
        */



    //Drupal.ajax[base] =
    let ajaxObject = new Drupal.ajax(element_settings);
    ajaxObject.execute();
  };

  Drupal.behaviors.sbf_views_ajax_interactions = {
    attach: function (context, settings) {
      // the data attributes one can use
      // [data-sbf-view-id="machine_name_of_a_view"]
      // [data-sbf-view-display-id="machine_name_of_the_views_display"]
      // [data-sbf-view-arguments="one,two,tree"]
      const elementsToAttach = once('data-sbf-views', '[data-sbf-view-id]', context);
      elementsToAttach.forEach(function (value, index) {
        console.log("initializing 'Ajax Dynamic Views'");
        // Requires an ID.
        if (value?.dataset?.sbfViewId &&
          value?.dataset?.sbfViewDisplayId &&
          value?.dataset?.sbfViewRenderTarget &&
          value?.id
        ) {

          let eventtype = value?.dataset?.sbfViewEvent
          if (typeof(eventtype) == "undefined") {
            eventtype = "click";
          }
          if (eventtype == "click") {
            value.addEventListener(eventtype, loadViewOnClickEvent, {passive: true, once: true});
          }
          else if (eventtype == "load") {
              value.addEventListener('afterload', loadViewOnClickEvent, {passive: true, once: true});
              const event = new Event('afterload');
              value.dispatchEvent(event);
          }
        }
      });
    }
  }
})(jQuery, Drupal, drupalSettings);

