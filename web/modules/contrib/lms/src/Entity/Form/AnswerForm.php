<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\lms\Controller\CourseControllerTrait;
use Drupal\lms\Entity\Answer;
use Drupal\lms\Entity\LessonStatusInterface;
use Drupal\lms\Form\AnswerFormTrait;
use Drupal\lms\Plugin\ActivityAnswerInterface;
use Drupal\lms\TrainingManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Form controller for Answer edit forms.
 */
final class AnswerForm extends ContentEntityForm {

  use CourseControllerTrait;
  use AnswerFormTrait;

  /**
   * Activity - Answer plugin for this answer.
   */
  protected ?ActivityAnswerInterface $plugin;

  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    protected TrainingManager $trainingManager,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('lms.training_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    \assert($this->entity instanceof Answer);
    $lesson_status = $this->entity->getLessonStatus();

    // Check if lesson status is not over time.
    $redirect_url = $this->overTimeHandler($lesson_status);
    if ($redirect_url !== NULL) {
      // Form builder can actually return a redirect response.
      // @phpstan-ignore return.type
      return new RedirectResponse($redirect_url->toString(), 303);
    }

    $close_time = $lesson_status->getCloseTime();
    if ($close_time !== 0) {
      $form['lms_lesson_timer'] = [
        '#type' => 'component',
        '#component' => 'lms:lesson_timer',
        '#props' => [
          'close_time' => $close_time,
        ],
      ];
    }

    // Hide revision_log_message field.
    unset($form['revision_log_message']);

    $activity = $this->entity->getActivity();

    // Backwards navigation.
    $back_deltas = $this->trainingManager->getBackNavDeltas($this->entity);
    if (\count($back_deltas) !== 0) {
      $form['actions']['back'] = Link::createFromRoute($this->t('Back'), 'lms.group.answer_form', [
        'group' => $lesson_status->getCourseStatus()->getCourseId(),
        'lesson_delta' => $back_deltas['lesson'],
        'activity_delta' => $back_deltas['activity'],
      ], [
        'attributes' => ['class' => ['button']],
      ])->toRenderable();
    }

    $elements_count = \count($form);

    // Add activity specific form elements.
    $this->plugin = $this->trainingManager->getActivityAnswerPlugin($activity);
    if ($this->plugin !== NULL) {
      $this->plugin->answeringForm($form, $form_state, $this->entity);
    }

    // Actions tweaks.
    $form['actions']['submit']['#submit'] = ['::submitForm'];
    $form['actions']['submit']['#value'] = $this->t('Submit');
    // Last activity of the course.
    if ($lesson_status->getNextActivityDelta() === NULL && $lesson_status->getNextLessonDelta() === NULL) {
      $form['actions']['submit']['#value'] = $this->t('Finish course');
    }
    // No answer form elements - information only activity.
    elseif ($elements_count === \count($form)) {
      $form['actions']['submit']['#value'] = $this->t('Next');
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $this->entity = parent::validateForm($form, $form_state);
    \assert($this->entity instanceof Answer);
    if ($this->plugin === NULL) {
      return $this->entity;
    }

    $this->plugin->validateAnsweringForm($form, $form_state, $this->entity);
    return $this->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    \assert($this->entity instanceof Answer);
    $activity = $this->entity->getActivity();
    $lesson_status = $this->entity->getLessonStatus();

    // Check if lesson status is not over time.
    $redirect_url = $this->overTimeHandler($lesson_status);
    if ($redirect_url !== NULL) {
      $form_state->setRedirectUrl($redirect_url);
      return;
    }

    $lesson = $lesson_status->getLesson();
    $course_status = $lesson_status->getCourseStatus();

    $max_score = $this->trainingManager->getActivityMaxScore($lesson, $activity);

    // Determine score and evaluated property for the answer.
    if ($this->plugin !== NULL) {
      $this->plugin->submitAnsweringForm($form, $form_state, $this->entity);
      // 0 max score activities are always set as evaluated.
      if ($max_score === 0) {
        $score = 0;
        $evaluated = TRUE;
      }
      elseif ($this->plugin->evaluatedOnSave($this->entity)) {
        $score = $this->plugin->getScore($this->entity) * $max_score;
        $evaluated = TRUE;
      }
      else {
        // Always set unevaluated score to max to avoid blocking user from
        // going to next lessons.
        $score = $max_score;
        $evaluated = FALSE;
        $lesson_status->setEvaluated(FALSE);
      }
    }
    // No plugin, just config - static display-only activity.
    else {
      $evaluated = TRUE;
      $score = $max_score;
    }

    $this->entity
      ->setEvaluated($evaluated)
      ->setScore($score)
      ->save();

    // Set latest user activity time on Course status.
    $this->trainingManager->setLastActivityTime($course_status);

    // Proceed to the next activity.
    $next_activity_delta = $lesson_status->getNextActivityDelta($activity);
    $next_lesson_status = NULL;
    if ($next_activity_delta === NULL) {
      $this->trainingManager->updateLessonStatus($lesson_status);
      try {
        $next_lesson_status = $this->trainingManager->getNextLessonStatus($course_status);
      }
      catch (\Exception $e) {
        $this->trainingManager->updateCourseStatus($course_status);
        $url = $this->handleError($course_status->getCourse(), $e);
        $form_state->setRedirectUrl($url);
        return;
      }

      if ($next_lesson_status !== NULL) {
        // Reset delta if navigating to the next lesson.
        $next_activity_delta = 0;
        $next_lesson_status->setCurrentActivityDelta($next_activity_delta);
        $next_lesson_status->save();
        $course_status->set('current_lesson_status', $next_lesson_status);
        $lesson_status = $next_lesson_status;
      }

      // We have all required properties set on Course Status now,
      // we can recalculate and save.
      $last_activity = $next_lesson_status === NULL;
      $this->trainingManager->updateCourseStatus($course_status, $last_activity);
    }
    else {
      $lesson_status->setCurrentActivityDelta($next_activity_delta);
      $lesson_status->save();
      // We also need to save Course status to update last activity time.
      $course_status->save();
    }

    if ($next_activity_delta === NULL) {
      $url = $this->handleFinishedCourse($course_status);
      $form_state->setRedirectUrl($url);
      return;
    }

    $form_state->setRedirect('lms.group.answer_form', [
      'group' => $course_status->getCourseId(),
      'lesson_delta' => $lesson_status->getCurrentLessonDelta(),
      'activity_delta' => $lesson_status->getCurrentActivityDelta(),
    ]);
  }

  /**
   * Handle overtime.
   */
  private function overTimeHandler(LessonStatusInterface $lesson_status): ?Url {
    if (!$lesson_status->isOverTime()) {
      return NULL;
    }

    $lesson_status->setFinished();
    $this->trainingManager->updateLessonStatus($lesson_status);
    $course_status = $lesson_status->getCourseStatus();
    try {
      $next_lesson_status = $this->trainingManager->getNextLessonStatus($course_status);
    }
    catch (\Exception $e) {
      $this->messenger()->addError('Lesson is over time.');
      $this->trainingManager->updateCourseStatus($course_status);
      return $this->handleError($course_status->getCourse(), $e);
    }

    if ($next_lesson_status !== NULL) {
      $this->messenger()->addError("Lesson is over time, you've been redirected to the next lesson.");
      $next_lesson_status->save();
      $course_status->set('current_lesson_status', $next_lesson_status);
      $this->trainingManager->updateCourseStatus($course_status);
      return Url::fromRoute('lms.group.answer_form', [
        'group' => $course_status->getCourseId(),
        'lesson_delta' => $next_lesson_status->getCurrentLessonDelta(),
        'activity_delta' => $next_lesson_status->getCurrentActivityDelta(),
      ]);
    }
    else {
      $this->messenger()->addError('Lesson is over time, course finished.');
      $this->trainingManager->updateCourseStatus($course_status, TRUE);
      return Url::fromRoute('entity.group.canonical', [
        'group' => $course_status->getCourseId(),
      ]);
    }

  }

}
