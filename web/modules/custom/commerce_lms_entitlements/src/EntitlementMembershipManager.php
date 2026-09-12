<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;

/**
 * Manages entitlement-backed Group membership ownership.
 */
final class EntitlementMembershipManager {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Grants targets without discarding an existing ownership decision.
   *
   * @param array $entitlement
   *   The entitlement database row.
   * @param \Drupal\user\UserInterface $account
   *   The learner account.
   * @param array $targets
   *   Course/Class target maps. Only class_id is used here.
   * @param string $benefit
   *   The benefit that supports the membership, such as base or vip.
   */
  public function grantTargets(
    array $entitlement,
    UserInterface $account,
    array $targets,
    string $benefit,
  ): void {
    foreach ($targets as $target) {
      $transaction = $this->database->startTransaction();

      try {
        $class_id = (int) $target['class_id'];
        $class = $this->entityTypeManager->getStorage('group')->load($class_id);
        if (!$class instanceof GroupInterface) {
          throw new \RuntimeException(sprintf('Missing LMS Class %d.', $class_id));
        }

        $ledger = $this->database
          ->select('commerce_lms_entitlement_membership', 'm')
          ->fields('m')
          ->condition('eid', $entitlement['eid'])
          ->condition('class_id', $class_id)
          ->condition('benefit', $benefit)
          ->forUpdate()
          ->execute()
          ->fetchAssoc();

        $membership_created = FALSE;
        if (!$class->getMember($account)) {
          $class->addMember($account);
          $membership_created = TRUE;
        }

        if ($ledger !== FALSE) {
          $fields = [
            'uid' => $account->id(),
            'active' => 1,
          ];
          // If this grant had to recreate the Group membership, it now owns
          // that membership. Otherwise retain the previous ownership value.
          if ($membership_created) {
            $fields['membership_created'] = 1;
          }
          $this->database
            ->update('commerce_lms_entitlement_membership')
            ->fields($fields)
            ->condition('id', $ledger['id'])
            ->execute();
        }
        else {
          $this->database
            ->insert('commerce_lms_entitlement_membership')
            ->fields([
              'eid' => $entitlement['eid'],
              'class_id' => $class_id,
              'uid' => $account->id(),
              'membership_created' => $membership_created ? 1 : 0,
              'benefit' => $benefit,
              'active' => 1,
              'created' => $this->time->getRequestTime(),
            ])
            ->execute();
        }
      }
      catch (\Throwable $exception) {
        $transaction->rollBack();
        throw $exception;
      }

      unset($transaction);
    }
  }

  /**
   * Revokes one entitlement's support without deleting manual membership.
   *
   * A ledger row is changed and its Group membership is removed in the same
   * transaction. If Group removal fails, the active ledger state is restored
   * by rollback so a later reconciliation can retry it.
   */
  public function revokeBenefit(array $entitlement, ?string $benefit = NULL): void {
    $query = $this->database
      ->select('commerce_lms_entitlement_membership', 'm')
      ->fields('m', ['id'])
      ->condition('eid', $entitlement['eid'])
      ->condition('active', 1);
    if ($benefit !== NULL) {
      $query->condition('benefit', $benefit);
    }

    foreach ($query->execute()->fetchCol() as $membership_id) {
      $transaction = $this->database->startTransaction();

      try {
        $membership = $this->database
          ->select('commerce_lms_entitlement_membership', 'm')
          ->fields('m')
          ->condition('id', $membership_id)
          ->condition('active', 1)
          ->forUpdate()
          ->execute()
          ->fetchObject();
        if ($membership !== FALSE) {
          $this->database
            ->update('commerce_lms_entitlement_membership')
            ->fields(['active' => 0])
            ->condition('id', $membership->id)
            ->execute();

          $other_active = (bool) $this->database
            ->select('commerce_lms_entitlement_membership', 'm')
            ->condition('class_id', $membership->class_id)
            ->condition('uid', $membership->uid)
            ->condition('active', 1)
            ->countQuery()
            ->execute()
            ->fetchField();
          $module_created = (bool) $this->database
            ->select('commerce_lms_entitlement_membership', 'm')
            ->condition('class_id', $membership->class_id)
            ->condition('uid', $membership->uid)
            ->condition('membership_created', 1)
            ->countQuery()
            ->execute()
            ->fetchField();

          if ($module_created && !$other_active) {
            $class = $this->entityTypeManager
              ->getStorage('group')
              ->load($membership->class_id);
            $account = $this->entityTypeManager
              ->getStorage('user')
              ->load($membership->uid);
            if (
              $class instanceof GroupInterface
              && $account instanceof UserInterface
              && $class->getMember($account)
            ) {
              $class->removeMember($account);
            }
          }
        }
      }
      catch (\Throwable $exception) {
        $transaction->rollBack();
        throw $exception;
      }

      unset($transaction);
    }
  }

}
