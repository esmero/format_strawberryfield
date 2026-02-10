
import WaveSurfer from 'https://cdn.jsdelivr.net/npm/wavesurfer.js@7/dist/wavesurfer.esm.js';

(function ($, Drupal, WaveSurfer, once, drupalSettings) {

  'use strict';
  var viewers = [];

  const ActiveControllers = new Map();


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
          var external_control_shared = drupalSettings.format_strawberryfield.audiovideo[element_id]['use_external_control_shared'];
          var use_wavesurfer = drupalSettings.format_strawberryfield.audiovideo[element_id]['use_wavesurfer'];
          var hide_control = drupalSettings.format_strawberryfield.audiovideo[element_id]['hide_native_control'];
          var active_classes = drupalSettings.format_strawberryfield.audiovideo[element_id]['external_control_element_active_class'];
          if (active_classes.length > 0) {
            active_classes = active_classes.split(" ").filter(Boolean);
          }
          else {
            active_classes = null;
          }
          // @TODO. Future. This is a bit complex since we could also be asking
          // the Original element to be positioned somewhere else?
          var attach_control_to_media_pos = 'none';
          // Pick the control/if any.
          let $waversurfer_container = null
          if (external_control) {
            // The selector might be a class. If multiple Viewers are in the same Screen
            // We might want to assign a control to each or share.
            // We start by checking from the closest possible elements and going up until we reach the page
            // If the Control has already a media attached, then we will clone it.
            var control_blueprint = null;
            const closest_field = this.closest('.field');
            // Avoid pre-cloned controllers (already attached to another one)
            control_query_selector = control_query_selector + ':not([data-cloned])'
            if (closest_field) {
              control_blueprint = closest_field.querySelector(control_query_selector);
            }
            // Deals with Views
            if (!control_blueprint) {
              const closest_view = this.closest('.view_content');
              if (closest_view) {
                control_blueprint = control_blueprint == null ? closest_view.querySelector(control_query_selector) : control_blueprint;
              }
            }
            // Deals with ADOs/Nodes
            if (!control_blueprint) {
              const closest_node = this.closest('.node');
              if (closest_node) {
                control_blueprint = control_blueprint == null ? closest_node.querySelector(control_query_selector) : control_blueprint;
              }
            }
            // Deals with Block output (could be a view too)
            if (!control_blueprint) {
              const closest_block = this.closest('.block');
              if (closest_block) {
                control_blueprint = control_blueprint == null ? closest_block.querySelector(control_query_selector) : control_blueprint;
              }
            }
            control_blueprint = control_blueprint == null ? document.querySelector(control_query_selector) : control_blueprint;
            function customMediaController(control_blueprint, audiovideo_element, use_wavesurfer, attach_control_to_media_pos, hide_control) {
              this.active_audiovideo_element = audiovideo_element;
              this.audiovideo_elements = [];
              this.audiovideo_elements_map = new Map();
              if (!this.audiovideo_elements_map.has(audiovideo_element.id)) {
                this.audiovideo_elements_map.set(audiovideo_element.id, audiovideo_element);
                this.audiovideo_elements.push(audiovideo_element.id);
              }
              this.control = null;
              // If the control_blueprint lacks an ID (recommended people add one, specially if they want to target a specific selector
              // Then we need to add one we can track the provenance of a cloned controller
              if (control_blueprint.id == "") {
                control_blueprint.id = element_id + '-controller-blueprint';
              }
              // Sets
              this.control_blueprint = control_blueprint;
              this.valid = false;
              this.wavesurfer = false;

              // Check for the minimal needed classes inside. Play/Pause/Mute/UnMute.
              let $playBtn = control_blueprint.querySelector('.playBtn');
              let $pauseBtn = control_blueprint.querySelector('.pauseBtn');
              let $muteBtn = control_blueprint.querySelector('.muteBtn');
              let $unmuteBtn = control_blueprint.querySelector('.unmuteBtn');
              if ($playBtn && $pauseBtn && $muteBtn && $unmuteBtn) {
                // Hide original Media Controls.
                this.active_audiovideo_element.controls = !hide_control;
                this.valid = true;

                // If Video we can't hide.
                // Might be hidden already by the Formatter to avoid Popping up
                // when external control is provided. We don't hide Video.
                // But we could reposition It inside the player if there is a
                // Media Container? New option just driven by HTML?
                this.active_audiovideo_element.hidden = !use_wavesurfer || !this.active_audiovideo_element.classList.contains('video-av');
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
                  this.control.removeAttribute('id');
                  this.control.hidden = false;
                  control_blueprint.after(this.control);
                }
                // Mark as used. So we don't clone the clone.
                this.control.dataset.cloned = true;
                this.attachWaveSurfer = function() {
                  if (use_wavesurfer) {
                    $waversurfer_container = this.control.querySelector('.wavesurferContainer');
                    if ($waversurfer_container) {
                      let wavesurfer_overrides = drupalSettings.format_strawberryfield.audiovideo[this.active_audiovideo_element.id]['viewer_overrides'];
                      let default_waversurfer_settings = {
                        container: $waversurfer_container,
                        media: this.active_audiovideo_element,
                        mediaControls: false,
                        backend: "MediaElement"
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
                      try {
                        if (drupalSettings.format_strawberryfield.audiovideo[this.active_audiovideo_element.id]['waveform_url']) {
                          fetch(drupalSettings.format_strawberryfield.audiovideo[this.active_audiovideo_element.id]['waveform_url'],
                          {
                            credentials: "include"
                          }
                          )
                            .then(response => {
                              if (!response.ok) {
                                throw new Error("HTTP error " + response.status);
                              }
                              return response.json();
                            })
                            .then(peaks => {
                              if (peaks.hasOwnProperty('data') &&
                                peaks.hasOwnProperty('length') &&
                              peaks.hasOwnProperty('samples_per_pixel') &&
                              peaks.hasOwnProperty('sample_rate')) {
                                default_waversurfer_settings.peaks = peaks.data;
                                // duration = length * samples_per_pixel / sample_rate
                                default_waversurfer_settings.duration = (peaks.length * peaks.samples_per_pixel) / peaks.sample_rate;
                                default_waversurfer_settings.normalize = true;
                                console.log('loaded peaks from JSON at sample_rate: ' + peaks.sample_rate);
                                this.wavesurfer = WaveSurfer.create(default_waversurfer_settings);
                              }
                              else {
                                throw new Error("Decoded Waveform data is not properly encoded");
                              }
                            })
                            .catch((e) => {
                              console.error('error', e);
                            });
                        }
                        else {
                          this.wavesurfer = WaveSurfer.create(default_waversurfer_settings);
                          this.wavesurfer.on('loading', (percent) => {
                            console.log('Wavesurfer Loading current track', percent + '%');
                          });
                          this.wavesurfer.on('decode', () => {
                            console.log("Wavesurfer Peaks decoded");
                          })
                        }
                      }
                      catch (error) {
                        console.error("Waversurfer could not initialize for this media source " + error);
                      };
                    }
                  }
                }

                this.$pauseBtn = this.control.querySelector('.pauseBtn');
                this.$playBtn = this.control.querySelector('.playBtn');
                this.$stopBtn = this.control.querySelector('.stopBtn');
                this.$muteBtn = this.control.querySelector('.muteBtn');
                this.$unmuteBtn = this.control.querySelector('.unmuteBtn');
                this.$ccBtn = this.control.querySelector('.ccBtn');
                this.$subtitleTrack = this.control.querySelector('.subtitleTrack');
                this.$subtitleContainer = this.control.querySelector('.subtitleContainer');
                this.$mediaContainer = this.control.querySelector('.mediaContainer');
                this.$progressSlider = this.control.querySelector(".progressSlider");
                this.$volumeSlider = this.control.querySelector(".volumeSlider");
                this.$fullscreenBtn = this.control.querySelector('.fullscreenBtn');
                this.$mediaTitle = this.control.querySelector('.mediaFileName');
                // If Fullscreen API is not available, don't show the button.
                if (!document?.fullscreenEnabled && this.$fullscreenBtn !== null ) {
                  this.$fullscreenBtn.style.display = "none";
                }
                this.$currentTime = this.control.querySelector('.currentTime');
                this.$durationTime = this.control.querySelector('.durationTime');

                // Used to load next set of media, in case of a IIIF Manifest or multiple Audio/Videos.
                this.$nextBtn = this.control.querySelector('.nextBtn');
                this.$prevBtn = this.control.querySelector('.prevBtn');

                this.multipleMediaCapable = function () {
                  if (this.$nextBtn && this.$prevBtn) {
                    return true;
                  }
                  return false;
                }

                if (this.$nextBtn) {
                  this.$nextBtn.addEventListener("click", (e) => {
                    this.nextMediaElement();
                  });
                }

                if (this.$prevBtn) {
                  this.$prevBtn.addEventListener("click", (e) => {
                    this.prevMediaElement();
                  });
                }

                if (this.$fullscreenBtn && document?.fullscreenEnabled) {
                  this.$fullscreenBtn.addEventListener("click", (e) => {
                    if (document.fullscreenElement !== null) {
                      // The document is in fullscreen mode
                      document.exitFullscreen();
                    } else {
                      // The document is not in fullscreen mode
                      this.control.requestFullscreen();
                    }
                  })
                };

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
                if (this.$stopBtn) {
                  this.$stopBtn.addEventListener("click", (e) => {
                    this.active_audiovideo_element.pause();
                    this.active_audiovideo_element.currentTime = 0;
                    e.currentTarget.hidden = true;
                    this.$playBtn.hidden = false;
                    if (this.$progressSlider) {
                      this.$progressSlider.value = 0;
                    }
                  });
                }
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

                this.updateProgress = function() {
                  if (this.$progressSlider) {
                    this.$progressSlider.removeAttribute("max")
                    this.$progressSlider.addEventListener("click", (e) => {
                      if (!Number.isFinite(this.active_audiovideo_element.duration)) return;
                      const rect = this.$progressSlider.getBoundingClientRect();
                      const pos = (e.pageX - rect.left) / this.$progressSlider.offsetWidth;
                      this.active_audiovideo_element.currentTime = pos * this.active_audiovideo_element.duration;
                    });
                  }
                }
                this.updateProgress();

                this.loadeddataEventFunction = function (e) {
                  // Might not fire when swapping between multiple element sources.
                  if (e.currentTarget.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
                    console.log('Calling initializeTextTracks');
                    this.initializeTextTracks()

                    if (this.$progressSlider) {
                      this.$progressSlider.setAttribute("max", e.currentTarget.duration);
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
                  // This one is called consistently! Wow.
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
                this.updateTimeIndicators = function() {
                  if (this.active_audiovideo_element.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
                    if (this.$progressSlider) {
                      this.$progressSlider.setAttribute("max",this.active_audiovideo_element.duration);
                    }
                    if (this.$durationTime) {
                      if (this.active_audiovideo_element.duration < 3600) {
                        this.$durationTime.innerText = new Date(this.active_audiovideo_element.duration * 1000).toISOString().substring(14, 19)
                      } else {
                        this.$durationTime.innerText = new Date(this.active_audiovideo_element.duration * 1000).toISOString().substring(11, 16)
                      }
                    }
                    if (this.$currentTime) {
                      if (this.active_audiovideo_element.duration < 3600) {
                        this.$currentTime.innerText = new Date(this.active_audiovideo_element.currentTime * 1000).toISOString().substring(14, 19)
                      } else {
                        this.$currentTime.innerText = new Date(this.active_audiovideo_element.currentTime * 1000).toISOString().substring(11, 16)
                      }
                    }
                  }
                }


                this.playEventFunction = function (e) {
                  this.updateTimeIndicators();
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

                this.addMediaElement = function (audiovideo_element) {
                  if (this.multipleMediaCapable()) {
                    // hide so we don't have multiple elements all around
                    // visible.
                    audiovideo_element.hidden = true;
                    if (!this.audiovideo_elements_map.has(audiovideo_element.id)) {
                      this.audiovideo_elements_map.set(audiovideo_element.id, audiovideo_element);
                      this.audiovideo_elements.push(audiovideo_element.id);
                    }
                    this.$prevBtn.hidden = false;
                    this.$nextBtn.hidden = false;
                    return true;
                  }
                  return false;
                }

                this.stopAndResetUI = function() {
                  // We won't reset the muted. BC if muted, we want to remember that.
                  this.active_audiovideo_element.pause();
                  this.active_audiovideo_element.currentTime = 0;
                  if (this.$stopBtn) {
                    this.$stopBtn.hidden = true;
                  }
                  this.$playBtn.hidden = false;
                  this.$pauseBtn.hidden = true;
                  if (this.$progressSlider) {
                    this.$progressSlider.value = 0;
                  }
                }

                this.nextMediaElement = function () {
                  this.stopAndResetUI();
                  const muted = this.active_audiovideo_element.muted;
                  const paused = this.active_audiovideo_element.paused;
                  this.active_audiovideo_element.hidden = true;
                  this.mediaEventRemove();
                  const first = this.audiovideo_elements.shift();
                  this.active_audiovideo_element = this.audiovideo_elements_map.get(this.audiovideo_elements[0]);
                  this.active_audiovideo_element.load();
                  this.active_audiovideo_element.muted = muted;
                  this.audiovideo_elements.push(first);
                  this.mediaEventInitialize();
                  if (!paused) {
                    this.active_audiovideo_element.play();
                  }
                  if (this.wavesurfer) {
                    this.wavesurfer.destroy();
                  }
                  this.attachWaveSurfer();
                  this.updateTimeIndicators();
                }
                this.prevMediaElement = function () {
                  this.stopAndResetUI();
                  const muted = this.active_audiovideo_element.muted;
                  const paused = this.active_audiovideo_element.paused;
                  this.mediaEventRemove();
                  const last = this.audiovideo_elements.pop();
                  this.active_audiovideo_element = this.audiovideo_elements_map.get(last);
                  this.active_audiovideo_element.muted = muted;
                  this.active_audiovideo_element.load();
                  this.audiovideo_elements.unshift(last);
                  this.mediaEventInitialize();
                  if (!paused) {
                    this.active_audiovideo_element.play();
                  }
                  if (this.wavesurfer) {
                    this.wavesurfer.destroy();
                  }
                  this.attachWaveSurfer();
                  this.updateTimeIndicators();
                }

                this.trackCueEvent = function(e) {
                  console.log('cue changed');
                  if (this.$subtitleContainer) {
                    this.$subtitleContainer.innerHTML = ''; // Clear previous subtitle
                    // Display current cue text
                    if (e.currentTarget.activeCues.length > 0) {
                      const currentCue = e.currentTarget.activeCues[0];
                      const subtitleText = document.createElement('em');
                      subtitleText.textContent = currentCue.text;
                      // We need per track containers here. Because the user could enable multiple Tracks at the same time?
                      this.$subtitleContainer.appendChild(subtitleText);
                    }
                  }
                }

                this.captionStatus = function(e) {
                  console.log(e);
                }

                this.initializeTextTracks = function() {
                  // In case we are swapping media after initialization. We will remove all existing tracks first
                  this.control.querySelectorAll('.subtitleTrack-active[data-track-id]').forEach(e => e.remove());
                  let $showing_subtitles = null;
                  const textTracks = this.active_audiovideo_element.textTracks;
                  textTracks.addEventListener("change", this.captionStatus, false);
                  if (textTracks.length > 0) {

                    let $i = 0;
                    for (const track of textTracks) {
                      if (track.kind !== "metadata") {
                        track.addEventListener('cuechange', (e) => {
                          let cues = e.target.activeCues;
                          this.$subtitleContainer.innerHTML = ''; // Clear previous subtitle
                          // Display current cue text
                          if (e.target.activeCues.length > 0) {
                            const currentCue = e.target.activeCues[0];
                            const subtitleText = document.createElement('em');
                            subtitleText.textContent = currentCue.text;
                            // We need per track containers here. Because the user could enable multiple Tracks at the same time?
                            this.$subtitleContainer.appendChild(subtitleText);
                          }
                        });

                        if (this.$subtitleContainer) {
                          // Note. Some browsers allow multiple track.mode == showing
                          // Some toggle.
                          // We can't depend on the browser here, so we will toggle
                          // @TODO. We can't signal right now if we have multiple types
                          // Like description, subtitle and captions
                          // But in the future we should have a way
                          // Listen for cue changes
                          // Clone the $subtitleTrack, add onclick logic to swap subtitle
                          if (this.$subtitleTrack) {
                            const $subtitleTrackClone = this.$subtitleTrack.cloneNode(true);
                            $subtitleTrackClone.hidden = false;
                            $subtitleTrackClone.classList.add('subtitleTrack-active');
                            $subtitleTrackClone.dataset.trackId = $i;
                            const track_label = track.label;
                            if (track.label == '') {
                              track.label = track.kind + " " + $i;
                            }
                            $subtitleTrackClone.innerText = track_label
                            if (track.mode == "showing") {
                              if (active_classes) {
                                for (const $class of active_classes) {
                                  $subtitleTrackClone.classList.toggle($class)
                                }
                              }
                            }
                            this.$subtitleTrack.before($subtitleTrackClone);
                            $subtitleTrackClone.addEventListener("click", (e) => {
                              console.log(e.currentTarget);

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
                                for (const subtitleTrack of textTracks) {
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
                          $i++;
                        }
                      }
                    }
                  }
                }

                this.mediaEventInitialize = function() {
                  this.active_audiovideo_element.hidden = (use_wavesurfer && !this.active_audiovideo_element.classList.contains('video-av'));
                  this.active_audiovideo_element.addEventListener("loadeddata", this.loadeddataEventFunctionBound );
                  this.active_audiovideo_element.addEventListener("timeupdate", this.timeupdateEventFunctionBound );
                  this.active_audiovideo_element.addEventListener("ended", this.endedEventFunctionBound );
                  this.active_audiovideo_element.addEventListener("pause", this.pauseEventFunctionBound );
                  // https://developer.mozilla.org/en-US/docs/Web/API/HTMLMediaElement/play_event
                  this.active_audiovideo_element.addEventListener("play", this.playEventFunctionBound );
                  this.active_audiovideo_element.addEventListener("volumechange", this.volumechangeEventFunctionBound);
                  if (this.$mediaContainer && !this.active_audiovideo_element.dataset.repositioned) {
                    this.$mediaContainer.appendChild(this.active_audiovideo_element)
                    this.active_audiovideo_element.dataset.repositioned = true;
                  }
                  if (this.$mediaTitle) {
                    if (this.active_audiovideo_element.ariaLabel) {
                      this.$mediaTitle.innerText = this.active_audiovideo_element.ariaLabel;
                    }
                  }
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
                this.attachWaveSurfer();

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
              } else {
                // If no Play/Pause mute and unmute/default to not hidden
                // and show controls
                this.active_audiovideo_element.hidden = false;
                this.active_audiovideo_element.controls = true;
              }
            }

            // and this is where we actually attach to existing (adding media to it)
            // Or create a new instance from scratch.
            if (control_blueprint)  {
              // Now how we decide? new instance? Clone?
              let attached_to_existing = false
              // In that case we match the IDs.
              if (external_control_shared) {
                if (typeof ActiveControllers.get(control_blueprint.id) !== "undefined" ) {
                  // returns false if the Controller Blueprint has no  next/prev buttons, And also does not add more media.
                  if (ActiveControllers.get(control_blueprint.id).addMediaElement(this)) {
                    attached_to_existing = true;
                  }
                }
              }
              if (!attached_to_existing) {
                const Controllerinstance = new customMediaController(control_blueprint, this, use_wavesurfer, attach_control_to_media_pos, hide_control);
                if (Controllerinstance.valid && Controllerinstance.multipleMediaCapable && external_control_shared) {
                  ActiveControllers.set(Controllerinstance.control_blueprint.id, Controllerinstance);
                }
              }
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
          if (use_wavesurfer && !control_blueprint) {
            $waversurfer_container = $waversurfer_container == null ? this.parentNode.querySelector('.strawberry-av-item-wavesurfer') : $waversurfer_container;
            let wavesurfer_overrides =  drupalSettings.format_strawberryfield.audiovideo[element_id]['viewer_overrides'];
            let default_waversurfer_settings = {
              container: $waversurfer_container,
              media: this,
              mediaControls: !hide_control
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
            try {
              if (drupalSettings.format_strawberryfield.audiovideo[element_id]['waveform_url']) {
                fetch(drupalSettings.format_strawberryfield.audiovideo[element_id]['waveform_url'],
                  {
                    credentials: "include"
                  }
                )
                  .then(response => {
                    if (!response.ok) {
                      throw new Error("HTTP error " + response.status);
                    }
                    return response.json();
                  })
                  .then(peaks => {
                    if (peaks.hasOwnProperty('data') &&
                      peaks.hasOwnProperty('length') &&
                      peaks.hasOwnProperty('samples_per_pixel') &&
                      peaks.hasOwnProperty('sample_rate')) {
                      default_waversurfer_settings.peaks = peaks.data;
                      // duration = length * samples_per_pixel / sample_rate
                      default_waversurfer_settings.duration = (peaks.length * peaks.samples_per_pixel) / peaks.sample_rate;
                      default_waversurfer_settings.normalize = true;
                      console.log('loaded peaks from JSON at sample_rate: ' + peaks.sample_rate);
                      const wavesurfer = WaveSurfer.create(default_waversurfer_settings);
                    } else {
                      throw new Error("Decoded Waveform data is not properly encoded");
                    }
                  })
                  .catch((e) => {
                    console.error('error', e);
                  });
              }
              else {
                const wavesurfer = WaveSurfer.create(default_waversurfer_settings);
              }
            }
            catch (error) {
              console.error("Waversurfer could not initialize for this media soruce " + error);
            };
          }
        }
      });
    }
  };

})(jQuery, Drupal, WaveSurfer, once, drupalSettings);
