/**
 * @file
 * Copy values from visible to hidden ones between exposed elements inside Modal Exposed Views Forms
 */


(function ($, Drupal, once, drupalSettings) {
  'use strict';


  Drupal.behaviors.sbfModalExposedFormViewsInteractions = {

    detach: function (context, drupalSettings, trigger) {
      if (trigger === 'unload') {
        //@see https://www.drupal.org/node/3158256
      }
    },
    attach: function (context, drupalSettings) {
      this.onChangeHandler = function(event) {
        // Get first the closes Form to the target. WE want to exclude it from the copy destination
        const selfForm = event.target.closest('div[data-sbf-modalblock-copytothers=true] form');
        if (selfForm) {
          const othersame_inputs = document.querySelectorAll('div[data-drupal-modalblock-selector] form:not(#' + selfForm.id + ') input[name=' + event.target.name + ']');
          othersame_inputs.forEach(function (targetElement) {
            targetElement.value = event.target.value;
          });
        }
        // Note that hidden is allowed bc there might be cases where we override Selects to use Bootstrap components. So the
        // original value/and trigger is the hidden element after being set by the component.
        const allowed_autosubmit_types = ['select', 'select-one', 'checkbox', 'radio', 'hidden'];
        // Don't allow Advanced Search to trigger anything.

        if (allowed_autosubmit_types.includes(event.target.type) && typeof event.target.dataset?.advancedSearchType == "undefined") {
          const autosubmit_form = event.target.closest('div[data-sbf-modalblock-autosubmit=true] form');
          if (autosubmit_form) {
            autosubmit_form.submit();
          }
        }
      };
      var $context = $(context);
      this.attachModalFormInteractions = function (input) {
        input.addEventListener("change", this.onChangeHandler);
        //const $combined_view = $input.data('drupal-target-view');
      }
      if ($context.is('div[data-sbf-modalblock-copytothers=true], div[data-sbf-modalblock-autosubmit=true] form')) {
        var $that = this;
        once('modal-block-form', 'form', context).forEach(function (value, index) {
            $that.attachModalFormInteractions(value);
        });
      }
      else {
        var $that = this;
        once('modal-block-form', 'div[data-sbf-modalblock-copytothers=true] form, div[data-sbf-modalblock-autosubmit=true] form', context).forEach(function (value, index) {
            $that.attachModalFormInteractions(value);
        });
      }
    }
  };
})(jQuery, Drupal, once, drupalSettings);

