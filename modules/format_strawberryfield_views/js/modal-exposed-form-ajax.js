/**
 * @file
 * Facets views AJAX handling.
 */


(function ($, Drupal) {
  'use strict';

  /**
   * Keep the original beforeSend method to use it later.
   */
  var beforeSend = Drupal.Ajax.prototype.beforeSend;

  var attachExposedFormAjax = Drupal.views.ajaxView.prototype.attachExposedFormAjax;

  /**
   * Trigger views AJAX refresh on click.
   */
  Drupal.behaviors.sbfModalExposedFormViewsAjax = {
    attach: function (context, settings) {

      // Loop through all facets.
      $.each(settings.format_strawberryfield_views.modal_exposed_form_block, function (formId, blockSettings) {
        // Get the View for the current facet.
        var view, current_dom_id, view_path;
        if (settings.views && settings.views.ajaxViews) {
          $.each(settings.views.ajaxViews, function (domId, viewSettings) {
            // Check if we have facet for this view.
            if (blockSettings.view_id == viewSettings.view_name && blockSettings.current_display_id == viewSettings.view_display_id) {
              view = $('.js-view-dom-id-' + viewSettings.view_dom_id);
              current_dom_id = viewSettings.view_dom_id;
              view_path = blockSettings.ajax_path;
            }
          });
        }

        if (!view || view.length != 1) {
          return;
        }
        this.$view = view;

        var ajaxPath = drupalSettings.views.ajax_path;

        if (ajaxPath.constructor.toString().indexOf('Array') !== -1) {
          ajaxPath = ajaxPath[0];
        }

        var queryString = window.location.search || '';

        if (queryString !== '') {
          queryString = queryString.slice(1).replace(/q=[^&]+&?|&?render=[^&]+/, '');

          if (queryString !== '') {
            queryString = (/\?/.test(ajaxPath) ? '&' : '?') + queryString;
          }
        }

        this.element_settings = {
          url: ajaxPath + queryString,
          submit: settings,
          setClick: true,
          event: 'click',
          selector: view,
          progress: {
            type: 'fullscreen'
          }
        };
        this.settings = settings;
        this.$exposed_form = $(formId);
        once('exposed-form', this.$exposed_form).forEach($.proxy(attachExposedFormAjax, this));
        var selfSettings = $.extend({}, this.element_settings, {
          event: 'RefreshView',
          base: this.selector,
          element: this.$view.get(0)
        });
        this.refreshViewAjax = Drupal.ajax(selfSettings);


      /*Drupal.views.ajaxView.prototype.attachExposedFormAjax = function () {
        var that = this;
        this.exposedFormAjax = [];

        if (that.element_settings.submit) {
          that.element_settings.submit.exposed_form_display = 1;
        }

        $('input[type=submit], button[type=submit], input[type=image]', this.$exposed_form).not('[data-drupal-selector=edit-reset]').each(function (index) {
          var selfSettings = $.extend({}, that.element_settings, {
            base: $(this).attr('id'),
            element: this
          });
          that.exposedFormAjax[index] = Drupal.ajax(selfSettings);
        });
      };*/






          /*$('[data-drupal-facet-id=' + facetId + ']').each(function (index, facet_item) {
            if ($(facet_item).hasClass('js-facets-widget')) {
              $(facet_item).unbind('facets_filter.facets');
              $(facet_item).on('facets_filter.facets', function (event, url) {
                $('.js-facets-widget').trigger('facets_filtering');
                updateFacetsView(url, current_dom_id, view_path);
              });
            }
          });*/
      });
    }
  };

  // Helper function to update views output & Ajax facets.
  var updateFacetsView = function (href, current_dom_id, view_path, block_ids) {
    // Refresh view.
    var views_parameters = Drupal.Views.parseQueryString(href);
    var views_arguments = Drupal.Views.parseViewArgs(href, 'search');
    var views_settings = $.extend(
      {},
      Drupal.views.instances['views_dom_id:' + current_dom_id].settings,
      views_arguments,
      views_parameters
    );

    // Update View.
    var views_ajax_settings = Drupal.views.instances['views_dom_id:' + current_dom_id].element_settings;
    views_ajax_settings.submit = views_settings;
    views_ajax_settings.url = view_path + '?q=' + href;

    Drupal.ajax(views_ajax_settings).execute();

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

    // Refresh facets blocks.
    updateModalViewsFormBlocks(href);
  }

  // Helper function, updates facet blocks.
  var updateModalViewsFormBlocks = function (href, block_ids) {
    var settings = drupalSettings;
    var modalviews_blocks = block_ids;

    // Update Modal Exposed Form Views blocks.
    var modal_form_exposed_settings = {
      url: Drupal.url('exposed-views-form-block-ajax'),
      submit: {
        exposedform_link: href,
        modalviews_blocks: modalviews_blocks
      }
    };

    Drupal.ajax(modal_form_exposed_settings).execute();
  };


  // Helper function, return facet blocks.
  var modalViewsFormBlocks = function () {
    // Get all ajax facets blocks from the current page.
    var modalviews_blocks = {};

    $('.block-modalformviews-ajax').each(function (index) {
      var block_id_start = 'js-modal-form-views-block-id-';
      var block_id = $.map($(this).attr('class').split(' '), function (v, i) {
        if (v.indexOf(block_id_start) > -1) {
          return v.slice(block_id_start.length, v.length);
        }
      }).join();
      var block_selector = '#' + $(this).attr('id');
      modalviews_blocks[block_id] = block_selector;
    });

    return modalviews_blocks;
  };

  /**
   * Overrides beforeSend to trigger facetblocks update on exposed filter change.
   *
   * @param {XMLHttpRequest} xmlhttprequest
   *   Native Ajax object.
   * @param {object} options
   *   jQuery.ajax options.
   */
  Drupal.Ajax.prototype.beforeSend = function (xmlhttprequest, options) {

    // Get view from options.
    if (typeof options.extraData !== 'undefined' && typeof options.extraData.view_name !== 'undefined') {
      var href = window.location.href;
      var settings = drupalSettings;

      var reload = false;
      var block_ids = {};
      $.each(settings.format_strawberryfield_views.modal_exposed_form_block, function (formId, blockSettings) {
        if (blockSettings.view_id == options.extraData.view_name && blockSettings.current_display_id == options.extraData.view_display_id) {
          reload = true;
          block_ids[formId] = blockSettings.block_id;
        }

      });

      if (reload) {
        href = addExposedFiltersToModalExposedViewsBlockUrl(href, options.extraData.view_name, options.extraData.view_display_id);
        updateModalViewsFormBlocks(href, block_ids);
      }
    }

    // Call the original Drupal method with the right context.
    beforeSend.apply(this, arguments);
  }

  // Helper function to add exposed form data to Modal Exposed Views Form url
  var addExposedFiltersToModalExposedViewsBlockUrl = function (href, view_name, view_display_id) {
    var $exposed_form = $('form#views-exposed-form-' + view_name.replace(/_/g, '-') + '-' + view_display_id.replace(/_/g, '-'));

    var params = Drupal.Views.parseQueryString(href);

    $.each($exposed_form.serializeArray(), function () {
      params[this.name] = this.value;
    });

    return href.split('?')[0] + '?' + $.param(params);
  };

})(jQuery, Drupal);
