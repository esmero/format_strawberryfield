(function ($, Drupal) {

  /**
   * Enables autosaving in code mirror so the changes are actually reflected in
   * the textarea.
   */
  Drupal.behaviors.codeMirrorAutosave = {
    attach: function attach(context) {
      $('.CodeMirror', context).on('keyup', function (event) {
        this.CodeMirror.save();
      })
    }
  };

})(jQuery, Drupal);
