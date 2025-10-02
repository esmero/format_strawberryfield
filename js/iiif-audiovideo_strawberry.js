
import WaveSurfer from 'https://cdn.jsdelivr.net/npm/wavesurfer.js@7/dist/wavesurfer.esm.js';

(function ($, Drupal, WaveSurfer, once, drupalSettings) {

  'use strict';
  var viewers = [];

  function FormatStrawberryfieldMediaControllers(customMediaControllerInstance) {
    this.controllerInstances = customMediaControllerInstance;
  }

  Drupal.behaviors.format_strawberryfield_audiovideo = {
    attach: function (context, settings) {
      var groupsid =  {};
      const elementsToAttach = once('attache_audio_video', '.strawberry-av-item-js', context);
      $(elementsToAttach).each(function (index, value) {
        // Get the node uuid for this element
        var element_id = $(this).attr("id");
        if  (typeof drupalSettings?.format_strawberryfield?.audiovideo[element_id] !== "undefined") {
          var external_control = drupalSettings.format_strawberryfield.audiovideo[element_id]['use_external_control'];
          var control_query_selector = drupalSettings.format_strawberryfield.audiovideo[element_id]['external_control_selector'];
          var use_wavesurfer = drupalSettings.format_strawberryfield.audiovideo[element_id]['use_wavesurfer'];
          var hide_controls = drupalSettings.format_strawberryfield.audiovideo[element_id]['hide_native_controls'];
          var active_classes = drupalSettings.format_strawberryfield.audiovideo[element_id]['external_control_element_active_class'];
          if (active_classes.length > 0) {
            active_classes = active_classes.split(" ").filter(Boolean);
          }
          else {
            active_classes = null;
          }
          var attach_control_to_media_pos = 'after';
          // Pick the control/if any.
          var control = null;
          let $waversurfer_container = null
          if (external_control) {
            // The selector might be a class. If multiple Viewers are in the same Screen
            // We might want to assign a control to each.
            // Sharing a single control Might be a future use case, but focusing on the most common one
            // So we start by checking from closest possible elements and going up until we reach the page
            // If the Control has already a media attached, then we will clone it.
            var control_blueprint = null;
            const closest_field = this.closest('.field');
            control_query_selector = control_query_selector + ':not([data-cloned])'
            if (closest_field) {
              control_blueprint = closest_field.querySelector(control_query_selector);
            }
            if (!control_blueprint) {
              const closest_view = this.closest('.view_content');
              if (closest_view) {
                control_blueprint = control_blueprint == null ? closest_view.querySelector(control_query_selector) : control_blueprint;
              }
            }
            if (!control_blueprint) {
              const closest_node = this.closest('.node');
              if (closest_node) {
                control_blueprint = control_blueprint == null ? closest_node.querySelector(control_query_selector) : control_blueprint;
              }
            }
            if (!control_blueprint) {
              const closest_block = this.closest('.block');
              if (closest_block) {
                control_blueprint = control_blueprint == null ? closest_block.querySelector(control_query_selector) : control_blueprint;
              }
            }
            control_blueprint = control_blueprint == null ? document.querySelector(control_query_selector) : control_blueprint;
            function customMediaController(control_blueprint, audiovideo_element, use_wavesurfer, attach_control_to_media_pos) {
              this.active_audiovideo_element = audiovideo_element;
              this.audiovideo_elements = [];
              this.audiovideo_elements.push(audiovideo_element);
              this.control = null;
              this.control_blueprint = control_blueprint;
              // Check for the minimal needed classes inside. Play/Pause/Mute/UnMute.
              let $playBtn = control_blueprint.querySelector('.playBtn');
              let $pauseBtn = control_blueprint.querySelector('.pauseBtn');
              let $muteBtn = control_blueprint.querySelector('.muteBtn');
              let $unmuteBtn = control_blueprint.querySelector('.unmuteBtn');
              if ($playBtn && $pauseBtn && $muteBtn && $unmuteBtn) {
                // Hide original Media Controls.
                this.active_audiovideo_element.controls = !hide_controls;
                // If Video we can't hide.
                // Might be hidden already by the Formatter to avoid Popping up
                // when external control is provided.
                this.active_audiovideo_element.hidden = !use_wavesurfer || this.active_audiovideo_element.classList.contains('.video-av');
                // Only here we can actually start doing things.
                // We will clone deep.
                // and hide the original (if not hidden already).
                if (attach_control_to_media_pos !== 'none') {
                  control_blueprint.hidden = true;
                  this.control = control_blueprint.cloneNode(true);
                  this.control.removeAttribute('id');
                  if (attach_control_to_media_pos == 'after') {
                    this.active_audiovideo_element.after(this.control);
                    this.control.hidden = false;
                  } else if (attach_control_to_media_pos == 'before') {
                    this.active_audiovideo_element.before(this.control);
                    this.control.hidden = false;
                  }
                } else {
                  control_blueprint.hidden = true;
                  this.control = control_blueprint.cloneNode(true);
                  this.control.hidden = false;
                  control_blueprint.after(this.control);
                  this.control.removeAttribute('id');
                }
                // Mark as used. So we don't clone the clone.
                this.control.dataset.cloned = true;
                if (use_wavesurfer) {
                  $waversurfer_container = this.control.querySelector('.wavesurferContainer');
                }

                this.$subtitleContainer = this.control.querySelector('.subtitleContainer');

                this.$pauseBtn = this.control.querySelector('.pauseBtn');
                this.$playBtn = this.control.querySelector('.playBtn');
                this.$stopBtn = this.control.querySelector('.stopBtn');
                this.$muteBtn = this.control.querySelector('.muteBtn');
                this.$unmuteBtn = this.control.querySelector('.unmuteBtn');
                this.$ccBtn = this.control.querySelector('.ccBtn');
                this.$subtitleTrack = this.control.querySelector('.subtitleTrack');

                this.$progressSlider = this.control.querySelector(".progressSlider");

                this.$volumeSlider = this.control.querySelector(".volumeSlider");
                this.$fullscreenBtn = this.control.querySelector('.fullscreenBtn');
                this.$currentTime = this.control.querySelector('.currentTime');
                this.$durationTime = this.control.querySelector('.durationTime');

                // Used to load next set of media, in case of a IIIF Manifest or multiple Audio/Videos.
                this.$nextBtn = this.control.querySelector('.nextBtn');
                this.$prevBtn = this.control.querySelector('.prevBtn');

                // Hide Play and Stop button
                this.$pauseBtn.hidden = true;
                this.$unmuteBtn.hidden = true;

                if (this.$ccBtn) {
                  // hide initially
                  this.$ccBtn.hidden = true;
                }

                if (this.$nextBtn) {
                  // hide initially
                  this.$nextBtn.hidden = true;
                }

                if (this.$prevBtn) {
                  // hide initially
                  this.$prevBtn.hidden = true;
                }

                if (this.$stopBtn) {
                  this.$stopBtn.hidden = true;
                }

                if (this.$subtitleTrack) {
                  // hide initially. We will need to clone this one for each track
                  // And allow it to enable/disable subtitles.
                  this.$subtitleTrack.hidden = true;
                }

                this.$playBtn.addEventListener("click", (e) => {
                  if (this.active_audiovideo_element.paused || this.active_audiovideo_element.ended) {
                    this.active_audiovideo_element.play();
                    e.currentTarget.hidden = true;
                    this.$pauseBtn.hidden = false;
                    if (this.$stopBtn) {
                      this.$stopBtn.hidden = false;
                    }
                  } else {
                    // Because we have separate buttons for each action
                    // this should never hit.
                    this.active_audiovideo_element.pause();
                  }
                });
                this.$pauseBtn.addEventListener("click", (e) => {
                  if (!this.active_audiovideo_element.paused && !this.active_audiovideo_element.ended) {
                    this.active_audiovideo_element.pause();
                    e.currentTarget.hidden = true;
                    this.$playBtn.hidden = false;
                    if (this.$stopBtn) {
                      this.$stopBtn.hidden = true;
                    }
                  } else {
                    // Because we have separate buttons for each action
                    // this should never hit.
                    this.active_audiovideo_element.play();
                  }
                });
                this.$muteBtn.addEventListener("click", (e) => {
                  this.active_audiovideo_element.muted = !this.active_audiovideo_element.muted;
                  e.currentTarget.hidden = true;
                  this.$unmuteBtn.hidden = false;
                });
                this.$unmuteBtn.addEventListener("click", (e) => {
                  this.active_audiovideo_element.muted = !this.active_audiovideo_element.muted;
                  e.currentTarget.hidden = true;
                  this.$muteBtn.hidden = false;
                });
                // We only attach $progressSlider if present AND waversurfer
                // is not being used. Why? because Waversurfer Is a slider.
                if (this.$progressSlider) {
                  this.$progressSlider.removeAttribute("max")
                  this.$progressSlider.addEventListener("click", (e) => {
                    if (!Number.isFinite(this.active_audiovideo_element.duration)) return;
                    const rect = this.$progressSlider.getBoundingClientRect();
                    const pos = (e.pageX - rect.left) / this.$progressSlider.offsetWidth;
                    this.active_audiovideo_element.currentTime = pos * this.active_audiovideo_element.duration;
                  });
                }

                this.loadeddataEventFunction = function (e) {
                  if (e.currentTarget.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
                    if (this.$progressSlider) {
                      this.$progressSlider.setAttribute("max", this.active_audiovideo_element.duration);
                    }
                    if (this.$durationTime) {
                      if (e.currentTarget.duration < 3600) {
                        this.$durationTime.innerText = new Date(e.currentTarget.duration * 1000).toISOString().substring(14, 19)
                      } else {
                        this.$durationTime.innerText = new Date(e.currentTarget.duration * 1000).toISOString().substring(11, 16)
                      }
                    }
                  }
                };
                this.timeupdateEventFunction = function (e) {
                  if (this.$currentTime) {
                    if (e.currentTarget.duration < 3600) {
                      this.$currentTime.innerText = new Date(e.currentTarget.currentTime * 1000).toISOString().substring(14, 19)
                    } else {
                      this.$currentTime.innerText = new Date(e.currentTarget.currentTime * 1000).toISOString().substring(11, 16)
                    }
                  }
                  if (this.$progressSlider) {
                    this.$progressSlider.value = e.currentTarget.currentTime;
                  }
                  // if native is visible and controls too then we need to sync buttons
                  if (e.currentTarget.controls && !e.currentTarget.hidden && this.$pauseBtn.hidden && !e.currentTarget.paused) {
                    this.$playBtn.hidden = true;
                    this.$pauseBtn.hidden = false;
                    if (this.$stopBtn) {
                      this.$stopBtn.hidden = false;
                    }
                  }
                };
                this.endedEventFunction = function (e) {
                  if (!this.$pauseBtn.hidden) {
                    this.$playBtn.hidden = false;
                    this.$pauseBtn.hidden = true;
                  }
                  if (this.$stopBtn) {
                    if (!this.$stopBtn.hidden) {
                      this.$stopBtn.hidden = true;
                    }
                  }
                };
                this.pauseEventFunction = function (e) {
                  if (!this.$pauseBtn.hidden) {
                    this.$playBtn.hidden = false;
                    this.$pauseBtn.hidden = true;
                  }
                  if (this.$stopBtn) {
                    if (!this.$stopBtn.hidden) {
                      this.$stopBtn.hidden = true;
                    }
                  }
                }
                this.playEventFunction = function (e) {
                  if (!this.$playBtn.hidden) {
                    this.$playBtn.hidden = true;
                    this.$pauseBtn.hidden = false;
                  }
                  if (this.$stopBtn) {
                    if (this.$stopBtn.hidden) {
                      this.$stopBtn.hidden = false;
                    }
                  }
                }
                this.volumechangeEventFunction = function (e) {
                  if (this.$volumeSlider) {
                    this.$volumeSlider.value = e.currentTarget.volume;
                  }
                }
                this.loadeddataEventFunctionBound = this.loadeddataEventFunction.bind(this);
                this.timeupdateEventFunctionBound = this.timeupdateEventFunction.bind(this);
                this.endedEventFunctionBound  =  this.endedEventFunction.bind(this);
                this.pauseEventFunctionBound   =  this.pauseEventFunction.bind(this);
                this.playEventFunctionBound  =  this.playEventFunction.bind(this);
                this.volumechangeEventFunctionBound  =  this.volumechangeEventFunction.bind(this);

                this.mediaEventInitialize = function() {
                  this.active_audiovideo_element.addEventListener("loadeddata", this.loadeddataEventFunctionBound );
                  this.active_audiovideo_element.addEventListener("timeupdate", this.timeupdateEventFunctionBound );
                  this.active_audiovideo_element.addEventListener("ended", this.endedEventFunctionBound );
                  this.active_audiovideo_element.addEventListener("pause", this.pauseEventFunctionBound );
                  // https://developer.mozilla.org/en-US/docs/Web/API/HTMLMediaElement/play_event
                  this.active_audiovideo_element.addEventListener("play", this.playEventFunctionBound );
                  this.active_audiovideo_element.addEventListener("volumechange", this.volumechangeEventFunctionBound);
                }
                this.mediaEventRemove = function() {
                  this.active_audiovideo_element.removeEventListener("loadeddata", this.loadeddataEventFunctionBound );
                  this.active_audiovideo_element.removeEventListener("timeupdate", this.timeupdateEventFunctionBound );
                  this.active_audiovideo_element.removeEventListener("ended", this.endedEventFunctionBound );
                  this.active_audiovideo_element.removeEventListener("pause", this.pauseEventFunctionBound );
                  // https://developer.mozilla.org/en-US/docs/Web/API/HTMLMediaElement/play_event
                  this.active_audiovideo_element.removeEventListener("play", this.playEventFunction);
                  this.active_audiovideo_element.removeEventListener("volumechange", this.volumechangeEventFunctionBound );
                }
                this.mediaEventInitialize();

                if (this.$volumeSlider) {
                  this.$volumeSlider.setAttribute("max", 1);
                  this.$volumeSlider.setAttribute("min", 0);
                  const currentVolume = Math.floor(this.active_audiovideo_element.volume * 10) / 10;
                  this.$volumeSlider.value = currentVolume;
                  this.$volumeSlider.addEventListener("click", (e) => {
                    const currentVolume = Math.floor(this.active_audiovideo_element.volume * 10) / 10;
                    if (!Number.isFinite(currentVolume)) return;
                    const rect = e.currentTarget.getBoundingClientRect();
                    const writing_mode = window.getComputedStyle(e.currentTarget).getPropertyValue('writing-mode');
                    let pos = 0;
                    if (writing_mode.includes('vertical')) {
                      const direction = window.getComputedStyle(e.currentTarget).getPropertyValue('direction');
                      if (direction == 'rtl') {
                        //means 0 is at the bottom
                        pos = ((e.currentTarget.offsetHeight - (e.clientY - rect.top)) /  e.currentTarget.offsetHeight).toFixed(1);
                      } else {
                        // Means normal, 0 is at the top
                        pos = (( e.currentTarget.offsetHeight - (rect.bottom - e.clientY)) /  e.currentTarget.offsetHeight).toFixed(1);
                      }
                    } else {
                      pos = (e.pageX - rect.left) / e.currentTarget.offsetWidth;
                    }
                    if (pos <= 1 && pos >= 0) {
                      this.active_audiovideo_element.volume = pos;
                    }
                  });
                }
                this.initializeTextTracks();
              } else {
                // If no Play/Pause mute and unmute/default to not hidden
                // and show controls
                this.active_audiovideo_element.hidden = false;
                this.active_audiovideo_element.controls = true;
              }
            }

            customMediaController.prototype.initializeTextTracks = function() {
              // In case we are swapping media after initialization. We will remove all existing tracks first
              this.control.querySelectorAll('.subtitleTrack-active[data-track-id]').forEach(e => e.remove());
              let $showing_subtitles = null;
              if (this.active_audiovideo_element.textTracks.length > 0) {
                this.$ccBtn.hidden = false;
                let $i = 0;
                for (const track of this.active_audiovideo_element.textTracks) {
                  if (this.$subtitleContainer) {
                    // Note. Some browsers allow multiple track.mode == showing
                    // Some toggle.
                    // We can't depend on the browser here, so we will toggle
                    // @TODO. We can't signal right now if we have multiple types
                    // Like description, subtitle and captions
                    // But in the future we should have a way
                    if (track.default) {
                      track.mode = "showing";
                    }
                    // Listen for cue changes
                    // Clone the $subtitleTrack, add onclick logic to swap subtitle
                    if (this.$subtitleTrack) {
                      let $subtitleTrackClone = this.$subtitleTrack.cloneNode(true);
                      $subtitleTrackClone.hidden = false;
                      $subtitleTrackClone.classList.add('subtitleTrack-active');
                      $subtitleTrackClone.dataset.trackId = $i;
                      $subtitleTrackClone.innerText = track.label;
                      if (track.mode == "showing") {
                        if (active_classes) {
                          for (const $class of active_classes) {
                            $subtitleTrackClone.classList.toggle($class)
                          }
                        }
                      }
                      this.$subtitleTrack.before($subtitleTrackClone);
                      $subtitleTrackClone.addEventListener("click", (e) => {
                        console.log(track);
                        console.log(track.mode);
                        console.log(e.currentTarget.dataset);

                        if (track.mode == "showing" || track.mode == "hidden") {
                          track.mode = "disabled";
                          this.$subtitleContainer.innerHTML = '';
                          if (active_classes) {
                            for (const $class of active_classes) {
                              e.currentTarget.classList.toggle($class, false)
                            }
                          }
                        } else {
                          // First make all other not showing.
                          for (const subtitleTrack of this.active_audiovideo_element.textTracks) {
                            subtitleTrack.mode = "disabled"
                          }

                          track.mode = "showing";
                          // Means I need to toggle any other one active
                          if (active_classes) {
                            const trackId = e.currentTarget.dataset.trackId;
                            const $allothertracks = this.control.querySelectorAll('.subtitleTrack-active[data-track-id]:not([data-track-id="' + trackId + '"])');

                            $allothertracks.forEach((subtitleTrackCloneElement) => {
                              for (const $class of active_classes) {
                                subtitleTrackCloneElement.classList.toggle($class, false)
                              }
                            });
                            for (const $class of active_classes) {
                              e.currentTarget.classList.toggle($class, true)
                            }
                          }
                        }
                      });
                    }

                    track.addEventListener('cuechange', () => {
                      this.$subtitleContainer.innerHTML = ''; // Clear previous subtitle
                      // Display current cue text
                      if (track.activeCues.length > 0) {
                        const currentCue = track.activeCues[0];
                        const subtitleText = document.createElement('em');
                        subtitleText.textContent = currentCue.text;
                        // We need per track containers here. Because the user could enable multiple Tracks at the same time?
                        this.$subtitleContainer.appendChild(subtitleText);
                      }
                    });
                    $i++;
                  }
                }
                this.$ccBtn.addEventListener("click", (e) => {
                  if (active_classes) {
                    for (const $class of active_classes) {
                      this.$ccBtn.classList.toggle($class)
                    }
                  }
                });
              }
            }

            if (control_blueprint)  {
              const Controllerinstance = new customMediaController(control_blueprint, this, use_wavesurfer, attach_control_to_media_pos);
            }
            else {
              // Control Selector lead to no UI.
              this.hidden = false;
              this.controls = true;
            }
          }
          else {
            // Just in case, restore visible and controls.
            this.hidden = false;
            this.controls = true;
          }
          if (use_wavesurfer) {
            $waversurfer_container = $waversurfer_container == null ? this.parentNode.querySelector('.strawberry-av-item-wavesurfer') : $waversurfer_container;
            let wavesurfer_overrides =  drupalSettings.format_strawberryfield.audiovideo[element_id]['viewer_overrides'];
            let default_waversurfer_settings = {
              container: $waversurfer_container,
              media: this,
              mediaControls: control == null ? true : false
            };
            if (typeof wavesurfer_overrides == 'object' &&
              !Array.isArray(wavesurfer_overrides) &&
              wavesurfer_overrides !== null) {
              delete wavesurfer_overrides?.url;
              delete wavesurfer_overrides?.media;
              delete wavesurfer_overrides?.container;
              default_waversurfer_settings = {
                ...default_waversurfer_settings,
                ...wavesurfer_overrides,
              };
            }
            const wavesurfer = WaveSurfer.create(default_waversurfer_settings)
          }
        }
      });
    }
  };

  /**
   * Extend the FormatStrawberryfieldPanoramas.
   */
  $.extend(
    FormatStrawberryfieldMediaControllers,
    /** @lends Drupal.FormatStrawberryfieldMediaControllers */ {
      /**
       * Store all created Panorama Viewer Instances.
       *
       * @type {Array.<Drupal.FormatStrawberryfieldMediaControllers>}
       */
      controllerInstances: new Map(),
    },
  );

  // Make the FormatStrawberryfieldPanoramas object available in the Drupal namespace.
  Drupal.FormatStrawberryfieldMediaControllers = FormatStrawberryfieldMediaControllers;


})(jQuery, Drupal, WaveSurfer, once, drupalSettings);
