<?php

declare(strict_types=1);

namespace Drupal\Tests\lms_free_text_model\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\lms\Entity\ActivityInterface;
use Drupal\lms\Entity\Answer;
use Drupal\lms\Entity\Bundle\CourseInterface;
use Drupal\lms\Entity\CourseStatusInterface;
use Drupal\lms\Entity\LessonStatusInterface;
use Drupal\lms\TrainingManager;
use Drupal\lms_free_text_model\Controller\ModelResponseController;
use Drupal\lms_free_text_model\ModelResponseSubmission;
use Drupal\lms_free_text_model\Plugin\ActivityAnswer\FreeTextModelResponse;
use Drupal\Tests\UnitTestCase;

/**
 * Tests ownership and Group access on the saved-answer review route.
 *
 * @group lms_free_text_model
 */
final class ModelResponseAccessTest extends UnitTestCase {

  /**
   * Access requires the owner, matching course, plugin, and take permission.
   *
   * @dataProvider accessCases
   */
  public function testReviewAccess(string $uid, string $gid, bool $take, bool $model_plugin, bool $allowed): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $group = $this->createMock(CourseInterface::class);
    $group->method('id')->willReturn($gid);
    $group->method('access')->willReturn($take ? AccessResult::allowed() : AccessResult::forbidden());
    $course = $this->createMock(CourseStatusInterface::class);
    $course->method('getCourseId')->willReturn('15');
    $lesson = $this->createMock(LessonStatusInterface::class);
    $lesson->method('getCourseStatus')->willReturn($course);
    $answer = $this->createMock(Answer::class);
    $answer->method('isNew')->willReturn(FALSE);
    $answer->method('getUserId')->willReturn('163');
    $answer->method('getLessonStatus')->willReturn($lesson);
    $answer->method('getActivity')->willReturn($this->createMock(ActivityInterface::class));
    $manager = $this->createMock(TrainingManager::class);
    $plugin = $model_plugin ? new FreeTextModelResponse([], 'free_text_model_response', []) : NULL;
    $manager->method('getActivityAnswerPlugin')->willReturn($plugin);
    $submission = new ModelResponseSubmission($manager, $this->createMock(PrivateTempStoreFactory::class));
    $controller = new ModelResponseController($submission, $manager);
    $result = $controller->access($group, $answer, $account);
    self::assertSame($allowed, $result->isAllowed());
    self::assertSame(0, $result->getCacheMaxAge());
  }

  /**
   * Review access combinations.
   */
  public static function accessCases(): array {
    return [
      'owner with course access' => ['163', '15', TRUE, TRUE, TRUE],
      'different user' => ['999', '15', TRUE, TRUE, FALSE],
      'different course' => ['163', '16', TRUE, TRUE, FALSE],
      'no course access' => ['163', '15', FALSE, TRUE, FALSE],
      'different plugin' => ['163', '15', TRUE, FALSE, FALSE],
    ];
  }

}
