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
  public function build()
  {
    $add_classes = function (array &$option,  $classes_to_add) {
      $classes = preg_split('/\s+/', $classes_to_add);
      $classes = array_filter($classes);
      $option = array_unique(array_merge($option, $classes));
    };

    $is_sort_exposed = FALSE;
    /** @var \Drupal\views\Plugin\views\HandlerBase $sort */
    $sort_ids = [];
    foreach ($this->view->display_handler->getHandlers('sort') as $key => $sort) {
      if ($sort->isExposed()) {
        // We really don't know if sorting is exposed. So we will have to ask the view
        $sort_ids[$sort->options['expose']['field_identifier']] = $sort->options['expose']['field_identifier'];
        // Never the case that a SORT as a select is going to keep its own value
        // But if a hanlder decides to make it a checkbox? Or some theme does that?
        $is_sort_exposed = true;
        // New in Drupal 10.2? But these are fixed. When combined by Better exposed Filters they will appear inside #info (still)
        $sort_ids['sort_by'] = 'sort_by';
        $sort_ids['sort_order'] = 'sort_order';
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
    // This changes after an Ajax call, but out settings have not ...
    $output['#id'] = Html::getUniqueId('views_exposed_form_modal-' .  $this->view->storage->id() . '-' . $this->view->current_display);
    $exposed_elements = $output['#info'] ?? [];
    foreach ($exposed_elements as $key => $exposed_values) {
      if (strpos($key,'filter-') === 0) {
        if (isset($exposed_values['value'])) {
          $filter_ids[$exposed_values['value']] = $exposed_values['value'];
        }
      }
      // Leaving this for posterity. No longer true that #info will contain sort elements
      // Sorts are now named simply sort_by and sort_order in the main key ...
      if (strpos($key,'sort-') === 0) {
        if (isset($exposed_values['value'])) {
          $sort_ids[$exposed_values['value']] = $exposed_values['value'];
        }
      }
    }

    $advanced_search_real_id = NULL;
    // Hide Filters if needed
    foreach ($filter_ids as $field_id) {
      $real_ids = [];
      if (isset($output[$field_id]) && is_array($output[$field_id])) {
        $real_ids[] = $field_id;
      }
      elseif (isset($output[$field_id.'_wrapper']) && is_array($output[$field_id.'_wrapper'])) {
        $real_ids[] = $field_id.'_wrapper';
        if (!empty($output[$field_id.'_wrapper']['#attributes']['data-advanced-wrapper'] ?? NULL)) {
          // we are in the presence of a magical being. The advanced search filter. Things just got complicated.
          $advanced_search_real_id = $field_id;
          $count =  $output[$field_id.'_advanced_search_fields_count']['#value'] ?? 1;
          for ($i = 1; $i <= $count; $i++) {
            if (!empty($output[$field_id.'_wrapper_'.$i]['#attributes']['data-advanced-wrapper'] ?? NULL)) {
              $real_ids[] = $field_id.'_wrapper_'.$i;
            }
          }
        }
      }
      //Now this gets tricky with the advanced Search.
      // we might have multiple $field_id.'_wrapper_1 to N';
      // But i can do a trick here.
      // Check if i have a specific data attribute. If so

      foreach ($real_ids as $real_id) {
        $hide = $this->checkIfNeedsToHideFilter($output[$real_id] ?? []);
        if ($hide) {
          $output[$real_id]['#attributes']['class'][] = 'visually-hidden';
          $output[$real_id]['#attributes']['aria-hidden'] = 'true';
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
              $value['#attributes']['aria-hidden'] = 'true';
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
          $output[$real_id]['#attributes']['aria-hidden'] = 'true';
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
              $value['#attributes']['aria-hidden'] = 'true';
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
      $output['actions']['submit']['#attributes']['aria-hidden'] = 'true';
    }

    // Hide also the add more / remove if hiding advanced search
    if (!$this->configuration['views_exposed_sbf_show_advanced_search_filter'] && $advanced_search_real_id) {
      if (isset($output['actions'][$advanced_search_real_id . '_addone'])) {
        $output['actions'][$advanced_search_real_id . '_addone']['#attributes']['class'][] = 'visually-hidden';
        $output['actions'][$advanced_search_real_id . '_addone']['#attributes']['aria-hidden'] = 'true';
      }
    }


    if (!$this->configuration['views_exposed_sbf_show_reset'] && isset($output['actions']['reset'])) {
      $output['actions']['reset']['#attributes']['class'][] = 'visually-hidden';
      $output['actions']['reset']['#attributes']['aria-hidden'] = 'true';
    }

    if ($this->view->display_handler->ajaxEnabled()) {
      $output['#attributes']['class'][] = 'block-modalformviews-ajax';
      $output['#attributes']['class'][] = 'js-modal-form-views-block';
      // We know (thanks Core) that Resetting via Ajax breaks things
      // So we will mark the reset button to have [data-drupal-selector=edit-reset]
      // so we can ensure no Ajax binding will happen to that button.
      if (isset($output['actions']['reset'])) {
        $output['actions']['reset']['#attributes']['data-drupal-selector'] = 'edit-reset';
      }


      $js_settings = [
        'view_id' => $this->view->id(),
        'current_display_id' => $this->view->current_display,
        'view_base_path' => ltrim($this->view->getPath() ?? '', '/'),
        'ajax_path' => Url::fromRoute('views.ajax')->toString(),
      ];
      $output['#attached']['drupalSettings']['format_strawberryfield_views']['modal_exposed_form_block'][$output['#id']] = $js_settings;
      $output['#attached']['library'][] = 'format_strawberryfield_views/modal-exposed-form-views-ajax';
    }

    if ($this->configuration['views_exposed_sbf_copy_values_to_other_js']) {
      $output['#attributes']['data-sbf-modalblock-copytothers'] = "true";
    }
    if ($this->configuration['views_exposed_sbf_autosubmit_js']) {
      $output['#attributes']['data-sbf-modalblock-autosubmit'] = "true";
    }
    if ($this->configuration['views_exposed_sbf_autosubmit_js'] || $this->configuration['views_exposed_sbf_copy_values_to_other_js']) {
      $output['#attached']['library'][] = 'format_strawberryfield_views/modal-exposed-form-views-interactions';
    }

    if (is_array($output) && !empty($output)) {
      $classes = $output['#attributes']['class'] ?? [];
      if (!$this->configuration['views_exposed_sbf_override_css']) {
        if ($this->view->display_handler->options['defaults']['css_class']) {
          $add_classes($classes, $this->view->displayHandlers->get('default')->options['css_class']);
        } else {
          $add_classes($classes, $this->view->display_handler->options['css_class']);
        }
      }
      else {
        $add_classes($classes, trim($this->configuration['views_exposed_sbf_overriden_css'] ?? ''));
      }
      $output['#attributes']['class'] = $classes;
      $output += [
        '#view' => $this->view,
        '#display_id' => $this->displayID,
      ];
    }

    // Before returning the block output, convert it to a renderable array with
    // contextual links.
    $this->addContextualLinks($output, 'exposed_filter');

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
    if (isset($element['#attributes']) && is_array($element['#attributes'])
      && count(
        array_intersect(array_keys($element['#attributes']), ['data-advanced-search-type', 'data-advanced-wrapper']
      )
    )) {
      // We return sooner just in this case, so we don't end hiding a select or any other text in case we know it is
      // an Advanced Search Element, but we are not/or are hiding.
      if (!$this->configuration['views_exposed_sbf_show_advanced_search_filter']) {
        return TRUE;
      }
      else {
        return FALSE;
      }
    }

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
        $element['#type'], ['select', 'radio']
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
    $default_config['views_exposed_sbf_copy_values_to_other_js'] = 0;
    $default_config['views_exposed_sbf_autosubmit_js'] = 0;
    $default_config['views_exposed_sbf_show_advanced_search_filter'] = 0;
    $default_config['views_exposed_sbf_override_css'] = 0;
    $default_config['views_exposed_sbf_overriden_css'] = '';
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
    $this->configuration['views_exposed_sbf_show_advanced_search_filter'] = $form_state->getValue('views_exposed_sbf_show_advanced_search_filter',0);
    $this->configuration['views_exposed_sbf_show_sort_filter'] = $form_state->getValue('views_exposed_sbf_show_sort_filter',0);
    $this->configuration['views_exposed_sbf_show_pager'] = $form_state->getValue('views_exposed_sbf_show_pager',0);
    $this->configuration['views_exposed_sbf_show_submit'] = $form_state->getValue('views_exposed_sbf_show_submit',0);
    $this->configuration['views_exposed_sbf_show_reset'] = $form_state->getValue('views_exposed_sbf_show_reset',0);
    $this->configuration['views_exposed_sbf_copy_values_to_other_js'] = $form_state->getValue('views_exposed_sbf_copy_values_to_other_js',0);
    $this->configuration['views_exposed_sbf_autosubmit_js'] = $form_state->getValue('views_exposed_sbf_autosubmit_js',0);
    $this->configuration['views_exposed_sbf_override_css'] = $form_state->getValue('views_exposed_sbf_override_css',0);
    $this->configuration['views_exposed_sbf_overriden_css'] = $form_state->getValue('views_exposed_sbf_override_css',0) ? $form_state->getValue('views_exposed_sbf_override_css','') : '';
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

    $form['views_exposed_sbf_show_advanced_search_filter'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Advanced Search Filter if exposed'),
      '#description' => $this->t('Disabling Select and Text individually will have no effect on the Complex Advanced Search Filter and its internal components of those types'),
      '#default_value' => !empty($this->configuration['views_exposed_sbf_show_advanced_search_filter']),
      '#fieldset' => 'views_exposed_sbf_fieldset',
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
    $form['views_exposed_sbf_copy_values_to_other_js'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Copy visible Input Values (on user interaction/change) to other View Exposed Form Modal Blocks belonging to the same View'),
      '#default_value' => !empty($this->configuration['views_exposed_sbf_copy_values_to_other_js']),
      '#fieldset' => 'views_exposed_sbf_fieldset',
      '#states' => [
        'enabled' => [
          [
            [':input[name="settings[views_exposed_sbf_show_sort_filter]"]' => ['checked' => TRUE]],
            'OR',
            [':input[name="settings[views_exposed_sbf_show_checksandoptions_filter]"]' => ['checked' => TRUE]],
            'OR',
            [':input[name="settings[views_exposed_sbf_show_text_filter]"]' => ['checked' => TRUE]],
            'OR',
            [':input[name="settings[views_exposed_sbf_show_select_filter]"]' => ['checked' => TRUE]],
          ],
        ],
      ],
    ];
    $form['views_exposed_sbf_autosubmit_js'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto submits the form, on user interaction/change of select, checkboxes and option elements. This excludes Advanced Search and its internal components. '),
      '#default_value' => !empty($this->configuration['views_exposed_sbf_autosubmit_js']),
      '#fieldset' => 'views_exposed_sbf_fieldset',
      '#states' => [
        'enabled' => [
          [
            [':input[name="settings[views_exposed_sbf_show_sort_filter]"]' => ['checked' => TRUE]],
            'OR',
            [':input[name="settings[views_exposed_sbf_show_checksandoptions_filter]"]' => ['checked' => TRUE]],
            'OR',
            [':input[name="settings[views_exposed_sbf_show_text_filter]"]' => ['checked' => TRUE]],
            'OR',
            [':input[name="settings[views_exposed_sbf_show_select_filter]"]' => ['checked' => TRUE]],
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
            ':input[name="settings[views_exposed_sbf_show_submit]"]' => ['checked' => TRUE],
          ],
        ],
      ],
      '#fieldset' => 'views_exposed_sbf_fieldset',
    ];
    $form['views_exposed_sbf_override_css'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Override CSS inherited from the View '),
      '#default_value' => !empty($this->configuration['views_exposed_sbf_override_css']),
      '#fieldset' => 'views_exposed_sbf_fieldset',
    ];
    $form['views_exposed_sbf_overriden_css'] = [
      '#title' => $this->t('CSS classes separated by spaces'),
      '#type' => 'textfield',
      '#default_value' => $this->configuration['views_exposed_sbf_overriden_css'] ?: '',
      '#states' => [
        'visible' => [
          [
            ':input[name="settings[views_exposed_sbf_override_css]"]' => ['checked' => TRUE],
          ],
        ],
      ],
      '#fieldset' => 'views_exposed_sbf_fieldset',
    ];

    return $form;
  }

  public function getCacheTags() {
    $tags = parent::getCacheTags();
    if ($this->view) {
      $tags = Cache::mergeTags($tags, $this->view->display_handler->getCacheMetadata()->getCacheTags() ?? []);
    }
    return $tags;
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
