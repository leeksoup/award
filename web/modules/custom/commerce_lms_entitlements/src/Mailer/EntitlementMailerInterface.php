<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Mailer;

use Drupal\symfony_mailer\Component\ComponentMailerInterface;

/**
 * Sends transactional Commerce LMS entitlement messages.
 */
interface EntitlementMailerInterface extends ComponentMailerInterface {

  /**
   * Sends a one-time course invitation.
   *
   * @param string $to
   *   Recipient email address.
   * @param string $invitationUrl
   *   Absolute URL containing the one-time invitation token.
   *
   * @return bool
   *   Whether the configured transport accepted the message.
   */
  public function sendInvitation(string $to, string $invitationUrl): bool;

  /**
   * Sends a VIP booking lifecycle notification.
   *
   * @param string $type
   *   One of the supported VIP booking message sub-types.
   * @param string $to
   *   Recipient email address.
   * @param string $session
   *   Human-readable session label.
   * @param string $start
   *   Human-readable session start time.
   *
   * @return bool
   *   Whether the configured transport accepted the message.
   */
  public function sendVipBookingNotification(
    string $type,
    string $to,
    string $session,
    string $start,
  ): bool;

}
