(function ($, Drupal, drupalSettings) {
  'use strict';
  Drupal.behaviors.format_strawberryfield_clipboard_copy = {
    attach: function (context, settings) {
      console.log(context);
      console.log(settings);
      $('.clipboard-copy').once('attach_clipboard')
        .each(function (index, value) {
          var theid = '#' + $(this).attr("id");
          var copyContainer = document.querySelector(theid);
          console.log('why is console log not working!');
        });
    }
  };
})(jQuery, Drupal, drupalSettings);
