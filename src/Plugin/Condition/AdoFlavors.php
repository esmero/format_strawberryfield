<?php

namespace Drupal\format_strawberryfield\Plugin\Condition;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Condition\ConditionPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\strawberryfield\StrawberryfieldUtilityServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the 'ADO has Flavor' condition.
 *
 * @Condition(
 *   id = "ado_flavor_condition",
 *   label = @Translation("ADO has Strawberry Flavour"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", label = @Translation("node"))
 *   }
 * )
 */
class AdoFlavors extends ConditionPluginBase implements ContainerFactoryPluginInterface {

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
    $form['ado_flavors'] = [
      '#title' => $this->pluginDefinition['label'],
      '#description' => t(
        'e.g OCR, Enter Processor ids (one per line) for Strawberry Flavours that need to match. If enabled and left empty, any Strawberry Flavour Processor ID associated to this ADO will match.<br>
                Note: at least ONE of the following 3 settings (checkboxes) need to enabled for this condition to be evaluated. If none, this whole Condition will be skipped (returning TRUE even if negated)',
      ),
      '#type' => 'textarea',
      '#default_value' => implode(PHP_EOL, $this->configuration['ado_flavors'] ?? ''),
    ];
    $form['ado_flavors_op'] = [
      '#title' => "All Flavours need to match",
      '#description' => t(
        'If checked, ALL Processor ids set in the previous field need to match. If disabled, only one of those.',
      ),
      '#type' => 'checkbox',
      '#default_value' =>  $this->configuration['ado_flavors_op'] ?? FALSE
    ];
    $form['ado_direct'] = [
      '#title' => $this->t('ADO has indexed (Search API) Flavor'),
      '#description' => t(
        'A Loaded ADO on the same Page this Block is going to be position produced/has indexed a flavor matching the Flavour config.',
      ),
      '#type' => 'checkbox',
      '#default_value' => $this->configuration['ado_direct'] ?? FALSE,
    ];
    $form['ado_children'] = [
      '#title' => $this->t('Children of ADO have at least one indexed (Search API) Flavour'),
      '#description' => t(
      'At least one Child of an ADO loaded on the same Page this Block is going to be position produced/has indexed a flavor matching the Flavour config',
      ),
      '#type' => 'checkbox',
      '#default_value' => $this->configuration['ado_children'] ?? FALSE,
    ];
    $form['ado_grandchildren'] = [
      '#title' => $this->t('Grandchildren of ADO have at least one indexed (Search API) Flavour'),
      '#description' => t(
        'At least one Grandchild of an ADO loaded on the same Page this Block is going to be position produced/has indexed a flavour matching the Flavor config',
      ),
      '#type' => 'checkbox',
      '#default_value' => $this->configuration['ado_grandchildren'] ?? FALSE,
    ];
    $form['ado_level_op'] = [
      '#title' => "All selected ADO Levels need to match at the same time.",
      '#description' => t(
        'If checked, any of the previous (checked) levels will have to contain Flavours with the selected Processor IDs to evaluate to true. If disabled, one matching will be enough.',
      ),
      '#type' => 'checkbox',
      '#default_value' =>  $this->configuration['ado_level_op'] ?? FALSE
    ];

    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['ado_flavors'] = array_filter(array_map('trim', explode(PHP_EOL, $form_state->getValue('ado_flavors'))));
    $this->configuration['ado_direct'] = (bool) $form_state->getValue('ado_direct');
    $this->configuration['ado_children'] = (bool) $form_state->getValue('ado_children');
    $this->configuration['ado_grandchildren'] = (bool) $form_state->getValue('ado_grandchildren');
    $this->configuration['ado_level_op'] = (bool) $form_state->getValue('ado_level_op');
    $this->configuration['ado_flavors_op'] = (bool) $form_state->getValue('ado_flavors_op');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate() {
    // Returns true if no flavors are selected and negate option is disabled.
    if (empty($this->configuration['ado_flavors']) && !$this->isNegated()) {
      return TRUE;
    }
    // If no level is selected then  no sense to run a query. Return empty.
    if (empty($this->configuration['ado_direct']) &&  empty($this->configuration['ado_children'])  &&  empty($this->configuration['ado_grandchildren'])) {
      return TRUE;
    }
    /** @var \Drupal\node\Entity\Node $node */
    $entity = $this->getContextValue('node');

    $matches = FALSE;
    if ($entity && $sbf_fields = $this->strawberryfieldUtilityService->bearsStrawberryfield($entity)) {
      try {
        $processor_op = ($this->configuration['ado_flavors_op'] ?? FALSE) ? 'AND' : 'OR';
        $level_op = ($this->configuration['ado_level_op'] ?? FALSE) ? 'AND' : 'OR';
        $matches = $this->strawberryfieldUtilityService->getCountByProcessorsAndLevelInSolr($entity, $this->configuration['ado_flavors'], $processor_op, $this->configuration['ado_direct'], $this->configuration['ado_children'], $this->configuration['ado_grandchildren'], $level_op) > 0;
      }
      catch (\Exception $e) {
        $this->messenger()->addWarning('The ADO Flavours Block condition for Block @id generated a Search API exception. Please check your Logs.',[
          '@id' => $this->getPluginId()
        ]);
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
    if (count($this->configuration['ado_flavors']) > 1) {
      $ado_flavors = $this->configuration['ado_flavors'];
      $last = array_pop($ado_flavors);
      $ado_flavors = implode(', ', $ado_flavors);

      if (empty($this->configuration['negate'])) {
        return $this->t('@label is @ado_flavors or @last', [
          '@label' => $this->pluginDefinition['label'],
          '@ado_flavors' => $ado_flavors,
          '@last' => $last,
        ]);
      }
      else {
        return $this->t('@label is not @ado_flavors or @last', [
          '@label' => $this->pluginDefinition['label'],
          '@ado_flavors' => $ado_flavors,
          '@last' => $last,
        ]);
      }
    }
    $ado_flavors = reset($this->configuration['ado_flavors']);

    if (empty($this->configuration['negate'])) {
      return $this->t('@label is @ado_flavors', [
        '@label' => $this->pluginDefinition['label'],
        '@ado_flavors' => $ado_flavors,
      ]);
    }
    else {
      return $this->t('@label is not @ado_flavors', [
        '@label' => $this->pluginDefinition['label'],
        '@ado_flavors' => $ado_flavors,
      ]);
    }
  }
  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'ado_flavors' => [],
        'ado_direct' => FALSE,
        'ado_children' => FALSE,
        'ado_grandchildren' => FALSE,
        'ado_level_op' => FALSE,
        'ado_flavors_op' => FALSE,
      ] + parent::defaultConfiguration();
  }
}
