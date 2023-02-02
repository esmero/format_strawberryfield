(function ($, Drupal, drupalSettings) {
  'use strict';
  Drupal.behaviors.format_strawberryfield_clipboard_copy = {
    attach: function (context, settings) {
      $('.clipboard-copy-data').once('attach_clipboard')
        .each(function (index, value) {
          var theid = '#' + $(this).attr('id');
          var copyContainer = document.querySelector(theid);
          var copyButtonClassData = copyContainer.dataset.clipboardCopyButton;
          var copyButtonClasses = copyButtonClassData.split(' ');
          var copyButtonClass = copyButtonClasses[0];
          var copyContentClass = copyContainer.dataset.clipboardCopyContent;
          var copyButtonText = copyContainer.dataset.clipboardCopyButtonText;
          var copyButtonContainers;
          var copyContentContainers = document.querySelectorAll('.' + copyContentClass);
          if(copyContentContainers.length == 0) {
            console.log('Class provided by Twig function is not available. Clipboard Copy was not initiated.');
          } else if(copyButtonClass == 'clipboard-copy-button') {
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
              var userCopyButton = copyButtonContainer.querySelector('clipboard-copy');
              if (userCopyButton) {
                copyButtonContainer.addEventListener('click', function () {
                  userCopyButton.click();
                });
              } else {
                var copyContentContainer = copyContentContainers[i];
                if(!copyContentContainer.hasAttribute('id')) {
                  copyContentContainer.id = 'clipboard-copy-content-' + i;
                }
                var copyButtonWrapper = document.createElement('button');
                var copyButton = document.createElement('clipboard-copy');
                copyButton.setAttribute('for', copyContentContainer.id);
                copyButton.innerHTML = copyButtonText;
                copyButtonWrapper.appendChild(copyButton);
                for (var i = 0; i < copyButtonClasses.length; i++) {
                  var copyButtonClassAppend = copyButtonClasses[i];
                  copyButtonWrapper.classList.add(copyButtonClassAppend);
                }
                copyButtonContainer.parentElement.insertBefore(copyButtonWrapper, copyButtonContainer.nextSibling);
              }
            }
          }
        });
    }
  };
})(jQuery, Drupal, drupalSettings);
