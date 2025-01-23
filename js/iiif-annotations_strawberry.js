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

    var annotorious = [];
    var viewers = [];

    Drupal.behaviors.format_strawberryfield_annotations_initiate = {
        attach: function (context, settings) {
            var disableUrlClickWhenVisible = function (event) {
                // Bound to groupsetting (this)
                event.preventDefault();
                // We have no way of cancelling / avoiding bubbling on the annotation onClik
               // we actually have to check if someone clicked the SVG wrapper.
                if (event.target.tagName != 'svg') {
                  return;
                }
                // If there is a Link around the image we will reuse as direct link
                // if we don't prevent the default.
                // Sadly there is no getVisible() method. But we can check the annotationlayer if any
                // if (event.currentTarget.querySelector("svg.a9s-annotationlayer")?.style?.display !== "none") {
                const image_data = {
                    "fileuuid": this.file_uuid,
                    "nodeuuid": this.nodeuuid,
                    "fragment": "xywh=percent:0,0,100,100",
                    "textualbody": "whole image"
                }
                Drupal.FormatStrawberryfieldIiifUtils.dispatchImageViewChange(event.target, btoa(pako.gzip(JSON.stringify(image_data))));
            }
            var annotorious_annotations = [];
            var groupssettings = {};
            // Only attach to images that have an ID and a not empty data-sbf-annotations-nodeuuid property
            const elementsToAttach = once('attache_annotations', 'img[data-sbf-annotations-nodeuuid][id]:not([data-sbf-annotations-nodeuuid=""])', context);
            $(elementsToAttach).each(function (index, value) {
                // Get the node uuid for this element
                let element_id = $(this).attr("id");
                let node_uuid = $(this).data("sbf-annotations-nodeuuid");
                let file_uuid = $(this).data("sbf-annotations-fileuuid");
                let processors = $(this).data("sbf-annotations-processors");
                let flavor_id = $(this).data("sbf-annotations-flavorid");
                // Processor can be starting in 1.5.0 be ommited if we have a file_uuid
                if (typeof file_uuid !== "undefined" && (processors !== "undefined" || flavor_id !== "undefined")) {
                    groupssettings[element_id] = {
                        "webannotations": false,
                        "nodeuuid": node_uuid,
                        "file_uuid": file_uuid,
                        "processors": processors,
                        "flavor_id": flavor_id
                    }
                }
            });
            var PopperInstance = {};
            if (context && Object.keys(groupssettings).length !== 0) {
              if (!document.getElementById("sbf-annotations-popup")) {
                  const popup = document.createElement('div');
                  popup.setAttribute("id", "sbf-annotations-popup");
                  popup.setAttribute("role", "tooltip");
                  popup.classList.add('popper-background');
                  const popup_text = document.createElement('span');
                  popup.appendChild(popup_text);
                  const arrow = document.createElement('div');
                  arrow.classList.add('popper-arrow');
                  arrow.setAttribute('data-popper-arrow', '');
                  popup.appendChild(arrow);
                  if (context !== document) {
                      context.closest('div').appendChild(popup);
                  }
                  else {
                      let main_element = document.getElementById('main');
                      if (!main_element) {
                        main_element = document.getElementById('main-content');
                      }
                      if (main_element) {
                        main_element.appendChild(popup);
                      }
                  }
              }
              // Why again? because it might have been created by a previous Ajax call. So we query it.
                // But if created in this pass then we are also OK and fetch it in the same const.
                const popup = document.getElementById("sbf-annotations-popup")

                function generateGetBoundingClientRect(x = 0, y = 0) {
                    return () => ({
                        width: 0,
                        height: 0,
                        top: y,
                        right: x,
                        bottom: y,
                        left: x,
                    });
                }
                const virtualElement = {
                    getBoundingClientRect: generateGetBoundingClientRect(),
                };
                const PopperInstance = Popper.createPopper(virtualElement, popup);
                $.each(groupssettings, function (element_id, groupssetting) {
                    function loadFirstAnnotationOfGroup(element_id) {
                        jQuery.ajax({
                            url: '/do/' + groupssetting.nodeuuid + '/webannon/readsbf',
                            type: "GET",
                            dataType: 'json',
                            element_id: element_id,
                            data: {
                                'target_resource_uuid': groupssetting.file_uuid,
                                'processors': groupssetting.processors,
                                'flavor_id': groupssetting.flavor_id
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
                    let $widgets = [];
                    const $anonconfig = {
                        "readOnly": $readonly,
                        "widgets": $widgets,
                        "image": document.getElementById(element_id),
                        "editorDisabled": true,
                        "disableSelect": true,
                    }

                    annotorious[element_id] = Annotorious.init($anonconfig);
                    const wrapping_link =  document.getElementById(element_id).closest("a")
                    if (wrapping_link) {
                      wrapping_link.addEventListener("click", disableUrlClickWhenVisible.bind(groupssetting), false);
                    }
                    annotorious_annotations[element_id] = [];
                    loadFirstAnnotationOfGroup(element_id);
                    // let toggle = ThreeWaySwitchElement(element_id, false);
                    // $('#toolbar-' + element_id).prepend(toggle);
                    annotorious[element_id].on('createSelection', async function (selection) {
                        if ($readonly) {
                            return;
                        }
                        ;
                        // Extract the image snippet, recording
                        // - image snippet (as canvas element)
                        // - x/y coordinate of the snippet top-left (image coordinate space)
                        // - kx/ky scale factors between canvas element physical and logical dimensions
                        // Polygon coordinates, in the snippet element's logical coordinate space
                    });
                    annotorious[element_id].on('clickAnnotation', function (annotation, element) {

                        const image_data = {
                            "fileuuid": groupssetting.file_uuid,
                            "nodeuuid": groupssetting.nodeuuid,
                            "fragment": annotation.target.selector.value,
                            "textualbody": annotation.body?.value
                        }
                        Drupal.FormatStrawberryfieldIiifUtils.dispatchImageViewChange(element, btoa(pako.gzip(JSON.stringify(image_data))));
                    });
                    annotorious[element_id].on('mouseEnterAnnotation', function (annotation, element) {
                        // element is a <g> so we need to use getBBox.
                        popup.setAttribute('data-show', '');
                        PopperInstance.setOptions((options) => ({
                            ...options,
                            modifiers: [
                                ...options.modifiers,
                                { name: 'eventListeners', enabled: true },
                            ],
                        }));
                        const svg = element.closest("svg");
                        if (svg) {
                            const p = svg.createSVGPoint()
                            p.x = element.getBBox().x+ (element.getBBox().width/2);
                            p.y = element.getBBox().y + element.getBBox().height;
                            const transformed = p.matrixTransform(svg.getScreenCTM());
                            popup.querySelector('span').innerText = annotation.body?.value;
                            virtualElement.getBoundingClientRect = generateGetBoundingClientRect(transformed.x, transformed.y);
                            PopperInstance.update();
                        }
                    });
                    annotorious[element_id].on('mouseLeaveAnnotation', function (annotation, element) {

                        PopperInstance.setOptions((options) => ({
                            ...options,
                            modifiers: [
                                ...options.modifiers,
                                { name: 'eventListeners', enabled: false },
                            ],
                        }));
                        popup.removeAttribute('data-show');
                    });
                });
            }
        }
    };
})(jQuery, Drupal, once, window.Annotorious);
