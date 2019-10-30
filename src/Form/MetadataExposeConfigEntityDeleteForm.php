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
class MetadataExposeConfigEntityDeleteForm extends EntityConfirmFormBase {

  public function getQuestion() {
    return $this->t('Are you sure you want to delete %name?', ['%name' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.metadatadisplay_entity.collection');
  }


  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $this->entity->delete();

    $this->messenger()->addMessage(
      $this->t('Metadata exposed endpoint @label deleted.',
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
   /*
   //@TODO check if we can get how many times this entity is referenced in a plugin..
   $num_nodes = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', $this->entity->id())
      ->count()
      ->execute();
    if ($num_nodes) {
      $caption = '<p>' . $this->formatPlural($num_nodes, '%type is used by 1 piece of content on your site. You can not remove this content type until you have removed all of the %type content.', '%type is used by @count pieces of content on your site. You may not remove %type until you have removed all of the %type content.', ['%type' => $this->entity->label()]) . '</p>';
      $form['#title'] = $this->getQuestion();
      $form['description'] = ['#markup' => $caption];
      return $form;
    }
    */
    return parent::buildForm($form, $form_state);
  }

}

