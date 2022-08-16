<?php

namespace Drupal\format_strawberryfield\Plugin\Condition;

use Drupal\Core\Condition\ConditionPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
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
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $type_options = [];
    foreach(['schema_org_creative_works', 'schema_org_cw_collections'] as $webform_option_list_id) {
      $webform_option_list = WebformOptions::load($webform_option_list_id);
      if (!empty($webform_option_list)) {
        $type_options = array_merge($type_options, $webform_option_list->getOptions());
      }
    }
    asort($type_options);
    $form['ado_types'] = [
      '#title' => $this->pluginDefinition['label'],
      '#type' => 'checkboxes',
      '#options' => $type_options,
      '#default_value' => $this->configuration['ado_types'],
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['ado_types'] = array_filter($form_state->getValue('ado_types'));
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
    $node = $this->getContextValue('node');

    // Get the ADO type from the entity.
    if ($node->hasField('field_descriptive_metadata')) {
      $metadata = $node->get('field_descriptive_metadata')->getString();
      if (!empty($metadata)) {
        $metadata = json_decode($metadata);
        if(!empty($metadata->type)) {
          // Return true if the `type` value from the json matches a selected ado_type.
          return !empty($this->configuration['ado_types'][$metadata->type]);
        }
      }
    }
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
      ] + parent::defaultConfiguration();
  }

}
