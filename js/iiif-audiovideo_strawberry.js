
import WaveSurfer from 'https://cdn.jsdelivr.net/npm/wavesurfer.js@7/dist/wavesurfer.esm.js';

(function ($, Drupal, WaveSurfer, once, drupalSettings) {

  'use strict';
  var viewers = [];

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
                // If we have to move the element, then will clone deep, edit the ID (should not have one)
                // and hide the original (if not hidden).
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
                if (use_wavesurfer) {
                  $waversurfer_container = this.control.querySelector('.wavesurferContainer');
                }

                const $subtitleContainer = this.control.querySelector('.subtitleContainer');

                const $pauseBtn = this.control.querySelector('.pauseBtn');
                const $playBtn = this.control.querySelector('.playBtn');
                const $stopBtn = this.control.querySelector('.stopBtn');
                const $muteBtn = this.control.querySelector('.muteBtn');
                const $unmuteBtn = this.control.querySelector('.unmuteBtn');
                const $ccBtn = this.control.querySelector('.ccBtn');
                const $subtitleTrack = this.control.querySelector('.subtitleTrack');

                const $progressSlider = this.control.querySelector(".progressSlider");

                const $volumeSlider = this.control.querySelector(".volumeSlider");
                const $fullscreenBtn = this.control.querySelector('.fullscreenBtn');
                const $currentTime = this.control.querySelector('.currentTime');
                const $durationTime = this.control.querySelector('.durationTime');

                // Used to load next set of media, in case of a IIIF Manifest or multiple Audio/Videos.
                const $nextBtn = this.control.querySelector('.nextBtn');
                const $prevBtn = this.control.querySelector('.prevBtn');

                // Hide Play and Stop button
                $pauseBtn.hidden = true;
                $unmuteBtn.hidden = true;

                if ($ccBtn) {
                  // hide initially
                  $ccBtn.hidden = true;
                }

                if ($nextBtn) {
                  // hide initially
                  $nextBtn.hidden = true;
                }

                if ($prevBtn) {
                  // hide initially
                  $prevBtn.hidden = true;
                }

                if ($stopBtn) {
                  $stopBtn.hidden = true;
                }

                if ($subtitleTrack) {
                  // hide initially. We will need to clone this one for each track
                  // And allow it to enable/disable subtitles.
                  $subtitleTrack.hidden = true;
                }

                $playBtn.addEventListener("click", (e) => {
                  if (this.active_audiovideo_element.paused || this.active_audiovideo_element.ended) {
                    this.active_audiovideo_element.play();
                    e.currentTarget.hidden = true;
                    $pauseBtn.hidden = false;
                    if ($stopBtn) {
                      $stopBtn.hidden = false;
                    }
                  } else {
                    // Because we have separate buttons for each action
                    // this should never hit.
                    this.active_audiovideo_element.pause();
                  }
                });
                $pauseBtn.addEventListener("click", (e) => {
                  if (!this.active_audiovideo_element.paused && !this.active_audiovideo_element.ended) {
                    this.active_audiovideo_element.pause();
                    e.currentTarget.hidden = true;
                    $playBtn.hidden = false;
                    if ($stopBtn) {
                      $stopBtn.hidden = true;
                    }
                  } else {
                    // Because we have separate buttons for each action
                    // this should never hit.
                    this.active_audiovideo_element.play();
                  }
                });
                $muteBtn.addEventListener("click", (e) => {
                  this.active_audiovideo_element.muted = !this.active_audiovideo_element.muted;
                  e.currentTarget.hidden = true;
                  $unmuteBtn.hidden = false;
                });
                $unmuteBtn.addEventListener("click", (e) => {
                  this.active_audiovideo_element.muted = !this.active_audiovideo_element.muted;
                  e.currentTarget.hidden = true;
                  $muteBtn.hidden = false;
                });
                // We only attach $progressSlider if present AND waversurfer
                // is not being used. Why? because Waversurfer Is a slider.
                if ($progressSlider) {
                  $progressSlider.removeAttribute("max")
                  $progressSlider.addEventListener("click", (e) => {
                    if (!Number.isFinite(this.active_audiovideo_element.duration)) return;
                    const rect = $progressSlider.getBoundingClientRect();
                    const pos = (e.pageX - rect.left) / $progressSlider.offsetWidth;
                    this.active_audiovideo_element.currentTime = pos * this.active_audiovideo_element.duration;
                  });
                }

                // ON enough data, update the Duration time.
                this.active_audiovideo_element.addEventListener("loadeddata", () => {
                  if (this.active_audiovideo_element.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
                    if ($progressSlider) {
                      $progressSlider.setAttribute("max", this.active_audiovideo_element.duration);
                    }
                    if ($durationTime) {
                      if (this.active_audiovideo_element.duration < 3600) {
                        $durationTime.innerText = new Date(this.active_audiovideo_element.duration * 1000).toISOString().substring(14, 19)
                      } else {
                        $durationTime.innerText = new Date(this.active_audiovideo_element.duration * 1000).toISOString().substring(11, 16)
                      }
                    }
                  }
                });

                this.active_audiovideo_element.addEventListener("timeupdate", () => {
                  if ($currentTime) {
                    if (this.active_audiovideo_element.duration < 3600) {
                      $currentTime.innerText = new Date(this.active_audiovideo_element.currentTime * 1000).toISOString().substring(14, 19)
                    } else {
                      $currentTime.innerText = new Date(this.active_audiovideo_element.currentTime * 1000).toISOString().substring(11, 16)
                    }
                  }
                  if ($progressSlider) {
                    $progressSlider.value = this.active_audiovideo_element.currentTime;
                  }
                  // if native is visible and controls too then we need to sync buttons
                  if (this.active_audiovideo_element.controls && this.active_audiovideo_element.hidden == false && $pauseBtn.hidden) {
                    $playBtn.hidden = true;
                    $pauseBtn.hidden = false;
                    if ($stopBtn) {
                      $stopBtn.hidden = false;
                    }
                  }
                });
                // https://developer.mozilla.org/en-US/docs/Web/API/HTMLMediaElement/ended_event
                this.active_audiovideo_element.addEventListener("ended", (event) => {
                  if (!$pauseBtn.hidden) {
                    $playBtn.hidden = false;
                    $pauseBtn.hidden = true;
                  }
                  if ($stopBtn) {
                    if (!$stopBtn.hidden) {
                      $stopBtn.hidden = true;
                    }
                  }
                });

                this.active_audiovideo_element.addEventListener("pause", (event) => {
                  if (!$pauseBtn.hidden) {
                    $playBtn.hidden = false;
                    $pauseBtn.hidden = true;
                  }
                  if ($stopBtn) {
                    if (!$stopBtn.hidden) {
                      $stopBtn.hidden = true;
                    }
                  }
                });
                // https://developer.mozilla.org/en-US/docs/Web/API/HTMLMediaElement/play_event
                this.active_audiovideo_element.addEventListener("play", (event) => {
                  if (!$playBtn.hidden) {
                    $playBtn.hidden = true;
                    $pauseBtn.hidden = false;
                  }
                  if ($stopBtn) {
                    if ($stopBtn.hidden) {
                      $stopBtn.hidden = false;
                    }
                  }
                });

                if ($volumeSlider) {
                  $volumeSlider.setAttribute("max", 1);
                  $volumeSlider.setAttribute("min", 0);
                  const currentVolume = Math.floor(this.active_audiovideo_element.volume * 10) / 10;
                  $volumeSlider.value = currentVolume;
                  $volumeSlider.addEventListener("click", (e) => {
                    const currentVolume = Math.floor(this.active_audiovideo_element.volume * 10) / 10;
                    if (!Number.isFinite(currentVolume)) return;
                    const rect = $volumeSlider.getBoundingClientRect();
                    const writing_mode = window.getComputedStyle($volumeSlider).getPropertyValue('writing-mode');
                    let pos = 0;
                    if (writing_mode.includes('vertical')) {
                      const direction = window.getComputedStyle($volumeSlider).getPropertyValue('direction');
                      if (direction == 'rtl') {
                        //means 0 is at the bottom
                        pos = (($volumeSlider.offsetHeight - (e.clientY - rect.top)) / $volumeSlider.offsetHeight).toFixed(1);
                      } else {
                        // Means normal, 0 is at the top
                        pos = (($volumeSlider.offsetHeight - (rect.bottom - e.clientY)) / $volumeSlider.offsetHeight).toFixed(1);
                      }
                    } else {
                      pos = (e.pageX - rect.left) / $volumeSlider.offsetWidth;
                    }
                    if (pos <= 1 && pos >= 0) {
                      this.active_audiovideo_element.volume = pos;
                    }
                  });
                  this.active_audiovideo_element.addEventListener("volumechange", (event) => {
                    $volumeSlider.value = this.active_audiovideo_element.volume;
                  });
                }

                let $showing_subtitles = null;
                if (this.active_audiovideo_element.textTracks.length > 0) {
                  $ccBtn.hidden = false;
                  let $i = 0;
                  for (const track of this.active_audiovideo_element.textTracks) {
                    if ($subtitleContainer) {
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
                      if ($subtitleTrack) {
                        let $subtitleTrackClone = $subtitleTrack.cloneNode(true);
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
                        $subtitleTrack.before($subtitleTrackClone);
                        $subtitleTrackClone.addEventListener("click", (e) => {
                          console.log(track);
                          console.log(track.mode);
                          console.log(e.currentTarget.dataset);

                          if (track.mode == "showing" || track.mode == "hidden") {
                            track.mode = "disabled";
                            $subtitleContainer.innerHTML = '';
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
                            ;

                            track.mode = "showing";
                            // Means I need to toggle any other one active
                            if (active_classes) {
                              const trackId = e.currentTarget.dataset.trackId;
                              const $allothertracks = control.querySelectorAll('.subtitleTrack-active[data-track-id]:not([data-track-id="' + trackId + '"])');

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
                        $subtitleContainer.innerHTML = ''; // Clear previous subtitle
                        // Display current cue text
                        if (track.activeCues.length > 0) {
                          const currentCue = track.activeCues[0];
                          const subtitleText = document.createElement('em');
                          subtitleText.textContent = currentCue.text;
                          // We need per track containers here. Because the user could enable multiple Tracks at the same time?
                          $subtitleContainer.appendChild(subtitleText);
                        }
                      });
                      $i++;
                    }
                  }
                  $ccBtn.addEventListener("click", (e) => {
                    if (active_classes) {
                      for (const $class of active_classes) {
                        $ccBtn.classList.toggle($class)
                      }
                    }
                  });
                }
                ;
              } else {
                // If no Play/Pause mute and unmute/default to not hidden
                // and show controls
                this.active_audiovideo_element.hidden = false;
                this.active_audiovideo_element.controls = true;
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
})(jQuery, Drupal, WaveSurfer, once, drupalSettings);
