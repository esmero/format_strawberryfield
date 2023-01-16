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
  var beforeSerialize = Drupal.Ajax.prototype.beforeSerialize;

  Drupal.updateModalViewsFormBlocks = function (href, view_id, display_id) {
    var settings = drupalSettings;
    var reload = false;
    var block_ids = {};
    $.each(settings.format_strawberryfield_views.modal_exposed_form_block, function (formId, blockSettings) {
      if (blockSettings.view_id == view_id && blockSettings.current_display_id == display_id) {
        reload = true;
        block_ids[formId] = blockSettings.block_id;
      }
    });

    if (reload) {
      // Update Modal Exposed Form Views blocks.
      var modal_form_exposed_settings = {
        url: Drupal.url('exposed-views-form-block-ajax'),
        submit: {
          exposedform_link: href,
          modalviews_blocks: block_ids
        }
      };

      var exposed_form_selector = '#views-exposed-form-' + view_id.replace(/_/g, '-') + '-' + display_id.replace(/_/g, '-');
      var $exposed_form = $(exposed_form_selector).length;
      var ModalRefreshAjaxObject = Drupal.ajax(modal_form_exposed_settings);
      ModalRefreshAjaxObject.execute();
    }
  };

  /**
   * Trigger views AJAX refresh on click.
   */
  Drupal.behaviors.sbfModalExposedFormViewsAjax = {
    detach: function (context, drupalSettings, trigger) {
      if (trigger === 'unload') {
        //@see https://www.drupal.org/node/3158256
      }
    },
    attach: function (context, drupalSettings) {
      var $context = $(context);
      this.attachModalFormAjax = function ($input) {
        $input.find('form').each(function (index, value) {
          var $modal_exposed_form = $(value);
          var $modal_exposed_form_id = value.id;
          $modal_exposed_form.exposedFormAjax = [];
          const $combined_view = $input.data('drupal-target-view');
          var $view_parts = [];
          $view_parts = $combined_view.split("-");
          // data-drupal-target-view="solr_search_content-page_1"
          // data-drupal-modalblock-selector="js-modal-form-views-block-id-exposedformsolr_search_contentpage_1_3"
          // The idea
          // For each initialized Views Instance of type Drupal.views.ajaxView
          // Check which modal blocks (from the settings)
          // belong to it.
          // Do what Drupal.views.ajaxView.prototype.attachExposedFormAjax does differently

          Object.keys(drupalSettings.views.ajaxViews || {}).forEach((i) => {
              //var block_settings = drupalSettings.format_strawberryfield_views.modal_exposed_form_block[$modal_exposed_form_id];
              if (Drupal.views.instances[i].settings.view_name == $view_parts[0] //block_settings.view_id
                && Drupal.views.instances[i].settings.view_display_id == $view_parts[1]) { //block_settings.current_display_id) {
                var exposed_form_selector = '#views-exposed-form-' + $view_parts[0].replace(/_/g, '-') + '-' +$view_parts[1].replace(/_/g, '-');
                var $exposed_form = $(exposed_form_selector).length;
                if ($exposed_form > 0) {
                  $exposed_form = 1 ;
                }
                $('input[type=submit], button[type=submit], input[type=image]', $modal_exposed_form).not('[data-drupal-selector=edit-reset]').each(function (index) {
                  var selfSettings = $.extend({}, Drupal.views.instances[i].element_settings, {
                    base: $(this).attr('id'),
                    element: this
                  });
                  selfSettings.submit.exposed_form_display = $exposed_form;
                  $modal_exposed_form.exposedFormAjax[index] = Drupal.ajax(selfSettings);
                });
              }
          });
        });
      }

      if (typeof(drupalSettings.views.ajaxViews) != 'undefined') {
        if ($context.is('div[data-drupal-modalblock-selector]')) {
          this.attachModalFormAjax($context);
        }
        else {
          var $that = this;
          once('modal-block', 'div[data-drupal-modalblock-selector]', context).forEach(function (value, index) {
            var $input = $(value);
            $that.attachModalFormAjax($input);
          });
        }
      }
    }
  };

  // Helper function, return modal view blocks blocks with a given submit URL
  var modalViewsFormBlocks = function (url) {
    // Get all ajax modal view blocks from the current page.
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
   * Overrides beforeSend to trigger facetblocks/modal views exposed blocks update on exposed filter change.
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
        const $current_form_params_as_array = this.$form.serializeArray();
        href = addExposedFiltersToModalExposedViewsBlockUrl(href, options.extraData.view_name, options.extraData.view_display_id, $current_form_params_as_array);
        Drupal.updateModalViewsFormBlocks(href, options.extraData.view_name, options.extraData.view_display_id);
        var reloadfacets = false;
        $.each(settings.facets_views_ajax, function (facetId, facetSettings) {
          if (facetSettings.view_id == options.extraData.view_name && facetSettings.current_display_id == options.extraData.view_display_id) {
            reloadfacets = true;
          }
        });
        if (reloadfacets) {
          Drupal.AjaxFacetsView.updateFacetsBlocks(href, options.extraData.view_name, options.extraData.view_display_id);
        }
      }
    }

    // Call the original Drupal method with the right context.
    beforeSend.apply(this, arguments);
  }

  // Helper function to add exposed form data to Modal Exposed Views Form url
  var addExposedFiltersToModalExposedViewsBlockUrl = function (href, view_name, view_display_id, current_form_params_as_array) {
    // In case the default exposed form ID is also there.
    var $exposed_form = $('form#views-exposed-form-' + view_name.replace(/_/g, '-') + '-' + view_display_id.replace(/_/g, '-'));
    var params = Drupal.Views.parseQueryString(href);

    $.each($exposed_form.serializeArray(), function () {
      params[this.name] = this.value;
    });

    $.each(current_form_params_as_array,  function () {
      params[this.name] = this.value;
    });

    return href.split('?')[0] + '?' + $.param(params);
  };


})(jQuery, Drupal, once, drupalSettings);
