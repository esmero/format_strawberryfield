(function ($, Drupal) {
  Drupal.behaviors.sbfAdvancedSearchViewsForm = {
    attach(context) {
      var formInterception = function(defaultSubmitInput, ev) {
        if (ev.keyCode == 13) {
          ev.preventDefault();
          ev.stopPropagation();
          defaultSubmitInput.click();
        }
      };

      var addMoreInterception = function(defaultSubmitInput, ev) {
        ev.preventDefault();
        ev.stopPropagation();
        if (typeof ev.target.dataset.advancedSearchPrefix !== 'undefined') {
          const $name = ev.target.dataset.advancedSearchPrefix + '_advanced_search_fields_count';
          const $form = defaultSubmitInput.closest('.views-exposed-form');
          let $count = $form.querySelector('input[name="'+$name+'"]');
          if ($count) {
            $count.value = Number($count.value) + 1;
            defaultSubmitInput.click();
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
            $count.value = Number($count.value) - 1;
            const $tounsetSelector = ev.target.dataset.advancedSearchPrefix + '_' + $count.value;
            let $tounset = $form.querySelector('input[name="' + $tounsetSelector + '"]');
            if ($tounset) {
              $tounset.value = '';
            }
            defaultSubmitInput.click();
          }
        }
      };

      for (const defaultSubmitInput of document.querySelectorAll('.views-exposed-form [data-default-submit]')) {
        for (const formInput of defaultSubmitInput.form.querySelectorAll('input')) {
          formInput.addEventListener('keypress', formInterception.bind(null, defaultSubmitInput));
        }
        for (const addMore of document.querySelectorAll('.views-exposed-form [data-advanced-search-addone]')) {
          let $parent_edit_actions = addMore.closest('[data-drupal-selector="edit-actions"]');
          if ($parent_edit_actions) {
            const $submitForAdv = $parent_edit_actions.querySelector('[data-default-submit]');
            if ($submitForAdv) {
              addMore.addEventListener('click', addMoreInterception.bind(null, $submitForAdv));
            }
          }
        }
        for (const delOne of document.querySelectorAll('.views-exposed-form [data-advanced-search-delone]')) {
          let $parent_edit_actions = delOne.closest('[data-drupal-selector="edit-actions"]');
          if ($parent_edit_actions) {
            const $submitForAdv = $parent_edit_actions.querySelector('[data-default-submit]');
            if ($submitForAdv) {
              delOne.addEventListener('click', delOneInterception.bind(null, $submitForAdv));
            }
          }
        }
      }
    }
  }
})(jQuery, Drupal);
