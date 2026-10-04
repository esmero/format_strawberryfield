// Create Worker with importing OpenCV library.
// eslint-disable-next-line no-undef
importScripts('opencv.js', 'https://cdn.jsdelivr.net/npm/simplify-js@1.2.4/simplify.min.js');
log('Importing openCV');


// 2. Await the promise returned by the cv object/module initialization
let cvPromise = (async () => {
  if (typeof cv === 'function' || typeof cv === 'object' && cv instanceof Promise) {
    self.cv = await cv;
  } else if (typeof cv !== 'undefined' && cv.then) {
    self.cv = await cv;
  }
  // Now cv is fully initialized and ready to use
  console.log('OpenCV is ready in worker');
})();




// Take vendor prefixes in account.
self.postMessage = self.webkitPostMessage || self.postMessage;

/**
 * Instance of CascadeClassifier for Front faces.
 *
 * @type {object}
 */
var faceCascade = null ;

/**
 * Instance of CascadeClassifier for Eyes.
 *
 * @type {object}
 */
var eyeCascade = null ;

var initialized = false;

/**
 * Helper function for logging to get information about source worker.
 *
 * @return {string}
 *   Returns source for logging messages.
 */
function source() {
  'use strict';
  return 'OpenCV Face/Contour/Text detection Worker';
}

/**
 * Logging function. It's required because Worker doesn't output console logs.
 *
 * Logging should be done in following way:
 *   console.log.apply(console, event.data.msg);
 *
 * @param {string} msg
 *   Message that should be logged.
 */
function log(msg) {
  'use strict';

  self.postMessage({
    type: 'debug',
    source: source(),
    msg: msg
  });
}

/*************************************************************************
 *
 * Basic concept for this is from the official OpenCV docs:
 * https://docs.self.cv.org/3.4/dc/dcf/tutorial_js_contour_features.html
 *
 *************************************************************************/

// Helper: chunks an array (i.e array to array of arrays)
const chunk = (array, size) => {
  const chunked_arr = [];

  let index = 0;
  while (index < array.length) {
    chunked_arr.push(array.slice(index, size + index));
    index += size;
  }

  return chunked_arr;
}

const chunkObjects = (array, size) => {
  const chunked_obj = [];

  let index = 0;
  while (index < array.length) {
    let coords = array.slice(index, size + index)
    chunked_obj.push({x:coords[0], y:coords[1]});
    index += size;
  }

  return chunked_obj;
}

/**
 * Helper function to create files for self.cv.
 *
 * @param {string} in_memory_path
 *   Path to file that will be stored in memory.
 * @param {string} url
 *   Url for file.
 * @param {function} callback
 *   Callback function when file is stored.
 */
function createFileFromUrl(in_memory_path, url, callback) {
  'use strict';

  let request = new XMLHttpRequest();
  /* eslint no-restricted-globals: 0 */
  // eslint-disable-next-line no-restricted-globals
  self.requestFileSystemSync = self.webkitRequestFileSystemSync || self.requestFileSystemSync;
  console.log("requestFileSystemSync", self.requestFileSystemSync);
  request.open('GET', url, true);
  request.responseType = 'arraybuffer';
  request.onload = function (ev) {
    if (request.readyState === 4) {
      if (request.status === 200) {
        // eslint-disable-next-line no-undef
        let data = new Uint8Array(request.response);
        self.cv.FS_createDataFile('/', in_memory_path, data, true, false, false);
        callback();
      }
      else {
        // eslint-disable-next-line no-console
        console.log('Failed to load ' + url + ' status: ' + request.status);
      }
    }
  };

  request.send();
}

/**
 * Function to load model files.
 *
 * @param {string} modelName
 *   Model name for classification.
 */
function loadModel() {
  'use strict';
  if (typeof self.cv === "undefined" || self.cv == null) {
    log('CV is not ready yet');
    return;
  }
  log(self.location.pathname);
  let faceCascadeFile = 'haarcascade_frontalface_default.xml'; // path to xml
  let eyesCascadeFile = 'haarcascade_eye.xml'; // path to xmlhaarcascade_eye.xml
  createFileFromUrl(faceCascadeFile, faceCascadeFile, () => {
    faceCascade = new self.cv.CascadeClassifier();
    faceCascade.load(faceCascadeFile); // in the callback, load the cascade from file
  });

  createFileFromUrl(eyesCascadeFile, eyesCascadeFile, () => {
    eyeCascade = new self.cv.CascadeClassifier();
    eyeCascade.load(eyesCascadeFile); // in the callback, load the cascade from file
  });

  self.postMessage({
    type: 'ready'
  });
}

