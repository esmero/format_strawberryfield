(function ($, Drupal, drupalSettings, Mirador) {

    'use strict';

    Drupal.behaviors.format_strawberryfield_mirador_initiate = {
        attach: function(context, settings) {
            $('.strawberry-mirador-item[data-iiif-infojson]').once('attache_mirador')
                .each(function (index, value) {
                    // Get the node uuid for this element
                    var element_id = $(this).attr("id");
                    // Check if we got some data passed via Drupal settings.
                    if (typeof(drupalSettings.format_strawberryfield.mirador[element_id]) != 'undefined') {

                        $(this).height(drupalSettings.format_strawberryfield.mirador[element_id]['height']);
                        if (drupalSettings.format_strawberryfield.mirador[element_id]['width'] != '100%') {
                            $(this).width(drupalSettings.format_strawberryfield.mirador[element_id]['width']);
                        }
                        // Defines our basic options for Mirador IIIF.
                        var $options = {
                            id: element_id,
                            windows: [{
                                manifestId: drupalSettings.format_strawberryfield.mirador[element_id]['manifesturl'],
                                thumbnailNavigationPosition: 'far-bottom',
                            }]
                        };
                        var $firstmanifest = [drupalSettings.format_strawberryfield.mirador[element_id]['manifesturl']];
                        var $allmanifests = $firstmanifest.concat(drupalSettings.format_strawberryfield.mirador[element_id]['manifestother']);
                        var $secondmanifest = drupalSettings.format_strawberryfield.mirador[element_id]['manifestother'].find(x=>x!==undefined);

                        if (Array.isArray($allmanifests) && $allmanifests.length && typeof($secondmanifest) != 'undefined') {
                            var $secondwindow = new Object();
                            $secondwindow.manifestId = $secondmanifest;
                            $secondwindow.thumbnailNavigationPosition = 'far-bottom';
                            $options.windows.push($secondwindow);
                            var $manifests = new Object();
                            $allmanifests.forEach(manifestURL => {
                                // TODO Provider should be passed by metadata at
                                // \Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryMiradorFormatter::viewElements
                                // Deal with this for Beta3
                                $manifests[manifestURL] = new Object({'provider':'See Metadata'});
                            })
                            $options.manifests = $manifests;
                        }
                        //@TODO add an extra Manifests key with every other one so people can select the others.
                        const miradorInstance = Mirador.viewer($options);
                        console.log('initializing Mirador 3.1.1')
                        // Work around https://github.com/ProjectMirador/mirador/issues/3486
                        const mirador_window = document.getElementById(element_id);
                        var observer = new MutationObserver(function(mutations) {
                            let mirador_videos = document.querySelectorAll(".mirador-viewer video source");
                            if (mirador_videos.length) {
                                mutations.forEach(function (mutation) {
                                    if ((mutation.target.localName == "video") && (mutation.addedNodes.length > 0) && (typeof(mutation.target.lastChild.src) != "undefined" )) {
                                        mutation.target.src = mutation.target.lastChild.getAttribute('src');
                                    }
                                });
                            }
                        });
                        observer.observe(mirador_window, {
                            childList: true,
                            subtree: true,
                        });
                    }
                })}}
})(jQuery, Drupal, drupalSettings, window.Mirador);
