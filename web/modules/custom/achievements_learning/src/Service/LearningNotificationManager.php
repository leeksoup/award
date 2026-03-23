<?php

namespace Drupal\achievements_learning\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\node\NodeInterface;
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
    $lesson = $this->entityTypeManager->getStorage('node')->load($lessonId);
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$lesson instanceof NodeInterface || !$account) {
      return;
    }

    if (!$lesson->hasField('field_completion_email_enabled') || !(bool) $lesson->get('field_completion_email_enabled')->value) {
      return;
    }

    $student_subject = $this->getTextFieldValue($lesson, 'field_completion_email_subject');
    $student_body = $this->getTextFieldValue($lesson, 'field_completion_email_body');
    $parent_subject = $this->getTextFieldValue($lesson, 'field_parent_completion_email_subject');
    $parent_body = $this->getTextFieldValue($lesson, 'field_parent_completion_email_body');

    $course_title = '';
    if (\Drupal::hasService('anu_lms.lesson')) {
      $course = \Drupal::service('anu_lms.lesson')->getLessonCourse($lessonId);
      if ($course) {
        $course_title = $course->label();
      }
    }

    $replacements = [
      '@student_name' => $account->getDisplayName(),
      '@lesson_title' => $lesson->label(),
      '@course_title' => $course_title,
    ];

    if ($student_subject && $student_body && $account->getEmail()) {
      $this->sendMail($account->getEmail(), strtr($student_subject, $replacements), strtr($student_body, $replacements));
    }

    $parent_email_field = $this->configFactory->get('achievements_learning.settings')->get('parent_email_field') ?: 'field_parent_email';
    $parent_email = $account->hasField($parent_email_field) ? (string) $account->get($parent_email_field)->value : '';
    if ($parent_email && $parent_subject && $parent_body) {
      $this->sendMail($parent_email, strtr($parent_subject, $replacements), strtr($parent_body, $replacements));
    }
  }

  /**
   * Sends a single mail message.
   */
  protected function sendMail(string $to, string $subject, string $body): void {
    try {
      $this->mailManager->mail(
        'achievements_learning',
        'lesson_completion',
        $to,
        $this->languageManager->getDefaultLanguage()->getId(),
        [
          'subject' => $subject,
          'body' => $body,
        ]
      );
    }
    catch (\Throwable $exception) {
      $this->logger->error('Failed to send achievements_learning lesson email to @to: @message', [
        '@to' => $to,
        '@message' => $exception->getMessage(),
      ]);
    }
  }

  /**
   * Returns the string value from a text or string field when present.
   */
  protected function getTextFieldValue(NodeInterface $lesson, string $fieldName): string {
    if (!$lesson->hasField($fieldName) || $lesson->get($fieldName)->isEmpty()) {
      return '';
    }

    $item = $lesson->get($fieldName)->first();
    return (string) ($item->value ?? '');
  }

}
