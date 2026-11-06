<?php

declare(strict_types=1);

namespace Drupal\lms_hooks_test\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Entity\LessonStatusInterface;
use Drupal\lms\Exception\TrainingException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Contains LMS hooks for testing purposes.
 */
final class LmsTestHooks {

  /**
   * Constructs a new LMS test hooks instance.
   */
  public function __construct(
    // Use state to trigger hooks.
    private readonly AccountInterface $currentUser,
    #[Autowire(service: 'keyvalue.database')]
    private readonly KeyValueFactoryInterface $keyValueFactory,
  ) {}

  #[Hook('lms_initialize_lesson')]
  public function lmsInitializeLesson(LessonStatusInterface $lesson_status): void {
    $key_value = $this->keyValueFactory->get('lms_test');
    if ($key_value->get('lms_initialize_lesson', FALSE) !== TRUE) {
      return;
    }
    $key_value->delete('lms_initialize_lesson');

    if (!$lesson_status->isNew()) {
      throw new TrainingException(NULL, 13, NULL, t('Lesson status should always be new when invoking this hook.'));
    }

    // Test preventing lesson start with redirection.
    throw new TrainingException(NULL, 13, NULL, t('No lesson access, you have been redirected'), Url::fromRoute('entity.user.canonical', ['user' => $this->currentUser->id()]));
  }

  /**
   * Returns a course link build or NULL depending on key-value state.
   */
  #[Hook('lms_course_link', order: Order::First)]
  public function lmsCourseLinkFirst(Course $course, AccountInterface $current_user, CacheableMetadata $cacheability): ?array {
    $key_value = $this->keyValueFactory->get('lms_test');
    if ($key_value->get('lms_course_link', FALSE) !== TRUE) {
      return NULL;
    }
    $cacheability->addCacheTags(['lms_test:course_link']);
    return ['#markup' => 'Test course link'];
  }

  /**
   * Records its execution to allow testing implementation skipping.
   *
   * When an implementation executed earlier returns a non-empty build,
   * this one should not be invoked at all.
   *
   * The ?array return type must be kept (instead of a standalone null type)
   * for PHP 8.1 compatibility, even though this implementation never
   * actually returns an array.
   *
   * @todo Switch to a standalone `null` return type once the module drops
   * ^10.3 support and PHP 8.1 compatibility is no longer required.
   *
   * @phpstan-ignore return.unusedType
   */
  #[Hook('lms_course_link', order: Order::Last)]
  public function lmsCourseLinkLast(Course $course, AccountInterface $current_user, CacheableMetadata $cacheability): ?array {
    $this->keyValueFactory->get('lms_test')->set('lms_course_link_last_executed', TRUE);
    return NULL;
  }

}
