(function ($, Drupal, once) {
  Drupal.behaviors.sbfAdvancedSearchViewsForm = {
    attach(context) {
      var formInterception = function(defaultSubmitInput, ev) {
        if (ev.keyCode == 13) {
          ev.preventDefault();
          ev.stopPropagation();
          defaultSubmitInput.click();
        }
      };

      var shiftFormElementValues = function (StartWrapper) {

      }


      var addMoreInterception = function(defaultSubmitInput, ev) {
        ev.preventDefault();
        ev.stopPropagation();
        if (typeof ev.target.dataset.advancedSearchPrefix !== 'undefined') {
          const $name = ev.target.dataset.advancedSearchPrefix + '_advanced_search_fields_count';
          const $form = defaultSubmitInput.closest('.views-exposed-form');
          let $count = $form.querySelector('input[name="'+$name+'"]');
          if ($count) {
            if (ev.target.dataset.advancedSearchMode !== 'undefined' && ev.target.dataset.advancedSearchMax !== 'undefined') {
              if ($count.value < Number(ev.target.dataset.advancedSearchMax)) {
                $count.value = Number($count.value) + 1;
              }
              if (ev.target.dataset.advancedSearchMode == "true") {
                if ($count.value <= Number(ev.target.dataset.advancedSearchMax)) {
                  const $hidden_ones = $form.querySelectorAll(".hidden[data-advanced-wrapper='true']");
                  if ($hidden_ones.length > 0) {
                    const $first_hidden_one = $hidden_ones[0];
                    $first_hidden_one.classList.toggle('hidden');
                  }
                }
                if ($count.value == Number(ev.target.dataset.advancedSearchMax)) {
                  ev.target.classList.add('hidden');
                }
                if ($count.value > Number(ev.target.dataset.advancedSearchMin)) {
                  const $hidden_del_one = $form.querySelector('.hidden[data-advanced-search-delone]');
                  if ($hidden_del_one) {
                    $hidden_del_one.classList.remove('hidden')
                  }
                }
              }
              else {
                defaultSubmitInput.click();
              }
            }
          }
        }
      };

      var delOneInterception = function(defaultSubmitInput, ev) {
        ev.preventDefault();
        ev.stopPropagation();
        if (typeof ev.target.dataset.advancedSearchPrefix !== 'undefined') {
          const $name = ev.target.dataset.advancedSearchPrefix + '_advanced_search_fields_count';
          const $form = defaultSubmitInput.closest('.views-exposed-form');
          let $count = $form.querySelector('input[name="'+$name+'"]');
          if ($count) {
            if (ev.target.dataset.advancedSearchMode !== 'undefined' && ev.target.dataset.advancedSearchMin !== 'undefined') {
              if ($count.value > Number(ev.target.dataset.advancedSearchMin)) {
                $count.value = Number($count.value) - 1;
              }
              if (ev.target.dataset.advancedSearchMode == "true") {
                if ($count.value >= Number(ev.target.dataset.advancedSearchMin)) {
                  let $not_hidden_one = $form.querySelectorAll(":not(.hidden)[data-advanced-wrapper='true']");
                  if (ev.target.dataset.advancedSearchTarget == "last") {
                    if ($not_hidden_one.length > 0) {
                      const $last_not_hidden_one = $not_hidden_one[$not_hidden_one.length - 1];
                      $last_not_hidden_one.classList.toggle('hidden');
                      // name^= means starts with.
                      let $tounset = $last_not_hidden_one.querySelector('input[name^="' +  ev.target.dataset.advancedSearchPrefix + '"]');
                      if ($tounset) {
                        $tounset.value = '';
                      }
                      let $tounsetselect = $last_not_hidden_one.querySelectorAll('select[name^="' +  ev.target.dataset.advancedSearchPrefix + '"]');
                      if ($tounsetselect.length > 0) {
                        [].forEach.call($tounsetselect, function(el) {
                          // -1 means nothing which is not the drupal default...
                          el.selectedIndex = 0;
                        });
                      }
                    }
                  }
                  else if (ev.target.dataset.advancedSearchTarget == "self") {
                    // But here is the trick. I need to clear this one but hide still the last one...
                    // and then shift, per wrapper all values one step starting from the one cleared ...
                    // Side note: there are chances that $not_hidden_one last and $to_clear are the same
                    // when we are at the "last" remove button.
                    const $to_clear = ev.target.closest(":not(.hidden)[data-advanced-wrapper='true']");
                    if ($to_clear) {
                      // this will clear the values from the current without hiding.
                      let $tounset = $to_clear.querySelector('input[name^="' + ev.target.dataset.advancedSearchPrefix + '"]');
                      if ($tounset) {
                        $tounset.value = '';
                      }
                      let $tounsetselect = $to_clear.querySelectorAll('select[name^="' + ev.target.dataset.advancedSearchPrefix + '"]');
                      if ($tounsetselect.length > 0) {
                        [].forEach.call($tounsetselect, function (el) {
                          // -1 means nothing which is not the drupal default...
                          el.selectedIndex = 0;
                        });
                      }

                      // Now, shift values one step back
                      if ($not_hidden_one.length > 0) {
                        let previous = $to_clear;
                        let found = false;
                        [].forEach.call($not_hidden_one, function(el) {
                          if (!found && el.id == $to_clear.id) {
                            found = true;
                            // that way we skip this one too.
                            return;
                          }
                          if (!found) {
                            return;
                          }
                          const $tocopy = el.querySelector('input[name^="' +  ev.target.dataset.advancedSearchPrefix + '"]');
                          const $tocopyinto = previous.querySelector('input[name^="' + ev.target.dataset.advancedSearchPrefix + '"]');
                          if ($tocopy && $tocopyinto) {
                            $tocopyinto.value = $tocopy.value;
                          }
                          let $tocopyselect = el.querySelectorAll('select[name^="' +  ev.target.dataset.advancedSearchPrefix + '"]');
                          if ($tocopyselect.length > 0 && $tocopy) {
                            [].forEach.call($tocopyselect, function(select) {
                              // fetch the same by data attribute from the previous
                              const $which = select.dataset.advancedSearchType;
                              const $sameSelectTo = previous.querySelector('[data-advanced-search-type="'+ $which + '"]');
                              const currentSelectValue = select.selectedIndex;
                              if (currentSelectValue && $sameSelectTo) {
                                $sameSelectTo.selectedIndex = currentSelectValue
                              }
                              else if ($sameSelectTo) {
                                //if null set to the first
                                $sameSelectTo.selectedIndex = 0;
                              }
                            });
                          }
                          previous = el;
                        });
                        // Then hide the last.
                        const $last_not_hidden_one = $not_hidden_one[$not_hidden_one.length - 1];
                        $last_not_hidden_one.classList.toggle('hidden');
                        // name^= means starts with.
                        let $tounset = $last_not_hidden_one.querySelector('input[name^="' +  ev.target.dataset.advancedSearchPrefix + '"]');
                        if ($tounset) {
                          $tounset.value = '';
                        }
                        let $tounsetselect = $last_not_hidden_one.querySelectorAll('select[name^="' +  ev.target.dataset.advancedSearchPrefix + '"]');
                        if ($tounsetselect.length > 0) {
                          [].forEach.call($tounsetselect, function(el) {
                            // -1 means nothing which is not the drupal default...
                            el.selectedIndex = 0;
                          });
                        }
                      }
                    }
                  }
                }
                if ($count.value == Number(ev.target.dataset.advancedSearchMin) && ev.target.dataset.advancedSearchTarget != "self") {
                  // Don't hide if we are using individual buttons, the last wrapper container already gets hidden.
                  ev.target.classList.add('hidden');
                }
                if ($count.value < Number(ev.target.dataset.advancedSearchMax)) {
                  const $hidden_add_more = $form.querySelector('.hidden[data-advanced-search-addone]');
                  if ($hidden_add_more) {
                    $hidden_add_more.classList.remove('hidden')
                  }
                }
              }
              else {
                // Normal behavior. Simple stuff
                const $tounsetSelector = ev.target.dataset.advancedSearchPrefix + '_' + $count.value;
                let $tounset = $form.querySelector('input[name="' + $tounsetSelector + '"]');
                if ($tounset) {
                  $tounset.value = '';
                }
                defaultSubmitInput.click();
              }
            }
          }
        }
      };

      const defaultSubmitInputs = once('sbf-adv-default', '.views-exposed-form [data-default-submit]');
      for (const defaultSubmitInput of defaultSubmitInputs) {
        for (const formInput of defaultSubmitInput.form.querySelectorAll('input')) {
          formInput.addEventListener('keypress', formInterception.bind(null, defaultSubmitInput));
        }
      }
      const addMores = once('sbf-adv-addmore', '.views-exposed-form [data-advanced-search-addone]');
      for (const addMore of addMores) {
        let $parent_edit_actions = addMore.closest('[data-drupal-selector="edit-actions"]');
        if ($parent_edit_actions) {
          const $submitForAdv = $parent_edit_actions.querySelector('[data-default-submit]');
          if ($submitForAdv) {
            addMore.addEventListener('click', addMoreInterception.bind(null, $submitForAdv));
          }
        }
      }
      const delOnes = once('sbf-adv-addmore', '.views-exposed-form [data-advanced-search-delone]');
      for (const delOne of delOnes) {
        let $parent_edit_actions = delOne.closest('.views-exposed-form').querySelector('[data-drupal-selector="edit-actions"]');
        if ($parent_edit_actions) {
          const $submitForAdv = $parent_edit_actions.querySelector('[data-default-submit]');
          if ($submitForAdv) {
            delOne.addEventListener('click', delOneInterception.bind(null, $submitForAdv));
          }
        }
      }
    }
  }
})(jQuery, Drupal, once);
