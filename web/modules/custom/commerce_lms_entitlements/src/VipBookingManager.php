<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\recurring_events_registration\RegistrationCreationService;

/** Enforces VIP eligibility and one Recurring Events booking per month. */
final class VipBookingManager {

  public function __construct(private Connection $database, private EntityTypeManagerInterface $entityTypeManager, private ConfigFactoryInterface $configFactory, private TimeInterface $time, private LockBackendInterface $lock, private RegistrationCreationService $registration, private MailManagerInterface $mail, private LanguageManagerInterface $languageManager) {}

  public function hasAccess(int $uid): bool {
    return $uid > 0 && (bool) $this->database->select('commerce_lms_entitlement', 'e')
      ->condition('learner_uid', $uid)
      ->condition('status', 'active')
      ->condition('vip_active', 1)
      ->countQuery()->execute()->fetchField();
  }

  /** Returns selectable future instances, with contrib capacity respected. */
  public function availableInstances(int $uid): array {
    $this->assertAccess($uid);
    $ids = $this->configuredSeriesIds();
    if (!$ids) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('eventinstance');
    $query = $storage->getQuery()->accessCheck(TRUE)->condition('eventseries_id', $ids, 'IN')->sort('date.value');
    $instances = $storage->loadMultiple($query->execute());
    $available = [];
    foreach ($instances as $instance) {
      $start = $this->startTimestamp($instance);
      if (!$start || !$this->beforeCutoff($start)) {
        continue;
      }
      $this->registration->setEventInstance($instance);
      $capacity = (int) $this->registration->retrieveAvailability();
      $month = $this->monthKey($start);
      $existing = $this->booking($uid, $month);
      if ($capacity === 0 && (!$existing || (int) $existing['eventinstance_id'] !== (int) $instance->id())) {
        continue;
      }
      $available[(int) $instance->id()] = [
        'entity' => $instance,
        'start' => $start,
        'month' => $month,
        'capacity' => $capacity,
        'selected' => $existing && (int) $existing['eventinstance_id'] === (int) $instance->id(),
      ];
    }
    return $available;
  }

