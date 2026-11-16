<?php
namespace Drupal\format_strawberryfield;

use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Access\AccessResult;

class MetadataDisplayAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   *
   * Link the activities to the permissions. checkAccess is called with the
   * $operation as defined in the routing.yml file.
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account)
  {
    if ($account->hasPermission('administer metadatadisplay entity')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return match ($operation) {
      'view' => AccessResult::allowedIfHasPermission($account, 'view metadatadisplay entity'),
      'edit', 'view all revisions', 'view revision', 'revert' => AccessResult::allowedIfHasPermission($account, 'edit metadatadisplay entity'),
      'delete' => AccessResult::allowedIfHasPermission($account, 'delete metadatadisplay entity'),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   *
   * Separate from the checkAccess because the entity does not yet exist, it
   * will be created during the 'add' process.
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'add metadatadisplay entity');
  }

}