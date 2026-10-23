<?php

namespace Drupal\format_strawberryfield\EventSubscriber;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\strawberryfield\Event\StrawberryfieldCrudEvent;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\strawberryfield\StrawberryfieldEventType;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\format_strawberryfield\Controller\WebAnnotationController;

/**
 * Event subscriber that deletes formatter (e.g. Annotations) temp storage
 * for SBF bearing entities.
 *
 * The actual deletion only happens after persistence of a Node.
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
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  private $loggerFactory;

  /**
   * The current user.
   */
  protected AccountProxyInterface $account;

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
    PrivateTempStoreFactory $temp_store_factory,
    AccountProxyInterface $account
  ) {
    $this->stringTranslation = $string_translation;
    $this->messenger = $messenger;
    $this->loggerFactory = $logger_factory;
    $this->tempStoreFactory = $temp_store_factory;
    $this->account =  $account;


  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {

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
    // NOTE: Since this is a logged-in user specific event
    // And might trigger if, e.g. an SBR runners updates a NODe
    // AND it is run via cron instead of hydroponics
    // (Breaking sessions via - Symfony warning)
    // And it makes no sense to generate a key from
    // anonymous keystore because we do not write on it any ways,
    // we will skip it if the user is anonymous
    if ($this->account->isAnonymous()) {
      return;
    }

    $current_class = get_called_class();
    $entity = $event->getEntity();

    /* @var $tempstore \Drupal\Core\TempStore\PrivateTempStore */
    $tempstore = $this->tempStoreFactory->get('webannotation');
    $keystoreid = WebAnnotationController::getTempStoreKeyNameForAnnotations(
      $entity->uuid()
    );
    $tempstore->delete($keystoreid);

    $event->setProcessedBy($current_class, true);
  }

}
