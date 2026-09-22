<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Mailer;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\symfony_mailer\Attribute\MailerInfo;
use Drupal\symfony_mailer\Component\ComponentMailerBase;

/**
 * Builds entitlement messages through Mailer Plus's native pipeline.
 */
#[MailerInfo(
  base_tag: 'commerce_lms_entitlements',
  sub_defs: [
    'invitation' => new TranslatableMarkup('Course invitation'),
    'vip_booking_confirmed' => new TranslatableMarkup('VIP booking confirmation'),
    'vip_booking_changed' => new TranslatableMarkup('VIP booking change'),
    'vip_booking_cancelled' => new TranslatableMarkup('VIP booking cancellation'),
  ],
  required_config: ['email_subject', 'email_body'],
  token_types: ['site'],
  variables: [
    'invitation_url' => new TranslatableMarkup('Secure invitation URL'),
    'session' => new TranslatableMarkup('VIP session name'),
    'start' => new TranslatableMarkup('VIP session start time'),
  ],
)]
final class EntitlementMailer extends ComponentMailerBase implements EntitlementMailerInterface {

  /**
   * {@inheritdoc}
   */
  public function sendInvitation(string $to, string $invitationUrl): bool {
    return $this->newEmail('invitation')
      ->setTo($to)
      ->setVariable('invitation_url', $invitationUrl)
      ->send();
  }

  /**
   * {@inheritdoc}
   */
  public function sendVipBookingNotification(
    string $type,
    string $to,
    string $session,
    string $start,
  ): bool {
    $allowed_types = [
      'vip_booking_confirmed',
      'vip_booking_changed',
      'vip_booking_cancelled',
    ];
    if (!in_array($type, $allowed_types, TRUE)) {
      throw new \InvalidArgumentException('Unknown VIP booking notification type.');
    }

    return $this->newEmail($type)
      ->setTo($to)
      ->setVariable('session', $session)
      ->setVariable('start', $start)
      ->send();
  }

}
