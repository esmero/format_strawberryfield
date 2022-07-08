(function ($, Drupal,  OpenSeadragonAnnotorious, drupalSettings) {

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

  var ThreeWaySwitchElement = function(id) {
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

    const container = document.createElement('div');
    container.style = "display:inline-flex";
    const toolbar = document.createElement('div');
    toolbar.setAttribute('id', id+ '-annon-toolbar');
    container.appendChild(toolbar);
    container.appendChild(input1);
    container.appendChild(input2);
    container.appendChild(input3);

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
          var annotations = drupalSettings.format_strawberryfield.openseadragon[element_id]['webannotations'];
          var annotations_tool = drupalSettings.format_strawberryfield.openseadragon[element_id]['webannotations_tool'];
          var file_uuid = drupalSettings.format_strawberryfield.openseadragon[element_id]['dr:uuid'];
          var keystoreid = drupalSettings.format_strawberryfield.openseadragon[element_id]['keystoreid'];
          current_user = drupalSettings.format_strawberryfield.openseadragon[element_id]['user'];
          var group = $(this).data("iiif-group");
          var infojson = $(this).data("iiif-infojson");
          var showthumbs = $(this).data("iiif-thumbnails");
          if (!groupsinfojsons.hasOwnProperty(group)) {
            groupsinfojsons[group] = [infojson];

            groupssettings[group] = {
              "default_width": default_width,
              "default_height": default_height,
              "webannotations" : false,
              "annotations_tool": annotations_tool,
              "nodeuuid" : settings.format_strawberryfield.openseadragon.innode[element_id],
              "file_uuid" : file_uuid,
              "keystoreid" : keystoreid,
              "showthumbs": showthumbs
            }

            if (typeof annotations != "undefined" && annotations == true) {
              groupssettings[group].webannotations = true;
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
          prefixUrl: "https://cdn.jsdelivr.net/npm/openseadragon@2.4.2/build/openseadragon/images/",
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
              'COMMENT',
              'TAG'
            ],
          }
          // terminate the worker if the user can not add annotations
          if ($readonly) { worker.terminate() };

          annotorious[element_id] = window.OpenSeadragon.Annotorious(viewers[element_id], $anonconfig);
          annotorious[element_id].setDrawingTool(groupssettings[group].annotations_tool);
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
            let toggle = ThreeWaySwitchElement(element_id);
            // #toolbar-'+ element_id is passed as a div at the same level of the OSD viewer by
            // \Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryMediaFormatter::generateElementForItem
            $('#toolbar-'+ element_id).prepend(toggle);
            window.Annotorious.Toolbar(annotorious[element_id], document.getElementById(element_id+'-annon-toolbar'));
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
})(jQuery, Drupal, window.OpenSeadragon.Annotorious, drupalSettings);

// override getTileUrl
OpenSeadragon.IIIFTileSource.prototype.getTileUrl = function( level, x, y ){
  if(this.emulateLegacyImagePyramid) {
    var url = null;
    if ( this.levels.length > 0 && level >= this.minLevel && level <= this.maxLevel ) {
      url = this.levels[ level ].url;
    }
    return url;
  }

  //# constants
  var IIIF_ROTATION = '0',
    //## get the scale (level as a decimal)
    scale = Math.pow( 0.5, this.maxLevel - level ),

    //# image dimensions at this level
    levelWidth = Math.ceil( this.width * scale ),
    levelHeight = Math.ceil( this.height * scale ),

    //## iiif region
    tileWidth,
    tileHeight,
    iiifTileSizeWidth,
    iiifTileSizeHeight,
    iiifRegion,
    iiifTileX,
    iiifTileY,
    iiifTileW,
    iiifTileH,
    iiifSize,
    iiifSizeW,
    iiifSizeH,
    iiifQuality,
    uri;

  tileWidth = this.getTileWidth(level);
  tileHeight = this.getTileHeight(level);
  iiifTileSizeWidth = Math.ceil( tileWidth / scale );
  iiifTileSizeHeight = Math.ceil( tileHeight / scale );
  if (this.version === 1) {
    iiifQuality = "native." + this.tileFormat;
  } else {
    iiifQuality = "default." + this.tileFormat;
  }
  if ( levelWidth < tileWidth && levelHeight < tileHeight ){
    if ( this.version === 2 && levelWidth === this.width ) {
      iiifSize = "full";
    } else if ( this.version === 3 && levelWidth === this.width && levelHeight === this.height ) {
      iiifSize = "max";
    } else if ( this.version === 3 ) {
      iiifSize = levelWidth + "," + levelHeight;
    } else {
      iiifSize = levelWidth + ",";
    }
    iiifRegion = 'full';
  } else {
    iiifTileX = x * iiifTileSizeWidth;
    iiifTileY = y * iiifTileSizeHeight;
    iiifTileW = Math.min( iiifTileSizeWidth, this.width - iiifTileX );
    iiifTileH = Math.min( iiifTileSizeHeight, this.height - iiifTileY );
    if ( x === 0 && y === 0 && iiifTileW === this.width && iiifTileH === this.height ) {
      iiifRegion = "full";
    } else {
      iiifRegion = [ iiifTileX, iiifTileY, iiifTileW, iiifTileH ].join( ',' );
    }
    iiifSizeW = Math.ceil( iiifTileW * scale );
    iiifSizeH = Math.ceil( iiifTileH * scale );
    if ( this.version === 2 && iiifSizeW === this.width ) {
      iiifSize = "full";
    } else if ( this.version === 3 && iiifSizeW === this.width && iiifSizeH === this.height ) {
      iiifSize = "max";
    } else if (this.version === 3) {
      iiifSize = iiifSizeW + "," + iiifSizeH;
    } else {
      iiifSize = iiifSizeW + ",";
    }
  }

  //OLD//uri = [ this['@id'], iiifRegion, iiifSize, IIIF_ROTATION, iiifQuality ].join( '/' );
  queryParams = this['@id'].match(/\?.*/);
  tilesUrl = this['@id'].replace(queryParams, '');
  uri = [ tilesUrl, iiifRegion, iiifSize, IIIF_ROTATION, iiifQuality ].join( '/' );
  if (queryParams) {
    uri += queryParams;
  }

  return uri;
};

// override configure
OpenSeadragon.IIIFTileSource.prototype.configure = function( data, url ){
  // Try to deduce our version and fake it upwards if needed

  queryParams = url.match(/\?.*/);
  tilesUrl = url.replace(queryParams, '');

  if ( !$.isPlainObject(data) ) {
    var options = configureFromXml10( data );
    options['@context'] = "http://iiif.io/api/image/1.0/context.json";

    //OLD//options['@id'] = url.replace('/info.xml', '');
    options['@id'] = tilesUrl.replace('/info.xml', '');
    if (queryParams) {
      options['@id'] += queryParams;
    }

    options.version = 1;
    return options;
  } else {
    if ( !data['@context'] ) {
      data['@context'] = 'http://iiif.io/api/image/1.0/context.json';

      //OLD//data['@id'] = url.replace('/info.json', '');
      data['@id'] = tilesUrl.replace('/info.xml', '');
      if (queryParams) {
        data['@id'] += queryParams;
      }

      data.version = 1;
    } else {
      var context = data['@context'];
      if (Array.isArray(context)) {
        for (var i = 0; i < context.length; i++) {
          if (typeof context[i] === 'string' &&
            ( /^http:\/\/iiif\.io\/api\/image\/[1-3]\/context\.json$/.test(context[i]) ||
              context[i] === 'http://library.stanford.edu/iiif/image-api/1.1/context.json' ) ) {
            context = context[i];
            break;
          }
        }
      }
      switch (context) {
        case 'http://iiif.io/api/image/1/context.json':
        case 'http://library.stanford.edu/iiif/image-api/1.1/context.json':
          data.version = 1;
          break;
        case 'http://iiif.io/api/image/2/context.json':
          data.version = 2;
          break;
        case 'http://iiif.io/api/image/3/context.json':
          data.version = 3;
          break;
        default:
          $.console.error('Data has a @context property which contains no known IIIF context URI.');
      }
    }
    if ( !data['@id'] && data['id'] ) {
      data['@id'] = data['id'];
    }

    if (queryParams) {
      data['@id'] += queryParams;
    }

    if(data.preferredFormats) {
      for (var f = 0; f < data.preferredFormats.length; f++ ) {
        if ( OpenSeadragon.imageFormatSupported(data.preferredFormats[f]) ) {
          data.tileFormat = data.preferredFormats[f];
          break;
        }
      }
    }
    return data;
  }
};
