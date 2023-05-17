/*
 * 🍂class L.ImageOverlay.iiifBounded
 * 🍂inherits ImageOverlay
 *
 * Based on https://github.com/IvanSanchez/Leaflet.ImageOverlay.Rotated
 * and extended to 4 points/3D Matrix Projection from 2D by
 * @MISC {339033,
    TITLE = {Finding the Transform matrix from 4 projected points (with Javascript)},
    AUTHOR = {MvG (https://math.stackexchange.com/users/35416/mvg)},
    HOWPUBLISHED = {Mathematics Stack Exchange},
    NOTE = {URL:https://math.stackexchange.com/q/339033 (version: 2019-02-28)},
    EPRINT = {https://math.stackexchange.com/q/339033},
    URL = {https://math.stackexchange.com/q/339033}
}
 * *four* control points instead of *three* + IIIF info.json processing
 * @example
 * ```
 * var overlay = new L.ImageOverlay.iiifBounded("http://yourdomain/iiif/2/imageid/full/full/0/default.jpg", topleft, topright,  bottomright, bottomleft {
 * 	opacity: 0.4,
 * 	interactive: true,
 * 	attribution: "&copy; My Copyright"
 * });
 * ```
 * @see https://iiif.io/api/extension/georef/?mc_cid=46c37da63d&mc_eid=f820ccac92
 */

