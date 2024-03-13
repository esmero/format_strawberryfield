<?php
namespace Drupal\format_strawberryfield\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Form controller for the MetadataDisplayEntity entity delete form.
 *
 * @ingroup format_strawberryfield
 */
class MetadataAPIConfigEntityDeleteForm extends EntityConfirmFormBase {

  public function getQuestion() {
    return $this->t('Are you sure you want to delete %name?', ['%name' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.metadataapi_entity.collection');
  }


  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $this->entity->delete();

    $this->messenger()->addMessage(
      $this->t('Metadata API endpoint @label deleted.',
        [
          '@label' => $this->entity->getLabel(),
        ]
      )
    );
    /** @var \Drupal\Core\Template\TwigEnvironment $environment */
    $environment = \Drupal::service('twig');
    $environment->invalidate();

    $form_state->setRedirectUrl($this->getCancelUrl());
  }


  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    return parent::buildForm($form, $form_state);
  }

}

