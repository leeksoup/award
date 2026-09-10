<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Form;

use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\commerce_lms_entitlements\PlanChangeManager;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Confirms a purchaser-owned base/VIP PayPal plan revision. */
final class ChangeTierForm extends ConfirmFormBase {

  private array $entitlement = [];
  protected EntitlementManager $entitlements;
  protected PlanChangeManager $changes;

  public function __construct(EntitlementManager $entitlements, PlanChangeManager $changes) {
    $this->entitlements = $entitlements;
    $this->changes = $changes;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('commerce_lms_entitlements.manager'), $container->get('commerce_lms_entitlements.plan_change_manager'));
  }

  public function getFormId(): string { return 'commerce_lms_entitlements_change_tier'; }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $eid = NULL): array {
    $this->entitlement = $eid ? ($this->entitlements->load($eid) ?? []) : [];
    if (!$this->entitlement || (int) $this->entitlement['purchaser_uid'] !== (int) $this->currentUser()->id()) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }
    return parent::buildForm($form, $form_state);
  }

  public function getQuestion(): string {
    return (string) (!empty($this->entitlement['vip_active']) ? $this->t('Remove VIP at your next billing date?') : $this->t('Add VIP at your next billing date?'));
  }

  public function getDescription(): string {
    return (string) $this->t('PayPal will ask you to approve the revised recurring amount. Your current access remains unchanged until the next successful billing cycle.');
  }

  public function getCancelUrl(): Url { return Url::fromRoute('commerce_lms_entitlements.my_subscriptions'); }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $approval = $this->changes->request((int) $this->entitlement['eid'], (int) $this->currentUser()->id());
      $form_state->setRedirectUrl(Url::fromUri($approval));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($e->getMessage());
      $form_state->setRedirect('commerce_lms_entitlements.my_subscriptions');
    }
  }

}
