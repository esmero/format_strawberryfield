<?php

namespace Drupal\format_strawberryfield\EventSubscriber;

use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\strawberryfield\StrawberryfieldEventType;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Event subscriber that deletes format temp storage for SBF bearing entities.
 *
 * The actual deletion only happens after persistance of a Node.
 *
 */
class formatStrawberryfieldDeleteTmpStorage implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * @var int
   */
  protected static $priority = -700;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The serializer.
   * @var \Symfony\Component\Serializer\SerializerInterface;
   */
  protected $serializer;

  /**
   * Stores the tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * StrawberryfieldEventInsertSubscriberDepositDO constructor.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   */
  public function __construct(
    TranslationInterface $string_translation,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory,
    PrivateTempStoreFactory $temp_store_factory
  ) {
    $this->stringTranslation = $string_translation;
    $this->messenger = $messenger;
    $this->loggerFactory = $logger_factory;
    $this->tempStoreFactory = $temp_store_factory;

  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {

    // @TODO check event priority and adapt to future D9 needs.
    $events[StrawberryfieldEventType::SAVE][] = ['onEntitySave', static::$priority];
    return $events;
  }


  /**
   * Method called when Save/Update Event occurs.
   *
   * @param \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent $event
   *
   * @throws \Drupal\Core\TempStore\TempStoreException
   */
  public function onEntitySave(StrawberryfieldCrudEvent $event) {
     /* @var $entity \Drupal\Core\Entity\ContentEntityInterface */

    // Means an existing Entity
    // Our storage key will be less generic, using the actual uuid.

    $current_class = get_called_class();
    $entity = $event->getEntity();
    $sbf_fields = $event->getFields();

    /* @var $tempstore \Drupal\Core\TempStore\PrivateTempStore */

    $tempstore = $this->tempStoreFactory->get('webannotation');

    foreach ($sbf_fields as $field_name) {
      /* @var $field \Drupal\Core\Field\FieldItemInterface */
      $field = $entity->get($field_name);
      /* @var \Drupal\strawberryfield\Field\StrawberryFieldItemList $field */

      $fieldname = $field->getName();
      foreach ($field->getIterator() as $delta => $itemfield) {
        $keyid = $this->getTempStoreKeyName($fieldname, $delta, $entity->uuid());
        $tempstore->delete($keyid);
      }
    }

    $event->setProcessedBy($current_class, true);
  }

  /**
   * Gives us a key name used by the webforms and widgets.
   *
   * @param $fieldname
   * @param int $delta
   * @param string $entity_uuid
   *
   * @return string
   */
  public function getTempStoreKeyName($fieldname, $delta = 0, $entity_uuid = '0') {
    $unique_seed = array_merge(
      [$fieldname],
      [$delta],
      [$entity_uuid]
    );
    return sha1(implode('-', $unique_seed));
  }

}
