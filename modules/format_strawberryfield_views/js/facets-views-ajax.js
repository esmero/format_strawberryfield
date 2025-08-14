/**
 * @file
 * Facets views AJAX handling.
 */


(function ($, Drupal, once, drupalSettings) {
  'use strict';

  /**
   * Keep the original beforeSend method to use it later.
   */
  var beforeSend = Drupal.Ajax.prototype.beforeSend;


  /**
   * Trigger views AJAX refresh on click.
   */
  Drupal.behaviors.facetsViewsAjax = {
    attach: function (context, settings) {

      // Loop through all facets.
      $.each(settings.facets_views_ajax, function (facetId, facetSettings) {
        // Get the View for the current facet.
        var view, current_dom_id, view_path, all_dom_ids_need_refresh = [];
        if (settings.views && settings.views.ajaxViews) {
          $.each(settings.views.ajaxViews, function (domId, viewSettings) {
            // Check if we have facet for this view.
            if (facetSettings.view_id == viewSettings.view_name && facetSettings.current_display_id == viewSettings.view_display_id) {
              view = $('.js-view-dom-id-' + viewSettings.view_dom_id);
              current_dom_id = viewSettings.view_dom_id;
              view_path = facetSettings.ajax_path;
            }
            else {
              // Means we don't have facets for this view, but we still might have a pager that needs to be reloaded, so it catches up with the Facet Query arguments.
              const pagers = $('.js-view-dom-id-' + viewSettings.view_dom_id).find(
                '.js-pager__items a, th.views-field a, .attachment .views-summary a',
              );
              if (typeof pagers !== "undefined" &&  pagers.length > 0) {
                // means we have a pager
                all_dom_ids_need_refresh.push(viewSettings.view_dom_id);
              }
            }
          });
        }

        if (!view || view.length != 1) {
          return;
        }
        all_dom_ids_need_refresh = Array.from(new Set(all_dom_ids_need_refresh));
        // Update view on summary block click.
        if (Drupal.AjaxFacetsView.updateFacetsSummaryBlock() && (facetId === 'facets_summary_ajax')) {
            const elementsToAttach = once('summaryblock_attache', '[data-drupal-facets-summary-id=' + facetSettings.facets_summary_id + ']', context);
            $(elementsToAttach).children('ul').children('li').click(function (e) {
            e.preventDefault();
            var facetLink = $(this).find('a');
            // Note for myself here. Only the actual View that is targeted by the current Facet can use facetLink.attr('href')
            // the other ones need to use the original URL cleaned up + the arguments of the facetLink.attr('href')
            // This is needed since Facet URL generator will (for good reasons) remove the ?page=argument.
            // And also is absolutely unaware of pagers with different names!
            Drupal.AjaxFacetsView.UpdateView(facetLink.attr('href'), current_dom_id, view_path);
              all_dom_ids_need_refresh.forEach((other_dom_id) => {
                // Check if we need to update this ones too for 1.6? Same as we do for paged views?
              });

          });
        }
        // Update view on facet item click.
        else {
          $('[data-drupal-facet-id=' + facetId + ']').each(function (index, facet_item) {
            if ($(facet_item).hasClass('js-facets-widget')) {
              $(facet_item).unbind('facets_filter.facets');
              $(facet_item).on('facets_filter.facets', function (event, url) {
                $('.js-facets-widget').trigger('facets_filtering');
                // Note for myself here. Only the actual View that is targeted by the current Facet can use facetLink.attr('href')
                // the other ones need to use the original URL cleaned up + the arguments of the facetLink.attr('href')
                // This is needed since Facet URL generator will (for good reasons) the ?page=argument.
                // And also is absolutely unaware of pagers with different names!
                console.log(url);
                Drupal.AjaxFacetsView.UpdateView(url, current_dom_id, view_path);
                all_dom_ids_need_refresh.forEach((other_dom_id) => {
                  Drupal.AjaxFacetsView.UpdateView(url, other_dom_id, view_path);
                });
              });
            }
          });
        }
      });
    }
  };

  // Helper function to update views output & Ajax facets.
  Drupal.AjaxFacetsView = {};

  Drupal.AjaxFacetsView.UpdateView = function (href, current_dom_id, view_path) {
    // Refresh view.
    var atLeastone = false;
      if (typeof(Drupal.views.instances['views_dom_id:' + current_dom_id]) !== 'undefined') {
        atLeastone = true;
      var views_parameters = Drupal.Views.parseQueryString(href);
      let views_path = 'search';
      if (Drupal.views.instances['views_dom_id:' + current_dom_id].settings.view_base_path !== 'undefined') {
        views_path = Drupal.views.instances['views_dom_id:' + current_dom_id].settings.view_base_path;
      }
      const views_arguments = Drupal.Views.parseViewArgs(href, views_path);
      const views_settings = $.extend(
        {},
        Drupal.views.instances['views_dom_id:' + current_dom_id].settings,
        views_arguments,
        views_parameters
      );
      // Not even needed here if we are using the original element settings ....mmmm
      // Update View.
      const views_ajax_settings = Drupal.views.instances['views_dom_id:' + current_dom_id].element_settings;
      views_ajax_settings.submit = views_settings;
      // Used to be the way in Drupal 9.x to 10.0 ... views_ajax_settings.url = view_path + '?q=' + href;
      views_ajax_settings.url = view_path;

      const viewRefreshAjaxObject = Drupal.ajax(views_ajax_settings);
      const success = viewRefreshAjaxObject.success();

      viewRefreshAjaxObject.success = function (response, status) {
        return Promise.resolve(
          Drupal.Ajax.prototype.success.call(viewRefreshAjaxObject, response, status),
        ).then(() => {
          Drupal.AjaxFacetsView.updateFacetsBlocks(href, views_settings.view_name, views_settings.view_display_id);
          if (typeof(drupalSettings.format_strawberryfield_views) !== 'undefined') {
            // Refresh facets blocks.
            Drupal.updateModalViewsFormBlocks(href, views_settings.view_name, views_settings.view_display_id);
          }
        });
      };
      viewRefreshAjaxObject.execute();
    }
    if (atLeastone) {
      // Update url.
      window.historyInitiated = true;
      window.history.pushState(null, document.title, href);

      // ToDo: Update views+facets with ajax on history back.
      // For now we will reload the full page.
      window.addEventListener("popstate", function (e) {
        if (window.historyInitiated) {
          window.location.reload();
        }
      });
    }
  }
  Drupal.AjaxFacetsView.updateFacetsBlocks = function (href, view_id, current_display_id) {
    var settings = drupalSettings;
    var facets_blocks = this.facetsBlocks(view_id, current_display_id);

    // Remove All Range Input Form Facet Blocks from being updated.
    if(settings.facets && settings.facets.rangeInput) {
      $.each(settings.facets.rangeInput, function (index, value) {
        delete facets_blocks[value.facetId];
      });
    }
    // Even if empty (e.g the query returns nothing/returned nothing before and all not visible)
    // The summary might need to be updated anyhow.
    // @TODO also do the same treatment for summary. Maybe we want to have multiple summaries in a single page?
    // Update facet blocks.
    var facet_settings = {
      url: Drupal.url('facets-block-ajax'),
      submit: {
        facet_link: href,
        facets_blocks: facets_blocks
      }
    };

    // Update facets summary block.
    if (this.updateFacetsSummaryBlock()) {
      var $facet_summary_wrapper = $('[data-drupal-facets-summary-id=' + settings.facets_views_ajax.facets_summary_ajax.facets_summary_id + ']');
      if ($facet_summary_wrapper.length > 0) {
        var facet_summary_wrapper_id = $facet_summary_wrapper.attr('id');
        var facet_summary_block_id = '';
        if (facet_summary_wrapper_id.indexOf('--') !== -1) {
          facet_summary_block_id = facet_summary_wrapper_id.substring(0, facet_summary_wrapper_id.indexOf('--')).replace('block-', '');
        } else {
          facet_summary_block_id = facet_summary_wrapper_id.replace('block-', '');
        }
        facet_settings.submit.update_summary_block = true;
        facet_settings.submit.facet_summary_block_id = facet_summary_block_id;
        facet_settings.submit.facet_summary_wrapper_id = settings.facets_views_ajax.facets_summary_ajax.facets_summary_id;
      }
    }
    if (Object.keys(facet_settings.submit.facets_blocks).length > 0) {
      Drupal.ajax(facet_settings).execute();
    }
  };

  // Helper function to determine if we should update the summary block.
  // Returns true or false.
  Drupal.AjaxFacetsView.updateFacetsSummaryBlock = function () {
    var settings = drupalSettings;
    var update_summary = false;

    if (settings.facets_views_ajax.facets_summary_ajax) {
      update_summary = true;
    }

    return update_summary;
  };

  // Helper function, return facet blocks.
  Drupal.AjaxFacetsView.facetsBlocks = function (view_id,current_display_id) {
    // Get all ajax facets blocks from the current page.
    var facets_blocks = {};
    var facets_for_current_view = [];
    $.each(drupalSettings.facets_views_ajax, function (facetId, facetSettings) {
      if (facetSettings.view_id == view_id && current_display_id == facetSettings.current_display_id){
        facets_for_current_view.push(facetId);
      }
    });

    if (facets_for_current_view.length > 0) {
      $('.block-facets-ajax').each(function (index) {
        var $facet_found = false;
        $(this).find('[data-drupal-facet-id]').each(function (index, value) {
          var current_facet_id = $(this).data('drupal-facet-id');
          if (facets_for_current_view.includes(current_facet_id)) {
            $facet_found = true;
          }
          // We only need one. No reason to check every UL/LI of element
          return false;
        });

        if ($facet_found) {
          var block_id_start = 'js-facet-block-id-';
          var block_id = $.map($(this).attr('class').split(' '), function (v, i) {
            if (v.indexOf(block_id_start) > -1) {
              return v.slice(block_id_start.length, v.length);
            }
          }).join();
          var block_selector = '#' + $(this).attr('id');
          facets_blocks[block_id] = block_selector;
        }
      });
    }
    return facets_blocks;
  };


})(jQuery, Drupal, once, drupalSettings);
