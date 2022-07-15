(function ($, Drupal, drupalSettings, L) {

  'use strict';

  Drupal.behaviors.format_strawberryfield_views_leaflet_initiate = {
    attach: function(context, settings) {
      $('.strawberry-leaflet-views-item[data-iiif-infojson]').once('attache_leaflet')
        .each(function (index, value) {

          var $featurecount = 0;

          function popUpFeature(feature, layer){
            var popupText = feature.properties.name +"<br>";
            layer.bindPopup(popupText);
          }

          function onEachFeature(feature, layer) {
            popUpFeature(feature, layer);
          }
          // Get the node uuid for this element
          var element_id = $(this).attr("id");
          let element = $(this);
          // Check if we got some data passed via Drupal settings.
          if (typeof(drupalSettings.format_strawberryfield_views.leaflet[element_id]) != 'undefined') {

            $(this).height(drupalSettings.format_strawberryfield_views.leaflet[element_id]['height']);
            $(this).width(drupalSettings.format_strawberryfield_views.leaflet[element_id]['width']);
            // Defines our basic options for leaflet GEOJSON
            var $initialzoom = 5;
            // The tilemap url in /{z}/{x}/{y}.png format. Can have a key after a ? if provided by the user.
            // Defaults, should never be needed, in case wants to get around of restricted forms?
            // See https://operations.osmfoundation.org/policies/tiles/ and consider contributing if you
            // are reading this.
            var $tilemap = {
              url:'https://a.tile.openstreetmap.org/{z}/{x}/{y}.png',
              attribution: '&copy; <a href="https://openstreetmap.org/copyright">OpenStreetMap contributors</a>'
            }
            if (drupalSettings.format_strawberryfield_views.leaflet[element_id]['initialzoom'] || drupalSettings.format_strawberryfield_views.leaflet[element_id]['initialzoom'] === 0) {
              $initialzoom = drupalSettings.format_strawberryfield_views.leaflet[element_id]['initialzoom'];
            }
            var $minzoom = 0;
            var $maxzoom = 10;

            if (drupalSettings.format_strawberryfield_views.leaflet[element_id]['tilemap_url']) {
              $tilemap.url = drupalSettings.format_strawberryfield_views.leaflet[element_id]['tilemap_url'];
              $tilemap.attribution = drupalSettings.format_strawberryfield_views.leaflet[element_id]['tilemap_attribution'];
            }

            if (drupalSettings.format_strawberryfield_views.leaflet[element_id]['minzoom'] || drupalSettings.format_strawberryfield_views.leaflet[element_id]['minzoom'] === 0) {
              $minzoom = drupalSettings.format_strawberryfield_views.leaflet[element_id]['minzoom'];
            }
            if (drupalSettings.format_strawberryfield_views.leaflet[element_id]['maxzoom'] || drupalSettings.format_strawberryfield_views.leaflet[element_id]['maxzoom'] === 0) {
              $maxzoom = drupalSettings.format_strawberryfield_views.leaflet[element_id]['maxzoom'];
            }


            // initialize the map
            var map = L.map(element_id, {
                zoom: 2,
                maxZoom: $maxzoom
              }
            ).setView([40.1, -100], $initialzoom);
            // load a tile layer
            L.tileLayer($tilemap.url,
              {
                attribution: $tilemap.attribution,
                maxZoom: $maxzoom,
                minZoom: $minzoom
              }).addTo(map);

            var controlLayers = L.control.layers();

            // Use current's user lat/long
            // Does not work without HTTPS
            //  map.locate({setView: true, maxZoom: 8});
            // Because DRUPAL is "something/gosh"
            // and does always a deep merge of drupal settings, passing a different array will always lead in every
            // previous and new GeoJSON urls merged together, making FACETING impossible.
            // Solution for now. atob was used before to decode base64 but that really makes the data grow!
            let urls = JSON.parse(drupalSettings.format_strawberryfield_views.leaflet[element_id]['geojsonurls']);
            let groupedurls = JSON.parse(drupalSettings.format_strawberryfield_views.leaflet[element_id]['geojsongroupedurls']);

            var markers = new L.MarkerClusterGroup({
              showCoverageOnHover: true,
              chunkedLoading: true,
              maxClusterRadius: 80
            });

            /* main non grouped ones */
            var geojsonLayer  = L.geoJson.ajax(Object.values(urls),{
              onEachFeature: onEachFeature,
              pointToLayer: function (feature, latlng) {
                return L.marker (latlng);
              },
            });

            /* grouped ones coming from a view, each group will become a layer control using subgroups */
            if (
              typeof groupedurls === 'object' &&
              !Array.isArray(groupedurls) &&
              groupedurls !== null
             && Object.keys(groupedurls).length > 0) {
              // Only add to map if groups present.
              controlLayers.addTo(map);
              console.log(groupedurls);
              Object.entries(groupedurls).forEach(([key, value], index) => {
                console.log(`${index}: ${key} = ${value}`);
                if (Array.isArray(value) && Object.values(value).length > 0) {
                  console.log(value);
                let geojsonLayer_group  = L.geoJson.ajax(Object.values(value), {
                  onEachFeature: onEachFeature,
                  pointToLayer: function (feature, latlng) {
                    return L.marker(latlng);
                  },
                });
                geojsonLayer_group.on('data:loaded', function () {
                  var subgroup = L.featureGroup.subGroup(markers).addTo(map);
                  geojsonLayer_group.eachLayer(function (layer) {
                    console.log(layer);
                    layer.addTo(subgroup);
                  });
                  console.log(key);
                  console.log(subgroup);
                  controlLayers.addOverlay(subgroup, key);
                  //markers.addLayer(geojsonLayer_group);
                });
              }});
            }

            geojsonLayer.on('data:loaded', function () {
              markers.addLayer(geojsonLayer);
              if (geojsonLayer.getLayers().length > 1) {
                map.addLayer(markers).fitBounds(markers.getBounds());
              }
              else {
                map.addLayer(markers).setView(markers.getBounds().getCenter(), $initialzoom);
              }
            });


            map.on('layeradd', function (e) {
              e.layer.on('data:loaded',  function () {
                console.log('All GeoJSONs are loaded') }, this);
            });

            var $firstgeojson = [drupalSettings.format_strawberryfield_views.leaflet[element_id]['geojsonurl']];
            var $allgeojsons = $firstgeojson.concat(drupalSettings.format_strawberryfield_views.leaflet[element_id]['geojsonother']);
            var $secondgeojson = drupalSettings.format_strawberryfield_views.leaflet[element_id]['geojsonother'].find(x=>x!==undefined);

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
            console.log('initializing leaflet 1.6.0')
          }
        })},
    detach: function(content, settings, trigger) {
      console.log(trigger);
      console.log(settings);
      console.log(content);
      if (trigger === 'unload') {
        $('.strawberry-leaflet-views-item[data-iiif-infojson]').each(function (index, value) {
          // Get the node uuid for this element
          var element_id = $(this).attr("id");
          // Check if we got some data passed via Drupal settings.
          //drupalSettings.format_strawberryfield_views.leaflet[element_id]['geojsonurls'] = [];
          console.log(settings.format_strawberryfield_views);
          console.log(element_id);
        });
      }}
  }
})(jQuery, Drupal, drupalSettings, window.L);
