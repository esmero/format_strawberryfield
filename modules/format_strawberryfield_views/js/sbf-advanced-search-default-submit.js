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
                  let $not_hidden_one = [];
                  if (ev.target.dataset.advancedSearchTarget == "last") {
                    $not_hidden_one = $form.querySelectorAll(":not(.hidden)[data-advanced-wrapper='true']");
                  }
                  else if (ev.target.dataset.advancedSearchTarget == "self") {
                    // I don't use the NodeList object, i just iterate over its members, so this is valid.
                    $not_hidden_one.push(ev.target.closest(":not(.hidden)[data-advanced-wrapper='true']"));
                  }

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
                if ($count.value == Number(ev.target.dataset.advancedSearchMin)) {
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
