(function ($, Drupal, once, drupalSettings, pannellum) {

  'use strict';

  function checkMobileDevice() {
    let check = false;
    (function (a) {
      if (/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino|android|ipad|playbook|silk/i.test(a) || /1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i.test(a.substr(0, 4))) check = true;
    })(navigator.userAgent || navigator.vendor || window.opera);
    return check;
  };


  function FormatStrawberryfieldPanoramas(panorama) {
    this.panorama = panorama;
  }

  function FormatStrawberryfieldhotspotPopUp(event, url) {
    if (url !== null) {
      let fullscreenelement = document.fullscreenElement || document.mozFullScreen || document.webkitFullscreenElement || document.msFullscreenElement;
      if (url.indexOf('http://') === 0 || url.indexOf('https://') === 0) {
        var open = confirm(Drupal.t('Confirm to open URL ' + url + ' in a new Window'));
        if (open == true) {
          window.open(url, '_blank');
        } else {
          if (typeof (fullscreenelement) != 'undefined') {
            event.preventDefault();
            // Let's delay the back to Fullscreen to give the cancel some time to return
            setTimeout(function () {
              var $fse = document.querySelector('#' + fullscreenelement.id);
              try {
                if ($fse.requestFullscreen) {
                  $fse.requestFullscreen();
                } else if ($fse.mozRequestFullScreen) {
                  $fse.mozRequestFullScreen();
                } else if ($fse.msRequestFullscreen) {
                  $fse.msRequestFullscreen();
                } else {
                  document.webkitCancelFullScreen();
                  $fse.webkitRequestFullScreen();
                }
              } catch (event) {
                // Fullscreen doesn't work
              }
              // Add tasks to do
            }, 100);

            return false;
          }
        }
      }
      else {
        if (!$.fn.modal) {
          var ajaxObject = Drupal.ajax({
            url: url,
            dialogType: 'modal',
            dialog: {width: '800px'},
            progress: {
              type: 'fullscreen',
              message: Drupal.t('Please wait...')
            }
          });
          if (typeof (fullscreenelement) != 'undefined') {
            try {
              document.exitFullscreen();
              document.webkitCancelFullScreen();
            } catch (event) {
              try {
                document.exitFullscreen();
              }
              catch (event) {
                console.log('Full screen is not supported by this Browser.')
              }
              console.log('Full screen is not supported by this Browser.')
            }
          }
          ajaxObject.execute();
        }
        else {
          var ajaxObject = Drupal.ajax({
            url: url,
            wrapper: 'sbfModalBody',
            method: 'append',
            dialogType: 'ajax',
            progress: {
              type: 'fullscreen',
              message: Drupal.t('Please wait...')
            },
          });
          var success = ajaxObject.success;

          ajaxObject.success = function (response, status) {
            $('#sbfModalBody').empty();
            success.bind(this)(response, status);
            $('#sbfModal').modal('show');
          };
          if (typeof (fullscreenelement) != 'undefined') {
            try {
              document.webkitCancelFullScreen();
            } catch (event) {
              console.log('Full screen is not supported by this Browser.')
            }
          }
          ajaxObject.execute();
        }
      }
    }
    event.preventDefault();
  }


  Drupal.behaviors.format_strawberryfield_pannellum_initiate = {
    attach: function (context, settings) {

      const elementsToAttach = once('attache_pnl', '.strawberry-panorama-item[data-iiif-image]', context);
      $(elementsToAttach).each(function (index, value) {

        // Get the node uuid for this element
        var element_id = $(this).attr("id");
        if (typeof (drupalSettings.format_strawberryfield.pannellum[element_id]) != 'undefined') {

          var hotspots = [];
          var $multiscene = drupalSettings.format_strawberryfield.pannellum[element_id].hasOwnProperty('tour');
          var default_width = drupalSettings.format_strawberryfield.pannellum[element_id]['width'];
          var default_height = drupalSettings.format_strawberryfield.pannellum[element_id]['height'];
          var externalConfigURL = drupalSettings.format_strawberryfield.pannellum[element_id]['configjsonurl'];
          let panellum_default_settings = {}

          if (externalConfigURL) {
            $.getJSON(externalConfigURL, function (data) {
              panellum_default_settings = data;
            });
          }

          // Check what is the max canvas size this Browser/WebGL impl. allows before committing to doom the navigator.
          var canvas = document.createElement('canvas'); // or reuse the existing
          var gl = canvas.getContext('webgl');
          if (gl) {
            console.log('Max webGL texture size is' +
              gl.getParameter(gl.MAX_TEXTURE_SIZE));
          }
          // There are the defaults for the used Panellum release.
          if (Object.keys(panellum_default_settings).length == 0) {
            panellum_default_settings = {
              "type": "equirectangular",
              "hotSpotDebug": false,
              "autoLoad": true,
              "haov": 360,
              "vaov": 180,
              "friction": 0.5,
              "maxYaw": 180,
              "minYaw": -180,
              "vOffset": 0,
              "maxPitch": undefined,
              "minPitch": undefined,
              // Additional Pannellum settings
              "hfov": 100,
              "minHfov": 50,
              "maxHfov": 120,
              "pitch": 0,
              "yaw": 0,
              "horizonPitch": 0,
              "horizonRoll": 0,
              "compass": false,
              "northOffset": 0,
              "preview": "",
              "showZoomCtrl": true,
              "keyboardZoom": true,
              "mouseZoom": true,
              "draggable": true,
              "disableKeyboardCtrl": false,
              "showFullscreenCtrl": true,
              "showControls": true,
              "autoRotate": false,
              "autoRotateInactivityDelay": 0,
              "autoRotateStopDelay": 0,
              "sceneFadeDuration": 1000
            }
          }
          let panellum_default_settings_from_element = {}
          if (typeof drupalSettings.format_strawberryfield.pannellum[element_id].settings == "object") {
            panellum_default_settings_from_element = drupalSettings.format_strawberryfield.pannellum[element_id].settings;
          }
          if ($multiscene) {
            Object.assign(
              panellum_default_settings_from_element,
              drupalSettings.format_strawberryfield.pannellum[element_id].tour || {}
            );
          }


          // Merge Default with settings passed by the formatter.
          if (panellum_default_settings_from_element != {}) {
            Object.assign(
              panellum_default_settings,
              panellum_default_settings_from_element|| {}
            );
          }

          $(this).height(default_height);
          $(this).css("width", default_width);

          if (drupalSettings.format_strawberryfield.pannellum[element_id].hasOwnProperty('hotspots') &&
            !$multiscene
          ) {
            $.each(drupalSettings.format_strawberryfield.pannellum[element_id].hotspots, function (id, hotspotdata) {
              // Also add Popups for Standalone Panoramas if they have an URL.
              if (hotspotdata.hasOwnProperty('URL') && hotspotdata.URL !== null) {
                if (hotspotdata.URL.indexOf('http://') === 0 || hotspotdata.URL.indexOf('https://') === 0) {
                  hotspotdata.text = hotspotdata.text + Drupal.t(' (External URL)');
                }
                else {
                  hotspotdata.text = hotspotdata.text + Drupal.t(' (Digital Object)');
                }
                hotspotdata.clickHandlerFunc = Drupal.FormatStrawberryfieldhotspotPopUp;
                hotspotdata.clickHandlerArgs = hotspotdata.URL;
              }
              hotspots.push(hotspotdata);
            });
          }

          // When loading a webform with an embeded Viewer
          // The context of Pannellum is not global
          // So we can't really use 'pannellum' directly
          let mobile = false;
          if (checkMobileDevice()) {
            mobile = true;
            console.log('Mobile defaulting to smaller version');
            var sourceimage = $(value).data('iiifImageMobile');
          } else {
            mobile = false;
            var sourceimage = $(value).data('iiifImage');
          }
          if (!$multiscene) {
            // Sets the source image and hotspots
            panellum_default_settings.panorama = sourceimage;
            panellum_default_settings.hotSpots = hotspots;
            console.log('Initializing Pannellum.')
            var viewer = window.pannellum.viewer(element_id, panellum_default_settings);
          }
          else {
            console.log('Initializing Multiscene Pannellum.');
            $.each(panellum_default_settings?.scenes , function (sceneid, data) {
              //permeate also per scene defaults from global ones ?
              Object.assign(
                panellum_default_settings.scenes[sceneid],
                panellum_default_settings || {}
              );
              // Add Model Window Behaviour to hotSpots with Links
              if (data.hasOwnProperty('hotSpots')) {
                $.each(data.hotSpots, function (hotspotid, hotspotdata) {
                  if (hotspotdata.hasOwnProperty('URL') && hotspotdata.URL !== null) {
                    if (hotspotdata.URL.indexOf('http://') === 0 || hotspotdata.URL.indexOf('https://') === 0) {
                      hotspotdata.text = hotspotdata.text + Drupal.t(' (External URL)');
                    }
                    else {
                      hotspotdata.text = hotspotdata.text + Drupal.t(' (Digital Object)');
                    }
                    panellum_default_settings.scenes[sceneid].hotSpots[hotspotid].clickHandlerFunc = Drupal.FormatStrawberryfieldhotspotPopUp;
                    panellum_default_settings.scenes[sceneid].hotSpots[hotspotid].clickHandlerArgs = hotspotdata.URL;
                  }
                });
              }
              if (mobile) {
                // Since the settings for other scenes is built by the Formatter as an Object
                // We need to replace the right Image for a mobile.
                panellum_default_settings.scenes[sceneid].panorama = panellum_default_settings.scenes[sceneid].panoramaMobile;
              }
              delete panellum_default_settings.scenes[sceneid].panoramaMobile;
            });

            var viewer = window.pannellum.viewer(element_id, panellum_default_settings);
            viewer.on('scenechange',
              function (e) {
                const event = new CustomEvent('sbf:ado:change', { bubbles: true, detail: {nodeid: e} });
                const el = document.getElementById(element_id);
                el.dispatchEvent(event);
              }
            );
          }
          FormatStrawberryfieldPanoramas.panoramas.set(element_id, new FormatStrawberryfieldPanoramas(viewer));
        }

      })
    }
  }
  /**
   * Extend the FormatStrawberryfieldPanoramas.
   */
  $.extend(
    FormatStrawberryfieldPanoramas,
    /** @lends Drupal.FormatStrawberryfieldPanoramas */ {
      /**
       * Store all created Panorama Viewer Instances.
       *
       * @type {Array.<Drupal.FormatStrawberryfieldPanoramas>}
       */
      panoramas: new Map(),
      hotspots: new Map(),
    },
  );

  // Make the FormatStrawberryfieldPanoramas object available in the Drupal namespace.
  Drupal.FormatStrawberryfieldPanoramas = FormatStrawberryfieldPanoramas;
  // Make the FormatStrawberryfieldhotspotPopUp function available in the Drupal namespace.
  Drupal.FormatStrawberryfieldhotspotPopUp = FormatStrawberryfieldhotspotPopUp;
})(jQuery, Drupal, once, drupalSettings, window.pannellum);
