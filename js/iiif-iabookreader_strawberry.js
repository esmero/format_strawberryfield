(function ($, Drupal, drupalSettings) {

    'use strict';

    Drupal.behaviors.format_strawberryfield_iabookreader_initiate = {
        attach: function(context, settings) {
            $('.strawberry-iabook-item[data-iiif-infojson]').once('attache_iab')
                .each(function (index, value) {

                    // Get the node uuid for this element
                    var element_id = $(this).attr("id");
                    // Check if we got some data passed via Drupal settings.
                    if (typeof(drupalSettings.format_strawberryfield.iabookreader[element_id]) != 'undefined') {
                        console.log(drupalSettings.format_strawberryfield.iabookreader);

                        /*                        var data = drupalSettings.webprofiler.time.events;
                                                var parts = [];
                                                var dataL = data.length;
                                                var perL;
                                                var labelW = [];
                                                var rowW;
                                                var scalePadding;
                                                var endTime = parseInt(data[(dataL - 1)].endtime);
                                                var roundTime = Math.ceil(endTime / 1000) * 1000;
                                                var endScale;

                                                for (var j = 0; j < dataL; j++) {
                                                    perL = data[j].periods.length; */


                        $(this).height(drupalSettings.format_strawberryfield.iabookreader[element_id]['height']);
                        $(this).width(drupalSettings.format_strawberryfield.iabookreader[element_id]['width']);
                        // Defines our basic options for IIIF.
                        var options = {
                            ui: 'full', // embed, full (responsive)
                            el: '#' + element_id,
                            iiifmanifesturl: drupalSettings.format_strawberryfield.iabookreader[element_id]['manifesturl'],
                            iiifmanifest: drupalSettings.format_strawberryfield.iabookreader[element_id]['manifest'],
                            iiifdefaultsequence: null, //If null given will use the first sequence found.
                            maxWidth: 800,
                            imagesBaseURL: 'https://cdn.jsdelivr.net/gh/internetarchive/bookreader@4.2.0/BookReader/images/',
                        };
                        console.log('initializing iabookreader')
                        var br = new BookReader(options);
                        br.init();
                    }

                })}}
})(jQuery, Drupal, drupalSettings);
