<?php

namespace Drupal\anu_lms_custom_page_data\EventSubscriber;

use Drupal\anu_lms\Event\CoursesPageDataGeneratedEvent;
use Drupal\anu_lms\Event\LessonPageDataGeneratedEvent;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Anu LMS event subscriber.
 */
class CustomPageDataSubscriber implements EventSubscriberInterface {

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * Constructs event subscriber.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;
  }

  /**
   * Handles event when courses page data ready to be rendered on the frontend.
   *
   * @param \Drupal\anu_lms\Event\CoursesPageDataGeneratedEvent $event
   *   Response event.
   */
  public function onCoursesDataGenerated(CoursesPageDataGeneratedEvent $event) {
    $data = $event->getPageData();
    $node = $event->getNode();
    $data['additional-example-data'] = 'test';
    $this->messenger->addStatus('Data was generated for "' . $node->label() . '": ' . count($data['courses']) . ' courses');
    $event->setPageData($data);
  }

  /**
   * Handles event when lesson page data ready to be rendered on the frontend.
   *
   * @param \Drupal\anu_lms\Event\LessonPageDataGeneratedEvent $event
   *   Response event.
   */
  public function onLessonDataGenerated(LessonPageDataGeneratedEvent $event) {
    $data = $event->getPageData();
    $node = $event->getNode();
    $data['lesson-example-data'] = 'test';
    $this->messenger->addStatus('Data was generated for lesson "' . $node->label() . '"');
    $event->setPageData($data);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'anu_lms_courses_page_data_generated' => ['onCoursesDataGenerated'],
      'anu_lms_lesson_page_data_generated' => ['onLessonDataGenerated'],
    ];
  }

}
