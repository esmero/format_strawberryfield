<?php
namespace Drupal\format_strawberryfield\Form;
use Drupal\ami\Entity\amiSetEntity;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\format_strawberryfield\MetadataDisplayInterface;
use Drupal\format_strawberryfield\MetadataDisplayUsageService;
use Drupal\format_strawberryfield\MetadataDisplayUsageServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Form controller for the MetadataDisplayEntity entity Usage forms.
 *
 * @ingroup format_strawberryfield
 */
class MetadataDisplayUsageForm extends FormBase {
  private MetadataDisplayUsageServiceInterface $metadatadisplayUsageService;

  /**
   * @param MetadataDisplayUsageServiceInterface $metadatadisplay_usage_service
   */
  public function __construct(MetadataDisplayUsageServiceInterface $metadatadisplay_usage_service) {
    $this->metadatadisplayUsageService = $metadatadisplay_usage_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('format_strawberryfield.metadatadisplay_usage_service'),
    );
  }

  /**
   * Returns a unique string identifying the form.
   *
   * @return string
   *   The unique string identifying the form.
   */
  public function getFormId() {
    return 'format_strawberryfield_metadatadisplay_usage';
  }

  /**
   * Form submission handler.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param FormStateInterface $form_state
   *   An associative array containing the current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Empty implementation of the abstract submit class.
  }


  /**
   * Define the form used for MetadataDisplayEntity settings.
   * @return array
   *   Form definition array.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param FormStateInterface $form_state
   *   An associative array containing the current state of the form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, MetadataDisplayInterface $metadatadisplay_entity = NULL) {
   if ($metadatadisplay_entity) {
     $form = $this->metadatadisplayUsageService->getRenderableUsage($metadatadisplay_entity);
   }
    return $form;
  }
}
