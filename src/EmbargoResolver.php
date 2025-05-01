<?php

namespace Drupal\format_strawberryfield;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\UseCacheBackendTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use DateTime;

/**
 * Embargo And Inheritance Resolver service
 *
 * @ingroup format_strawberryfield
 */
class EmbargoResolver implements EmbargoResolverInterface {
  use UseCacheBackendTrait;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The Global embargo Config.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $embargoConfig;

  /**
   * The Current User
   * @var \Drupal\Core\Session\AccountInterface
   */

  protected $currentUser;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;


  /**
   * A Per HTTP Request static cache of resolved Embargoes
   *
   * @var array
   */
  protected array $resolvedEmbargos = [];

  /**
   * A Per HTTP Request static cache of resolved Embargoes
   *
   * @var array
   */
  protected array $resolvedEmbargosNID = [];


  /**
   * DisplayResolver constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   */
  public function __construct(ConfigFactoryInterface $config_factory, AccountInterface $current_user, RequestStack $request_stack) {
    $this->configFactory = $config_factory;
    $this->embargoConfig = $this->configFactory->get('format_strawberryfield.embargo_settings');
    $this->currentUser = $current_user;
    $this->requestStack = $request_stack;
  }

  /**
   * Checks if we can bypass embargo.
   *    If not possible we return an array with more info.
   *    If the user is anonymous, and IP data is present in the ADO, we kill the cache too.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   * @param array $jsondata
   *
   * @return array
   *    Returns array
   *    with [(bool) embargoed, $date|FALSE, (bool) IP is enforced], (bool) if cacheable at all or not
   */
  public function embargoInfo(ContentEntityInterface $entity, array $jsondata) {
    $uuid = $entity->uuid();
    $nid = $entity->id();
    static $cache = [];
    $cache_id = $uuid . md5(serialize($jsondata));
    // This cache will only work per extending class
    // If we want the cache to survive longer
    // We need to make the function itself static
    // but also add the varying IP to it.
    // @TODO evaluate if making it static is worth the effort.
    // Since all act on the same data/entity
    // And will share during the life
    // of the static cache
    // Same user/roles/etc
    // but IP can vary, even for the same user.
    // But never on a single HTTP call.
    if (isset($cache[$cache_id])) {
      return $cache[$cache_id];
    }

    $noembargo = TRUE;
    // If embargo by IP is enforced
    $ip_embargo = FALSE;
    // If embargo by date is enforced
    $date_embargo = FALSE;
    $cacheable = TRUE;

    if (!$this->embargoConfig->get('enabled')) {
      $embargo_info = [!$noembargo, FALSE, FALSE, $cacheable];
    }
    $user_roles = $this->currentUser->getRoles();
    if (in_array('administrator', $user_roles)) {
      $embargo_info = [!$noembargo, FALSE, FALSE, $cacheable];
    }
    elseif ($this->currentUser->hasPermission('see strawberryfield embargoed ados')) {
      $embargo_info = [!$noembargo, FALSE , FALSE, $cacheable];
    }
    else {
      if ($this->currentUser->hasPermission('see strawberryfield time embargoed ados')) {
        $noembargo = TRUE;
        $date_embargo = FALSE;
      }
      else {
        // Check the actual embargo options
        $date_embargo_key = $this->embargoConfig->get('date_until_json_key') ?? '';
        if (strlen($date_embargo_key) > 0 && !empty($jsondata[$date_embargo_key]) && is_string($jsondata[$date_embargo_key])) {
          $date = $this->parseStringToDate(trim($jsondata[$date_embargo_key]));
          if ($date) {
            if ((strtotime(date('Y-m-d')) - strtotime($date)) > 0) {
              $noembargo = TRUE;
            }
            else {
              $noembargo = FALSE;
              $date_embargo = TRUE;
            }
          }
        }
      }
      if ($this->currentUser->hasPermission('see strawberryfield IP embargoed ados')) {
        $ip_embargo = FALSE;
      }
      else {
        $ip_embargo_key = $this->embargoConfig->get('ip_json_key') ?? '';
        if (strlen($ip_embargo_key) > 0 && !empty($jsondata[$ip_embargo_key])) {
          $current_ip = $this->requestStack->getCurrentRequest()->getClientIp();
          if ($this->currentUser->isAnonymous()) {
            if (\Drupal::moduleHandler()->moduleExists('page_cache')) {
              // we won't be able to cache varying contexts for anonymous, so
              // simply kill the page cache
              \Drupal::service('page_cache_kill_switch')->trigger();
              $cacheable = FALSE;
            }
          }
          // Why would the current IP not be present? Should we deny all if that
          // exception happens?
          if ($current_ip) {
            $ip_evaluated = FALSE;
            if (is_array($jsondata[$ip_embargo_key])) {
              foreach ($jsondata[$ip_embargo_key] as $ip_embargo_value) {
                if (is_string($ip_embargo_value)) {
                  $ip_embargo = IpUtils::checkIp4($current_ip, trim($ip_embargo_value)) || $ip_embargo;
                  // Here we need to do it differently. We will || all the $ip_embargo
                  // and then check the $noembargo variable outside of this loop
                  $ip_evaluated = TRUE;
                }
              }
              $noembargo = $noembargo && $ip_embargo;
            }
            elseif (is_string($jsondata[$ip_embargo_key])) {
              $ip_embargo = IpUtils::checkIp4($current_ip, trim($jsondata[$ip_embargo_key]));
              $noembargo = $noembargo && $ip_embargo;
              $ip_evaluated = TRUE;
            }

            if ($this->embargoConfig->get('global_ip_bypass_enabled')) {
              $global_ip_embargo = FALSE;
              // If the key is there and set to TRUE. Replace/Additive/Local does not apply here
              if (is_bool($jsondata[$ip_embargo_key] ?? []) && $jsondata[$ip_embargo_key] == TRUE) {
                $ip_embargo = $this->evaluateGlobalIPembargo($current_ip);
                $noembargo = $noembargo && $ip_embargo;
              }
              // Only makes sense to check the modes IF the ADO already had IP data and was evaluated.
              elseif ($ip_evaluated) {
                $mode = $this->embargoConfig->get('global_ip_bypass_mode');
                // Replace means global ip bypass wins. So any other evaluation that e.g would allow
                // a user to bypass is invalidated, and we need to re-evaluate.
                if ($mode == "replace") {
                  $ip_embargo = $this->evaluateGlobalIPembargo($current_ip);
                  $noembargo = $noembargo && $ip_embargo;
                }
                if ($mode == "additive") {
                  $ip_embargo = $this->evaluateGlobalIPembargo($current_ip) || $ip_embargo;
                  $noembargo = $noembargo && $ip_embargo;
                }
                if ($mode == "local") {
                  // Do nothing really.
                  $ip_embargo = $ip_embargo;
                }
              }
            }
          }
        }
      }
    }
    $embargo_info = [!$noembargo, $date_embargo ? $date: FALSE , $ip_embargo, $cacheable];

    $cache[$cache_id] = $embargo_info;
    $this->resolvedEmbargos[$uuid] = $this->resolvedEmbargosNID[$nid] = $embargo_info;

    return $embargo_info;
  }


