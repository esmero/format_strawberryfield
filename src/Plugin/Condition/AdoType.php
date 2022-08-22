<?php

namespace Drupal\format_strawberryfield\Plugin\Condition;

use Drupal\Core\Condition\ConditionPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\strawberryfield\StrawberryfieldUtilityService;
use Drupal\webform\Entity\WebformOptions;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the 'ADO Type' condition.
 *
 * @Condition(
 *   id = "ado_type_condition",
 *   label = @Translation("ADO Type"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", label = @Translation("node"))
 *   }
 * )
 */
class AdoType extends ConditionPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Creates a new AdoType instance.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\strawberryfield\StrawberryfieldUtilityService $strawberryfieldUtilityService
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, StrawberryfieldUtilityService $strawberryfieldUtilityService) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->strawberryfieldUtilityService = $strawberryfieldUtilityService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('strawberryfield.utility')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['ado_types'] = [
      '#title' => $this->pluginDefinition['label'],
      '#description' => t(
        'Enter one or more ADO type names, <strong>one per line</strong>. A list of ADO types can be viewed on the <a href="@link">ADO Type to View Mode Mapping Form</a>.',
        ['@link' => Url::fromRoute('format_strawberryfield.view_mode_mapping_settings_form')->toString()]
      ),
      '#type' => 'textarea',
      '#default_value' => implode(PHP_EOL, $this->configuration['ado_types']),
      '#required' => TRUE,
    ];
    $form['recurse_ado_types'] = [
      '#title' => t("Recurse metadata"),
      '#description' => t('Do you want this condition to look for type values everywhere in the ADO metadata, instead of just at the top level? For example, entering "Image" would cause this condition to match an ADO having an attached image file if this option is checked.'),
      '#type' => 'checkbox',
      '#default_value' => (bool) $this->configuration['recurse_ado_types'],
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['ado_types'] = array_filter(array_map('trim', explode(PHP_EOL, $form_state->getValue('ado_types'))));
    $this->configuration['recurse_ado_types'] = (bool) $form_state->getValue('recurse_ado_types');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate() {
    // Returns true if no ado types are selected and negate option is disabled.
    if (empty($this->configuration['ado_types']) && !$this->isNegated()) {
      return TRUE;
    }
    /** @var \Drupal\node\Entity\Node $node */
    $entity = $this->getContextValue('node');

    $ado_types = [];
    if ($sbf_fields = $this->strawberryfieldUtilityService->bearsStrawberryfield($entity)) {
      foreach ($sbf_fields as $field_name) {
        /* @var \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem $field */
        $field = $entity->get($field_name);
        if (!$field->isEmpty()) {
          foreach ($field->getIterator() as $delta => $itemfield) {
            /** @var \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem $itemfield */
            if($this->configuration['recurse_ado_types']) {
              $values = (array) $itemfield->provideFlatten();
            }
            else {
              $values = (array) $itemfield->provideDecoded();
            }
            if (isset($values['type'])) {
              $ado_types = array_merge($ado_types, (array) $values['type']);
            }
          }
        }
      }
    }
    if(!empty($ado_types)) {
      // Return true if the any of the entity's types are in the condition's ado_types.
      return (count(array_intersect($this->configuration['ado_types'], $ado_types)) > 0);
    }
    // Default, return not matched.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  // TODO: This seems to require javascript to display, though it seems pretty superfluous.
  public function summary() {
    if (count($this->configuration['ado_types']) > 1) {
      $ado_types = $this->configuration['ado_types'];
      $last = array_pop($ado_types);
      $ado_types = implode(', ', $ado_types);

      if (empty($this->configuration['negate'])) {
        return $this->t('@type is @ado_types or @last', [
          '@type' => $this->pluginDefinition['label'],
          '@ado_types' => $ado_types,
          '@last' => $last,
        ]);
      }
      else {
        return $this->t('@type is not @ado_types or @last', [
          '@type' => $this->pluginDefinition['label'],
          '@ado_types' => $ado_types,
          '@last' => $last,
        ]);
      }
    }
    $ado_type = reset($this->configuration['ado_types']);

    if (empty($this->configuration['negate'])) {
      return $this->t('@type is @ado_type', [
        '@type' => $this->pluginDefinition['label'],
        '@ado_type' => $ado_type,
      ]);
    }
    else {
      return $this->t('@type is not @ado_type', [
        '@type' => $this->pluginDefinition['label'],
        '@ado_type' => $ado_type,
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'ado_types' => [],
        'recurse_ado_types' => FALSE,
      ] + parent::defaultConfiguration();
  }

}
