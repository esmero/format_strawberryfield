(function ($, Drupal, once, drupalSettings, pdfjsLib, pdfjsWorker) {
  'use strict';
  Drupal.behaviors.format_strawberryfield_pdfjs = {
    attach: function(context, settings) {
      // The workerSrc property is a must!
      // @TODO: Would love to simply push ther 'worker' property since we already have it loaded
      // but i lack enough understanding of the API.
      // See here https://github.com/mozilla/pdf.js/blob/master/src/display/api.js#L218
      // Probably (have to check) passing an Object with that key instead of a string to pdfjsLib.getDocument($doc)
      //  and worker being the global window['pdfjs-dist/build/pdf.worker'] should work
      // basically doc.src='url' and doc.worker = window['pdfjs-dist/build/pdf.worker' ?
      const elementsToAttach = once('attache_pdf', '.strawberry-document-item[data-iiif-document]', context);
      $(elementsToAttach).each(function (index, value) {
        var $document = $(this).data("iiif-document");
        let $theid = $(this).attr("id");
        let $thefileselectorid = $(this).attr("id") + '_file_selector';
        var $initialpage = $(this).data("iiif-initialpage");
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@2.2.228/build/pdf.worker.min.js';

        /**
         * Single Page render
         * Could be used for thumbnails?
         */
        function singlePageRender() {
          // Asynchronous download of PDF
          var loadingTask = pdfjsLib.getDocument($document);
          loadingTask.promise.then(function (pdf) {
            console.log('PDF loaded');
            // Fetch the first page
            var pageNumber = 1;
            pdf.getPage(pageNumber).then(function (page) {
              console.log('Page loaded');

              var scale = 1.5;
              var viewport = page.getViewport({scale: scale});

              // Prepare canvas using PDF page dimensions
              var canvas = document.getElementById($theid);
              var ctx = canvas.getContext('2d');
              canvas.height = viewport.height;
              canvas.width = viewport.width;

              // Render PDF page into canvas context
              var renderContext = {
                canvasContext: ctx,
                viewport: viewport
              };
              var renderTask = page.render(renderContext);
              renderTask.promise.then(function () {
                console.log('Page rendered');
              });
            });
          }, function (reason) {
            // PDF loading error
            console.error(reason);
          });
        }

        var pdfDoc = null,
          pageNum = $initialpage,
          pageRendering = false,
          pageNumPending = null,
          scale = 1.5,
          canvas = document.getElementById($theid),
          ctx = canvas.getContext('2d');

        /**
         * Get page info, resize and render.
         * @param num Page number.
         */
        function renderPage(num) {
          pageRendering = true;
          // Using promise to fetch the page
          pdfDoc.getPage(num).then(function(page) {
            var viewport = page.getViewport({scale: scale});
            canvas.height = viewport.height;
            canvas.width = viewport.width;

            var renderContext = {
              canvasContext: ctx,
              viewport: viewport
            };
            var renderTask = page.render(renderContext);

            renderTask.promise.then(function() {
              pageRendering = false;
              if (pageNumPending !== null) {
                renderPage(pageNumPending);
                pageNumPending = null;
              }
            });
          });
          // Update page counters
          document.getElementById($theid+'-pagenum').textContent = num;
        }

        function queueRenderPage(num) {
          if (pageRendering) {
            pageNumPending = num;
          } else {
            renderPage(num);
          }
        }

        /**
         * Displays prev. page.
         */
        function onPrevPage() {
          if (pageNum <= 1) {
            return;
          }
          pageNum--;
          queueRenderPage(pageNum);
        }
        document.getElementById($theid+'-prev').addEventListener('click', onPrevPage);

        /**
         * Displays next page.
         */
        function onNextPage() {
          if (pageNum >= pdfDoc.numPages) {
            return;
          }
          pageNum++;
          queueRenderPage(pageNum);
        }
        document.getElementById($theid+'-next').addEventListener('click', onNextPage);

        /**
         * Asynchronously downloads PDF.
         */
        pdfjsLib.getDocument($document).promise.then(function(pdfDoc_) {
          pdfDoc = pdfDoc_;
          document.getElementById($theid+'-pagecount').textContent = pdfDoc.numPages;
          renderPage(pageNum);
        });

        let select_file = $('#'+$thefileselectorid);
        if (select_file.length) {
          select_file.change(function() {
            pdfjsLib.getDocument($( this ).val()).promise.then(function(pdfDoc_) {
              pdfDoc = pdfDoc_;
              document.getElementById($theid+'-pagecount').textContent = pdfDoc.numPages;
              renderPage(pageNum);
            });
          });
        }

      });
    }
  };
})(jQuery, Drupal, once, drupalSettings, window.pdfjsLib, window.pdfjsWorker);
