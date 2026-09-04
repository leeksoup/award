<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Form;

use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\commerce_lms_entitlements\PayPalSubscriptionOperations;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Cancels renewal or carries out the time-limited guarantee workflow. */
final class CancelEntitlementForm extends ConfirmFormBase {
  private ?array $entitlement = NULL;

  public function __construct(private EntitlementManager $manager, private PayPalSubscriptionOperations $paypal, private EntityTypeManagerInterface $entityTypeManager) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('commerce_lms_entitlements.manager'), $container->get('commerce_lms_entitlements.paypal_operations'), $container->get('entity_type.manager'));
  }

  public function getFormId(): string { return 'commerce_lms_entitlements_cancel'; }
  public function getQuestion(): string { return $this->t('Cancel this course subscription?'); }
  public function getCancelUrl(): Url { return Url::fromRoute('commerce_lms_entitlements.my_subscriptions'); }

  public function buildForm(array $form, FormStateInterface $form_state, $eid = NULL): array {
    $this->entitlement = $this->manager->load((int) $eid);
    if (!$this->entitlement || (int) $this->entitlement['purchaser_uid'] !== (int) $this->currentUser()->id()) {
      throw new AccessDeniedHttpException();
    }
    if (!in_array($this->entitlement['purchase_type'], ['recurring', 'lifetime'], TRUE)) {
      throw new AccessDeniedHttpException();
    }
    $form['eid'] = ['#type' => 'value', '#value' => (int) $eid];
    if ($this->manager->isGuaranteeEligible($this->entitlement)) {
      $form['guarantee'] = ['#type' => 'checkbox', '#title' => $this->t('Use the 40-day guarantee and request an immediate refund'), '#description' => $this->t('Access is removed immediately. Failed refunds remain visible to staff for recovery.')];
    }
    elseif ($this->entitlement['purchase_type'] === 'lifetime') {
      throw new AccessDeniedHttpException();
    }
    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $entitlement = $this->manager->load((int) $form_state->getValue('eid'));
    if (!$entitlement || (int) $entitlement['purchaser_uid'] !== (int) $this->currentUser()->id()) { throw new AccessDeniedHttpException(); }
    $gateway = $this->gateway($entitlement);
    try {
      $guarantee = (bool) $form_state->getValue('guarantee') && $this->manager->isGuaranteeEligible($entitlement);
      if ($entitlement['purchase_type'] === 'recurring') {
        if (empty($entitlement['paypal_subscription_id'])) {
          throw new \RuntimeException('The PayPal subscription is not yet available for cancellation.');
        }
        $remote = $this->paypal->cancel($gateway, (string) $entitlement['paypal_subscription_id']);
        $this->manager->applyRemoteSubscription($entitlement, $remote);
      }
      if ($guarantee) {
        $this->manager->update((int) $entitlement['eid'], ['guarantee_requested' => \Drupal::time()->getRequestTime()]);
        $this->manager->revokeImmediately($this->manager->load((int) $entitlement['eid']));
        try {
          $refund_id = $this->paypal->refundCapture($gateway, (string) $entitlement['initial_capture_id']);
          $this->manager->update((int) $entitlement['eid'], ['status' => 'guarantee_refunded', 'refund_id' => $refund_id, 'access_through' => NULL]);
        }
        catch (\Throwable $e) {
          $this->manager->update((int) $entitlement['eid'], ['status' => 'guarantee_refund_pending', 'access_through' => NULL]);
          $this->getLogger('commerce_lms_entitlements')->error('Guarantee refund for entitlement @eid requires recovery: @message', ['@eid' => $entitlement['eid'], '@message' => $e->getMessage()]);
        }
      }
      $this->messenger()->addStatus($this->t('Your cancellation request has been sent to PayPal.'));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('PayPal could not process the cancellation. Please contact support.'));
      $this->getLogger('commerce_lms_entitlements')->error('Cancellation for entitlement @eid failed: @message', ['@eid' => $entitlement['eid'], '@message' => $e->getMessage()]);
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  private function gateway(array $entitlement): object {
    $order = $this->entityTypeManager->getStorage('commerce_order')->load($entitlement['order_id']);
    $gateway_id = $order?->get('payment_gateway')->target_id;
    $gateway = $gateway_id ? $this->entityTypeManager->getStorage('commerce_payment_gateway')->load($gateway_id) : NULL;
    if (!$gateway) { throw new \RuntimeException('The order payment gateway no longer exists.'); }
    return $gateway;
  }
}
