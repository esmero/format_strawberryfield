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

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('format_strawberryfield.embargo_settings')
      ->set('date_until_json_key',trim($form_state->getValue('date_until_json_key')))
      ->set('ip_json_key', trim($form_state->getValue('ip_json_key')))
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->save();
    parent::submitForm($form, $form_state);
  }
}
