
import WaveSurfer from 'https://cdn.jsdelivr.net/npm/wavesurfer.js@7/dist/wavesurfer.esm.js';

(function ($, Drupal, WaveSurfer, once, drupalSettings) {

  'use strict';
  var viewers = [];

  Drupal.behaviors.format_strawberryfield_audiovideo = {
    attach: function (context, settings) {
      var groupsid =  {};
      const elementsToAttach = once('attache_audio_video', '.strawberry-av-item', context);
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
          var attach_control_to_media_pos = 'after'; drupalSettings.format_strawberryfield.audiovideo[element_id]['wavesurfer'];
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
            if  (closest_field) {
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
            control_blueprint = control_blueprint == null ? document.querySelector(control_query_selector) :control_blueprint;
            if (control_blueprint) {
              // Check for the minimal needed classes inside. Play/Pause/Mute/UnMute.
              let $playBtn = control_blueprint.querySelector('.playBtn');
              let $pauseBtn = control_blueprint.querySelector('.pauseBtn');
              let $muteBtn = control_blueprint.querySelector('.muteBtn');
              let $unmuteBtn = control_blueprint.querySelector('.unmuteBtn');
              if ($playBtn && $pauseBtn && $muteBtn && $unmuteBtn) {
                // Hide original Media Controls.
                this.controls = !hide_controls;
                // If Video we can't hide.
                // Might be hidden already by the Formatter to avoid Popping up
                // when external control is provided.
                this.hidden = !use_wavesurfer || this.classList.contains('.video-av');
                // Only here we can actually start doing things.
                // If we have to move the element, then will clone deep, edit the ID (should not have one)
                // and hide the original (if not hidden).
                if (attach_control_to_media_pos !== 'none') {
                  control_blueprint.hidden = true;
                  control = control_blueprint.cloneNode(true);
                  control.removeAttribute('id');
                  if (attach_control_to_media_pos == 'after') {
                    this.after(control);
                    control.hidden = false;
                  }
                  else if(attach_control_to_media_pos == 'before') {
                    this.before(control);
                    control.hidden = false;
                  }
                }
                else {
                  control = control_blueprint;
                  control_blueprint.hidden = false;
                }
                if (use_wavesurfer) {
                  $waversurfer_container = control.querySelector('.wavesurferContainer');
                }

                const $subtitleContainer = control.querySelector('.subtitleContainer');

                const $pauseBtn = control.querySelector('.pauseBtn');
                const $playBtn = control.querySelector('.playBtn');
                const $stopBtn = control.querySelector('.stopBtn');
                const $muteBtn = control.querySelector('.muteBtn');
                const $unmuteBtn = control.querySelector('.unmuteBtn');
                const $ccBtn = control.querySelector('.ccBtn');
                const $subtitleTrack = control.querySelector('.subtitleTrack');

                const $progressSlider =  control.querySelector(".progressSlider");

                const $volumeSlider = control.querySelector(".volumeSlider");
                const $fullscreenBtn = control.querySelector('.fullscreenBtn');
                const $currentTime = control.querySelector('.currentTime');
                const $durationTime = control.querySelector('.durationTime');

                // Used to load next set of media, in case of a IIIF Manifest or multiple Audio/Videos.
                const $nextBtn = control.querySelector('.nextBtn');
                const $prevBtn = control.querySelector('.prevBtn');

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
                  if (this.paused || this.ended) {
                    this.play();
                    e.currentTarget.hidden = true;
                    $pauseBtn.hidden = false;
                    if ($stopBtn) {
                      $stopBtn.hidden = false;
                    }
                  } else {
                    // Because we have separate buttons for each action
                    // this should never hit.
                    this.pause();
                  }
                });
                $pauseBtn.addEventListener("click", (e) => {
                  if (!this.paused && !this.ended) {
                    this.pause();
                    e.currentTarget.hidden = true;
                    $playBtn.hidden = false;
                    if ($stopBtn) {
                      $stopBtn.hidden = true;
                    }
                  } else {
                    // Because we have separate buttons for each action
                    // this should never hit.
                    this.play();
                  }
                });
                $muteBtn.addEventListener("click", (e) => {
                  this.muted = !this.muted;
                  e.currentTarget.hidden = true;
                  $unmuteBtn.hidden = false;
                });
                $unmuteBtn.addEventListener("click", (e) => {
                  this.muted = !this.muted;
                  e.currentTarget.hidden = true;
                  $muteBtn.hidden = false;
                });
                // We only attach $progressSlider if present AND waversurfer
                // is not being used. Why? because Waversurfer Is a slider.
                if ($progressSlider) {
                  $progressSlider.removeAttribute("max")
                  $progressSlider.addEventListener("click", (e) => {
                    if (!Number.isFinite(this.duration)) return;
                    const rect = $progressSlider.getBoundingClientRect();
                    const pos = (e.pageX - rect.left) / $progressSlider.offsetWidth;
                    this.currentTime = pos * this.duration;
                  });
                }

                // ON enough data, update the Duration time.
                this.addEventListener("loadeddata", () => {
                  if (this.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
                    if ($progressSlider) {
                      $progressSlider.setAttribute("max", this.duration);
                    }
                    if ($durationTime) {
                      if (this.duration < 3600) {
                        $durationTime.innerText = new Date(this.duration * 1000).toISOString().substring(14, 19)
                      } else {
                        $durationTime.innerText = new Date(this.duration * 1000).toISOString().substring(11, 16)
                      }
                    }
                  }
                });

                this.addEventListener("timeupdate", () => {
                  if ($currentTime) {
                    if (this.duration < 3600) {
                      $currentTime.innerText = new Date(this.currentTime * 1000).toISOString().substring(14, 19)
                    } else {
                      $currentTime.innerText = new Date(this.currentTime * 1000).toISOString().substring(11, 16)
                    }
                  }
                  if ($progressSlider) {
                    $progressSlider.value = this.currentTime;
                  }
                  // if native is visible and controls too then we need to sync buttons
                  if (this.controls && this.hidden == false && $pauseBtn.hidden) {
                    $playBtn.hidden = true;
                    $pauseBtn.hidden = false;
                    if ($stopBtn) {
                      $stopBtn.hidden = false;
                    }
                  }
                });
                https://developer.mozilla.org/en-US/docs/Web/API/HTMLMediaElement/ended_event
                this.addEventListener("ended", (event) => {
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

                this.addEventListener("pause", (event) => {
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
                https://developer.mozilla.org/en-US/docs/Web/API/HTMLMediaElement/play_event
                this.addEventListener("play", (event) => {
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
                  const currentVolume = Math.floor(this.volume * 10) / 10;
                  $volumeSlider.value = currentVolume;
                  $volumeSlider.addEventListener("click", (e) => {
                    const currentVolume = Math.floor(this.volume * 10) / 10;
                    if (!Number.isFinite(currentVolume)) return;
                    const rect = $volumeSlider.getBoundingClientRect();
                    const writing_mode = window.getComputedStyle($volumeSlider).getPropertyValue('writing-mode');
                    let pos = 0;
                    if (writing_mode.includes('vertical')) {
                      const direction = window.getComputedStyle($volumeSlider).getPropertyValue('direction');
                      if (direction == 'rtl') {
                        //means 0 is at the bottom
                        pos = (($volumeSlider.offsetHeight - (e.clientY - rect.top)) / $volumeSlider.offsetHeight).toFixed(1);
                      }
                      else {
                        // Means normal, 0 is at the top
                        pos = (($volumeSlider.offsetHeight - (rect.bottom - e.clientY)) / $volumeSlider.offsetHeight).toFixed(1);
                      }
                    }
                    else {
                      pos = (e.pageX - rect.left) / $volumeSlider.offsetWidth;
                    }
                    if (pos <= 1 && pos >= 0) {
                      this.volume = pos;
                    }
                  });
                  this.addEventListener("volumechange", (event) => {
                    $volumeSlider.value = this.volume;
                  });
                }


                let $showing_subtitles = null;
                if (this.textTracks.length > 0) {
                  $ccBtn.hidden = false;
                  for (const track of this.textTracks) {
                    if ($subtitleContainer) {
                      track.mode = "showing";
                      // Listen for cue changes
                      // Clone the $subtitleTrack, add onclick logic to swap subtitle
                      if ($subtitleTrack) {
                        const $subtitleTrackClone = $subtitleTrack.cloneNode(true);
                        $subtitleTrackClone.hidden = false;
                        $subtitleTrackClone.innerText = track.label;
                        $subtitleTrack.before($subtitleTrackClone);
                        $subtitleTrackClone.addEventListener("click", (e) => {
                          if (track.mode != "disabled") {
                            track.mode = "disabled";
                          }
                          else {
                            track.mode = "showing";
                            if (active_classes) {
                              for (const $class of active_classes) {
                                $subtitleTrackClone.classList.toggle($class)
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
                    }
                  }
                  $ccBtn.addEventListener("click", (e) => {
                    if (active_classes) {
                      for (const $class of active_classes) {
                        $ccBtn.classList.toggle($class)
                      }
                    }
                  });
                };
              }
              else {
                // If no Play/Pause mute and unmute/default to not hidden
                // and show controls
                this.hidden = false;
                this.controls = true;
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
