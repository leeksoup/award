<?php

declare(strict_types=1);

$ids = range(1,14);
$apply = in_array('--apply', $_SERVER['argv'], TRUE);
$database = \Drupal::database();
$order_storage = \Drupal::entityTypeManager()->getStorage('commerce_order');
$manager = \Drupal::service('commerce_lms_entitlements.manager');
$entitlements = [];

foreach ($ids as $id) {
  $entitlement = $database->select('commerce_lms_entitlement', 'e')
    ->fields('e')
    ->condition('eid', $id)
    ->execute()
    ->fetchAssoc();
  if (!$entitlement) {
    printf("Entitlement %d: already absent.\n", $id);
    continue;
  }
  if ($order_storage->load((int) $entitlement['order_id'])) {
    throw new RuntimeException(sprintf(
      'Refusing to purge entitlement %d: order %d still exists.',
      $id,
      $entitlement['order_id'],
    ));
  }
  $membership_count = $database->select('commerce_lms_entitlement_membership', 'm')
    ->condition('eid', $id)
    ->countQuery()
    ->execute()
    ->fetchField();
  printf(
    "Entitlement %d: order=%d MISSING, status=%s, subscription=%s, membership rows=%d\n",
    $id,
    $entitlement['order_id'],
    $entitlement['status'],
    $entitlement['paypal_subscription_id'] ?: '(none)',
    $membership_count,
  );
  $entitlements[$id] = $entitlement;
}

if (!$apply) {
  print "Dry run only. Re-run with -- --apply after cancelling the listed subscriptions in PayPal Sandbox.\n";
  return;
}

$transaction = $database->startTransaction();
try {
  // Make all selected rows non-active before revocation so multiple selected
  // entitlements for one learner do not incorrectly preserve VIP state.
  if ($entitlements) {
    $database->update('commerce_lms_entitlement')
      ->fields(['status' => 'purging', 'changed' => \Drupal::time()->getRequestTime()])
      ->condition('eid', array_keys($entitlements), 'IN')
      ->execute();
  }

  // Revoke every selected entitlement before deleting any ownership-ledger
  // rows. This preserves the information needed for shared memberships.
  foreach (array_keys($entitlements) as $id) {
    $manager->revokeImmediately($manager->load($id));
  }

  foreach ($entitlements as $id => $entitlement) {
    if ($database->schema()->tableExists('commerce_lms_plan_change')) {
      $database->delete('commerce_lms_plan_change')
        ->condition('eid', $id)
        ->execute();
    }
    $database->delete('commerce_lms_entitlement_membership')
      ->condition('eid', $id)
      ->execute();
    $database->delete('commerce_lms_entitlement')
      ->condition('eid', $id)
      ->execute();

    $invitation_id = (string) ($entitlement['invitation_id'] ?? '');
    if ($invitation_id !== '') {
      $still_used = $database->select('commerce_lms_entitlement', 'e')
        ->condition('invitation_id', $invitation_id)
        ->countQuery()
        ->execute()
        ->fetchField();
      if (!$still_used) {
        $database->delete('commerce_lms_entitlement_invitation')
          ->condition('id', $invitation_id)
          ->execute();
      }
    }
    printf("Purged entitlement %d.\n", $id);
  }
}
catch (Throwable $exception) {
  $transaction->rollBack();
  throw $exception;
}

print "Stored webhook events were retained as audit records.\n";
