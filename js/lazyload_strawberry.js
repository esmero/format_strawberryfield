(function ($, Drupal, lozad) {

    'use strict';

    Drupal.behaviors.format_strawberryfield_lazyload = {
        attach: function(context) {
          if ($(context).find('img.iiif-lazy').length > 0) {
                  console.log('Loading Lazy Load for Images with iiif-lazy class');
                  const observer = lozad('img.iiif-lazy', {
                    rootMargin: '100px 0px', // syntax similar to that of CSS Margin
                    threshold: 0.1, // ratio of element convergence
                    enableAutoReload: true // it will reload the new image when validating attributes changes
                  });
                  observer.observe();
                };
        }
    };
})(jQuery, Drupal, lozad);
