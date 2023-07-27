(function ($, Drupal, drupalSettings, JSM) {

    'use strict';

    Drupal.behaviors.format_strawberryfield_jsm_initiate = {
        attach: function(context, settings) {
            $('.strawberry-3d-item[data-iiif-model]').once('attache_pnl')
                .each(function (index, value) {
                    // Get the node uuid for this element
                    var element_id = $(this).attr("id");
                    // Check if we got some data passed via Drupal settings.
                    var canvas = value;
                    var canvasDom = $(value);
                    var cameraUpVector = [0, 0, 1];
                    if (typeof settings.format_strawberryfield.strawberry_3d_formatter[element_id]['camera-up-vector'] !== "undefined") {
                      let cameraUp = settings.format_strawberryfield.strawberry_3d_formatter[element_id]['camera-up-vector'];
                      if (Array.isArray(cameraUp)) {
                        cameraUpVector = Array.from(cameraUp);
                        cameraUpVector = cameraUpVector.filter(component => (Math.round((component * 10) / 10).toFixed(1) == 1.0) || (Math.round((component * 10) / 10).toFixed(1) == 0.0));
                      }
                    }

                    var viewerSettings = {
                        cameraEyePosition: [-2.0, 1.5, 1.0],
                        cameraCenterPosition: [0.0, 0.0, 0.0],
                        cameraUpVector: cameraUpVector,
                        lightDiffuseColor : [0.95, 0.85, 0.85],
                        nearClippingPlane : 0.01
                        //lightAmbientColor : [0.5, 0.45, 0.3]
                    };
                    var sourceurl = $(value).data('iiif-model');
                    var textureurl = $(value).data('iiif-texture');
                    var materialurl = $(value).data('iiif-material');
                    const ado_title = $(value).data('ado-title');
                    var textureurls_for_mtl = $(value).data('iiif-filename2texture');
                    var browser_supported = JSM.IsWebGLEnabled() && JSM.IsFileApiEnabled();

                    // Ajusts width to what ever is smallest.
                    // If given width is less than window size, do nothing
                    // In any other case make it as width
                    function resizeCanvas () {
                        if (canvas.parentElement.clientWidth < canvasDom.data("iiif-image-width") || typeof canvasDom.data("iiif-image-width") == "undefined") {
                            canvasDom.width(canvasDom.first().parent().innerWidth());
                            canvasDom.attr("width", canvasDom.first().parent().innerWidth());
                        }
                    }
                    resizeCanvas();
                    console.log('Initializing JSModeler')
                    if (browser_supported) {
                        var urls = sourceurl;
                        if (urls === undefined || urls === null) {
                            console.log('JSM Invalid source files for' + element_id);
                            return;
                        }

                        var urlList = urls.split('|');
                        if (textureurl != null) {
                            urlList.push(textureurl);
                        }
                        if (materialurl != null) {
                            urlList.push(materialurl);
                        }

                        if (textureurls_for_mtl != null) {
                            urlList = urlList.concat(textureurls_for_mtl.split('|'));
                        }
                        // This is in case we have material, textures, etc in the same URL.
                        //@TODO allow people to select default materials
                        var $div = $("<div>", {id: "jsm-preloader", "class": "sbf-preloader"});
                        canvasDom.parent().append($div);
                        JSM.ResizeImageToPowerOfTwoSides = function (image)
                        {
                            image.crossOrigin = "Anonymous";
                            if (JSM.IsPowerOfTwo (image.width) && !JSM.IsPowerOfTwo (image.height)) {
                                return image;
                            }

                            var width = JSM.NextPowerOfTwo (image.width);
                            var height = JSM.NextPowerOfTwo (image.height);

                            var canvas = document.createElement ('canvas');
                            canvas.width = width;
                            canvas.height = height;

                            var context = canvas.getContext ('2d');
                            context.drawImage (image, 0, 0, width, height);
                            return context.getImageData (0, 0, width, height);
                        };


                        JSM.ConvertURLListToJsonData(urlList, {
                            onError: function () {
                                console.log('Could not convert file' + element_id);
                                $(".sbf-preloader").fadeOut('fast');
                                return;
                            },
                            onReady: function (fileNames, jsonData) {
                                console.log('Loaded Materials');
                                console.log(jsonData.materials);
                                // add a texture only if present.
                                // Will never be the case if an MTL was loaded.
                                if (textureurl !== null) {
                                    jsonData.materials[0].texture = textureurl;
                                    jsonData.materials[0].textureWidth = 1.0;
                                    jsonData.materials[0].textureHeight = 1.0;
                                }

                                var viewer = new JSM.ThreeViewer();

                                if (!viewer.Start(canvas, viewerSettings)) {
                                    console.log('Error initializing JSM Viewer' + element_id);
                                    $(".sbf-preloader").fadeOut('fast');
                                    return;
                                }

                                var currentMeshIndex = 0;
                                var environment = {
                                    boundingBox: {},
                                    onStart: function (/*taskCount, meshes*/) {
                                        viewer.EnableDraw(false);
                                        viewer.navigation.SetNearDistanceLimit (0.5);
                                        viewer.navigation.SetFarDistanceLimit (40.0);
                                    },
                                    onProgress: function (currentTask, meshes) {
                                        while (currentMeshIndex < meshes.length) {
                                            viewer.AddMesh(meshes[currentMeshIndex]);
                                            currentMeshIndex = currentMeshIndex + 1;
                                        }
                                    },
                                    onFinish: function (meshes) {
                                        if (meshes.length > 0) {
                                            //viewer.AdjustClippingPlanes(50.0);
                                            let i = 0;
                                            while (i < meshes.length) {
                                                meshes[i].geometry.computeBoundingBox();  // otherwise geometry.boundingBox will be undefined
                                                let boundingBox = meshes[i].geometry.boundingBox.clone();
                                                /* alert('bounding box coordinates: ' +
                                                    '(' + boundingBox.min.x + ', ' + boundingBox.min.y + ', ' + boundingBox.min.z + '), ' +
                                                    '(' + boundingBox.max.x + ', ' + boundingBox.max.y + ', ' + boundingBox.max.z + ')' ); */
                                                this.boundingBox = new JSM.Coord(boundingBox.max.x, boundingBox.max.y, boundingBox.max.z);
                                                console.log(this.boundingBox);
                                              i++;
                                            }
                                        }

                                        $(".sbf-preloader").fadeOut('slow');
                                        function downloadBase64File() {
                                            let downloadLink = document.createElement('a');
                                            downloadLink.className = 'btn btn-secondary';
                                            downloadLink.textContent = 'Download Screenshot';
                                            canvasDom.parent().prepend(downloadLink);
                                            downloadLink.target = '_self';
                                            // will be given dynamically on click
                                            downloadLink.href = '#';
                                            downloadLink.download = ado_title + '.jpg';
                                            downloadLink.onclick = function() {
                                                viewer.Draw();
                                                downloadLink.href = viewer.renderer.domElement.toDataURL('image/jpg');
                                            };
                                        }

                                        viewer.EnableDraw(true);
                                        viewer.FitInWindow();
                                        const direction = JSM.CoordSub (viewer.navigation.camera.center, viewer.navigation.camera.eye);
                                        const dist = direction.Length ();
                                        const direction_bounding_box = JSM.CoordSub (viewer.navigation.camera.center, this.boundingBox);
                                        const dist_bx = direction_bounding_box.Length ();
                                        console.log(dist);
                                        console.log(dist_bx);
                                        viewer.navigation.SetNearDistanceLimit (parseFloat(dist) - (parseFloat(dist_bx)));
                                        viewer.navigation.SetFarDistanceLimit (parseFloat(dist) + (parseFloat(dist_bx)/2));
                                        viewer.Draw();
                                        downloadBase64File();
                                        $( window ).resize(function() {
                                            resizeCanvas();
                                            viewer.FitInWindow();
                                        });
                                    }
                                };

                                var textureLoaded = function () {
                                    viewer.Draw();
                                };
                                JSM.ConvertJSONDataToThreeMeshes(jsonData, textureLoaded, environment);

                            }
                        });
                    }
                });
        }};
})(jQuery, Drupal, drupalSettings, window.JSM);
