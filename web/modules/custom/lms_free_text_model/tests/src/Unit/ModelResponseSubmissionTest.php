<?php

declare(strict_types=1);

namespace Drupal\Tests\lms_free_text_model\Unit;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\lms\Entity\ActivityInterface;
use Drupal\lms\Entity\Answer;
use Drupal\lms\Entity\CourseStatusInterface;
use Drupal\lms\Entity\LessonStatusInterface;
use Drupal\lms\Entity\LessonInterface;
use Drupal\lms\TrainingManager;
use Drupal\lms_free_text_model\ModelResponseSubmission;
use Drupal\lms_free_text_model\Plugin\ActivityAnswer\FreeTextModelResponse;
use Drupal\Tests\UnitTestCase;

/**
 * Tests that feedback follows saving and preserves LMS continuation.
 *
 * @group lms_free_text_model
 */
final class ModelResponseSubmissionTest extends UnitTestCase {

  /**
   * Overtime redirects do not reveal an answer that was not submitted.
   */
  public function testNoSubmissionDoesNotRedirect(): void {
    $factory = $this->createMock(PrivateTempStoreFactory::class);
    $factory->expects(self::never())->method('get');
    $submission = new ModelResponseSubmission($this->createMock(TrainingManager::class), $factory);
    $state = new FormState();
    $next = Url::fromRoute('entity.group.canonical', ['group' => 15]);
    $state->setRedirectUrl($next);
    $submission->afterSubmit($state);
    self::assertSame($next, $state->getRedirect());
  }

  /**
   * Feedback cannot be built for an unsaved answer.
   */
  public function testUnsavedAnswerHasNoModelResponse(): void {
    $answer = $this->createMock(Answer::class);
    $answer->method('isNew')->willReturn(TRUE);
    $answer->expects(self::never())->method('getActivity');
    $plugin = new FreeTextModelResponse([], 'free_text_model_response', []);
    self::assertSame([], $plugin->modelResponse($answer));
  }

  /**
   * The review redirect requires an answer actually persisted by LMS.
   */
  public function testUnsavedSubmissionDoesNotRedirect(): void {
    $answer = $this->createMock(Answer::class);
    $answer->method('isNew')->willReturn(TRUE);
    $form = $this->createMock(EntityFormInterface::class);
    $form->method('getEntity')->willReturn($answer);
    $factory = $this->createMock(PrivateTempStoreFactory::class);
    $factory->expects(self::never())->method('get');
    $submission = new ModelResponseSubmission($this->createMock(TrainingManager::class), $factory);
    $state = new FormState();
    $state->setFormObject($form);
    $state->set('lms_free_text_model_submitted', TRUE);
    $next = Url::fromRoute('entity.group.canonical', ['group' => 15]);
    $state->setRedirectUrl($next);
    $submission->afterSubmit($state);
    self::assertSame($next, $state->getRedirect());
  }

  /**
   * A stale form cannot replace a response already submitted in another tab.
   */
  public function testStaleFormCannotResubmit(): void {
    $activity = $this->createMock(ActivityInterface::class);
    $lesson = $this->createMock(LessonStatusInterface::class);
    $answer = $this->createMock(Answer::class);
    $answer->method('isNew')->willReturn(TRUE);
    $answer->method('getActivity')->willReturn($activity);
    $answer->method('getLessonStatus')->willReturn($lesson);
    $manager = $this->createMock(TrainingManager::class);
    $manager->expects(self::once())->method('loadAnswer')->with($lesson, $activity)
      ->willReturn($this->createMock(Answer::class));
    $submission = new ModelResponseSubmission($manager, $this->createMock(PrivateTempStoreFactory::class));
    $form = $this->createMock(EntityFormInterface::class);
    $form->method('getEntity')->willReturn($answer);
    $state = $this->createMock(\Drupal\Core\Form\FormStateInterface::class);
    $state->method('getFormObject')->willReturn($form);
    $state->expects(self::once())->method('setErrorByName')->with('answer', self::anything());
    $submission->validateUnsubmitted($state);
  }

  /**
   * Suggested partial marks are applied once and do not overwrite teacher work.
   */
  public function testReviewStartingScoreIsAppliedOnlyOnSubmission(): void {
    $activity = $this->createMock(ActivityInterface::class);
    $lesson = $this->createMock(LessonInterface::class);
    $status = $this->createMock(LessonStatusInterface::class);
    $status->method('getLesson')->willReturn($lesson);
    $answer = $this->createMock(Answer::class);
    $answer->method('getLessonStatus')->willReturn($status);
    $answer->method('getActivity')->willReturn($activity);
    $answer->method('isEvaluated')->willReturn(FALSE);
    $answer->expects(self::once())->method('setScore')->with(3)->willReturnSelf();
    $manager = $this->createMock(TrainingManager::class);
    $manager->method('getActivityMaxScore')->with($lesson, $activity)->willReturn(10);
    $submission = new ModelResponseSubmission($manager, $this->createMock(PrivateTempStoreFactory::class));
    $submission->prepareReviewScore($answer, 0.25);
    $submission->applyReviewScore($answer);
    // No submission marker remains for a later teacher save.
    $submission->applyReviewScore($answer);
  }

  /**
   * A saved answer gets review before the unchanged next/completion route.
   */
  public function testSavedSubmissionPreservesNextStep(): void {
    foreach (['lms.group.answer_form', 'entity.group.canonical'] as $route) {
      $next = Url::fromRoute($route, ['group' => 15], ['query' => ['example' => 'kept']]);
      $answer = $this->createMock(Answer::class);
      $answer->method('isNew')->willReturn(FALSE);
      $answer->method('id')->willReturn('91');
      $answer->method('uuid')->willReturn('saved-answer-uuid');
      $course = $this->createMock(CourseStatusInterface::class);
      $course->method('getCourseId')->willReturn('15');
      $lesson = $this->createMock(LessonStatusInterface::class);
      $lesson->method('getCourseStatus')->willReturn($course);
      $answer->method('getLessonStatus')->willReturn($lesson);
      $answer->expects(self::never())->method('save');

      $store = $this->createMock(PrivateTempStore::class);
      $record = [
        'route' => $route,
        'parameters' => ['group' => 15],
        'options' => ['query' => ['example' => 'kept']],
      ];
      $store->expects(self::once())->method('set')->with('saved-answer-uuid', $record);
      $store->method('get')->with('saved-answer-uuid')->willReturn($record);
      $factory = $this->createMock(PrivateTempStoreFactory::class);
      $factory->method('get')->with('lms_free_text_model')->willReturn($store);
      $submission = new ModelResponseSubmission($this->createMock(TrainingManager::class), $factory);

      $form = $this->createMock(EntityFormInterface::class);
      $form->method('getEntity')->willReturn($answer);
      $state = new FormState();
      $state->setFormObject($form);
      $state->set('lms_free_text_model_submitted', TRUE);
      $state->setRedirectUrl($next);
      $submission->afterSubmit($state);

      self::assertSame('lms_free_text_model.review', $state->getRedirect()->getRouteName());
      self::assertSame(['group' => '15', 'lms_answer' => '91'], $state->getRedirect()->getRouteParameters());
      $continue = $submission->continuation($answer);
      self::assertSame($next->getRouteName(), $continue->getRouteName());
      self::assertSame($next->getRouteParameters(), $continue->getRouteParameters());
      self::assertSame($next->getOptions(), $continue->getOptions());
    }
  }

}
