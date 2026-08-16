<?php

declare(strict_types=1);

namespace Drupal\lms_hooks_test\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
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

}
