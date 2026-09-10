<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Controller;

use Drupal\commerce_lms_entitlements\Form\VipBookingForm;
use Drupal\commerce_lms_entitlements\VipBookingManager;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Renders the protected VIP meeting details and learner bookings. */
final class VipSessionController extends ControllerBase {

  public function __construct(private VipBookingManager $bookings, private EntityTypeManagerInterface $entityTypeManager, private DateFormatterInterface $dateFormatter) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('commerce_lms_entitlements.vip_booking_manager'), $container->get('entity_type.manager'), $container->get('date.formatter'));
  }

  public function page(): array {
    $uid = (int) $this->currentUser()->id();
    if (!$this->bookings->hasAccess($uid)) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }
    $rows = [];
    foreach ($this->bookings->bookings($uid) as $month => $booking) {
      $instance = $this->entityTypeManager->getStorage('eventinstance')->load((int) $booking['eventinstance_id']);
      if (!$instance) {
        continue;
      }
      $start = strtotime((string) $instance->get('date')->value);
      $rows[] = [$month, $instance->label(), $start ? $this->dateFormatter->format($start, 'long') : $this->t('Unknown'), ['data' => ['#type' => 'link', '#title' => $this->t('Cancel'), '#url' => Url::fromRoute('commerce_lms_entitlements.vip_booking_cancel', ['month' => $month])]]];
    }
    $meeting_url = $this->bookings->meetingUrl();
    return [
      'meeting' => $meeting_url !== ''
        ? ['#type' => 'link', '#title' => $this->t('Open the protected VIP meeting room'), '#url' => Url::fromUri($meeting_url), '#attributes' => ['rel' => 'nofollow noopener']]
        : ['#markup' => $this->t('The VIP meeting room has not been configured yet.')],
      'bookings' => ['#type' => 'table', '#caption' => $this->t('Your bookings'), '#header' => [$this->t('Month'), $this->t('Session'), $this->t('Starts'), $this->t('Operations')], '#rows' => $rows, '#empty' => $this->t('You have no upcoming VIP session bookings.')],
      'form' => $this->formBuilder()->getForm(VipBookingForm::class),
      '#cache' => ['max-age' => 0],
    ];
  }

}
