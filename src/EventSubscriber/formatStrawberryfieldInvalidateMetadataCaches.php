<?php

namespace Drupal\format_strawberryfield\EventSubscriber;

use Drupal\search_api\Event\ItemsIndexedEvent;
use Drupal\search_api\Event\SearchApiEvents;
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
    $events[SearchApiEvents::ITEMS_INDEXED][] = ['itemsIndexed', static::$priority];
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

  /**
   * Reacts to the items indexed event and invalidates parent tags.
   *
   * @param \Drupal\search_api\Event\ItemsIndexedEvent $event
   *   The items indexed event.
   */
  public function itemsIndexed(ItemsIndexedEvent $event) {
    $tags = [];
    try {
      $processed_entities = $event->getProcessedIds();
      $items = $event->getIndex()->loadItemsMultiple($processed_entities);
      foreach ($items as $item) {
        // Content DataSources will return pluginID == NULL BC the entity
        // replaces the actual item, but SBF will still be of type datasource
        // We won't use Flavors here to update the parent ADO cache.
        // @TODO ask team. Should an OCR index invalidate the caches of a parent?
        if ($item->getPluginId() != "strawberryfield_flavor_data") {
          if ($item->getEntity()->hasField('field_sbf_nodetonode')) {
            $field = $item->getEntity()->get('field_sbf_nodetonode');
            foreach ($field->getIterator() as $delta => $itemfield) {
              $tags[] = 'node_metadatadisplay:' . $itemfield->target_id;
            }
          }
        }
      }
      $tags = array_unique($tags);
      if (!empty($tags)) {
        Cache::invalidateTags($tags);
      }
    }
    catch (\Exception $exception) {
      $this->loggerFactory->get('format_strawberryfield')->error(
        $this->t(
          'Error invalidating Caches for parent Nodes of newly Indexed Documents @e',
          ['@e' => $exception->getMessage()]
        )
      );
    }
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
      $this->loggerFactory->get('format_strawberryfield')->error($this->t('Error invalidating Caches for parent Nodes for Node ID @node', ['@node' => $entity->id()]));
    }
  }
}
