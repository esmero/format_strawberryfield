
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
          var attach_control_to_media_pos = 'after'; drupalSettings.format_strawberryfield.audiovideo[element_id]['wavesurfer'];
          // Pick the control/if any.
          var control = null;
          let $waversurfer_container = null
          if (external_control) {
            var control_blueprint = document.querySelector(control_query_selector);
            if (control_blueprint) {
              // Check for the minimal needed classes inside. Play/Pause/Mute/UnMute.
              let $playBtn = control_blueprint.querySelector('.playBtn');
              let $pauseBtn = control_blueprint.querySelector('.pauseBtn');
              let $muteBtn = control_blueprint.querySelector('.muteBtn');
              let $unmuteBtn = control_blueprint.querySelector('.unmuteBtn');
              if ($playBtn && $pauseBtn && $muteBtn && $unmuteBtn) {
                // Hide original Media Controls.
                this.controls = false;
                // We can't hide the original Media element IF we have to attach wavesurfer
                this.hidden = use_wavesurfer;
                // Only here we can actually start doing things.
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
                  // If we have to move the element, then will clone deep, edit the ID (should not have one)
                  // and hide the original (if not hidden).
                } else {
                  control = control_blueprint;
                  control_blueprint.hidden = false;
                }

                const $pauseBtn = control.querySelector('.pauseBtn');
                const $playBtn = control.querySelector('.playBtn');
                const $stopBtn = control.querySelector('.stopBtn');
                const $muteBtn = control.querySelector('.muteBtn');
                const $unmuteBtn = control.querySelector('.unmuteBtn');
                const $ccBtn = control.querySelector('.ccBtn');
                // Ideally $progressSlider would be a range element so there is "input feedback"
                // And accessibility.
                const $progressSlider =  !use_wavesurfer ? control.querySelector(".progressSlider") : null;
                const $progressStat =  !use_wavesurfer ? control.querySelector(".progressStat") : null;

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
                if ($stopBtn) {
                  $stopBtn.hidden = true;
                }

                $playBtn.addEventListener("click", (e) => {
                  if (this.paused || this.ended) {
                    this.play();
                    e.currentTarget.hidden = true;
                    $pauseBtn.hidden = false;
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
                  if ($progressStat) {
                    $progressStat.value = this.currentTime;
                  }
                });
                this.addEventListener("ended", (event) => {
                  if (!$pauseBtn.hidden) {
                    $playBtn.hidden = false;
                    $pauseBtn.hidden = true;
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
                      pos = (($volumeSlider.offsetHeight - (e.clientY - rect.top)) / $volumeSlider.offsetHeight).toFixed(1);
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
              }
            }
          }
          if (use_wavesurfer) {
            $waversurfer_container = $waversurfer_container == null ? this.parentNode.querySelector('.strawberry-av-item-waversurfer') : $waversurfer_container;
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