/**
 * Execute image Face classification.
 *
 * @param {array} imageData
 *   Binary image data.
 * @param {string} annotorious_id
 *   The ID of the annotorious instance
 * @param {array} coordinates
 *   The original Coordinates
 */
function executeFace(imageData, annotorious_id, coordinates) {
  'use strict';
  if (typeof self.cv === "undefined" || self.cv == null) {
    log('CV is not ready yet');
    return;
  }
  if (faceCascade == null) {
    loadModel();
  }

  var classifications = {
    "eyes" : [],
    "faces" : [],
  }
  // Prepare image data for usage in Open CV library.
  let matImage = self.cv.matFromImageData(imageData);

  // FOR COLOR
  //var frameBGR = new self.cv.Mat(imageData.height, imageData.width, self.cv.CV_8UC3);
  //self.cv.cvtColor(matImage, frameBGR, self.cv.COLOR_RGBA2BGR);


  let gray = new self.cv.Mat();
  self.cv.cvtColor(matImage, gray, self.cv.COLOR_RGBA2GRAY, 0);
  let faces = new self.cv.RectVector();
  let eyes = new self.cv.RectVector();

 // detect faces
  let msize = new self.cv.Size(0, 0);
  faceCascade.detectMultiScale(gray, faces, 1.05, 3, 0, msize, msize);
  for (let i = 0; i < faces.size(); ++i) {
    let roiGray = gray.roi(faces.get(i));
    let roiSrc = matImage.roi(faces.get(i));
    let point1 = new self.cv.Point(faces.get(i).x, faces.get(i).y);
    let point2 = new self.cv.Point(faces.get(i).x + faces.get(i).width,
      faces.get(i).y + faces.get(i).height);
    classifications.faces[i] = [[point1.x, point1.y], [point2.x,point1.y] , [point2.x,point2.y] ,[point1.x,point2.y]]
    // detect eyes in face ROI
    eyeCascade.detectMultiScale(roiGray, eyes);
    for (let j = 0; j < eyes.size(); ++j) {
      let point1 = new self.cv.Point(eyes.get(j).x, eyes.get(j).y);
      let point2 = new self.cv.Point(eyes.get(j).x + eyes.get(j).width,
        eyes.get(j).y + eyes.get(i).height);
      classifications.eyes[i] = [[point1.x, point1.y], [point2.x,point1.y] , [point2.x,point2.y] ,[point1.x,point2.y]]
    }
    roiGray.delete(); roiSrc.delete();
  }
  matImage.delete(); gray.delete();
  faces.delete(); eyes.delete();

  // Send list of faces from this worker to the page.
  self.postMessage({
    type: 'face_done',
    classifications: classifications,
    annotorious_id: annotorious_id,
    original_coordinates: coordinates,
  });
}


/**
 * Execute image Contour Adapt classification.
 *
 * @param {array} imageData
 *   Binary image data.
 * @param {string} annotorious_id
 *   The ID of the annotorious instance
 * @param {array} coordinates
 *   The original Coordinates
 */
