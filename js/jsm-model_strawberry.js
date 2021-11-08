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
                    var viewerSettings = {
                        cameraEyePosition: [-2.0, -1.5, 1.0],
                        cameraCenterPosition: [0.0, 0.0, 0.0],
                        cameraUpVector: [0, 0, 1],
                        lightDiffuseColor : [0.9, 0.8, 0.8]
                    };
                    var sourceurl = $(value).data('iiif-model');
                    var textureurl = $(value).data('iiif-texture');
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

                        JSM.GetStringBufferFromURL = function (url, callbacks)
                        {
                            var request = new XMLHttpRequest ();
                            request.open ('GET', url, true);
                            request.responseType = 'text';

                            request.onload = function () {
                                var stringBuffer = request.response;
                                if (stringBuffer && callbacks.onReady) {
                                    callbacks.onReady (stringBuffer);
                                }
                            };

                            request.onerror = function () {
                                if (callbacks.onError) {
                                    callbacks.onError ();
                                }
                            };

                            request.send (null);
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
                                // add a texture?
                                jsonData.materials[0].texture  = textureurl;
                                jsonData.materials[0].textureWidth = 1.0;
                                jsonData.materials[0].textureHeight = 1.0;

                                var viewer = new JSM.ThreeViewer();
                                viewer.RemoveMeshes ();

                                if (!viewer.Start(canvas, viewerSettings)) {
                                    console.log('Error initializing JSM Viewer' + element_id);
                                    $(".sbf-preloader").fadeOut('fast');
                                    return;
                                }

                                /* This depends on the actual Mesh dimensions.
                                viewer.navigation.SetNearDistanceLimit(0.2);
                                viewer.navigation.SetFarDistanceLimit(2.0);
                                 */

                                var currentMeshIndex = 0;
                                var environment = {
                                    onStart: function (/*taskCount, meshes*/) {
                                        viewer.EnableDraw(false);
                                    },
                                    onProgress: function (currentTask, meshes) {
                                        while (currentMeshIndex < meshes.length) {
                                            viewer.AddMesh(meshes[currentMeshIndex]);
                                            currentMeshIndex = currentMeshIndex + 1;
                                        }
                                    },
                                    onFinish: function (meshes) {
                                        if (meshes.length > 0) {
                                            viewer.AdjustClippingPlanes(50.0);
                                            while (currentMeshIndex < meshes.length) {
                                                meshes[currentMeshIndex].geometry.computeBoundingBox();  // otherwise geometry.boundingBox will be undefined

                                                var boundingBox = meshes[currentMeshIndex].geometry.boundingBox.clone();
                                                alert('bounding box coordinates: ' +
                                                    '(' + boundingBox.min.x + ', ' + boundingBox.min.y + ', ' + boundingBox.min.z + '), ' +
                                                    '(' + boundingBox.max.x + ', ' + boundingBox.max.y + ', ' + boundingBox.max.z + ')' );
                                            }
                                        }





                                        console.log(viewer.renderer.domElement.toDataURL( 'image/png' ), 'screenshot');
                                        $(".sbf-preloader").fadeOut('slow');
                                        viewer.EnableDraw(true);
                                        viewer.FitInWindow();
                                        //let bbox = viewer.GetFilteredBoundingBox();
                                        viewer.Draw();
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
