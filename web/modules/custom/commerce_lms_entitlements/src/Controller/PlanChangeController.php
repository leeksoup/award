<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Controller;

use Drupal\commerce_lms_entitlements\PlanChangeManager;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/** Handles PayPal approval and cancellation returns for tier revisions. */
final class PlanChangeController extends ControllerBase {

  public function __construct(private PlanChangeManager $changes) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('commerce_lms_entitlements.plan_change_manager'));
  }

  public function complete(string $token): RedirectResponse {
    try {
      $this->changes->complete($token, (int) $this->currentUser()->id(), FALSE);
      $this->messenger()->addStatus($this->t('PayPal approved the tier change. Access will change after the next successful renewal.'));
    }
    catch (\Throwable $e) {
      $this->messenger()->addWarning($e->getMessage());
    }
    return $this->redirect('commerce_lms_entitlements.my_subscriptions');
  }

  public function cancel(string $token): RedirectResponse {
    try {
      $this->changes->complete($token, (int) $this->currentUser()->id(), TRUE);
      $this->messenger()->addWarning($this->t('The tier change was not approved. Your existing subscription continues unchanged.'));
    }
    catch (\Throwable $e) {
      $this->messenger()->addWarning($e->getMessage());
    }
    return $this->redirect('commerce_lms_entitlements.my_subscriptions');
  }

}
