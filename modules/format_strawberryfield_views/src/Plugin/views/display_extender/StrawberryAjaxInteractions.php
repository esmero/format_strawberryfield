<?php

namespace Drupal\format_strawberryfield_views\Plugin\views\display_extender;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Plugin\views\display_extender\DisplayExtenderPluginBase;

/**
 * Views Attach Library display extender plugin.
 *
 * @ingroup views_display_extender_plugins
 *
 * @ViewsDisplayExtender(
 *   id = "sbf_ajax_interactions",
 *   title = @Translation("Enables Ajax Interactions between Strawberryfield Formatters and Views"),
 *   help = @Translation("See settings to select which Exposed filters or Contextual filters will act on Format Strawberryfield formatter events"),
 *   no_ui = FALSE
 * )
 */
class StrawberryAjaxInteractions extends DisplayExtenderPluginBase {

  /**
   * Provide a form to edit options for this plugin.
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    if ($form_state->get('section') == 'use_ajax') {
      $form['sbf_ajax_interactions'] = [
        '#title'         => $this->t('Strawberryfield ADO to ADO Interactions'),
        '#type'          => 'checkbox',
        '#description'   => $this->t('Allow this view to get Contextual filter values from other Strawberryfield formatters'),
        '#default_value' => isset($this->options['sbf_ajax_interactions'])
          ? $this->options['sbf_ajax_interactions'] : 0,
        '#states'        => [
          'enabled' => [
            ':input[name="use_ajax"]' => ['checked' => TRUE],
          ],
        ],
      ];
      $arguments = $this->displayHandler->getOption('arguments') ?? [];
      $all_exposed_arguments = array_combine(array_keys($arguments), array_keys($arguments));
      if (is_array($all_exposed_arguments) && count($all_exposed_arguments)) {
        $form['sbf_ajax_interactions_arguments'] = [
          '#type'          => 'checkboxes',
          '#title'         => $this->t(
            'Which Contextual filters (if any) should allow input from other ADOs'
          ),
          '#options'       => $all_exposed_arguments ?? [],
          '#default_value' => $this->options['sbf_ajax_interactions_arguments'] ?? [],
          '#states'        => [
            'enabled' => [
              ':input[name="sbf_ajax_interactions"]' => ['checked' => TRUE],
              ':input[name="use_ajax"]'              => ['checked' => TRUE],
            ],
          ],
        ];
      }
    }
  }

  /**
   * Handle any special handling on the validate form.
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    if ($form_state->get('section') == 'use_ajax') {
      $this->options['sbf_ajax_interactions'] = $form_state->cleanValues()->getValue('sbf_ajax_interactions');
      if ($this->options['sbf_ajax_interactions']) {
        $this->options['sbf_ajax_interactions_arguments']
          = $form_state->cleanValues()->getValue(
          'sbf_ajax_interactions_arguments'
        );
      }
      else {
        unset($this->options['sbf_ajax_interactions_arguments']);
        unset($this->options['sbf_ajax_interactions']);
      }
    }
  }

  /**
   * Set up any variables on the view prior to execution.
   */
  public function preExecute() {

  }

  /**
   * Inject anything into the query that the display_extender handler needs.
   */
  public function query() {

  }


  /**
   * {@inheritdoc}
   */
  public function validateOptionsForm(&$form, FormStateInterface $form_state) {
    if ($form_state->hasValue('use_ajax') && $form_state->getValue('use_ajax') != TRUE) {
      // Prevent use ajax history when ajax for view are disabled.
      $form_state->setValue('sbf_ajax_interactions', FALSE);
      $form_state->setValue('sbf_ajax_interactions_arguments', NULL);
    }
  }
  /**
   * Lists defaultable sections and items contained in each section.
   */
  public function defaultableSections(&$sections, $section = NULL) {

  }

}
