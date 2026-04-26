<?php

namespace Drupal\achievements_learning\EventSubscriber;

use Drupal\achievements_learning\Service\LearningAchievementManager;
use Drupal\achievements_learning\Service\LearningNotificationManager;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to lesson completion events.
 *
 * Supports both Anu LMS and Drupal LMS style completion events. The event
 * payload is inspected dynamically so this subscriber does not hard-depend on
 * either module's event class.
 */
class LessonCompletedSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    protected LearningAchievementManager $achievementManager,
    protected LearningNotificationManager $notificationManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Reacts to lesson completion.
   */
  public function onLessonCompleted(Event $event): void {
    [$uid, $lesson_id] = $this->extractCompletionContext($event);

    if ($uid <= 0 || $lesson_id <= 0) {
      $this->logger->warning('Lesson completion event could not be parsed for achievements_learning.');
      return;
    }

    $this->achievementManager->recordLessonCompleted($uid, $lesson_id);
    $this->notificationManager->sendLessonCompletionEmails($uid, $lesson_id);
  }

  /**
   * Extracts a user and lesson ID from supported completion event payloads.
   *
   * @return int[]
   *   [uid, lesson_id].
   */
  protected function extractCompletionContext(Event $event): array {
    $uid = 0;
    $lesson_id = 0;

    // Anu LMS pattern.
    if (method_exists($event, 'getAccount')) {
      $account = $event->getAccount();
      if ($account && method_exists($account, 'id')) {
        $uid = (int) $account->id();
      }
    }
    if (method_exists($event, 'getLessonId')) {
      $lesson_id = (int) $event->getLessonId();
    }

    // Drupal LMS common event payload patterns.
    if ($uid <= 0 && method_exists($event, 'getUser')) {
      $user = $event->getUser();
      if ($user && method_exists($user, 'id')) {
        $uid = (int) $user->id();
      }
    }

    if ($lesson_id <= 0 && method_exists($event, 'getLesson')) {
      $lesson = $event->getLesson();
      if ($lesson && method_exists($lesson, 'id')) {
        $lesson_id = (int) $lesson->id();
      }
      elseif (is_numeric($lesson)) {
        $lesson_id = (int) $lesson;
      }
    }

    if ($lesson_id <= 0 && method_exists($event, 'getActivity')) {
      $activity = $event->getActivity();
      if ($activity && method_exists($activity, 'id')) {
        $lesson_id = (int) $activity->id();
      }
    }

    return [$uid, $lesson_id];
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Legacy Anu LMS.
      'anu_lms.lesson_completed' => 'onLessonCompleted',
      // Drupal LMS event name.
      'lms.lesson.completed' => 'onLessonCompleted',
    ];
  }

}
