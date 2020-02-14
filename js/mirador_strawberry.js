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
                        $(this).width(drupalSettings.format_strawberryfield.mirador[element_id]['width']);
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
                        if (Array.isArray($allmanifests) || $allmanifests.length) {
                            // This implies auto-magically that a $secondmanifest exists!
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
                        var miradorInstance = Mirador.viewer($options);
                        console.log('initializing Mirador 3.0.0-beta.4')
                    }
                })}}
})(jQuery, Drupal, drupalSettings, window.Mirador);