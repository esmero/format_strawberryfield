(function ($, Drupal, once, drupalSettings) {
  'use strict';
  Drupal.behaviors.format_strawberryfield_clipboard_copy = {
    attach: function (context, settings) {
      const elementsToAttach = once('attache_clipboard', '.clipboard-copy-data', context);
      $(elementsToAttach).each(function (index, value) {
          const theid = '#' + this.id;
          const copyContainer = document.querySelector(theid);
          const copyButtonClassData = copyContainer.dataset.clipboardCopyButton;
          const copyButtonClasses = copyButtonClassData.split(' ');
          const copyButtonClass = copyButtonClasses[0];
          const copyContentClass = copyContainer.dataset.clipboardCopyContent;
          const copyButtonText = copyContainer.dataset.clipboardCopyButtonText;
          let copyButtonContainers;
          const copyContentContainers = document.querySelectorAll('.' + copyContentClass);
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
            for(let i = 0; i < copyButtonContainers.length; i++) {
              let copyButtonContainer = copyButtonContainers[i];
              let userCopyButton = copyButtonContainer.querySelector('clipboard-copy');
              if (userCopyButton) {
                copyButtonContainer.addEventListener('click', function () {
                  userCopyButton.click();
                });
              } else {
                let copyContentContainer = copyContentContainers[i];
                if(!copyContentContainer.hasAttribute('id')) {
                  copyContentContainer.id = 'clipboard-copy-content-' + i;
                }
                const copyButtonWrapper = document.createElement('button');
                const copyButton = document.createElement('clipboard-copy');
                copyButton.setAttribute('for', copyContentContainer.id);
                copyButton.innerHTML = copyButtonText;
                copyButtonWrapper.appendChild(copyButton);
                for (let j = 0; j < copyButtonClasses.length; j++) {
                  let copyButtonClassAppend = copyButtonClasses[j];
                  copyButtonWrapper.classList.add(copyButtonClassAppend);
                }
                copyButtonContainer.parentElement.insertBefore(copyButtonWrapper, copyButtonContainer.nextSibling);
              }
            }
          }
        });
    }
  };
})(jQuery, Drupal, once, drupalSettings);
