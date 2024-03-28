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
        const selfForm = event.target.closest('form')
        const othersame_inputs = document.querySelectorAll('div[data-drupal-modalblock-selector] form:not(#'+selfForm.id+') input[name='+event.target.name+']');
        othersame_inputs.forEach(function (targetElement) {
          targetElement.value = event.target.value;
        });
      };
      var $context = $(context);
      this.attachModalFormInteractions = function (input) {
        input.addEventListener("change", this.onChangeHandler);
        //const $combined_view = $input.data('drupal-target-view');
      }
      if ($context.is('div[ata-sbf-modalblock-copytothers=true]')) {
        var $that = this;
        once('modal-block-form', 'form', context).forEach(function (value, index) {
            $that.attachModalFormInteractions(value);
        });
      }
      else {
        var $that = this;
        once('modal-block-form', 'div[data-sbf-modalblock-copytothers=true] form', context).forEach(function (value, index) {
            $that.attachModalFormInteractions(value);
        });
      }
    }
  };
})(jQuery, Drupal, once, drupalSettings);

