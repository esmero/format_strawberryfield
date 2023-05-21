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
          var markerObject_interaction = {};

          function onEachFeature(feature, layer) {
            popUpFeature(feature, layer);
          }
          // Get the node uuid for this element
          var element_id = $(this).attr("id");
          // Check if we got some data passed via Drupal settings.
          if (typeof(drupalSettings.format_strawberryfield.leaflet[element_id]) != 'undefined') {

            $(this).height(drupalSettings.format_strawberryfield.leaflet[element_id]['height']);
            if (drupalSettings.format_strawberryfield.leaflet[element_id]['width'] != '100%') {
              $(this).width(drupalSettings.format_strawberryfield.leaflet[element_id]['width']);
            }
            // Defines our basic options for leaflet GEOJSON

            var $initialzoom = 5;

            if (drupalSettings.format_strawberryfield.leaflet[element_id]['initialzoom'] || drupalSettings.format_strawberryfield.leaflet[element_id]['initialzoom'] === 0) {
              $initialzoom = drupalSettings.format_strawberryfield.leaflet[element_id]['initialzoom'];
            }
            // initialize the map
            var map = L.map(element_id).setView([40.1, -100], $initialzoom);
            // Use current's user lat/long
            // Does not work without HTTPS
            //  map.locate({setView: true, maxZoom: 8});

            var geojsonLayer = L.geoJson.ajax(drupalSettings.format_strawberryfield.leaflet[element_id]['geojsonurl'],{
              onEachFeature: onEachFeature,
              pointToLayer: function (feature, latlng) {
                //markerObject_interaction.feature.
                let newmarker = L.marker (latlng);
                markerArray.push(newmarker);
                /* @TODO: Document this. Each Feature needs to have this property to enable interactions from
                other viewers. Make sure the leaflet Views map does the same!
                 */
                if (feature.properties.hasOwnProperty('sbf:ado:change')) {
                  markerObject_interaction[feature.properties['sbf:ado:change']] = newmarker;
                }
                return newmarker;
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
            // Now deal with the new Fancy IIIF Manifest based Image overlays!

            var $firstiiifjson = [drupalSettings.format_strawberryfield.leaflet[element_id]['iiifurl']];
            var $alliiifjsons = $firstiiifjson.concat(drupalSettings.format_strawberryfield.leaflet[element_id]['iiifother']);
            if (Array.isArray($alliiifjsons) && $alliiifjsons.length) {
              $alliiifjsons.forEach(iiifjsonurl => {
                const $iiifmanifest = Drupal.FormatStrawberryfieldIiifUtils.fetchIIIFManifest(iiifjsonurl);
                $iiifmanifest.then(iiifmanifest_promise_resolved => {
                  const leaflet_image_overlay_arguments = Drupal.FormatStrawberryfieldIiifUtils.getGeoAnnotations(iiifmanifest_promise_resolved);
                  console.log(leaflet_image_overlay_arguments);
                  leaflet_image_overlay_arguments.forEach(leaflet_image_overlay_argument => {
                    // vary the `full` based on Zoom levels? Until i figure out if i should or not tile?
                    const iiif_image_url = leaflet_image_overlay_argument.source + "/" + leaflet_image_overlay_argument.region.iiif_region + "/full/0/default.jpg";
                    const imageOverlay = new L.ImageOverlay.iiifBounded(iiif_image_url, L.GeoJSON.coordsToLatLng(leaflet_image_overlay_argument.bounds[0]), L.GeoJSON.coordsToLatLng(leaflet_image_overlay_argument.bounds[1]), L.GeoJSON.coordsToLatLng(leaflet_image_overlay_argument.bounds[2]), L.GeoJSON.coordsToLatLng(leaflet_image_overlay_argument.bounds[3]), {
                      opacity: 0.8,
                      interactive: true,
                      clip_path: leaflet_image_overlay_argument.region?.clip_path_string,
                    });
                    map.addLayer(imageOverlay);
                  });
                });
              });
            };



            //@TODO add an extra geojsons key with every other one so people can select the others.
            // load a tile layer
            geojsonLayer.addTo(map);
            console.log('initializing leaflet 1.6.0')
            console.log('initializing \'sbf:ado:change\' event listener on ADO changes');
            document.addEventListener('sbf:ado:change', (e) => {
              if (Array.isArray(e.detail.nodeid)) {
                // We can not fly to all NodeIds (and e.detail.nodeid is an array now)
                // but we can fly to the first one!
                // For many we fit to the bounds of all.
                let multinodeid = [];
                e.detail.nodeid.forEach(element => {
                  if (markerObject_interaction.hasOwnProperty(element)) {
                    multinodeid.push(markerObject_interaction[element].getLatLng());
                  }});
                if (multinodeid.length > 1) {
                  const bounds = new L.LatLngBounds(multinodeid);
                  map.fitBounds(bounds);
                } else if (multinodeid.length == 1) {
                  map.flyTo(markerObject_interaction[multinodeid[0]].getLatLng(), $maxzoom - 1);
                }
              }
              else if (markerObject_interaction.hasOwnProperty(e.detail.nodeid)) {
                map.flyTo(markerObject_interaction[e.detail.nodeid].getLatLng(), $maxzoom - 1);
              }
            });
          }
        })}}
})(jQuery, Drupal, drupalSettings, window.L);
