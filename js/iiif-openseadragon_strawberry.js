(function ($, Drupal,  OpenSeadragonAnnotorious, drupalSettings, Leaflet) {

  'use strict';

  var timers = {};
  var classificationQueue = [];
  var classifiedImages = [];

  const create_UUID = function() {
    var dt = new Date().getTime();
    var uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
      var r = (dt + Math.random()*16)%16 | 0;
      dt = Math.floor(dt/16);
      return (c=='x' ? r :(r&0x3|0x8)).toString(16);
    });
    return uuid;
  }

  var ThreeWaySwitchElement = function(id, opencv_enabled) {
    // 3. Triggers callbacks on user action
    var setOpenCV = function(evt) {
      // annotorious will be here already.
      $(evt.target.parentElement).find('> button').each(function () {
        $(this).removeClass('active');
      });

      if (annotorious[evt.target.getAttribute('data-annotorious-id')]._env.hasOwnProperty('openCV')) {
        if (annotorious[evt.target.getAttribute('data-annotorious-id')]._env.openCV == evt.target.name) {
          annotorious[evt.target.getAttribute('data-annotorious-id')]._env.openCV = false;
        } else {
          annotorious[evt.target.getAttribute('data-annotorious-id')]._env.openCV = evt.target.name;
          $(evt.target).addClass('active');
        }
      }
      else {
        annotorious[evt.target.getAttribute('data-annotorious-id')]._env.openCV = evt.target.name;
        $(evt.target).addClass('active');
      }
    }


    const container = document.createElement('div');
    container.style = "display:inline-flex";
    const toolbar = document.createElement('div');
    toolbar.setAttribute('id', id+ '-annon-toolbar');
    container.appendChild(toolbar);
    if (opencv_enabled) {
      const input1 = document.createElement('button');
      input1.setAttribute("name","face");
      input1.setAttribute("data-annotorious-id",id);
      const input2 = input1.cloneNode(true);
      const input3 = input1.cloneNode(true);
      input2.setAttribute("name","contour");
      input3.setAttribute("name","contour_adapt");
      input1.setAttribute("value","OpenCV Face Detect");
      input1.setAttribute("id",id + '_face');
      input2.setAttribute("value","OpenCV Countour");
      input2.setAttribute("id",id + '_countour');
      input3.setAttribute("value","OpenCV Countour 2");
      input3.setAttribute("id", id + '_countour_adapt');

      input1.classList.add('a9s-toolbar-btn','opencv-face');
      input2.classList.add('a9s-toolbar-btn','opencv-contour-light');
      input3.classList.add('a9s-toolbar-btn','opencv-contour-avg');
      input1.addEventListener('click', setOpenCV);
      input2.addEventListener('click', setOpenCV);
      input3.addEventListener('click', setOpenCV);
      container.appendChild(input1);
      container.appendChild(input2);
      container.appendChild(input3);
    }

    return container;
  }


  var ColorSelectorWidget = function(args) {

    // 1. Find a current color setting in the annotation, if any
    var currentColorBody = args.annotation ?
      args.annotation.bodies.find(function(b) {
        return b.purpose == 'highlighting';
      }) : null;

    // 2. Keep the value in a variable
    var currentColorValue = currentColorBody ? currentColorBody.value : null;

    // 3. Triggers callbacks on user action
    var addTag = function(evt) {
      if (currentColorBody) {
        args.onUpdateBody(currentColorBody, {
          type: 'TextualBody',
          purpose: 'highlighting',
          value: evt.target.dataset.tag
        });
      } else {
        args.onAppendBody({
          type: 'TextualBody',
          purpose: 'highlighting',
          value: evt.target.dataset.tag
        });
      }
    }

    var createButton = function(value) {
      var button = document.createElement('button');

      if (value == currentColorValue)
        button.className = 'selected';

      button.dataset.tag = value;
      button.style.backgroundColor = value;
      button.addEventListener('click', addTag);
      return button;
    }

    var container = document.createElement('div');
    container.className = 'colorselector-widget';
    var button1 = createButton('RED');
    var button2 = createButton('GREEN');
    var button3 = createButton('BLUE');

    container.appendChild(button1);
    container.appendChild(button2);
    container.appendChild(button3);

    return container;
  }


  var GeoMappingSelectorWidget = function(args) {
    // 1. Find a current color setting in the annotation, if any
    var currentGeoReferenceBody = args.annotation ?
      args.annotation.bodies.find(function(b) {
        return b.purpose == 'georeferencing' && b.type== 'FeatureCollection';
      }) : null;

    // 2. Keep the value in a variable
    var currentGeoReferenceBody = currentGeoReferenceBody ? currentGeoReferenceBody.features: null;

    // 3. Triggers callbacks on user action
    var addGeoTag = function(evt) {
      // Process from dataset pro the proper georeference IIIF structure
      /*
      {
        "type": "Feature",
        "properties": {
        "resourceCoords": [5085, 782]
      },
        "geometry": {
        "type": "Point",
          "coordinates": [4.4885839, 51.9101828]
      }
      }
      */
      const features = JSON.parse(evt.target.dataset.feature)
      const sourcecoords = JSON.parse(evt.target.dataset.sourcecoords)
      let feature_collection = [];
      if (Array.isArray(features)) {
        feature_collection = features.map((value, key ) => {
          return (
            {
              type: "Feature",
              properties: {
                resourceCoords: sourcecoords[key]
              },
              geometry: {
                type: "Point",
                coordinates: value
              }
            }
          );
        }
      );
      }
      if (currentGeoReferenceBody) {
        args.onSetProperty("motivation", "georeferencing");
        args.onUpdateBody(currentGeoReferenceBody, {
          type: 'FeatureCollection',
          purpose: 'georeferencing',
          features: feature_collection,
        });
      } else {
        args.onSetProperty("motivation", "georeferencing");
        args.onAppendBody({
          type: 'FeatureCollection',
          purpose: 'georeferencing',
          features: feature_collection,
        });
      }
    }

    var createGeoButton = function(value) {
      var button = document.createElement('button');
      button.innerHTML = "Save Feature";
      button.addEventListener('click', addGeoTag);
      return button;
    }

    var showMap = function(evt) {

      /* i need to transform xywh=pixel:217.31248474121094,240.13888549804688,2412.823989868164,1761.0184631347656
      into a valid IIIF Image URL.
      @TODO make this an extra argument of L.ImageOverlay.iiifBounded and let that function deal with it?
       "type": "SvgSelector", type can be an SvgSelector or a Fragment. Fragment allows me to fetch the portion of the image directly
       but the SvgSelector needs to be decompositioned in parts and we need to get the max.x,max,y, min.x and min.y to call The IIIF API Image endpoint
       "value": "<svg><polygon points=\"443.15740966796875,1675.254638671875 271.629638671875,1377.9398193359375 431.72222900390625,806.1805419921875 654.7083129882812,800.4629516601562 683.2962646484375,1480.8564453125 534.6388549804688,1823.9119873046875 528.9212646484375,1795.3240966796875\"><\/polygon><\/svg>"
      Also all the IIIF target parsing could be a separate reusable function!
      Note we are not using the "type" here not bc i'm lazy but bc
      we are moving data around in Dom Documents dataset properties. So we parse the string
       */

      const IIIFragment = evt.target.dataset.bound.split("=");
      if (IIIFragment.length == 0) {
        return;
      }
      let iiif_region = null;
      let clip_path = [];
      let iiif_geoextension_sourcecoords = [];
      // For our not fancy mesh deforming reality this is always so
      iiif_geoextension_sourcecoords.push([0,0]);
      let clip_path_string = null;
      if (IIIFragment[0] === "xywh") {
        const IIIFragmentCoords = IIIFragment[1].split(":");
        console.log()
        // @TODO what if using %percentage here?
        const IIIFragmentCoordsIndividual = IIIFragmentCoords[1].split(",");
        const iiif_coord_lx = Math.round(IIIFragmentCoordsIndividual[0]);
        const iiif_coord_ly = Math.round(IIIFragmentCoordsIndividual[1]);
        const iiif_coord_rx = Math.round(IIIFragmentCoordsIndividual[2]);
        const iiif_coord_ry = Math.round(IIIFragmentCoordsIndividual[3]);
        iiif_region = iiif_coord_lx + "," + iiif_coord_ly + "," + iiif_coord_rx + "," + iiif_coord_ry;
        iiif_geoextension_sourcecoords.push([Math.floor(iiif_coord_rx - iiif_coord_lx),0]);
        iiif_geoextension_sourcecoords.push([Math.floor(iiif_coord_rx - iiif_coord_lx), Math.floor(iiif_coord_ry - iiif_coord_ly)]);
        iiif_geoextension_sourcecoords.push([0, Math.floor(iiif_coord_ry - iiif_coord_ly)]);
      }
      else if (IIIFragment[0] == "<svg><polygon points") {
        try {
          let svgElement = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
          let svgElementNoNamespace = new DOMParser().parseFromString(evt.target.dataset.bound, 'text/xml').documentElement;
          const points = svgElementNoNamespace.firstChild.getAttribute('points');
          let polygon = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
          polygon.setAttribute("points", points);
          svgElement.appendChild(polygon);
          const toRemove = evt.target.insertAdjacentElement("beforeend", svgElement);
          const bounds = toRemove.firstChild.getBBox();
          toRemove?.remove();
          iiif_region = Math.floor(bounds.x) + "," + Math.floor(bounds.y) + "," + Math.floor(bounds.width) + "," + Math.floor(bounds.height);
          iiif_geoextension_sourcecoords.push([Math.floor(bounds.width),0]);
          iiif_geoextension_sourcecoords.push([Math.floor(bounds.width), Math.floor(bounds.height)]);
          iiif_geoextension_sourcecoords.push([0, Math.floor(bounds.height)]);
          const allpoints = polygon.points;
          for (let step = 0; step < allpoints.length; step++) {
            let point = allpoints.getItem(step);
            let percentx = ((point.x-bounds.x)/bounds.width) * 100;
            let percenty = ((point.y-bounds.y)/bounds.height) * 100
            if (percentx !== 0) { percentx = percentx + "%"}
            if (percenty !== 0) { percenty = percenty + "%"}
            clip_path.push(percentx + " " + percenty);
          };
          if (clip_path.length) {
            clip_path_string = "polygon(" + clip_path.join(",") + ")";
          }
        }
        catch (error) {
          alert('Sorry, we could not process your Annotation into valid region for Geographic Annotations');
          return;
        }
      }
      else {
        alert('Sorry, we do not support yet this type of IIIF selector as target yet');
        return;
      }
      if (!iiif_region) {
        alert('Sorry, no IIIF Image API region could be computed');
        return;
      }
      // We put the source coords into the dataset prop of the element;

      // This where i should scale instead of full, request the proper Zoom/level pixel size.
      const iiif_image_url = evt.target.dataset.source + "/" + iiif_region + "/full/0/default.jpg";
      let container = document.getElementById('AnnotoriousGeoMapWidget');
      var button_add_geo = createGeoButton();
      button_add_geo.dataset.sourcecoords = JSON.stringify(iiif_geoextension_sourcecoords);
      container.appendChild(button_add_geo);
      container.style.cssText = 'width:100%;height:250px;';
      let mapcontainer = document.createElement('div');
      mapcontainer.id = "geoTag";
      mapcontainer.style.cssText = 'width:100%;height:250px;';
      container.appendChild(mapcontainer);
      /* This will throw and error if initialized twice, Diego find a solution */
      let map = Leaflet.map(mapcontainer.id).setView([40.1, -100], 1);
      const $tilemap = {
        url:'https://a.tile.openstreetmap.org/{z}/{x}/{y}.png',
        attribution: '&copy; <a href="https://openstreetmap.org/copyright">OpenStreetMap contributors</a>'
      }
      const $minzoom = 1;
      const $maxzoom = 15;
      Leaflet.tileLayer($tilemap.url,
        {
          attribution: $tilemap.attribution,
          maxZoom: $maxzoom,
          minZoom: $minzoom
        }).addTo(map);
      map.on('zoomend', function(){
        console.log(map.getPixelWorldBounds());
        console.log(map.latLngToContainerPoint(new Leaflet.LatLng(41,-66)));
      });

      var imageOverlay = new Leaflet.ImageOverlay.iiifBounded(iiif_image_url, [41,-70], [41,-66], [39,-66], [39,-70], {
        opacity: 0.4,
        interactive: true,
        clip_path: clip_path_string,
      });
      var markerLT = new Leaflet.marker(new Leaflet.LatLng(41,-70), {draggable:'true'});
      var markerRT = new Leaflet.marker(new Leaflet.LatLng(41,-66), {draggable:'true'});
      var markerRB = new Leaflet.marker(new Leaflet.LatLng(39,-66), {draggable:'true'});
      var markerLB = new Leaflet.marker(new Leaflet.LatLng(39,-70), {draggable:'true'});

      const dragMarker = function(event) {
        var marker = event.target;
        var position = marker.getLatLng();
        marker.setLatLng(new Leaflet.LatLng(position.lat, position.lng),{draggable:'true'});
        imageOverlay.reposition(markerLT.getLatLng(), markerRT.getLatLng(), markerRB.getLatLng(), markerLB.getLatLng());
        map.panTo(new Leaflet.LatLng(position.lat, position.lng))
        /* Note to myself. This is hard. Every time a new IIIF spec comes out i see myself parsing extra JSON
        just to keep the specs gods pleased see https://iiif.io/api/extension/georef/?mc_cid=46c37da63d&mc_eid=f820ccac92#35-the-resourcecoords-property
        in our case resourceCoords are the relative tl, tr, rb, lb pixel coordinates (starting from 0,0) of a IIIF resource.
        {
        "type": "Feature",
        "properties": {
          "resourceCoords": [5085, 782]
        },
        "geometry": {
          "type": "Point",
           "coordinates": [4.4885839, 51.9101828]
        }
        }
         */
        // We put the geo coords into the dataset prop of the element;
        button_add_geo.dataset.feature = JSON.stringify([
          [markerLT.getLatLng().lng,markerLT.getLatLng().lat],
          [markerRT.getLatLng().lng,markerRT.getLatLng().lat],
          [markerRB.getLatLng().lng,markerRB.getLatLng().lat],
          [markerLB.getLatLng().lng,markerLB.getLatLng().lat]
        ]);
      };

      /* we will create for dragable markers */

      markerLT.on('dragend', dragMarker);
      markerRT.on('dragend', dragMarker);
      markerRB.on('dragend', dragMarker);
      markerLB.on('dragend', dragMarker);
      var positionGroup = Leaflet.layerGroup([markerLT, markerRT, markerRB, markerLB]);
      map.addLayer(imageOverlay);
      map.addLayer(positionGroup);
    }


    var createButtonShowMap = function(args) {
      const button = document.createElement('button');
      button.innerHTML = "Position Annotation Target on Map";
      let source = args.annotation.underlying.target.source;
      let bound = args.annotation.underlying.target.selector.value;
      button.dataset.source = source;
      button.dataset.bound = bound;
      button.dataset.feature = currentGeoReferenceBody;
      button.addEventListener('click', showMap);
      return button;
    }

    var container = document.createElement('div');

    container.id = 'AnnotoriousGeoMapWidget';

    container.className = 'geomapping-widget';
    var button_show = createButtonShowMap(args);
    container.appendChild(button_show);
    return container;
  }


  /**
   * Init required object for classification when worker is ready.
   */
  var onWorkerReady = function () {
    timers.start_classify_image = new Date();
    console.log('Classification is started.');
  };


  var onWorkerMessage = function (event) {
    // Helper: creates a dummy polygon annotation from the given coords
    const toAnnotation = (coords, tag =  "OpenCV") => ({
      "@context": "http://www.w3.org/ns/anno.jsonld" ,
      "id": "#" + create_UUID(),
      "type": "Annotation",
      "body": [
        {
          "type": "TextualBody",
          "value": tag,
          "created": new Date().toString(),
          "creator": {
            "name": "openCV"
          },
          "purpose": "tagging",
          "modified": new Date().toString()
        }
      ],
      "target": {
        "selector": [{
          "type": "SvgSelector",
          "value": `<svg><polygon points='${coords.map(xy => xy.join(',')).join(' ')}'></polygon></svg>`
        }]
      }
    });

    switch (event.data.type) {
      case 'debug':
        console.log(event.data.msg + event.data.source);
        break;

      case 'init':
        console.log('CV Worker is initialized');
        worker.postMessage({
          type: 'load',
        });
        break;

      case 'ready':
        console.log('CV Worker is ready. Feed it');
        onWorkerReady();
        break;

      case 'face_done':
        var classification_time = (new Date()) - timers.start_classify_image;
        console.log('Got classifications: ' + classification_time);
        console.log(event.data.classifications);
        // Current image zoom from OSD
        let imageZoom2 = viewers[event.data.annotorious_id].viewport.viewportToImageZoom(viewers[event.data.annotorious_id].viewport.getZoom());

        // Translate to image coordinate space
        let [x2, y2, kx2, ky2] = event.data.original_coordinates;
        let faces = [];
        let coords2 = event.data.classifications.faces.forEach((face) => {
          faces.push(face.map(xy => {
            const px = x2 + (xy[0] / kx2) / imageZoom2;
            const py = y2 + (xy[1] / ky2) / imageZoom2;
            return [ px, py ];
          }));
        });

        // Turn coords to W3C WebAnnotation
        faces.forEach((face) => {
          let annotation = toAnnotation(face, 'OpenCV Face Detected');

          // Add the new annotation in Annotorious without selection
          setTimeout(function () {
            annotorious[event.data.annotorious_id]._emitter.emit('createAnnotation', annotation);
            annotorious[event.data.annotorious_id].addAnnotation(annotation);

          }, 10);
        });
        break;

      case 'contour_done':
        var classification_time = (new Date()) - timers.start_classify_image;
        console.log('Got Contour classifications: ' + classification_time);
        console.log(event.data.classifications);
        // Current image zoom from OSD
        let imageZoom = viewers[event.data.annotorious_id].viewport.viewportToImageZoom(viewers[event.data.annotorious_id].viewport.getZoom());

        // Translate to image coordinate space
        let [x, y, kx, ky] = event.data.original_coordinates;
        let coords = event.data.classifications['contour'].map(xy => {
          let px = x + (xy[0] / kx) / imageZoom;
          let py = y + (xy[1] / ky) / imageZoom;
          return [ px, py ];
        });

        // Turn coords to W3C WebAnnotation
        let annotation = toAnnotation(coords, 'OpenCV Countour');

        // Add the new annotation in Annotorious and select it
        setTimeout(function() {
          annotorious[event.data.annotorious_id]._emitter.emit('createAnnotation', annotation);
          annotorious[event.data.annotorious_id].addAnnotation(annotation);
          annotorious[event.data.annotorious_id].selectAnnotation(annotation);

        }, 10);
        break;
    }
  };
  var worker = null;
  var annotorious = [];
  var viewers = [];

  Drupal.behaviors.format_strawberryfield_openseadragon_initiate = {
    attach: function (context, settings) {
      var current_openseadragon_tile = [];
      var groupsinfojsons =  {};
      var groupssettings = {};
      var groupsid =  {};
      var showthumbs = false
      var nodeuuid = null;
      var annotorious_annotations = [];
      var annotorious_current_tile = [];
      var current_user = null;

      // Create worker process and register event listener.
      var workerUrl = '/' + drupalSettings.format_strawberryfield.path + '/js/worker/opencv-worker.js';
      timers.worker_init = new Date();
      worker = new Worker(workerUrl);
      worker.onmessage = onWorkerMessage;

      $('.strawberry-media-item[data-iiif-infojson]').once('attache_osd')
        .each(function (index, value) {
          // Get the node uuid for this element
          var element_id = $(this).attr("id");
          var default_width = drupalSettings.format_strawberryfield.openseadragon[element_id]['width'];
          var default_height = drupalSettings.format_strawberryfield.openseadragon[element_id]['height'];
          var icons_prefixurl = drupalSettings.format_strawberryfield.openseadragon[element_id]['icons_prefixurl'];
          var annotations = drupalSettings.format_strawberryfield.openseadragon[element_id]['webannotations'];
          var annotations_tool = drupalSettings.format_strawberryfield.openseadragon[element_id]['webannotations_tool'];
          var annotations_opencv = drupalSettings.format_strawberryfield.openseadragon[element_id]['webannotations_opencv'];
          var annotations_betterpolygon = drupalSettings.format_strawberryfield.openseadragon[element_id]['webannotations_betterpolygon'];
          var file_uuid = drupalSettings.format_strawberryfield.openseadragon[element_id]['dr:uuid'];
          var keystoreid = drupalSettings.format_strawberryfield.openseadragon[element_id]['keystoreid'];
          current_user = drupalSettings.format_strawberryfield.openseadragon[element_id]['user'];
          var group = $(this).data("iiif-group");
          var infojson = $(this).data("iiif-infojson");
          var showthumbs = $(this).data("iiif-thumbnails");
          if (!groupsinfojsons.hasOwnProperty(group)) {
            groupsinfojsons[group] = [infojson];

            if (typeof icons_prefixurl == "undefined" || icons_prefixurl == "") {
              icons_prefixurl = "https://cdn.jsdelivr.net/npm/openseadragon@2.4.2/build/openseadragon/images/";
            }

            groupssettings[group] = {
              "default_width": default_width,
              "default_height": default_height,
              "webannotations" : false,
              "annotations_tool": annotations_tool,
              "annotations_opencv": false,
              "annotations_betterpolygon": false,
              "nodeuuid" : settings.format_strawberryfield.openseadragon.innode[element_id],
              "file_uuid" : file_uuid,
              "keystoreid" : keystoreid,
              "showthumbs": showthumbs,
              "icons_prefixurl" : icons_prefixurl,
            }

            if (typeof annotations != "undefined" && annotations == true) {
              groupssettings[group].webannotations = true;
            }
            if (typeof annotations_opencv != "undefined" && annotations_opencv == true) {
              groupssettings[group].annotations_opencv = true;
            }
            if (typeof annotations_betterpolygon != "undefined" && annotations_betterpolygon == true) {
              groupssettings[group].annotations_betterpolygon = true;
            }

            // We only need a single css id per group
            groupsid[group] = element_id;

            $(this).height(default_height);
            $(this).css("width",default_width);
          }
          else {
            groupsinfojsons[group].push(infojson);
            // hide other strawberry-media-items
            $(this).height(0);
            $(this).width(0);
          }
        });

      $.each(groupsid, function (group, element_id)  {

        var tiles = groupsinfojsons[group];
        var sequence = false;
        var thumbs = false
        if (tiles.length > 1) {
          sequence = true;
          thumbs = groupssettings[group].showthumbs;
        }

        if (tiles.length == 0) return false;

        current_openseadragon_tile[element_id] = tiles[0];

        viewers[element_id] = OpenSeadragon({
          showRotationControl: true,
          gestureSettingsTouch: {
            pinchRotate: true
          },
          debugMode: false,
          preserveViewport: true,
          id: element_id,
          sequenceMode: sequence,
          prefixUrl: groupssettings[group].icons_prefixurl,
          tileSources: tiles,
          showNavigator: true,
          navigatorAutoFade:  true,
          crossOriginPolicy: 'Anonymous',
          ajaxWithCredentials: false,
          showReferenceStrip: thumbs,
          referenceStripScroll: 'horizontal',
        });

        if (typeof groupssettings[group].webannotations != "undefined" && groupssettings[group].webannotations == true) {
          console.log("Attaching W3C Annotations");
          var $readonly = true;
          if (settings.user.uid != 0) {
            $readonly = false;
          }
          const $anonconfig = {
            "readOnly":$readonly,
            "widgets": [
              ColorSelectorWidget,
              GeoMappingSelectorWidget,
              'COMMENT',
              'TAG'
            ],
          }
          // terminate the worker if the user can not add annotations
          if ($readonly || !groupssettings[group].annotations_opencv) { worker.terminate() };

          annotorious[element_id] = window.OpenSeadragon.Annotorious(viewers[element_id], $anonconfig);
          if (groupssettings[group].annotations_tool == 'both') {
            annotorious[element_id].setDrawingTool('rect');
          }
          else {
            annotorious[element_id].setDrawingTool(groupssettings[group].annotations_tool);
          }
          if ((groupssettings[group].annotations_tool == 'both' || groupssettings[group].annotations_tool == 'polygon') && groupssettings[group].annotations_betterpolygon) {
            window.Annotorious.BetterPolygon(annotorious[element_id]);
          }

          annotorious_annotations[element_id] = [];
          annotorious_current_tile[element_id] = 0;

          /**
           * Cuts the selected image snippet from the OpenSeadragon CANVAS element.
           */
          const getCanvasSnippet = (viewer, annotation) => {
            // Scale factor for OSD canvas element (physical vs. logical resolution)
            const { canvas } = viewer.drawer;
            const canvasBounds = canvas.getBoundingClientRect();
            const kx = canvas.width / canvasBounds.width;
            const ky = canvas.height / canvasBounds.height;
            var bottomRight = null;
            var topLeft = null;
            let xi = 0;
            let wi = 0;
            let hi = 0;
            let yi = 0;
            let xii = 0;
            let yii = 0;

            // Check if we are in the presence of a polygon first
            if (annotation.target.selector.value.indexOf("<svg><polygon points") !== -1) {
              let string_coords = annotation.target.selector.value.replace('<svg><polygon points=\"','');
              string_coords =  string_coords.replace('\"></polygon></svg>','');
              let coords = string_coords
                .split(/[\s,]+/)
                .map(str => parseFloat(str));
              // In case some strange stuff happened and we are seeing NaN
              coords.filter(Boolean);
              for(var i = 0, l = coords.length; i < l; i += 2) {
                // Stupid algorithm but will do the job
                // Take the min x, the min y, the max x, the max y
                // Generate a square
                if (coords[i] > xii) {
                  xii = coords[i];
                }
                if (coords[i+1] > yii) {
                  yii = coords[i+1];
                }
                if (coords[i] < xi || xi == 0) {
                  xi = coords[i];
                }
                if (coords[i+1] < yi || yi == 0) {
                  yi = coords[i+1];
                }
              }
              bottomRight =  viewer.viewport.imageToViewerElementCoordinates(new OpenSeadragon.Point(xii, yii));
              topLeft = viewer.viewport.imageToViewerElementCoordinates(new OpenSeadragon.Point(xi, yi));
            }
            else {
              // Parse fragment selector (image coordinates)
              [xi, yi, wi, hi] = annotation.target.selector.value
                .split(':')[1]
                .split(',')
                .map(str => parseFloat(str));
              bottomRight =  viewer.viewport.imageToViewerElementCoordinates(new OpenSeadragon.Point(xi + wi, yi + hi));
              topLeft = viewer.viewport.imageToViewerElementCoordinates(new OpenSeadragon.Point(xi, yi));
            }

            // Convert image coordinates (=annotation) to viewport coordinates (=OpenSeadragon canvas)

            const { x, y } = topLeft;
            const w = bottomRight.x - x;
            const h = bottomRight.y - y;

            // Cut out the image snippet as in-memory canvas element
            const snippet = document.createElement('CANVAS');
            const ctx = snippet.getContext('2d');
            snippet.width = w;
            snippet.height = h;
            ctx.drawImage(canvas, x * kx, y * ky, w * kx, h * ky, 0, 0, w * kx, h * ky);
            // Return snippet canvas + basic properties useful for downstream coord translation
            const imageMem = ctx.getImageData(0, 0, w * kx, h * ky);
            return { imageMem , snippet, kx, ky, x: xi, y: yi };
          }

          viewers[element_id].world.addHandler('add-item', function(addItemEvent) {
            var tiledImage = addItemEvent.item;
            tiledImage.addHandler('fully-loaded-change', function(fullyLoadedChangeEvent) {
              console.log('fully loaded Canvas', fullyLoadedChangeEvent.fullyLoaded);
              /*var canvas = fullyLoadedChangeEvent.eventSource.viewer.drawer.canvas;
              var ctx = canvas.getContext('2d');
              const canvas2 = document.getElementById('testcanvas');
              const ctx2 = canvas2.getContext('2d');
              ctx2.putImageData(ctx.getImageData(0, 0, canvas.width, canvas.height), 0, 0);
              worker.postMessage({
                type: 'execute',
                image_data: ctx.getImageData(0, 0, canvas.width, canvas.height)
              });*/
            });
          });

          // We always start with the first Sequence (0)

          jQuery.ajax({
            url: '/do/'+ groupssettings[group].nodeuuid + '/webannon/read',
            type: "GET",
            dataType: 'json',
            element_id: element_id,
            data: {
              'target_resource': current_openseadragon_tile[element_id],
              'keystoreid': groupssettings[group].keystoreid,
            },
            success:  function(pagedata){
              console.log('Webannotations Loaded form Source');
              annotorious[this.element_id].setAnnotations(pagedata);
              annotorious_annotations[this.element_id] = [pagedata];
              console.log(annotorious_annotations[this.element_id]);
            }
          });

          if (settings.user.uid > 0) {
            annotorious[element_id].setAuthInfo({
              id: current_user['url'],
              displayName: current_user['name'],
            });

            let toggle = ThreeWaySwitchElement(element_id, groupssettings[group].annotations_opencv);
            // #toolbar-'+ element_id is passed as a div at the same level of the OSD viewer by
            // \Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryMediaFormatter::generateElementForItem
            $('#toolbar-' + element_id).prepend(toggle);
            if (groupssettings[group].annotations_tool == 'both') {
              window.Annotorious.Toolbar(annotorious[element_id], document.getElementById(element_id + '-annon-toolbar'));
            }
          }
          /* Acts on page change. We need to load new annotations when that happens! */
          viewers[element_id].addHandler("page", function (data) {
            current_openseadragon_tile[element_id] = tiles[data.page];
            console.log('previous page was'+ annotorious_current_tile[element_id]);
            // This stores current page before the actual change happens.
            annotorious_annotations[element_id][annotorious_current_tile[element_id]] = annotorious[element_id].getAnnotations();

            // Now set the current tile
            annotorious_current_tile[element_id] = data.page;
            if (typeof annotorious_annotations[element_id][data.page] == "undefined") {
              annotorious[element_id].setAnnotations([]);
              console.log('Reading annotations for sequence ' + data.page + ' from Live API data');
              jQuery.ajax({
                url: '/do/' + groupssettings[group].nodeuuid + '/webannon/read',
                type: "GET",
                page: data.page,
                element_id: element_id,
                dataType: 'json',
                data: {
                  'target_resource': current_openseadragon_tile[element_id],
                  'keystoreid': groupssettings[group].keystoreid,
                },
                success: function (pagedata) {
                  annotorious[this.element_id].setAnnotations(pagedata);
                  console.log(this.page);
                  annotorious_annotations[this.element_id][this.page] = pagedata;
                }
              });
            }
            else {
              // Reads from local copy
              console.log('Reading annotations for sequence ' + data.page + ' from cached data');
              annotorious[element_id].setAnnotations(annotorious_annotations[element_id][data.page]);
            }
          });

          // CV driven Create Selection
          // Will act depending on what mode is selected
          // Messaging the Worker
          annotorious[element_id].on('createSelection', async function(selection) {
            if ($readonly) { return; };
            // Extract the image snippet, recording
            // - image snippet (as canvas element)
            // - x/y coordinate of the snippet top-left (image coordinate space)
            // - kx/ky scale factors between canvas element physical and logical dimensions
            // Polygon coordinates, in the snippet element's logical coordinate space
            if (annotorious[element_id]._env.hasOwnProperty('openCV')) {
              const { imageMem, snippet, x, y, kx, ky } = getCanvasSnippet(viewers[element_id], selection);
              // Current image zoom from OSD
              const imageZoom = viewers[element_id].viewport.viewportToImageZoom(viewers[element_id].viewport.getZoom());
              if (annotorious[element_id]._env.openCV == 'face') {
                worker.postMessage({
                  type: 'execute_face',
                  image_data: imageMem,
                  annotorious_id: element_id,
                  original_coordinates: [x, y, kx, ky]
                });
              }
              else if (annotorious[element_id]._env.openCV == 'contour') {
                worker.postMessage({
                  type: 'execute_contour',
                  image_data: imageMem,
                  annotorious_id: element_id,
                  original_coordinates: [x, y, kx, ky]
                });
              }
              else if (annotorious[element_id]._env.openCV == 'contour_adapt') {
                worker.postMessage({
                  type: 'execute_contour_adapt',
                  image_data: imageMem,
                  annotorious_id: element_id,
                  original_coordinates: [x, y, kx, ky]
                });
              }
            }
          });

          // Attach handlers to listen to events
          annotorious[element_id].on('createAnnotation', function(a) {
            console.log(a);
            jQuery.ajax({
              url: '/do/'+ groupssettings[group].nodeuuid + '/webannon/post',
              type: "POST",
              dataType: 'json',
              data: {
                'data': a,
                'target_resource': current_openseadragon_tile[element_id],
                'keystoreid': groupssettings[group].keystoreid,
              },
              success:  function(data){
                console.log('Created');
                console.log(data);
              }
            });

            console.log(annotorious[element_id].getAnnotations());
          });

          // Attach handlers to listen to events
          annotorious[element_id].on('updateAnnotation', function(a,previous) {
            console.log('new');
            console.log(a);
            console.log('prev');
            console.log(previous);
            jQuery.ajax({
              url: '/do/'+ groupssettings[group].nodeuuid + '/webannon/put',
              type: "PUT",
              dataType: 'json',
              data: {
                'data': a,
                'target_resource': current_openseadragon_tile[element_id],
                'keystoreid': groupssettings[group].keystoreid,
              },
              success:  function(data){
                console.log('Updated');
                console.log(data);
              }
            });
            console.log(annotorious[element_id].getAnnotations());
          });

          // Attach handlers to listen to events
          annotorious[element_id].on('deleteAnnotation', function(a) {
            jQuery.ajax({
              url: '/do/'+ groupssettings[group].nodeuuid + '/webannon/delete',
              type: "DELETE",
              dataType: 'json',
              data: {
                'data': a,
                'target_resource': current_openseadragon_tile[element_id],
                'keystoreid': groupssettings[group].keystoreid,
              },
              success:  function(data){
                console.log('Deleted');
                console.log(data);
              }
            });
            console.log(annotorious[element_id].getAnnotations());
          });
        }
      });
    }
  };
})(jQuery, Drupal, window.OpenSeadragon.Annotorious, drupalSettings, window.L);
