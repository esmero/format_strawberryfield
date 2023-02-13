<?php

namespace Drupal\format_strawberryfield_views\Plugin\Block;

use Drupal\Core\Cache\Cache;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Url;
use Drupal\views\Plugin\Block\ViewsBlockBase;
use Drupal\Component\Utility\Html;

/**
 * Provides a 'Views Exposed Filter with Modal display' block.
 *
 * @Block(
 *   id = "views_exposed_filter_block_sbf",
 *   admin_label = @Translation("Views Exposed Filter Block with Selectable type of Filter"),
 *   deriver = "Drupal\views\Plugin\Derivative\ViewsExposedFilterBlock"
 * )
 */
class ViewsExposedFilterBlockModal extends ViewsBlockBase implements TrustedCallbackInterface {

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    $contexts = $this->view->display_handler->getCacheMetadata()->getCacheContexts();
    return Cache::mergeContexts(parent::getCacheContexts(), $contexts);
  }

  /**
   * {@inheritdoc}
   *
   * @return array
   *   A renderable array representing the content of the block with additional
   *   context of current view and display ID.
   */
  public function build() {

    $is_sort_exposed = FALSE;

    /** @var \Drupal\views\Plugin\views\HandlerBase $sort */
    $sort_ids = [];
    foreach ($this->view->display_handler->getHandlers('sort') as $key => $sort) {
      if ($sort->isExposed()) {
        $sort_ids[$sort->options['expose']['field_identifier']] = $sort->options['expose']['field_identifier'];
      }
    }


    $filter_ids = [];
    foreach ($this->view->display_handler->getHandlers('filter') as $key => $filter) {
      if ($filter->isExposed()) {
        if ($filter->options['expose']['multiple'] ?? FALSE) {
          $filter_ids[$filter->options['group_info']['identifier']] = $filter->options['group_info']['identifier'];
        }
        else {
          $filter_ids[$filter->options['expose']['identifier']] = $filter->options['expose']['identifier'];
        }
      }
    }
    $output = $this->view->display_handler->viewExposedFormBlocks();
    // Provide the context for block build and block view alter hooks.
    // \Drupal\views\Plugin\Block\ViewsBlock::build() adds the same context in
    // \Drupal\views\ViewExecutable::buildRenderable() using
    // \Drupal\views\Plugin\views\display\DisplayPluginBase::buildRenderable().
    $output['#attributes']['data-drupal-target-view'] = $this->view->storage->id() . '-' . $this->view->current_display;

    $output['#id'] = Html::getUniqueId('views_exposed_form_modal-' .  $this->view->storage->id() . '-' . $this->view->current_display);
    $exposed_elements = $output['#info'] ?? [];
    foreach ($exposed_elements as $key => $exposed_values) {
      if (strpos($key,'filter-') === 0) {
        if (isset($exposed_values['value'])) {
          $filter_ids[$exposed_values['value']] = $exposed_values['value'];
        }
      }
      if (strpos($key,'sort-') === 0) {
        if (isset($exposed_values['value'])) {
          $sort_ids[$exposed_values['value']] = $exposed_values['value'];
        }
      }
    }

    // Hide Filters if needed
    foreach ($filter_ids as $field_id) {
      $real_id = NULL;
      if (isset($output[$field_id]) && is_array($output[$field_id])) {
        $real_id = $field_id;
      }
      elseif (isset($output[$field_id.'_wrapper']) && is_array($output[$field_id.'_wrapper'])) {
        $real_id = $field_id.'_wrapper';
      }
      if ($real_id) {
        $hide = $this->checkIfNeedsToHideFilter($output[$real_id] ?? []);
        if ($hide) {
          $output[$real_id]['#attributes']['class'][] = 'visually-hidden';
          $output[$real_id]['#title_display'] = 'invisible';
          $output[$real_id]['#description_display'] = 'invisible';
          if (isset($output['#info']["filter-$field_id"]['label'])) {
            $output['#info']["filter-$field_id"]['label'] = '';
          }
        }

        foreach ($output[$real_id] as $possible_key => &$value) {
          if (strpos($possible_key, '#') === FALSE && is_array($value)
            && isset($value['#type'])
          ) {
            $hide = $this->checkIfNeedsToHideFilter($value ?? []);
            if ($hide) {
              $value['#type'] = 'hidden';
              $value['#attributes']['class'][] = 'visually-hidden';
              $output['#info']["filter-$field_id"]['label'] = '';
            }
          }
        }
      }
    }

    // Hide Sorts if needed
    foreach ($sort_ids as $field_id) {
      $real_id = NULL;
      if (isset($output[$field_id]) && is_array($output[$field_id])) {
        $real_id = $field_id;
      }
      elseif (isset($output[$field_id.'_wrapper']) && is_array($output[$field_id.'_wrapper'])) {
        $real_id = $field_id.'_wrapper';
      }
      if ($real_id) {
        $hide = $this->checkIfNeedsToHideSort($output[$real_id] ?? []);
        if ($hide) {
          $output[$real_id]['#attributes']['class'][] = 'visually-hidden';
          $output[$real_id]['#title_display'] = 'invisible';
          $output[$real_id]['#description_display'] = 'invisible';
          if (isset($output['#info']["sort-$field_id"]['label'])) {
            $output['#info']["sort-$field_id"]['label'] = '';
          }
        }

        foreach ($output[$real_id] as $possible_key => &$value) {
          if (strpos($possible_key, '#') === FALSE && is_array($value)
            && isset($value['#type'])
          ) {
            $hide = $this->checkIfNeedsToHideSort($value ?? []);
            if ($hide) {
              $value['#type'] = 'hidden';
              $value['#attributes']['class'][] = 'visually-hidden';
              $output['#info']["sort-$field_id"]['label'] = '';
            }
          }
        }
      }
    }
    if ($this->configuration['views_exposed_sbf_show_submit']) {
      if (trim($this->configuration['views_exposed_sbf_rename_submit_label'])
        != ''
        && isset($output['actions']['submit']['#value'])
      ) {
        $output['actions']['submit']['#value']
          = $this->configuration['views_exposed_sbf_rename_submit_label'];
      }
    }
    elseif (isset($output['actions']['submit'])) {
      $output['actions']['submit']['#attributes']['class'][] = 'visually-hidden';
    }

    if (!$this->configuration['views_exposed_sbf_show_reset'] && isset($output['actions']['reset'])) {
      $output['actions']['reset']['#attributes']['class'][] = 'visually-hidden';
    }

    if ($this->view->display_handler->ajaxEnabled()) {
      $output['#attributes']['class'][] = 'block-modalformviews-ajax';
      $output['#attributes']['class'][] = 'js-modal-form-views-block';

      $js_settings = [
        'view_id' => $this->view->id(),
        'current_display_id' => $this->view->current_display,
        'view_base_path' => ltrim($this->view->getPath() ?? '', '/'),
        'ajax_path' => Url::fromRoute('views.ajax')->toString(),
      ];
      $output['#attached']['drupalSettings']['format_strawberryfield_views']['modal_exposed_form_block'][$output['#id']] = $js_settings;
      $output['#attached']['library'][] = 'format_strawberryfield_views/modal-exposed-form-views-ajax';
    }

    if (is_array($output) && !empty($output)) {
      $output += [
        '#view' => $this->view,
        '#display_id' => $this->displayID,
      ];
    }

    // Before returning the block output, convert it to a renderable array with
    // contextual links.
    $this->addContextualLinks($output, 'views_exposed_filter_block_sbf');

    // Set the blocks title.
    if (!empty($this->configuration['label_display']) && ($this->view->getTitle() || !empty($this->configuration['views_label']))) {
      $output['#title'] = [
        '#markup' => empty($this->configuration['views_label']) ? $this->view->getTitle() : $this->configuration['views_label'],
        '#allowed_tags' => Xss::getHtmlTagList(),
      ];
    }

    return $output;
  }

  /**
   * Checks if a Form elements for a filter needs to be hidden or not.
   *
   * @param array $element
   *
   * @return bool
   */
  protected function checkIfNeedsToHideFilter(array $element) {
    $hide = FALSE;
    if (!$this->configuration['views_exposed_sbf_show_text_filter']
      && isset($element['#type'])
      && in_array(
        $element['#type'], ['textfield', 'search_api_autocomplete', 'textarea']
      )
    ) {
      $hide = TRUE;
    }
    if (!$this->configuration['views_exposed_sbf_show_select_filter']
      && isset($element['#type'])
      && in_array(
        $element['#type'], ['select']
      )
    ) {
      $hide = TRUE;
    }
    if (!$this->configuration['views_exposed_sbf_show_checksandoptions_filter']
      && isset($element['#type'])
      && in_array(
        $element['#type'], ['checkbox', 'radio', 'radios']
      )
    ) {
      $hide = TRUE;
    }
    return $hide;
  }

  /**
   * Checks if a Form elements for a filter needs to be hidden or not.
   *
   * @param array $element
   *
   * @return bool
   */
  protected function checkIfNeedsToHideSort(array $element) {
    $hide = FALSE;
    if (!$this->configuration['views_exposed_sbf_show_sort_filter']
      && isset($element['#type'])
      && in_array(
        $element['#type'], ['select']
      )
    ) {
      $hide = TRUE;
    }
    return $hide;
  }

  public function defaultConfiguration() {
    $default_config = parent::defaultConfiguration(
    ); // TODO: Change the autogenerated stub
    $default_config['views_exposed_sbf_show_text_filter'] = 1;
    $default_config['views_exposed_sbf_show_select_filter'] = 1;
    $default_config['views_exposed_sbf_show_checksandoptions_filter'] = 1;
    $default_config['views_exposed_sbf_show_sort_filter'] = 1;
    $default_config['views_exposed_sbf_show_pager'] = 1;
    $default_config['views_exposed_sbf_show_submit'] = 1;
    $default_config['views_exposed_sbf_show_reset'] = 1;
    $default_config['views_exposed_sbf_rename_submit_label'] = '';
    return $default_config;
  }


  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    if (!$form_state->isValueEmpty('views_label_checkbox')) {
      $this->configuration['views_label'] = $form_state->getValue('views_label');
    }
    else {
      $this->configuration['views_label'] = '';
    }

    if (!$form_state->isValueEmpty('views_exposed_sbf_rename_submit')) {
      $this->configuration['views_exposed_sbf_rename_submit_label'] = $form_state->getValue('views_exposed_sbf_rename_submit_label');
    }
    else {
      $this->configuration['views_exposed_sbf_rename_submit_label'] = '';
    }

    $this->configuration['views_exposed_sbf_show_text_filter'] = $form_state->getValue('views_exposed_sbf_show_text_filter', 0) ;
    $this->configuration['views_exposed_sbf_show_select_filter'] = $form_state->getValue('views_exposed_sbf_show_select_filter', 0) ;
    $this->configuration['views_exposed_sbf_show_checksandoptions_filter'] = $form_state->getValue('views_exposed_sbf_show_checksandoptions_filter',0);
    $this->configuration['views_exposed_sbf_show_sort_filter'] = $form_state->getValue('views_exposed_sbf_show_sort_filter',0);
    $this->configuration['views_exposed_sbf_show_pager'] = $form_state->getValue('views_exposed_sbf_show_pager',0);
    $this->configuration['views_exposed_sbf_show_submit'] = $form_state->getValue('views_exposed_sbf_show_submit',0);
    $this->configuration['views_exposed_sbf_show_reset'] = $form_state->getValue('views_exposed_sbf_show_reset',0);

    $form_state->unsetValue('views_label_checkbox');
    $form_state->unsetValue('views_exposed_sbf_rename_submit');
  }

  public function buildConfigurationForm(array $form,
    FormStateInterface $form_state
  ) {
    $form = parent::buildConfigurationForm(
      $form, $form_state
    );
    $form['views_exposed_sbf_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => 'Exposed Form element and component Visibility'
    ];

    $form['views_exposed_sbf_show_text_filter'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show filter components of type textfield if exposed'),
      '#default_value' => !empty($this->configuration['views_exposed_sbf_show_text_filter']),
      '#fieldset' => 'views_exposed_sbf_fieldset',
    ];

    $form['views_exposed_sbf_show_select_filter'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show filter components of type select if exposed'),
      '#default_value' => !empty($this->configuration['views_exposed_sbf_show_select_filter']),
      '#fieldset' => 'views_exposed_sbf_fieldset',
    ];

    $form['views_exposed_sbf_show_checksandoptions_filter'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show filter components of type checkbox/options if exposed'),
      '#default_value' => !empty($this->configuration['views_exposed_sbf_show_checksandoptions_filter']),
      '#fieldset' => 'views_exposed_sbf_fieldset',
    ];

    $form['views_exposed_sbf_show_sort_filter'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show sorting components if exposed'),
      '#default_value' => !empty($this->configuration['views_exposed_sbf_show_sort_filter']),
      '#fieldset' => 'views_exposed_sbf_fieldset',
    ];

    $form['views_exposed_sbf_show_pager'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show pager components if exposed'),
      '#default_value' => !empty($this->configuration['views_exposed_sbf_show_pager']),
      '#fieldset' => 'views_exposed_sbf_fieldset',
    ];

    $form['views_exposed_sbf_show_submit'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Submit Button if exposed'),
      '#description' => $this->t('Make sure you have autosubmit enabled if you decide to hide it'),
      '#default_value' => !empty($this->configuration['views_exposed_sbf_show_submit']),
      '#fieldset' => 'views_exposed_sbf_fieldset',
    ];

    $form['views_exposed_sbf_show_reset'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Reset Button if exposed'),
      '#default_value' => !empty($this->configuration['views_exposed_sbf_show_reset']),
      '#fieldset' => 'views_exposed_sbf_fieldset',
    ];

    $form['views_exposed_sbf_rename_submit'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Override Submit button Label'),
      '#default_value' => !empty($this->configuration['views_exposed_sbf_rename_submit_label']),
      '#fieldset' => 'views_exposed_sbf_fieldset',
      '#states' => [
        'visible' => [
          [
            ':input[name="settings[views_exposed_sbf_show_submit]"]' => ['checked' => TRUE],
          ],
        ],
      ],
    ];


    $form['views_exposed_sbf_rename_submit_label'] = [
      '#title' => $this->t('Submit button label'),
      '#type' => 'textfield',
      '#default_value' => $this->configuration['views_exposed_sbf_rename_submit_label'] ?: 'Apply',
      '#states' => [
        'visible' => [
          [
            ':input[name="settings[views_exposed_sbf_rename_submit]"]' => ['checked' => TRUE],
          ],
        ],
      ],
      '#fieldset' => 'views_exposed_sbf_fieldset',
    ];

    return $form;
  }


  /**
   * #pre_render callback for enriching this block.
   */
  public static function preRender($build) {
    // The invoker/rendered moves #attributes from 'content' back into the top structure
    if (isset($build['#attributes']['class']) && in_array('block-modalformviews-ajax', $build['#attributes']['class'])) {
      $build['#attributes']['class'][] = 'js-modal-form-views-block-id-' . $build['#id'];
      $build['#attributes']['data-drupal-modalblock-selector'] = 'js-modal-form-views-block-id-' . $build['#id'];
      $build['content']['#attached']['drupalSettings']['format_strawberryfield_views']['modal_exposed_form_block'][$build['content']['#id']]['block_id'] = $build['#id'];
    }
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['preRender'];
  }

}
