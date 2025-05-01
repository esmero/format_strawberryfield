<?php

namespace Drupal\format_strawberryfield\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\format_strawberryfield\Tools\IiifUrlValidator;


/**
 * Class EmbargoSettingsForm
 */
class EmbargoSettingsForm extends ConfigFormBase {

  /**
   * Constructs a \Drupal\system\ConfigFormBase object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->setConfigFactory($config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory')
    );
  }


  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'format_strawberryfield.embargo_settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'format_strawberryfield_embargo_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('format_strawberryfield.embargo_settings');

    $form['info'] = [
      '#markup' => $this->t('This form allows you to enable/disable embargo functionality enforced at the Formatter level and configure on which JSON Key/values those will act.'),
    ];

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Is Embargo checking and enforcing globally active?'),
      '#return_value' => TRUE,
      '#default_value' => $config->get('enabled') ?? FALSE,
    ];

    $form['date_until_json_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('JSON key present in your metadata that contains an embargo lift date that will be used to Embargo Metadata and Media.'),
      '#default_value' => $config->get('date_until_json_key') ?? '',
      '#required' => FALSE,
    ];

    $form['ip_json_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('JSON key present in your metadata that contains an allowed to bypass embargo through a visitor IP or IP range that will be used to Embargo Metadata and Media.'),
      '#default_value' => $config->get('ip_json_key') ?? '',
      '#required' => FALSE
    ];

    $form['global_ip_bypass_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Global IP Range Bypass Settings'),
      '#description' => $this->t('To allow global IP embargo bypass settings to act on an ADO, you must enable this option AND add the defined (above on this configuration form) JSON key to impacted ADOs with a value of boolean "true". For example, an ADO would have this set in the raw JSON data: <code>{ "ip_embargo_bypass": true }</code> '),
      '#return_value' => TRUE,
      '#default_value' => $config->get('global_ip_bypass_enabled') ?? FALSE,
    ];

    $form['global_ip_bypass_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Global IP Range Bypass Mode'),
      '#description' => $this->t('Select one of these three modes to determine the order preference for global versus granular Embargo bypasses. These distinct modes only affect already embargoed ADOs with "Embargo bypass by IP" values defined. We recommend using test ADOs to ensure you have configured the correct combination of embargo settings before using for production ADOs.'),
      '#options' => [
        'replace' => $this->t('Global IP Range will override any "Embargo bypass by IP" values defined at the granular ADO level'),
        'additive' => $this->t('Global IP Range will be added to "Embargo bypass by IP" values defined at the granular ADO level'),
        'local' =>  $this->t('Global IP Range will be ignored when an ADO holds "Embargo bypass by IP" values at the granular level'),
      ],
      '#default_value' => $config->get('global_ip_bypass_mode') ?? 'local',
      '#states' => [
        'required' => [
          ':input[name="global_ip_bypass_enabled"]' => ['checked' => true],
          'AND',
          ':input[name="enabled"]' => ['checked' => true],
          'AND',
          ':input[name="ip_json_key"]' => ['filled' => true],
        ],
      ]
    ];

    $global_ip_bypass = $config->get('global_ip_bypass_addresses') ?? [];
    $global_ip_bypass = implode("\n", $global_ip_bypass);

    $form['global_ip_bypass_addresses'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Global IP addresses and ranges embargo bypass'),
      '#cols' => '80',
      '#rows' => '10',
      '#description' => $this->t("Specify IP addresses in CDIR format. Enter one IP/IP Range per line."),
      '#default_value' => $global_ip_bypass ?? '',
      '#required' => FALSE,
      '#states' => [
        'required' => [
          ':input[name="global_ip_bypass_enabled"]' => ['checked' => true],
          'AND',
          ':input[name="ip_json_key"]' => ['filled' => true],
          'AND',
          ':input[name="enabled"]' => ['checked' => true],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Global IPs as array
    $global_ips = array_map('trim', explode("\n", $form_state->getValue('global_ip_bypass_addresses')));
    $this->config('format_strawberryfield.embargo_settings')
      ->set('date_until_json_key',trim($form_state->getValue('date_until_json_key')))
      ->set('ip_json_key', trim($form_state->getValue('ip_json_key')))
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('global_ip_bypass_enabled', (bool) $form_state->getValue('global_ip_bypass_enabled') ?? FALSE)
      ->set('global_ip_bypass_mode', $form_state->getValue('global_ip_bypass_mode') ?? 'local')
      ->set('global_ip_bypass_addresses', $global_ips ?? [])
      ->save();
    parent::submitForm($form, $form_state);
  }
}
