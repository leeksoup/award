<?php

declare(strict_types=1);

namespace Drupal\lms\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\lms\Entity\AnswerInterface;
use Drupal\lms\Entity\ActivityInterface;
use Drupal\lms\Entity\LessonInterface;
use Drupal\lms\Entity\LessonStatusInterface;
use Drupal\lms\Exception\TrainingException;

/**
 * Token hook implementations for Drupal LMS.
 */
final class LmsTokensHooks {

  use StringTranslationTrait;

  #[Hook('token_info')]
  public function tokenInfo(): array {
    return [
      'types' => [
        'lms_answer' => [
          'name' => $this->t("LMS Answer"),
          'description' => $this->t("Tokens related to LMS Answer entities."),
          'needs-data' => 'lms_answer',
        ],
        'lms_activity' => [
          'name' => $this->t("LMS Activity"),
          'description' => $this->t("Tokens related to LMS Activity entities."),
          'needs-data' => 'lms_activity',
        ],
        'lms_lesson' => [
          'name' => $this->t("LMS Lesson"),
          'description' => $this->t("Tokens related to LMS Lesson entities."),
          'needs-data' => 'lms_lesson',
        ],
        'lms_lesson_status' => [
          'name' => $this->t("LMS Lesson status"),
          'description' => $this->t("Tokens related to LMS Lesson status entities."),
          'needs-data' => 'lms_lesson_status',
        ],
      ],
      'tokens' => [
        'lms_answer' => [
          'label' => [
            'name' => $this->t('Label'),
            'description' => $this->t('The label of the answer.'),
          ],
        ],
        'lms_activity' => [
          'label' => [
            'name' => $this->t('Label'),
            'description' => $this->t('The label of the activity.'),
          ],
        ],
        'lms_lesson' => [
          'label' => [
            'name' => $this->t('Label'),
            'description' => $this->t('The label of the lesson.'),
          ],
        ],
        'lms_lesson_status' => [
          'lesson_number' => [
            'name' => $this->t('Lesson number'),
            'description' => $this->t('The position of the lesson in the course, starting from 1.'),
          ],
          'activity_number' => [
            'name' => $this->t('Activity number'),
            'description' => $this->t('The position of the current activity in the lesson, starting from 1.'),
          ],
        ],
      ],
    ];
  }

  /**
   * Implements hook_tokens().
   */
  #[Hook('tokens')]
  public function tokens(string $type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata): array {
    $replacements = [];
    if (
      $type === 'lms_answer' &&
      \array_key_exists('lms_answer', $data) &&
      $data['lms_answer'] instanceof AnswerInterface
    ) {
      foreach ($tokens as $name => $original) {
        if ($name === 'label') {
          $replacements[$original] = $data['lms_answer']->label();
          $bubbleable_metadata
            ->addCacheableDependency($data['lms_answer']->getOwner())
            ->addCacheableDependency($data['lms_answer']->getActivity());
        }
      }
    }
    elseif (
      $type === 'lms_activity' &&
      \array_key_exists('lms_activity', $data) &&
      $data['lms_activity'] instanceof ActivityInterface
    ) {
      foreach ($tokens as $name => $original) {
        if ($name === 'label') {
          $replacements[$original] = $data['lms_activity']->label();
        }
      }
    }
    elseif (
      $type === 'lms_lesson' &&
      \array_key_exists('lms_lesson', $data) &&
      $data['lms_lesson'] instanceof LessonInterface
    ) {
      foreach ($tokens as $name => $original) {
        if ($name === 'label') {
          $replacements[$original] = $data['lms_lesson']->label();
        }
      }
    }
    elseif (
      $type === 'lms_lesson_status' &&
      \array_key_exists('lms_lesson_status', $data) &&
      $data['lms_lesson_status'] instanceof LessonStatusInterface
    ) {
      $lesson_status = $data['lms_lesson_status'];
      foreach ($tokens as $name => $original) {
        if ($name === 'lesson_number') {
          try {
            $replacements[$original] = $lesson_status->getCurrentLessonDelta() + 1;
            $bubbleable_metadata->addCacheableDependency($lesson_status->getCourseStatus()->getCourse());
          }
          catch (TrainingException $e) {
            // The lesson was removed from the course.
          }
        }
        elseif ($name === 'activity_number') {
          $activity_delta = $lesson_status->getCurrentActivityDelta();
          if ($activity_delta !== NULL) {
            $replacements[$original] = $activity_delta + 1;
          }
        }
      }
    }
    return $replacements;
  }

}
