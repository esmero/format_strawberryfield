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
          if (typeof(drupalSettings.views) != 'undefined') {
            Object.keys(drupalSettings.views.ajaxViews || {}).forEach((i) => {
              //var block_settings = drupalSettings.format_strawberryfield_views.modal_exposed_form_block[$modal_exposed_form_id];
              if ((typeof (Drupal.views.instances[i]) !== 'undefined') && Drupal.views.instances[i].hasOwnProperty("settings")) {
                if (Drupal.views.instances[i].settings.view_name == $view_parts[0] //block_settings.view_id
                  && Drupal.views.instances[i].settings.view_display_id == $view_parts[1]) { //block_settings.current_display_id) {
                  var exposed_form_selector = '#views-exposed-form-' + $view_parts[0].replace(/_/g, '-') + '-' + $view_parts[1].replace(/_/g, '-');
                  var $exposed_form = $(exposed_form_selector).length;
                  if ($exposed_form > 0) {
                    $exposed_form = 1;
                  }
                  $('input[type=submit], button[type=submit], input[type=image]', $modal_exposed_form).not('[data-drupal-selector=edit-reset]').each(function (index) {
                    var selfSettings = $.extend({}, Drupal.views.instances[i].element_settings, {
                      base: $(this).attr('id'),
                      element: this
                    });
                    // We can't use the URL passed from the settings bc it might have the original
                    // Arguments stuck and the Ajax Controller will not know if to enforce the GET
                    // or POST ones.
                    var inputs_in_form = {};
                    $('input[type=select], input[type=hidden], input[type=text]', $modal_exposed_form).not('[data-drupal-selector=edit-reset]').each(function (index) {
                      inputs_in_form[$(this).attr('name')] = $(this).attr('name');
                    });
                    var $clean_url_split = selfSettings.url.split('?');
                    var $clean_url_base =  $clean_url_split.shift();
                    var searchParams = new URLSearchParams(selfSettings.url.substring(selfSettings.url.indexOf('?')));
                    Object.keys(inputs_in_form || {}).forEach(function (index) {
                      if (searchParams.has(inputs_in_form[index])) {
                        searchParams.delete(inputs_in_form[index]);
                      }
                    });
                    var href = searchParams.toString();
                    selfSettings.url = $clean_url_base + '?' + href;
                    selfSettings.submit.exposed_form_display = $exposed_form;
                    $modal_exposed_form.exposedFormAjax[index] = Drupal.ajax(selfSettings);
                  });
                }
              }
            });
          }
        });
      }

      if (typeof(drupalSettings.views) != 'undefined') {
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

  // Helper function, return modal view blocks with a given submit URL
  var modalViewsFormBlocks = function (url) {
    // Get all ajax modal view blocks from the current page.
    var modalviews_blocks = {};

    $('.block-modalformviews-ajax').each(function (index) {
      var block_id_start = 'js-modal-form-views-block-id-';
      var block_id = $.map($(this).attr('class').split(' '), function (v, i) {
        if (v.hasOwnProperty(indexOf) && v.indexOf(block_id_start) > -1) {
          return v.slice(block_id_start.length, v.length);
        }
      }).join();
      var block_selector = '#' + $(this).attr('id');
      modalviews_blocks[block_id] = block_selector;
    });

    return modalviews_blocks;
  };

  /**
   * Overrides beforeSend to trigger facet blocks/modal views exposed blocks update on exposed filter change.
   *
   * @param {XMLHttpRequest} xmlhttprequest
   *   Native Ajax object.
   * @param {object} options
   *   jQuery.ajax options.
   */
  Drupal.Ajax.prototype.beforeSend = function (xmlhttprequest, options) {

    var ajax_views_call = false;
    var view_name = null;
    var view_display_id = null;
    var href = window.location.href;
    // Get view from options.
    if (typeof options.extraData !== 'undefined' && typeof options.extraData.view_name !== 'undefined') {
      ajax_views_call = true;
      var settings = drupalSettings;
      const $current_form_params_as_array = this.$form.serializeArray();
      href = addExposedFiltersToModalExposedViewsBlockUrl(href, options.extraData.view_name, options.extraData.view_display_id, $current_form_params_as_array);

      view_name = options.extraData.view_name
      view_display_id = options.extraData.view_display_id;

      if (typeof(settings.format_strawberryfield_views) !== 'undefined' && typeof(settings.format_strawberryfield_views.modal_exposed_form_block) !== 'undefined') {
        var reload = false;
        var block_ids = {};
        $.each(settings.format_strawberryfield_views.modal_exposed_form_block, function (formId, blockSettings) {
          if (blockSettings.view_id == options.extraData.view_name && blockSettings.current_display_id == options.extraData.view_display_id) {
            reload = true;
            block_ids[formId] = blockSettings.block_id;
          }
        });
        if (reload) {
          Drupal.updateModalViewsFormBlocks(href, options.extraData.view_name, options.extraData.view_display_id);
        }
      }
      if (typeof(settings.facets_views_ajax) !== 'undefined') {
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
    else if (typeof(options.data) != 'undefined') {
      const const_url_parts = options.url.split('?');
      if (const_url_parts[0] == '/views/ajax') {
        ajax_views_call = true;
        if (typeof(options.data) == 'string') {
          const urlParams = new URLSearchParams('?' + options.data);
          view_name = urlParams.get('view_name');
          view_display_id = urlParams.get('view_display_id');
        }
        else {
          view_name = options.data.hasOwnProperty('view_name') ?  options.data['view_name'] : null;
          view_display_id = options.data.hasOwnProperty('view_display_id') ? options.data['view_display_id'] : null;
        }
      }
      // We could have an "else here". Normally a "facet_link" inside options.data as a single string,
      // calling the wrapper url would qualify.
      // facet processing has already logic to remove pages (page independent) so we are good there.
      // @Note ad @TODO. we could force ML tokens to be passed to the facet_link though.
    }

    // Check if this is going into an ajax/views call.
    if (ajax_views_call && view_name && view_display_id) {
      // This is a form and is a submissions and leading to an ajax call.
      // We need to avoid passing to the Ajax Controller the Page bc it will break a new query if the results
      // Don't include as many pages.
      // href has already that removed via addExposedFiltersToModalExposedViewsBlockUrl. Let's re-assign
      this.url = href;
      // options url might share the same arguments but a different path.
      // Might contain ajax data passed by drupal/theme ajax. Let's do it manually.
      const options_url_params = Drupal.Views.parseQueryString(options.url);
      delete options_url_params['page'];
      options.url =  options.url.split('?')[0] + '?' + $.param(options_url_params);

      var exposed_form_selector = '#views-exposed-form-' + view_name.replace(/_/g, '-') + '-' + view_display_id.replace(/_/g, '-');
      var $exposed_form = $(exposed_form_selector).length;
      if ($exposed_form > 0) {
        $exposed_form = 1;
      }
      if (typeof(options.extraData) != 'undefined') {
        options.extraData = {};
      }
      options.extraData = $.extend({}, options.extraData, {
        exposed_form_display: $exposed_form
      });

      if (typeof(options.data) == 'string') {
        var params = Drupal.Views.parseQueryString(options.data);
        params['exposed_form_display'] = $exposed_form;
        delete params['page']
        options.data = $.param(params);
      }
      else {
        if  ((options.data == null) || (typeof(options.data) == 'undefined')) {
          options.data = {};
        }
        options.data['exposed_form_display'] = $exposed_form;
      }
    }

    // Call the original Drupal method with the right context and modified URLs when needed.
    beforeSend.apply(this, [xmlhttprequest,options]);
  }

  // Helper function to add exposed form data to Modal Exposed Views Form url
  var addExposedFiltersToModalExposedViewsBlockUrl = function (href, view_name, view_display_id, current_form_params_as_array) {
    // In case the default exposed form ID is also there.
    var $exposed_form = $('form#views-exposed-form-' + view_name.replace(/_/g, '-') + '-' + view_display_id.replace(/_/g, '-'));
    var params = Drupal.Views.parseQueryString(href);
    // Form submissions via ajax are permeating also the "page" argument.
    // That is so bad. Bc a next search might hit, but not on the same page
    delete params['page'];

    $.each($exposed_form.serializeArray(), function () {
      if (this.name !== 'page') {
        params[this.name] = this.value;
      }
    });

    $.each(current_form_params_as_array,  function () {
      if (this.name!== 'page') {
        params[this.name] = this.value;
      }
    });

    return href.split('?')[0] + '?' + $.param(params);
  };

  Drupal.AjaxCommands.prototype.SbfSetBrowserUrl = function (ajax, response) {
    window.history.replaceState(null, '', response.url);
  }
})(jQuery, Drupal, once, drupalSettings);

