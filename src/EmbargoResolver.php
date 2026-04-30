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

  const OTHER_VALID_GLOBAL_IP_BYPASS_VALUES = [
    "1",
    1,
    "true",
    "TRUE"
  ];


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
    // Strange name. $noembargo == TRUE, means basically ADO can be seen by the
    // current user
    $noembargo = TRUE;
    $date = FALSE;
    // If embargo by IP is enforced/was evaluated.
    $ip_evaluated = FALSE;
    // If $ip_embargo_bypass is possible, also connected to
    // Cache-ability and cache tags.
    $ip_embargo_bypass = NULL;
    // If embargo by date is enforced
    $date_embargo = FALSE;
    // Only IP embargo triggers a complete uncacheable response;
    $cacheable = TRUE;

    /*  $embargo_info = [
     !$noembargo => If the ADO is embargoed (bool) (combined evaluation of Date and IP OR permissions),
     $date_embargo ? $date : FALSE If the ADO is embargoed by Date (FALSE|STRING) the actual Date if embargoed (TRUE) OR FALSE if user can see it,
     !$ip_embargo_bypass If the ADO is embargoed by IP (bool). TRUE if either local/global IPs do not match the current Client's IP. FALSE if not,
     $cacheable (bool). The computed Cache-ability. Anything that went through IP embargo evaluation can't be cached
     ]
    */

    if (!$this->embargoConfig->get('enabled')) {
      $embargo_info = [FALSE, FALSE, FALSE, TRUE];
    }
    else {
      $user_roles = $this->currentUser->getRoles();
      if (in_array('administrator', $user_roles) || $this->currentUser->hasPermission('see strawberryfield embargoed ados')) {
        $embargo_info = [FALSE, FALSE, FALSE, TRUE];
      }
      else {
        if ($this->currentUser->hasPermission('see strawberryfield time embargoed ados')) {
          $noembargo = TRUE; // Redundant, i know. But easier to read.
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
                // When true, the actual date is added to the $info.
                $date_embargo = TRUE;
              }
            }
          }
        }
        if ($this->currentUser->hasPermission('see strawberryfield IP embargoed ados')) {
          $ip_embargo_bypass = TRUE;
        }
        else {
          $ip_embargo_key = $this->embargoConfig->get('ip_json_key') ?? '';
          if (strlen($ip_embargo_key) > 0 && !empty($jsondata[$ip_embargo_key])) {
            $current_ip = $this->requestStack->getCurrentRequest()
              ->getClientIp();
            if ($this->currentUser->isAnonymous()) {
              if (\Drupal::moduleHandler()->moduleExists('page_cache')) {
                // we won't be able to cache varying contexts for anonymous, so
                // simply kill the page cache
                \Drupal::service('page_cache_kill_switch')->trigger();
                $cacheable = FALSE;
              }
            }
            // Why would the current IP not be present? Should we deny all if that
            // exception happens? Internal calls/ops might not have an IP.
            if ($current_ip) {
              $ip_evaluated = FALSE;
              // We copy temporarily the previous $noembargo value
              $noembargo_from_ip = $noembargo;
              if (is_array($jsondata[$ip_embargo_key])) {
                foreach ($jsondata[$ip_embargo_key] as $ip_embargo_value) {
                  if (is_string($ip_embargo_value)) {
                    // Global IP embargo values @see \Drupal\format_strawberryfield\EmbargoResolver::OTHER_VALID_GLOBAL_IP_BYPASS_VALUES
                    // CAN not be MIXED with real IPs. IF both present in array, the value will be considered
                    // AN IP and fail bc it is not an IP. In that case this is OK, Since a SINGLE Positive evaluation will suffice
                    // Because of the OR
                    $ip_embargo_bypass = IpUtils::checkIp($current_ip, trim($ip_embargo_value)) || $ip_embargo_bypass;
                    // Here we need to do it differently. We will || all the $ip_embargo
                    // and then check the $noembargo variable outside of this loop
                    $ip_evaluated = TRUE;
                  }
                }
                $noembargo_from_ip = $noembargo_from_ip && $ip_embargo_bypass;
              }
              elseif (is_string($jsondata[$ip_embargo_key])) {
                $ip_embargo_bypass = IpUtils::checkIp($current_ip, trim($jsondata[$ip_embargo_key]));
                $noembargo_from_ip = $noembargo_from_ip && $ip_embargo_bypass;
                $ip_evaluated = TRUE;
              }

              if ($this->embargoConfig->get('global_ip_bypass_enabled')) {
                // If the key is there and the only value is set to TRUE. Replace/Additive/Local does not apply here
                // Variations of TRUE.
                $global_ip_embargo = $jsondata[$ip_embargo_key];
                $global_ip_embargo = ((is_bool($global_ip_embargo) && $global_ip_embargo === TRUE) || in_array($global_ip_embargo, static::OTHER_VALID_GLOBAL_IP_BYPASS_VALUES, TRUE));
                if ($global_ip_embargo) {
                  // Here we have a problem. If this (using a variation of TRUE) was also evaluated as a string on line 211
                  // then it would have failed. So Even if we pass here, $noembargo && $ip_embargo_bypass = FALSE.
                  $ip_embargo_bypass = $this->evaluateGlobalIPembargo($current_ip);
                  // Here we use the original $noembargo since $noembargo_from_ip might be tainted
                  // by having a valid "true" being evaluated as a string before.
                  // Question for Allison. WE still AND against Time based Embargo?
                  $noembargo = $noembargo && $ip_embargo_bypass;
                }
                // Only makes sense to check the modes IF the ADO already had IP data and was evaluated.
                // In other words, if it was evaluated, it means that Global Embargo
                // Also applies even without a specific TRUE variation in the values.
                elseif ($ip_evaluated) {
                  // $ip_evaluated means $noembargo_from_ip is set.
                  // and $ip_embargo_bypass too.
                  $mode = $this->embargoConfig->get('global_ip_bypass_mode');
                  // Replace means global ip bypass wins. So any other evaluation that e.g. would allow
                  // a user to bypass is invalidated, and we need to re-evaluate.
                  if ($mode == "replace") {
                    $ip_embargo_bypass = $this->evaluateGlobalIPembargo($current_ip);
                    // WE still AND against Time based Embargo?
                    // Question for Allison.
                    $noembargo = $noembargo && $ip_embargo_bypass;
                  }
                  if ($mode == "additive") {
                    $ip_embargo_bypass = $this->evaluateGlobalIPembargo($current_ip) || $ip_embargo_bypass;
                    $noembargo = $noembargo && $ip_embargo_bypass;
                  }
                  if ($mode == "local") {
                    $noembargo = $noembargo_from_ip && $noembargo;
                  }
                }
              }
              elseif ($ip_evaluated) {
                // If global embargo is not enabled, we move back $noembargo_from_ip to $noembargo
                $noembargo = $noembargo_from_ip;
              }
            }
          }
        }
        // $ip_embargo is used as backup for no cache.
        // Do we need it to be 1 based on !$ip_embargo_bypass ?
        // $ip_embargo_bypass == NULL means it was not enabled.
        $ip_embargo = $ip_embargo_bypass == NULL ? FALSE : !$ip_embargo_bypass;
        $embargo_info = [
          !$noembargo,
          $date_embargo ? $date : FALSE,
          $ip_embargo,
          $cacheable
        ];
      }
    }
    $this->resolvedEmbargos[$uuid] = $this->resolvedEmbargosNID[$nid] = $embargo_info;
    $cache[$cache_id] = $embargo_info;
    return $embargo_info;
  }


  public function evaluateGlobalIPembargo($current_ip): bool {
    $ip_embargo_bypass = FALSE;
    $global_ips = $this->embargoConfig->get('global_ip_bypass_addresses') ?? [];
    foreach ($global_ips as $ip_embargo_value) {
      if (is_string($ip_embargo_value)) {
        $ip_embargo_bypass = IpUtils::checkIp($current_ip, trim($ip_embargo_value)) || $ip_embargo_bypass;
      }
    }
    return $ip_embargo_bypass;
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
   * @param int $nid
   *
   * @return array
   *    The embargo info in [!$noembargo, $date_embargo ? $date: FALSE , $ip_embargo, $cacheable];
   */
  public function getResolvedEmbargoesByNiD(int $nid): array {
    return $this->resolvedEmbargosNID[$nid] ?? [];
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
      // If not, check if it isn't a timestamp
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

  public function isEmbargoEnabled(): bool {
    return (bool) $this->embargoConfig->get('enabled');
  }

  public function isFileEmbargoEnabled(): bool {
    return (bool) ($this->embargoConfig->get('enabled') && $this->embargoConfig->get('file_embargo_enabled'));
  }

}