L.ImageOverlay.iiifBounded = L.ImageOverlay.extend({

  initialize: function (image, topleft, topright, bottomright, bottomleft, options) {
    /* @TODO Need to figure out tiling for large images
    * Requires an info.json
    * The rendered size of the Map
    * The zoom Level
    * A tiling function for IIIF Image API (v2) that is aware of the Annotation bounding box.
    * A URL Processor to allow selectively get smaller versions and chunks.
    * A matrix transformation per tile with probably an origin offset.
    * */

    this.setUrl(image);
    if (typeof(image) === 'string') {
      this._url = image;
    } else {
      // Assume that the first parameter is an instance of HTMLImage or HTMLCanvas
      this._rawImage = image;
    }

    this._topLeft    = L.latLng(topleft);
    this._topRight   = L.latLng(topright);
    this._bottomRight = L.latLng(bottomright);
    this._bottomLeft = L.latLng(bottomleft);
    L.setOptions(this, options);
  },

  onAdd: function (map) {
    if (!this._image) {
      this._initImage();

      if (this.options.opacity < 1) {
        this._updateOpacity();
      }
    }

    if (this.options.interactive) {
      L.DomUtil.addClass(this._rawImage, 'leaflet-interactive');
      this.addInteractiveTarget(this._rawImage);
    }

    map.on('zoomend resetview', this._reset, this);

    this.getPane().appendChild(this._image);
    this._reset();
  },

  onRemove: function(map) {
    map.off('zoomend resetview', this._reset, this);
    L.ImageOverlay.prototype.onRemove.call(this, map);
  },

  _initImage: function () {
    var img = this._rawImage;
    if (this._url) {
      img = L.DomUtil.create('img');
      img.style.display = 'none';	// Hide while the first transform (zero or one frames) is being done
      if (this.options.crossOrigin) {
        img.crossOrigin = '';
      }
      img.src = this._url;
      this._rawImage = img;
    }
    L.DomUtil.addClass(img, 'leaflet-image-layer');
    var div = this._image = L.DomUtil.create('div',
      'leaflet-image-layer' + (this._zoomAnimated ? 'leaflet-zoom-animated' : ''));
    this._updateZIndex(); // apply z-index style setting to the div (if defined)
    div.appendChild(img);
    div.onselectstart = L.Util.falseFn;
    div.onmousemove = L.Util.falseFn;
    img.onload = function(){
      this._reset();
      img.style.display = 'block';
      this.fire('load');
    }.bind(this);

    img.alt = this.options.alt;
  },


  _reset: function () {
    var div = this._image;
    if (!this._map) {
      return;
    }

    // Project control points to container-pixel coordinates
    var pxTopLeft    = this._map.latLngToLayerPoint(this._topLeft);
    var pxTopRight   = this._map.latLngToLayerPoint(this._topRight);
    var pxBottomLeft = this._map.latLngToLayerPoint(this._bottomLeft);
    var pxBottomRight = this._map.latLngToLayerPoint(this._bottomRight);
    // pxBounds is for positioning the <div> container, we will use the offset to do a translate3D chained to the matrix3D
    // since that is linear it made my life easier to go that route instead of multiply yet another matrix.
    var pxBounds = L.bounds([pxTopLeft, pxTopRight, pxBottomLeft, pxTopRight.subtract(pxTopLeft).add(pxBottomLeft)]);
    var size = pxBounds.getSize();
    var pxTopLeftInDiv = pxTopLeft.subtract(pxBounds.min);
    /* inherited property from layer, needed for animation/moving around */
    this._bounds = L.latLngBounds( this._map.layerPointToLatLng(pxBounds.min),
      this._map.layerPointToLatLng(pxBounds.max) );

    L.DomUtil.setPosition(div, pxBounds.min);

    div.style.width  = size.x + 'px';
    div.style.height = size.y + 'px';

    var imgW = this._rawImage.width;
    var imgH = this._rawImage.height;
    // When implementing the tiled version we should apply the transform to each tile individually but using
    // an offset origin based on the topLeft x,y corner of the first tile.
    if (!imgW || !imgH) {
      return;
    }

    var w = imgW;
    var h = imgH;
    // matrix(a, b, c, d, tx, ty) is a shorthand for
    // matrix3d(a, b, 0, 0, c, d, 0, 0, 0, 0, 1, 0, tx, ty, 0, 1).
    // a = (vectorX.x/imgW) with vectorX = pxTopRight.subtract(pxTopLeft);
    // b = (vectorX.y/imgW)
    // c = (vectorY.x/imgH) with vectorY = pxBottomLeft.subtract(pxTopLef
    // d = (vectorY.y/imgH)
    // tx = pxTopLeftInDiv.x
    // ty =  pxTopLeftInDiv.y
    /* @TODO. Check the math/displacement again. There are still some smaller issues of marker/image offsets when dragging the markers
    * Chances are the pxBounds used to calculate the translated3D might not be accurate/update on time
    * Maybe the solution is to pass already transformed/displaced/to worldcoordinates (bc the div that contains the image
    * is already displaced/coordinates to _general2DProjection ?
    * */

    var transformMatrix = this._general2DProjection(0, 0, pxTopLeft.x, pxTopLeft.y, w, 0, pxTopRight.x, pxTopRight.y, 0, h, pxBottomLeft.x, pxBottomLeft.y, w, h, pxBottomRight.x, pxBottomRight.y);//var transformMatrix = this._general2DProjection(0, 0, pxTopLeft.x, pxTopLeft.y, vectorX.x, 0, pxTopRight.x, pxTopRight.y, 0, vectorX.y, pxBottomRight.x, pxBottomRight.y, vectorX.x, vectorX.y, pxBottomLeft.x, pxBottomLeft.y);
    for(i = 0; i != 9; ++i) { transformMatrix[i] = transformMatrix[i]/transformMatrix[8]; }
    transformMatrix = [transformMatrix[0], transformMatrix[3], 0, transformMatrix[6], transformMatrix[1], transformMatrix[4], 0, transformMatrix[7], 0 , 0 , 1, 0 , transformMatrix[2] /*pxTopLeftInDiv.x*/, transformMatrix[5]/* pxTopLeftInDiv.y*/, 0, transformMatrix[8]];
    const t = "translate3d(" + (-1 * (transformMatrix[12] - pxTopLeftInDiv.x)) + "px," + (-1 * (transformMatrix[13] - pxTopLeftInDiv.y))+ "px, 0)" + " matrix3d(" + transformMatrix.join(", ") + ")";
    this._rawImage.style.transformOrigin = '0 0';
    this._rawImage.style.transform = t;
  },

  _general2DProjection: function(
    x1s, y1s, x1d, y1d,
    x2s, y2s, x2d, y2d,
    x3s, y3s, x3d, y3d,
    x4s, y4s, x4d, y4d
  ) {
    var s = this._basisToPoints(x1s, y1s, x2s, y2s, x3s, y3s, x4s, y4s);
    var d = this._basisToPoints(x1d, y1d, x2d, y2d, x3d, y3d, x4d, y4d);
    return this._multmm(d, this._adj(s));
  },
  _basisToPoints: function (x1, y1, x2, y2, x3, y3, x4, y4) {
    var m = [
      x1, x2, x3,
      y1, y2, y3,
      1,  1,  1
    ];
    var v = this._multmv(this._adj(m), [x4, y4, 1]);
    return this._multmm(m, [
      v[0], 0, 0,
      0, v[1], 0,
      0, 0, v[2]
    ]);
  },

  _adj: function(m) { // Compute the adjugate of m
    return [
      m[4]*m[8]-m[5]*m[7], m[2]*m[7]-m[1]*m[8], m[1]*m[5]-m[2]*m[4],
      m[5]*m[6]-m[3]*m[8], m[0]*m[8]-m[2]*m[6], m[2]*m[3]-m[0]*m[5],
      m[3]*m[7]-m[4]*m[6], m[1]*m[6]-m[0]*m[7], m[0]*m[4]-m[1]*m[3]
    ];
  },
  _multmm: function (a, b) { // multiply two matrices
    var c = Array(9);
    for (var i = 0; i != 3; ++i) {
      for (var j = 0; j != 3; ++j) {
        var cij = 0;
        for (var k = 0; k != 3; ++k) {
          cij += a[3*i + k]*b[3*k + j];
        }
        c[3*i + j] = cij;
      }
    }
    return c;
  },
  _multmv: function (m, v) { // multiply matrix and vector
    return [
      m[0]*v[0] + m[1]*v[1] + m[2]*v[2],
      m[3]*v[0] + m[4]*v[1] + m[5]*v[2],
      m[6]*v[0] + m[7]*v[1] + m[8]*v[2]
    ];
  },

  reposition: function(topleft, topright, bottomright, bottomleft) {
    this._topLeft    = L.latLng(topleft);
    this._topRight   = L.latLng(topright);
    this._bottomRight = L.latLng(bottomright);
    this._bottomLeft = L.latLng(bottomleft);
    this._reset();
  },

  setUrl: function (url) {
    this._url = url;

    if (this._rawImage) {
      this._rawImage.src = url;
    }
    return this;
  }
});
