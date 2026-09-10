<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Form;

use Drupal\commerce_lms_entitlements\VipBookingManager;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Confirms cancellation of one learner-owned monthly VIP booking. */
final class VipBookingCancelForm extends ConfirmFormBase {

  private string $month = '';
  protected VipBookingManager $bookings;

  public function __construct(VipBookingManager $bookings) {
    $this->bookings = $bookings;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('commerce_lms_entitlements.vip_booking_manager'));
  }

  public function getFormId(): string { return 'commerce_lms_entitlements_vip_booking_cancel'; }
  public function getQuestion(): string { return (string) $this->t('Cancel your @month VIP session?', ['@month' => $this->month]); }
  public function getCancelUrl(): Url { return Url::fromRoute('commerce_lms_entitlements.vip_sessions'); }

  public function buildForm(array $form, FormStateInterface $form_state, ?string $month = NULL): array {
    $this->month = (string) $month;
    if (!preg_match('/^\d{4}-\d{2}$/', $this->month)) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }
    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->bookings->cancel((int) $this->currentUser()->id(), $this->month);
      $this->messenger()->addStatus($this->t('Your VIP session booking has been cancelled.'));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($e->getMessage());
    }
    $form_state->setRedirect('commerce_lms_entitlements.vip_sessions');
  }

}
