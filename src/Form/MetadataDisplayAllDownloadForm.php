<?php
namespace Drupal\format_strawberryfield\Form;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\format_strawberryfield\MetadataDisplayInterface;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Batch\BatchBuilder;
use PharData;

/**
 * Form controller for the MetadataDisplayEntity entity Download form.
 *
 * @ingroup format_strawberryfield
 */
class MetadataDisplayAllDownloadForm extends FormBase {

  /**
   * Returns a unique string identifying the form.
   *
   * @return string
   *   The unique string identifying the form.
   */
  public function getFormId() {
    return 'format_strawberryfield_metadatadisplayall_download';
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
    $metadataDisplayEntities =  \Drupal::entityQuery('metadatadisplay_entity')->accessCheck(TRUE)->execute();
    $batch_builder = (new BatchBuilder())
      ->setTitle($this->t('Packaging your Metadata Display Entities...'))
      ->setFinishCallback([self::class, 'batchFinished'])
      ->setInitMessage($this->t('Starting export.'))
      ->setProgressMessage($this->t('Completed @current of @total.'))
      ->setErrorMessage($this->t('We encountered an error during the export.'));
      if ($this->currentUser()->hasPermission('Administer Metadata Display Template entity')
        || $this->currentUser()->hasPermission('View Metadata Display Template entity')
      ) {
        $request = $this->getRequest();
        $date = \DateTime::createFromFormat('U', $request->server->get('REQUEST_TIME'));
        $date_string = $date->format('Y-m-d-H-i');
        $hostname = str_replace('.', '-', $request->getHttpHost());
        $filename = 'temporary://metadata_display_entities-' . $hostname . '-' . $date_string . '.tar';
        $tarfile = new PharData($filename);
        $tarfile->
        $chunks = array_chunk($metadataDisplayEntities, 10);
        $format = $form_state->getValue('format');
        foreach ($chunks as $chunk) {
          $batch_builder->addOperation([
            self::class,
            'batchProcess'
          ], [
            "metadataDisplayEntityIDs" => $chunk,
            "format" => $format,
            "filename" => $filename
          ]);
        }
        batch_set($batch_builder->toArray());
      }







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


  public static function batchProcess($data, &$context): void {
    if (isset($data['metadataDisplayEntityIDs']) && is_array($data['metadataDisplayEntityIDs']))
      $metadatadisplay_entities = \Drupal::entityTypeManager()->getStorage('metadatadisplay_entity')->loadMultiple($data['metadataDisplayEntityIDs']);
      foreach($metadatadisplay_entities as $entity) {
          $payload = static::fetchMetadataContent($entity);
        $a = new PharData($data['filename']);

        $a->addFromString('path/to/file.txt', 'my simple file');
      }

    $context['results'][] = $item;
    $context['message'] = t('Processing item "@item"', ['@item' => end($chunk)]);
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
           '#value' => $this->t('Download All Metadata Display Entities'),
           '#submit' => [
             [$this, 'submitForm'],
           ],
         ]
       ];
   return $form;
  }


  public static function fetchMetadataContent(MetadataDisplayInterface $metadatadisplay_entity, $format):string {
    $encoded = "";
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
    return $encoded;
  }
}