function executeContourAdapt(imageData, annotorious_id, coordinates) {
  'use strict';
  if (typeof self.cv === "undefined" || self.cv == null) {
    log('CV is not ready yet');
    return;
  }

  var classifications = {
    "contour" : [],
  }
  // Prepare image data for usage in Open CV library.
  let matImage = self.cv.matFromImageData(imageData);

  const dst = self.cv.Mat.zeros(matImage.rows, matImage.cols,self.cv.CV_8UC3);

  // Convert to grayscale & threshold
  self.cv.cvtColor(matImage, matImage, self.cv.COLOR_RGB2GRAY, 0);
  self.cv.medianBlur(matImage, matImage, 25);
  self.cv.adaptiveThreshold(matImage, matImage, 255, self.cv.ADAPTIVE_THRESH_GAUSSIAN_C, self.cv.THRESH_BINARY_INV, 11, 2);

  let kernel = self.cv.getStructuringElement(self.cv.MORPH_RECT, new self.cv.Size(3,3));
  //Close
  self.cv.morphologyEx(matImage, matImage, self.cv.MORPH_CLOSE, kernel);
  // Dilate
  self.cv.dilate(matImage, matImage, kernel, new self.cv.Point(-1, -1), 2 ,self.cv.BORDER_CONSTANT, self.cv.morphologyDefaultBorderValue());
  // Find contours
  const contours = new self.cv.MatVector();
  const hierarchy = new self.cv.Mat();
  self.cv.findContours(matImage, contours, hierarchy, self.cv.RETR_EXTERNAL, self.cv.CHAIN_APPROX_SIMPLE);

  let largestAreaPolygon = { area: 0 };

  for (let i = 0; i < contours.size(); ++i) {
    const polygon = new self.cv.Mat();
    const contour = contours.get(i);

    self.cv.approxPolyDP(contour, polygon, 3, true);

    // Compute contour areas
    const area = self.cv.contourArea(polygon);
    if (area > largestAreaPolygon.area)
      largestAreaPolygon = { area, polygon };

    contour.delete();
  }

  const polygons = new self.cv.MatVector();
  polygons.push_back(largestAreaPolygon.polygon);

  matImage.delete();
  dst.delete();

  hierarchy.delete();
  contours.delete();
  polygons.delete();
  classifications.contour = simplify(chunkObjects(largestAreaPolygon.polygon.data32S, 2), 5, true);
  classifications.contour = classifications.contour.map(pair => {
    return [pair.x, pair.y]
  });


  //classifications.contour = chunk(largestAreaPolygon.polygon.data32S, 2);

  // Send list of faces from this worker to the page.
  self.postMessage({
    type: 'contour_done',
    classifications: classifications,
    original_coordinates: coordinates,
    annotorious_id: annotorious_id
  });
}

/**
 * Execute Contour
 *
 * @param {array} imageData
 *   Binary image data.
 * @param {string} annotorious_id
 *   The ID of the annotorious instance
 * @param {array} coordinates
 *   The original Coordinates
 */
function executeContour(imageData, annotorious_id, coordinates) {
  'use strict';
  if (typeof self.cv === "undefined" || self.cv == null) {
    log('CV is not ready yet');
    return;
  }

  var classifications = {
    "contour" : [],
  }
  // Prepare image data for usage in Open CV library.
  let matImage = self.cv.matFromImageData(imageData);

  const dst = self.cv.Mat.zeros(matImage.rows, matImage.cols,self.cv.CV_8UC3);

  // Convert to grayscale & threshold
  self.cv.cvtColor(matImage, matImage, self.cv.COLOR_RGB2GRAY, 0);
  self.cv.threshold(matImage, matImage, 200, 255, self.cv.THRESH_BINARY + self.cv.THRESH_OTSU);
  // Find contours
  const contours = new self.cv.MatVector();
  const hierarchy = new self.cv.Mat();

  self.cv.findContours(matImage, contours, hierarchy, self.cv.RETR_CCOMP, self.cv.CHAIN_APPROX_NONE); // CV_RETR_EXTERNAL

  let largestAreaPolygon = { area: 0 };

  for (let i = 0; i < contours.size(); ++i) {
    const polygon = new self.cv.Mat();
    const contour = contours.get(i);

    self.cv.approxPolyDP(contour, polygon, 3, true);

    // Compute contour areas
    const area = self.cv.contourArea(polygon);
    if (area > largestAreaPolygon.area)
      largestAreaPolygon = { area, polygon };

    contour.delete();
  }

  const polygons = new self.cv.MatVector();
  polygons.push_back(largestAreaPolygon.polygon);

  matImage.delete();
  dst.delete();

  hierarchy.delete();
  contours.delete();
  polygons.delete();
  classifications.contour = simplify(chunkObjects(largestAreaPolygon.polygon.data32S, 2), 5, true);
  classifications.contour = classifications.contour.map(pair => {
    return [pair.x, pair.y]
  });

  // Send list of faces from this worker to the page.
  self.postMessage({
    type: 'contour_done',
    classifications: classifications,
    original_coordinates: coordinates,
    annotorious_id: annotorious_id
  });
}

/**
 * On message handler.
 *
 * @param {object} event
 *   Message event for Worker.
 */
self.onmessage = async function (event) {
  'use strict';
  await cvPromise;

  switch (event.data.type) {
    case 'load':
      log('Loading models');
      loadModel();
      break;

    case 'execute_face':
      executeFace(event.data.image_data, event.data.annotorious_id, event.data.original_coordinates);
      break;
    case 'execute_contour':
      executeContour(event.data.image_data, event.data.annotorious_id, event.data.original_coordinates );
      break;
    case 'execute_contour_adapt':
      executeContourAdapt(event.data.image_data, event.data.annotorious_id, event.data.original_coordinates);
      break;
  }
};

