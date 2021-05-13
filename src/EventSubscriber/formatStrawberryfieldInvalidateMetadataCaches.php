<?php

namespace Drupal\format_strawberryfield\EventSubscriber;

use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\strawberryfield\StrawberryfieldEventType;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Cache\Cache;

/**
 * Event subscriber that deletes format temp storage for SBF bearing entities.
 *
 * The actual deletion only happens after persistance of a Node.
 *
 */
class formatStrawberryfieldInvalidateMetadataCaches implements EventSubscriberInterface {

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
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  private $loggerFactory;

  /**
   * formatStrawberryfieldInvalidateMetadataCaches constructor.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   */
  public function __construct(
    TranslationInterface $string_translation,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->stringTranslation = $string_translation;
    $this->messenger = $messenger;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {

    // @TODO check event priority and adapt to future D9 needs.
    $events[StrawberryfieldEventType::SAVE][] = ['onEntityOp', static::$priority];
    $events[StrawberryfieldEventType::DELETE][] = ['onEntityOp', static::$priority];
    $events[StrawberryfieldEventType::INSERT][] = ['onEntityOp', static::$priority];
    return $events;
  }


  /**
   * Method called when Save/Update Event occurs.
   *
   * @param \Drupal\strawberryfield\Event\StrawberryfieldCrudEvent $event
   */
  public function onEntityOp(StrawberryfieldCrudEvent $event) {
     /* @var $entity \Drupal\Core\Entity\ContentEntityInterface */
    $entity = $event->getEntity();
    $this->invalidate_cache($entity);
    $current_class = get_called_class();
    $event->setProcessedBy($current_class, true);
  }

  protected function invalidate_cache(ContentEntityInterface $entity) {
    try {
      $field = $entity->get('field_sbf_nodetonode');
      foreach ($field->getIterator() as $delta => $itemfield) {
        $tags[] = 'node_metadatadisplay:' . $itemfield->target_id;
      }
      if (!empty($tags)) {
        Cache::invalidateTags($tags);
      }
    }
    catch (\Exception $exception) {
      $this->loggerFactory->get('strawberryfield')->error($this->t('Error invalidating Caches for parent Nodes for Node ID @node', ['@node' => $entity->id()]));
    }
  }
}
