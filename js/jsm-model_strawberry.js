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
                    var viewerSettings = {
                        cameraEyePosition: [-2.0, -1.5, 1.0],
                        cameraCenterPosition: [0.0, 0.0, 0.0],
                        cameraUpVector: [0, 0, 1],
                        lightDiffuseColor : [0.9, 0.8, 0.8]
                    };
                    var sourceurl = $(value).data('iiif-model');
                    var browser_supported = JSM.IsWebGLEnabled() && JSM.IsFileApiEnabled();

                    console.log('Initializing JSModeler')
                    if (browser_supported) {
                        var urls = sourceurl;
                        if (urls === undefined || urls === null) {
                            console.log('Invalid source files for' + element_id);
                            return;
                        }

                        var urlList = urls.split('|');
                        // This is in case we have material, textures, etc in the same URL.
                        //@TODO allow people to select default materials
                        JSM.ConvertURLListToJsonData(urlList, {
                            onError: function () {
                                console.log('Could not convert file' + element_id);
                                return;
                            },
                            onReady: function (fileNames, jsonData) {

                                var viewer = new JSM.ThreeViewer();
                                if (!viewer.Start(canvas, viewerSettings)) {
                                    console.log('Error initializing Viewer' + element_id);
                                    return;
                                }

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
                                            viewer.FitInWindow();
                                        }
                                        viewer.EnableDraw(true);
                                        viewer.Draw();
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
