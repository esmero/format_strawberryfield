(function ($, Drupal, drupalSettings) {
  'use strict';
  Drupal.behaviors.format_strawberryfield_clipboard_copy = {
    attach: function (context, settings) {
      $('.clipboard-copy').once('attach_clipboard')
        .each(function (index, value) {
          var theid = '#' + $(this).attr("id");
          var copyContainer = document.querySelector(theid);
          var copyButtonClass = copyContainer.dataset.clipboardCopyButton;
          var copyContentClass = copyContainer.dataset.clipboardCopyContent;
          var copyButtonText = copyContainer.dataset.clipboardCopyButtonText;
          var copyButtonContainers;
          var copyContentContainers = document.querySelectorAll('.' + copyContentClass);
          if(copyContentContainers.length == 0) {
            console.log('Class provided by Twig function is not available. Clipboard Copy was not initiated.');
          } else if(copyButtonClass == copyContentClass) {
            copyButtonContainers = copyContentContainers;
          } else {
            copyButtonContainers = document.querySelectorAll('.' + copyButtonClass);
            if(copyButtonContainers.length !== copyContentContainers.length){
              copyButtonContainers = copyContentContainers;
            }
          }
          if(copyButtonContainers !== undefined) {
            for(var i = 0; i < copyButtonContainers.length; i++) {
              var copyButtonContainer = copyButtonContainers[i];
              var copyContentContainer = copyContentContainers[i];
              copyContentContainer.id = 'clipboard-copy-content-' + i;
              var copyButtonWrapper = document.createElement('button');
              var copyButton = document.createElement('clipboard-copy');
              copyButton.setAttribute('for', copyContentContainer.id);
              copyButton.innerHTML = copyButtonText;
              copyButtonWrapper.appendChild(copyButton);
              copyButtonContainer.parentElement.insertBefore(copyButtonWrapper, copyButtonContainer);
            }
          }
        });
    }
  };
})(jQuery, Drupal, drupalSettings);
