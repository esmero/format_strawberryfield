<?php
namespace Drupal\format_strawberryfield\Form;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Language\Language;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for the MetadataDisplayEntity entity edit forms.
 *
 * @ingroup format_strawberryfield
 */
class MetadataDisplayForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    /* @var $entity \Drupal\format_strawberryfield\Entity\MetadataDisplayEntity */
    $form = parent::buildForm($form, $form_state);

    $entity = $this->entity;

    $form['langcode'] = array(
      '#title' => $this->t('Language'),
      '#type' => 'language_select',
      '#default_value' => $entity->getUntranslated()->language()->getId(),
      '#languages' => Language::STATE_ALL,
    );

    $form['footer']['help'] = [
      '#title' => $this->t('Help? Full list of available Twig replacements and functions in Drupal 8.'),
      '#type' => 'link',
      '#url' =>  \Drupal\Core\Url::fromUri('https://www.drupal.org/docs/8/theming/twig/functions-in-twig-templates',['attributes' => ['target' => '_blank']])
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    try {
      $build = [
        '#type' => 'inline_template',
        '#template' => $form_state->getValue('twig')[0]['value'],
        '#context' => [],
      ];
      \Drupal::service('renderer')->renderPlain($build);
    }
    catch (\Exception $exception) {
      $form_state->setErrorByName('twig',$exception->getMessage());
    }

    return parent::validateForm(
      $form,
      $form_state
    ); // TODO: Change the autogenerated stub
  }


  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $status = parent::save($form, $form_state);

    $entity = $this->entity;
    if ($status == SAVED_UPDATED) {
      $this->messenger()->addMessage($this->t('The Metadata Display %entity has been updated.', ['%entity' => $entity->toLink()->toString()]));
    } else {
      $this->messenger()->addMessage($this->t('The Metadata Display %entity has been added.', ['%entity' => $entity->toLink()->toString()]));
    }
    \Drupal::service('plugin.manager.field.formatter')->clearCachedDefinitions();

    /** @var \Drupal\Core\Template\TwigEnvironment $environment */
    $environment = \Drupal::service('twig');
    $environment->invalidate();

    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $status;
  }
}