  public function evaluateGlobalIPembargo($current_ip) {
    $ip_embargo = FALSE;
    $global_ips = $this->embargoConfig->get('global_ip_bypass_addresses') ?? [];
    foreach ($global_ips as $ip_embargo_value) {
      if (is_string($ip_embargo_value)) {
        $ip_embargo = IpUtils::checkIp4($current_ip, trim($ip_embargo_value)) || $ip_embargo;
      }
    }
    return $ip_embargo;
  }

  /**
   * Getter for the resolved Static embargo Cache
   *
   * @param string $uuid
   *    The UUID of a node for which an embargo might/not have been resolved
   * @return array
   *    The embargo info in [!$noembargo, $date_embargo ? $date: FALSE , $ip_embargo, $cacheable];
   */
  public function getResolvedEmbargoesByUUid(string $uuid): array {
    return $this->resolvedEmbargos[$uuid] ?? [];
  }


  /**
   * Getter for the resolved Static embargo Cache
   *
   * @param string $uuid
   *    The UUID of a node for which an embargo might/not have been resolved
   * @return array
   *    The embargo info in [!$noembargo, $date_embargo ? $date: FALSE , $ip_embargo, $cacheable];
   */
  public function getResolvedEmbargoesByNiD(int $nid): array {
    return $this->resolvedEmbargosNID[$nid] ?? [];
  }


  /**
   * Get a list of ADO types based on the SBF.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity.
   *
   * @return array
   *   Array of ado types.
   *
   * @throws \Exception
   */
  protected function getAdoTypes(ContentEntityInterface $entity) {
    $cache_id = 'format_strawberry:view_mode_adotypes:' . $entity->id();
    $cached = $this->cacheGet($cache_id);
    if ($cached) {
      return $cached->data;
    }

    $ado_types = [];
    if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield($entity)) {
      foreach ($sbf_fields as $field_name) {
        /* @var \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem $field */
        $field = $entity->get($field_name);
        if (!$field->isEmpty()) {
          foreach ($field->getIterator() as $delta => $itemfield) {
            /** @var \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem $itemfield */
            $flat_values = (array) $itemfield->provideFlatten();
            if (isset($flat_values['type'])) {
              $ado_types = array_merge($ado_types, (array) $flat_values['type']);
            }
          }
        }
      }
    }

    // Cache tags need to depend on the entity itself, the new $cache_id but
    // also the ones from config.
    // @TODO: Change this for Drupal 9 as mergeTags will accept more arguments.
    // @see https://www.drupal.org/node/3125498
    $config = $this->configFactory->get('format_strawberryfield.viewmodemapping_settings');
    $this->cacheSet($cache_id, $ado_types, CacheBackendInterface::CACHE_PERMANENT, Cache::mergeTags(Cache::mergeTags($entity->getCacheTags(), $config->getCacheTags()), [$cache_id]));
    return $ado_types;
  }
  /**
   * Will try to parse an unknown string to an ISO8601 date.
   *
   * @param mixed $date
   *
   * @return false|string
   *    If string/int could not be parse returns false.
   *    If it was possible, return an Y-m-d date.
   */
  protected function parseStringToDate($date) {
    // Start by using a full ISO8601 date in case time zone is included
    $d = DateTime::createFromFormat('c', $date);
    if (!$d) {
      // If not check if its not a timestamp
      if (!is_numeric($date)) {
        $date = strtotime($date);
      }
      if ($date) {
        $d = DateTime::createFromFormat('U', $date);
      }
    }
    if ($d) {
      return $d->format('Y-m-d');
    }
    return FALSE;
  }

}
