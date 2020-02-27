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
                    var geojsongroup = new L.featureGroup([L.marker([40.1, -100])]);

                    function onEachFeature(feature, layer) {
                        console.log(feature);
                        popUpFeature(feature, layer);
                    }
                    // Get the node uuid for this element
                    var element_id = $(this).attr("id");
                    // Check if we got some data passed via Drupal settings.
                    if (typeof(drupalSettings.format_strawberryfield.leaflet[element_id]) != 'undefined') {

                        $(this).height(drupalSettings.format_strawberryfield.leaflet[element_id]['height']);
                        $(this).width(drupalSettings.format_strawberryfield.leaflet[element_id]['width']);
                        // Defines our basic options for leaflet GEOJSON

                        // initialize the map
                        var map = L.map(element_id).setView([40.1, -100], 4);
                        // Use current's user lat/long
                        // Does not work without HTTPS
                       //  map.locate({setView: true, maxZoom: 8});

                        var geojsonLayer = L.geoJson.ajax(drupalSettings.format_strawberryfield.leaflet[element_id]['geojsonurl'],{
                            onEachFeature: onEachFeature,
                            pointToLayer: function (feature, latlng) {
                                return L.marker (latlng);
                            },
                        });

                        /* var latLon = L.latLng(40.737, -73.923);
                        var bounds = latLon.toBounds(500); // 500 = metres
                        map.panTo(latLon).fitBounds(bounds);
                        map.setView(new L.LatLng(40.737, -73.923), 8); */



                        // load a tile layer
                        L.tileLayer('https://maps.wikimedia.org/osm-intl/{z}/{x}/{y}.png',
                            {
                                attribution: 'Tiles by <a href="https://foundation.wikimedia.org/wiki/Maps_Terms_of_Use">Wikimedia</a>',
                                maxZoom: 17,
                                minZoom: 4
                            }).addTo(map);


                        map.on('layeradd', function (e) {
                            console.log(e.layer);
                            //map.setView(geojsongroup.getBounds().getCenter());
                            map.fitBounds(L.featureGroup(e.layer).getBounds());
                        });


                        var $firstgeojson = [drupalSettings.format_strawberryfield.leaflet[element_id]['geojsonurl']];
                        var $allgeojsons = $firstgeojson.concat(drupalSettings.format_strawberryfield.leaflet[element_id]['geojsonother']);
                        var $secondgeojson = drupalSettings.format_strawberryfield.leaflet[element_id]['geojsonother'].find(x=>x!==undefined);

                        if (Array.isArray($allgeojsons) && $allgeojsons.length && typeof($secondgeojson) != 'undefined') {

                            $allgeojsons.forEach(geojsonURL => {
                                // TODO Provider should be passed by metadata at
                                // \Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryleafletFormatter::viewElements
                                // Deal with this for Beta3
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