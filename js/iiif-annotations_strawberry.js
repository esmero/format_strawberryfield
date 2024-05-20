(function ($, Drupal,  once, Annotorious) {

  'use strict';

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
    evt.target.disabled = true;
    evt.target.className ='r6o-btn outline';
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
    button_add_geo.className = 'r6o-btn';
    button_add_geo.dataset.sourcecoords = JSON.stringify(iiif_geoextension_sourcecoords);
    container.appendChild(button_add_geo);
    container.style.cssText = 'width:100%;height:450px;';
    let mapcontainer = document.createElement('div');
    mapcontainer.id = "geoTag";
    mapcontainer.style.cssText = 'width:100%;height:410px;';
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

    let allmarkers = [];
    allmarkers.push(new Leaflet.marker(new Leaflet.LatLng(41,-70), {draggable:'true'}));
    allmarkers.push(new Leaflet.marker(new Leaflet.LatLng(41,-66), {draggable:'true'}));
    allmarkers.push(new Leaflet.marker(new Leaflet.LatLng(39,-66), {draggable:'true'}));
    allmarkers.push(new Leaflet.marker(new Leaflet.LatLng(39,-70), {draggable:'true'}));
  }



  var annotorious = [];
  var viewers = [];

  Drupal.behaviors.format_strawberryfield_annotations_initiate = {
    attach: function (context, settings) {
      var annotorious_annotations = [];
      var groupssettings = {};
      // Only attach to images that have an ID and a not empty data-sbf-annotations-nodeuuid porperty
      const elementsToAttach = once('attache_annotations', 'img[data-sbf-annotations-nodeuuid][id]:not([data-sbf-annotations-nodeuuid=""])', context);
      $(elementsToAttach).each(function (index, value) {
        // Get the node uuid for this element
        let element_id = $(this).attr("id");
        let node_uuid = $(this).data("sbf-annotations-nodeuuid");
        let file_uuid = $(this).data("sbf-annotations-fileuuid");
        let processors = $(this).data("sbf-annotations-processors");
        if (typeof processors !== "undefined") {
          groupssettings[element_id] = {
            "webannotations" : false,
            "nodeuuid" : node_uuid,
            "file_uuid" : file_uuid,
            "processors" : processors
          }
        }
      });
      $.each(groupssettings, function (element_id, groupssetting)  {
        function loadFirstAnnotationOfGroup(element_id) {
          jQuery.ajax({
            url: '/do/' + groupssetting.nodeuuid + '/webannon/readsbf',
            type: "GET",
            dataType: 'json',
            element_id: element_id,
            data: {
              'target_resource_uuid': groupssetting.file_uuid,
              'processors': groupssetting.processors,
            },
            success: function (pagedata) {
              annotorious[this.element_id].setAnnotations(pagedata);
              annotorious_annotations[this.element_id] = [pagedata];
            },
            error: function (xhr, ajaxOptions, thrownError) {
              console.log(xhr.status);
            }
          });
        }

        console.log("Attaching W3C Annotations from Flavors");
        var $readonly = true;
        let $widgets = [
        ];
        const $anonconfig = {
          "readOnly":$readonly,
          "widgets": $widgets,
          "image" : document.getElementById(element_id),
        }

        annotorious[element_id] = Annotorious.init($anonconfig);
        annotorious_annotations[element_id] = [];
        loadFirstAnnotationOfGroup(element_id);
        let toggle = ThreeWaySwitchElement(element_id, false);
        $('#toolbar-' + element_id).prepend(toggle);
        annotorious[element_id].on('createSelection', async function(selection) {
          if ($readonly) { return; };
          // Extract the image snippet, recording
          // - image snippet (as canvas element)
          // - x/y coordinate of the snippet top-left (image coordinate space)
          // - kx/ky scale factors between canvas element physical and logical dimensions
          // Polygon coordinates, in the snippet element's logical coordinate space
        });
        annotorious[element_id].on('clickAnnotation', function(annotation, element) {
          console.log(element);
          console.log(annotation);
          //
        });
      });
    }
  };
})(jQuery, Drupal, once, window.Annotorious);
