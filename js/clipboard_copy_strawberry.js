(function ($, Drupal, drupalSettings) {
  'use strict';
  Drupal.behaviors.format_strawberryfield_clipboard_copy = {
    attach: function (context, settings) {
      //console.log(context);
      //console.log(settings);
      $('.clipboard-copy').once('attach_clipboard')
        .each(function (index, value) {
          var theid = '#' + $(this).attr("id");
          var copyContainer = document.querySelector(theid);
          var copyButtonClass = copyContainer.dataset.clipboardCopyButton;
          var copyContentClass = copyContainer.dataset.clipboardCopyContent;
          var copyButtonText = copyContainer.dataset.clipboardCopyButtonText;
          var copyButtonContainers;
          var copyContentContainers = document.querySelectorAll('.' + copyContentClass);
          if(copyButtonClass == copyContentClass) {
            copyButtonContainers = copyContentContainers;
          } else {
            copyButtonContainers = document.querySelectorAll('.' + copyButtonClass);
            if(copyButtonContainers.length !== copyContentContainers.length){
              copyButtonContainers = copyContentContainers;
            }
          }
          for(var i = 0; i < copyButtonContainers.length; i++) {
            var copyButtonContainer = copyButtonContainers[i];
            var copyButtonWrapper = document.createElement('button');
            var copyButton = document.createElement('clipboard-copy');
            copyButton.setAttribute('for', copyButtonContainer.id);
            copyButton.innerHTML = copyButtonText;
            copyButtonWrapper.appendChild(copyButton);
            copyButtonContainer.parentElement.insertBefore(copyButtonWrapper, copyButtonContainer);
          }
        });
    }
  };
})(jQuery, Drupal, drupalSettings);
