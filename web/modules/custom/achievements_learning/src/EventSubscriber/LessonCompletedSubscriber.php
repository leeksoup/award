<?php

namespace Drupal\achievements_learning\EventSubscriber;

use Drupal\achievements_learning\Service\LearningAchievementManager;
use Drupal\achievements_learning\Service\LearningNotificationManager;
use Drupal\anu_lms\Event\LessonCompletedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to lesson completion events from Anu LMS.
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
  public function onLessonCompleted(LessonCompletedEvent $event): void {
    $uid = (int) $event->getAccount()->id();
    $lesson_id = $event->getLessonId();

    if ($uid <= 0) {
      $this->logger->warning('Lesson completion event received without a valid user for lesson @lesson.', ['@lesson' => $lesson_id]);
      return;
    }

    $this->achievementManager->recordLessonCompleted($uid, $lesson_id);
    $this->notificationManager->sendLessonCompletionEmails($uid, $lesson_id);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      LessonCompletedEvent::EVENT_NAME => 'onLessonCompleted',
    ];
  }

}
