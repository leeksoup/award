<?php

namespace Drupal\achievements_learning\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends lesson completion notifications to students and parents.
 */
class LearningNotificationManager {

  /**
   * Constructs the notification manager.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MailManagerInterface $mailManager,
    protected LanguageManagerInterface $languageManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Sends lesson completion emails.
   */
  public function sendLessonCompletionEmails(int $uid, int $lessonId): void {
    $this->logger->info('Lesson completion notification scaffold invoked for uid @uid and lesson @lesson.', ['@uid' => $uid, '@lesson' => $lessonId]);
  }

}