  /** Creates or atomically switches the learner's booking for that month. */
  public function book(int $uid, int $instance_id): void {
    $this->assertAccess($uid);
    $instance = $this->loadEligibleInstance($instance_id);
    $start = $this->startTimestamp($instance);
    if (!$start || !$this->beforeCutoff($start)) {
      throw new \DomainException('This session is past its booking-change cutoff.');
    }
    $month = $this->monthKey($start);
    $lock_name = 'commerce_lms_vip_booking:' . $uid . ':' . $month;
    if (!$this->lock->acquire($lock_name, 15.0)) {
      throw new \RuntimeException('Your booking is already being changed. Please try again.');
    }
    $capacity_lock = 'commerce_lms_vip_capacity:' . $instance_id;
    if (!$this->lock->acquire($capacity_lock, 15.0)) {
      $this->lock->release($lock_name);
      throw new \RuntimeException('That session is being booked by another learner. Please try again.');
    }
    try {
      $existing = $this->booking($uid, $month);
      if ($existing && (int) $existing['eventinstance_id'] === $instance_id) {
        return;
      }
      $this->registration->setEventInstance($instance);
      if ($this->registration->hasWaitlist()) {
        throw new \DomainException('VIP event series must have their waitlist disabled.');
      }
      if ((int) $this->registration->retrieveAvailability() === 0) {
        throw new \DomainException('That session is full. Choose another session.');
      }
      $account = $this->entityTypeManager->getStorage('user')->load($uid);
      if (!$account) {
        throw new \RuntimeException('The learner account no longer exists.');
      }
      $series = $instance->getEventSeries();
      if (!$series) {
        throw new \RuntimeException('The VIP event instance has no parent series.');
      }
      $transaction = $this->database->startTransaction();
      try {
        $registrant = $this->entityTypeManager->getStorage('registrant')->create([
          'type' => $series->bundle(),
          'user_id' => $uid,
          'email' => $account->getEmail(),
          'waitlist' => 0,
        ]);
        $registrant->setEventInstance($instance);
        $registrant->save();
        if ($existing) {
          $old = $this->entityTypeManager->getStorage('registrant')->load((int) $existing['registrant_id']);
          $old?->delete();
          $this->database->update('commerce_lms_vip_booking')->fields([
            'eventinstance_id' => $instance_id,
            'registrant_id' => (int) $registrant->id(),
            'changed' => $this->time->getRequestTime(),
          ])->condition('id', $existing['id'])->execute();
          $mail_key = 'vip_booking_changed';
        }
        else {
          $now = $this->time->getRequestTime();
          $this->database->insert('commerce_lms_vip_booking')->fields([
            'uid' => $uid,
            'month_key' => $month,
            'eventinstance_id' => $instance_id,
            'registrant_id' => (int) $registrant->id(),
            'created' => $now,
            'changed' => $now,
          ])->execute();
          $mail_key = 'vip_booking_confirmed';
        }
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
      unset($transaction);
      $this->notify($mail_key, $account->getEmail(), $instance, $start);
    }
    finally {
      $this->lock->release($capacity_lock);
      $this->lock->release($lock_name);
    }
  }

  /** Cancels one owned monthly booking before the cutoff. */
  public function cancel(int $uid, string $month): void {
    $this->assertAccess($uid);
    $lock_name = 'commerce_lms_vip_booking:' . $uid . ':' . $month;
    if (!$this->lock->acquire($lock_name, 15.0)) {
      throw new \RuntimeException('Your booking is already being changed. Please try again.');
    }
    try {
      $booking = $this->booking($uid, $month);
      if (!$booking) {
        throw new \DomainException('That booking no longer exists.');
      }
      $instance = $this->loadEligibleInstance((int) $booking['eventinstance_id']);
      $start = $this->startTimestamp($instance);
      if (!$start || !$this->beforeCutoff($start)) {
        throw new \DomainException('This session is past its booking-change cutoff.');
      }
      $account = $this->entityTypeManager->getStorage('user')->load($uid);
      $registrant = $this->entityTypeManager->getStorage('registrant')->load((int) $booking['registrant_id']);
      $registrant?->delete();
      $this->database->delete('commerce_lms_vip_booking')->condition('id', $booking['id'])->execute();
      if ($account) {
        $this->notify('vip_booking_cancelled', $account->getEmail(), $instance, $start);
      }
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  public function bookings(int $uid): array {
    return $this->database->select('commerce_lms_vip_booking', 'b')->fields('b')->condition('uid', $uid)->orderBy('month_key')->execute()->fetchAllAssoc('month_key', \PDO::FETCH_ASSOC);
  }

  public function meetingUrl(): string {
    return $this->hasAccess((int) \Drupal::currentUser()->id()) ? (string) $this->configFactory->get('commerce_lms_entitlements.vip')->get('meeting_url') : '';
  }

  private function booking(int $uid, string $month): ?array {
    $row = $this->database->select('commerce_lms_vip_booking', 'b')->fields('b')->condition('uid', $uid)->condition('month_key', $month)->execute()->fetchAssoc();
    return $row ?: NULL;
  }

  private function assertAccess(int $uid): void {
    if (!$this->hasAccess($uid)) {
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('An active VIP learner entitlement is required.');
    }
  }

  private function configuredSeriesIds(): array {
    return array_map('intval', $this->configFactory->get('commerce_lms_entitlements.vip')->get('event_series_ids') ?: []);
  }

  private function loadEligibleInstance(int $id): object {
    $instance = $this->entityTypeManager->getStorage('eventinstance')->load($id);
    if (!$instance || !in_array((int) $instance->get('eventseries_id')->target_id, $this->configuredSeriesIds(), TRUE)) {
      throw new \DomainException('That event is not an eligible VIP session.');
    }
    return $instance;
  }

  private function startTimestamp(object $instance): ?int {
    $value = (string) ($instance->get('date')->value ?? '');
    if ($value === '') {
      return NULL;
    }
    return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
  }

  private function monthKey(int $timestamp): string {
    $timezone = (string) ($this->configFactory->get('system.date')->get('timezone.default') ?: 'UTC');
    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone($timezone))->format('Y-m');
  }

  private function beforeCutoff(int $start): bool {
    $hours = (int) ($this->configFactory->get('commerce_lms_entitlements.vip')->get('change_cutoff_hours') ?? 24);
    return $this->time->getRequestTime() < $start - ($hours * 3600);
  }

  private function notify(string $key, string $email, object $instance, int $start): void {
    if ($email === '') {
      return;
    }
    $this->mail->mail('commerce_lms_entitlements', $key, $email, $this->languageManager->getDefaultLanguage()->getId(), [
      'session' => $instance->label(),
      'start' => date(DATE_RFC2822, $start),
      // Bypass Mailer Override's global legacy converter and retain Drupal
      // core's From, Sender, and Return-Path headers.
      '__email' => TRUE,
    ]);
  }

}
