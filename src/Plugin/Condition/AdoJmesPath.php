<?php

namespace Drupal\format_strawberryfield\Plugin\Condition;

use Drupal\Core\Condition\ConditionPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\strawberryfield\StrawberryfieldUtilityServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the 'ADO Type' condition.
 *
 * @Condition(
 *   id = "ado_jmespath_condition",
 *   label = @Translation("ADO JMESPath"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", label = @Translation("node"))
 *   }
 * )
 */
class AdoJmesPath extends ConditionPluginBase implements ContainerFactoryPluginInterface {

  /**
   * @var \Drupal\strawberryfield\StrawberryfieldUtilityServiceInterface
   */
  private StrawberryfieldUtilityServiceInterface $strawberryfieldUtilityService;

  /**
   * Creates a new AdoType instance.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\strawberryfield\StrawberryfieldUtilityServiceInterface $strawberryfieldUtilityService
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, StrawberryfieldUtilityServiceInterface $strawberryfieldUtilityService) {
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
    $form['ado_jmespaths'] = [
      '#title' => $this->pluginDefinition['label'],
      '#description' => t(
        'Enter one or more ADO JMESPath expressions, <strong>one per line</strong>. Any non empty/not FALSE return (except an array with FALSE values) ,of the evaluated expresion(s), is considered a match',
      ),
      '#type' => 'textarea',
      '#default_value' => implode(PHP_EOL, $this->configuration['ado_jmespaths']),
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['ado_jmespaths'] = array_filter(array_map('trim', explode(PHP_EOL, $form_state->getValue('ado_jmespaths'))));
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate() {
    // Returns true if no ado types are selected and negate option is disabled.
    if (empty($this->configuration['ado_jmespaths']) && !$this->isNegated()) {
      return TRUE;
    }
    /** @var \Drupal\node\Entity\Node $node */
    $entity = $this->getContextValue('node');

    $matches = FALSE;
    if ($entity && $sbf_fields = $this->strawberryfieldUtilityService->bearsStrawberryfield($entity)) {
      foreach ($sbf_fields as $field_name) {
        /* @var \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem $field */
        $field = $entity->get($field_name);
        if (!$field->isEmpty()) {
          foreach ($field->getIterator() as $delta => $itemfield) {
            /** @var \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem $itemfield */
            foreach ($this->configuration['ado_jmespaths'] as $jmespath) {
              if (!$matches && !empty($jmespath)) {
                try {
                  $match_result = $itemfield->searchPath($jmespath);
                  if (is_array($match_result)) {
                    // Allows JMESPATHS that return [false] to be considered empty
                    $match_result = array_filter($match_result);
                  }
                  $matches = !empty($match_result);
                }
                catch (\Exception $e) {
                  $this->messenger()->addWarning('The JMESPath Block condition for Block @id is using an invalid JMESPath expression. Please correct the configuration.',[
                    '@id' => $this->getPluginId()
                  ]);
                }
              }
            }
          }
        }
      }
    }
    else {
      // This not an entity type that bears a strawberryfield. Therefore, this condition can not apply.
      // However, it could still be negated and cause in a "TRUE" evaluation to flip and result in the "FALSE"
      // being returned. Deal with that by removing the negation.
      unset($this->configuration['negate']);
      return TRUE;
    }
    // Default, return not matched.
    return $matches;
  }


  /**
   * {@inheritdoc}
   */
  // TODO: This seems to require javascript to display, though it seems pretty superfluous.
  public function summary() {
    if (count($this->configuration['ado_jmespaths']) > 1) {
      $ado_jmespaths = $this->configuration['ado_jmespaths'];
      $last = array_pop($ado_jmespaths);
      $ado_jmespaths = implode(', ', $ado_jmespaths);

      if (empty($this->configuration['negate'])) {
        return $this->t('@jmespath is @ado_jmespaths or @last', [
          '@jmespath' => $this->pluginDefinition['label'],
          '@ado_jmespaths' => $ado_jmespaths,
          '@last' => $last,
        ]);
      }
      else {
        return $this->t('@jmespath is not @ado_jmespaths or @last', [
          '@type' => $this->pluginDefinition['label'],
          '@ado_jmespaths' => $ado_jmespaths,
          '@last' => $last,
        ]);
      }
    }
    $ado_jmespath = reset($this->configuration['ado_jmespaths']);

    if (empty($this->configuration['negate'])) {
      return $this->t('@jmespath is @ado_jmespath', [
        '@jmespath' => $this->pluginDefinition['label'],
        '@ado_jmespath' => $ado_jmespath,
      ]);
    }
    else {
      return $this->t('@jmespath is not @ado_jmespath', [
        '@jmespath' => $this->pluginDefinition['label'],
        '@ado_jmespath' => $ado_jmespath,
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'ado_jmespaths' => [],
      ] + parent::defaultConfiguration();
  }

}
