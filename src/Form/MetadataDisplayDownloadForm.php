<?php
namespace Drupal\format_strawberryfield\Form;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\format_strawberryfield\MetadataDisplayInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Form controller for the MetadataDisplayEntity entity Download form.
 *
 * @ingroup format_strawberryfield
 */
class MetadataDisplayDownloadForm extends FormBase {


  /**
   * The entity being used by this form.
   *
   * @var \Drupal\Core\Entity\ContentEntityInterface|\Drupal\Core\Entity\RevisionLogInterface
   */
  protected $entity;
  /**
   * Returns a unique string identifying the form.
   *
   * @return string
   *   The unique string identifying the form.
   */
  public function getFormId() {
    return 'format_strawberryfield_metadatadisplay_download';
  }

  /**
   * {@inheritdoc}
   */
  public function getEntity() {
    return $this->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function setEntity(EntityInterface $entity) {
    $this->entity = $entity;
    return $this;
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
    $format = $form_state->getValue('format');
    $body = $this->fetchMetadataContent($format);
    switch ($format) {
      case 'twig' :
        $filename = $this->getEntity()->label() ."-".$this->getEntity()->uuid().".twig.html";
        $content_type = "text/html";
        break;
      default :
        $filename = $this->getEntity()->label() ."-".$this->getEntity()->uuid().".json";
        $content_type = "application/json";
    }

    $attachment = 'attachment;';

    $headers = [
      'Content-Length' => strlen($body),
      'Content-Type' => $content_type,
      'Content-Disposition' => $attachment . 'filename="' . $filename . '"',
    ];

    $response = new Response($body, 200, $headers);
    $response->setPrivate();
    $form_state->setResponse($response);
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
     if (!$this->getEntity()) {
       $this->setEntity($metadatadisplay_entity);
     }
      $form['format'] = [
        '#type' => 'select',
        '#title' => $this->t('Please select your download Format'),
        '#options' => [
          'twig' => "Twig template File (.twig.html)",
          'jsonapi' => "JSON API Create/Update Payload (.json)"
        ]
      ];

     $form['action'] = [
         'submit' => [
           '#type' => 'submit',
           '#value' => $this->t('Download Metadata Display Entity'),
           '#submit' => [
             [$this, 'submitForm'],
           ],
         ]
       ];
   }
   return $form;
  }


  public function fetchMetadataContent($format):string {
    $encoded = "";
    if ($metadatadisplay_entity = $this->getEntity()) {
      if ($format == "twig") {
          $encoded = $metadatadisplay_entity->get('twig')->first()->getString();
      }
      else  {
        $twig = $metadatadisplay_entity->get('twig')->first()->getString();
        $body = [
          'data' => [
            'type' => 'metadatadisplay_entity--metadatadisplay_entity',
            'id' => $metadatadisplay_entity->uuid(),
            'attributes' => [
              'name' => $metadatadisplay_entity->label(),
              'twig' => $twig,
              'langcode' => $metadatadisplay_entity->language()->getId(),
              'mimetype' => $metadatadisplay_entity->get('mimetype')->first()->getString()
            ]
          ]
        ];
        $encoded = json_encode($body, JSON_PRETTY_PRINT);
      }
    }
    return $encoded;
  }
}
