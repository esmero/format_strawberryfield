(function ($, Drupal, drupalSettings, L) {

    'use strict';

    Drupal.behaviors.format_strawberryfield_leaflet_initiate = {
        attach: function(context, settings) {
            $('.strawberry-leaflet-item[data-iiif-infojson]').once('attache_leaflet')
                .each(function (index, value) {

                    var $featurecount = 0;

                    function popUpFeature(feature, layer){
                        var popupText = feature.properties.name +"<br>";
                        layer.bindPopup(popupText);
                    }
                    var markerArray = [];

                    function onEachFeature(feature, layer) {
                        popUpFeature(feature, layer);
                    }
                    // Get the node uuid for this element
                    var element_id = $(this).attr("id");
                    // Check if we got some data passed via Drupal settings.
                    if (typeof(drupalSettings.format_strawberryfield.leaflet[element_id]) != 'undefined') {

                        $(this).height(drupalSettings.format_strawberryfield.leaflet[element_id]['height']);
                        $(this).width(drupalSettings.format_strawberryfield.leaflet[element_id]['width']);
                        // Defines our basic options for leaflet GEOJSON

                        var $initialzoom = 5;

                        if (drupalSettings.format_strawberryfield.leaflet[element_id]['initialzoom'] || drupalSettings.format_strawberryfield.leaflet[element_id]['initialzoom'] === 0) {
                            $maxzoom = drupalSettings.format_strawberryfield.leaflet[element_id]['initialzoom'];
                        }
                        // initialize the map
                        var map = L.map(element_id).setView([40.1, -100], $initialzoom);
                        // Use current's user lat/long
                        // Does not work without HTTPS
                       //  map.locate({setView: true, maxZoom: 8});

                        var geojsonLayer = L.geoJson.ajax(drupalSettings.format_strawberryfield.leaflet[element_id]['geojsonurl'],{
                            onEachFeature: onEachFeature,
                            pointToLayer: function (feature, latlng) {
                                markerArray.push(L.marker (latlng));
                                return L.marker (latlng);
                            },
                        });
                        // The tilemap url in /{z}/{x}/{y}.png format. Can have a key after a ? if provided by the user.
                        // Defaults, should never be needed, in case wants to get around of restricted forms?
                        // See https://operations.osmfoundation.org/policies/tiles/ and consider contributing if you
                        // are reading this.

                        var $tilemap = {
                            url:'https://a.tile.openstreetmap.org/{z}/{x}/{y}.png',
                            attribution: '&copy; <a href="https://openstreetmap.org/copyright">OpenStreetMap contributors</a>'
                        }
                        var $minzoom = 0;
                        var $maxzoom = 10;

                        if (drupalSettings.format_strawberryfield.leaflet[element_id]['tilemap_url']) {
                            $tilemap.url = drupalSettings.format_strawberryfield.leaflet[element_id]['tilemap_url'];
                            $tilemap.attribution = drupalSettings.format_strawberryfield.leaflet[element_id]['tilemap_attribution'];
                        }

                        if (drupalSettings.format_strawberryfield.leaflet[element_id]['minzoom'] || drupalSettings.format_strawberryfield.leaflet[element_id]['minzoom'] === 0) {
                            $minzoom = drupalSettings.format_strawberryfield.leaflet[element_id]['minzoom'];
                        }
                        if (drupalSettings.format_strawberryfield.leaflet[element_id]['maxzoom'] || drupalSettings.format_strawberryfield.leaflet[element_id]['maxzoom'] === 0) {
                            $maxzoom = drupalSettings.format_strawberryfield.leaflet[element_id]['maxzoom'];
                        }

                        // load a tile layer
                        L.tileLayer($tilemap.url,
                            {
                                attribution: $tilemap.attribution,
                                maxZoom: $maxzoom,
                                minZoom: $minzoom
                            }).addTo(map);

                        map.on('layeradd', function (e) {
                            if (markerArray.length > 0) {
                                var geojsongroup = new L.featureGroup(markerArray);
                                if (markerArray.length == 1) {
                                    map.setView(geojsongroup.getBounds().getCenter(), $initialzoom);
                                }
                                else {
                                    map.fitBounds(geojsongroup.getBounds());
                                }

                            }
                        });

                        var $firstgeojson = [drupalSettings.format_strawberryfield.leaflet[element_id]['geojsonurl']];
                        var $allgeojsons = $firstgeojson.concat(drupalSettings.format_strawberryfield.leaflet[element_id]['geojsonother']);
                        var $secondgeojson = drupalSettings.format_strawberryfield.leaflet[element_id]['geojsonother'].find(x=>x!==undefined);

                        if (Array.isArray($allgeojsons) && $allgeojsons.length && typeof($secondgeojson) != 'undefined') {

                            $allgeojsons.forEach(geojsonURL => {
                                // TODO Provider, rights, etc should be passed by metadata at
                                // \Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryMapFormatter
                                // Deal with this for Beta3
                                // Not a big issue if GeoJSON has that data. We can iterate over all Feature keys
                                // And print them on the overlay?
                                geojsonLayer.addUrl("geojsonURL");//we now have 2 layers
                            })
                        }
                        //@TODO add an extra geojsons key with every other one so people can select the others.
                        // load a tile layer
                        geojsonLayer.addTo(map);

                        console.log('initializing leaflet 1.6.0')
                    }
                })}}
})(jQuery, Drupal, drupalSettings, window.L);