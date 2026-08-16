<?php

declare(strict_types=1);

namespace Drupal\lms\Controller;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lms\Entity\AnswerInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Entity\LessonStatusInterface;
use Drupal\lms\TrainingManager;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The training controller.
 */
final class CourseController extends ControllerBase {

  use CourseControllerTrait;

  public function __construct(
    protected readonly TrainingManager $trainingManager,
  ) {}

  /**
   * Start the learning path.
   *
   * This page will redirect the user to the first learning path content.
   */
  public function start(Course $group): RedirectResponse {
    try {
      $lesson_status = $this->trainingManager->getRequestedLessonStatus($group, $this->currentUser());
    }
    catch (\Exception $e) {
      $url = $this->handleError($group, $e);
      return $this->redirect($url->getRouteName(), $url->getRouteParameters());
    }
    $route_parameters = [
      'group' => $group->id(),
      'lesson_delta' => $lesson_status->getCurrentLessonDelta(),
      'activity_delta' => $lesson_status->getCurrentActivityDelta(),
    ];

    return $this->redirect('lms.group.answer_form', $route_parameters);
  }

  /**
   * Returns lesson question answer form title.
   */
  public function activityFormTitle(Course $group, int $lesson_delta): string|TranslatableMarkup {
    try {
      $course_status = $this->trainingManager->getCurrentCourseStatus($group, $this->currentUser());
    }
    catch (\Exception $e) {
      return $this->t('Invalid request.');
    }

    $lesson = $this->trainingManager->getLessonByDelta($course_status, $lesson_delta);
    if ($lesson === NULL) {
      return $this->t('Invalid lesson delta.');
    }

    $lesson_status = $this->trainingManager->loadLessonStatus($course_status->id(), $lesson->id());
    if ($lesson_status === NULL) {
      return $this->t('Invalid lesson delta.');
    }
    $activity = $lesson_status->getCurrentActivity();
    if ($activity === NULL) {
      return $lesson->label();
    }

    return $lesson->label() . ' - ' . $activity->label();
  }

  /**
   * Specific activity callback.
   */
  public function activity(
    Course $group,
    int $lesson_delta,
    int $activity_delta,
  ): array|RedirectResponse {
    try {
      $lesson_status = $this->trainingManager->getRequestedLessonStatus($group, $this->currentUser(), [
        'lesson' => $lesson_delta,
        'activity' => $activity_delta,
      ]);
    }
    catch (\Exception $e) {
      $url = $this->handleError($group, $e);
      return $this->redirect($url->getRouteName(), $url->getRouteParameters());
    }

    return $lesson_status->getAnswerForm();
  }

  /**
   * Results page title callback.
   */
  public function resultsTitle(Course $group, ?UserInterface $user = NULL): TranslatableMarkup {
    return $this->t('@course results for @user', [
      '@course' => $group->label(),
      '@user' => $user === NULL ? $this->getCurrentUser()->label() : $user->label(),
    ]);
  }

  /**
   * Results page callback.
   */
  public function results(Course $group, ?UserInterface $user = NULL): array {
    $build = [
      'course_status' => [
        '#type' => 'fieldset',
        '#title' => $this->t('Completion status:'),
        'status' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['data-lms-selector' => 'course-status'],
        ],
      ],
      'lessons' => [],
    ];

    if ($user === NULL) {
      $user = $this->getCurrentUser();
    }
    $course_status = $this->trainingManager->loadCourseStatus($group, $user, [
      'current' => TRUE,
    ]);

    if ($course_status === NULL) {
      $build['course_status']['status']['#value'] = $this->t('Not started');
      return $build;
    }

    $build['course_status']['status']['#value'] = $course_status->getStatusAndScore();

    // Rely on lesson statuses only if course is finished.
    if ($course_status->isFinished()) {
      $lesson_statuses = $this->entityTypeManager()->getStorage('lms_lesson_status')->loadByProperties([
        'course_status' => $course_status->id(),
      ]);
    }
    else {
      $lesson_statuses = [];
      // Reload group from status since we may have a different revision.
      $group = $course_status->getCourse();
      /** @var \Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem */
      foreach ($group->get(Course::LESSONS) as $lesson_item) {
        $lesson_status = $this->trainingManager->loadLessonStatus($course_status->id(), $lesson_item->target_id);
        if ($lesson_status === NULL) {
          $lesson_status = $this->entityTypeManager()->getStorage('lms_lesson_status')->create([
            'course_status' => $course_status,
            LessonStatusInterface::LESSON_FIELD => $lesson_item->entity,
          ]);
        }
        $lesson_statuses[] = $lesson_status;
      }
    }

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($course_status);

    /** @var \Drupal\lms\Entity\LessonStatusInterface $lesson_status */
    foreach ($lesson_statuses as $lesson_status) {
      if ($lesson_status->isNew()) {
        $lms_selector = 'lesson-' . $lesson_status->getLessonId();
      }
      else {
        $lms_selector = 'lesson-status-' . $lesson_status->id();
        $cacheability->addCacheableDependency($lesson_status);
      }

      $lesson_build = [
        '#type' => 'details',
        '#title' => new FormattableMarkup('<span data-lms-selector="' . $lms_selector . '">@status</span>', [
          '@status' => $this->getLessonStatusSummary($lesson_status),
        ]),
        '#open' => FALSE,
        '#attributes' => ['class' => ['lesson-score-details']],
      ];

      $lesson_status->getLesson()->getLessonHandlerService()->buildResults($lesson_build, $lesson_status);
      $build['lessons'][] = $lesson_build;
    }

    $build['#attached']['library'] = [
      'core/drupal.dialog.ajax',
    ];

    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * Open evaluation modal form.
   */
  public function answerDetails(AnswerInterface $lms_answer, string $js = 'nojs'): array|AjaxResponse {
    $use_ajax = ($js === 'ajax');

    $build = [];

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($lms_answer);
    $cacheability->addCacheContexts(['user.group_permissions']);

    $build['answer'] = $this->entityTypeManager()->getViewBuilder('lms_answer')->view($lms_answer, 'answer');

    $display_evaluation_form = FALSE;
    $course = $lms_answer->getLessonStatus()->getCourseStatus()->getCourse();
    if ($course->hasPermission('grade students', $this->currentUser())) {
      $build['evaluation_form'] = $this->entityFormBuilder()->getForm($lms_answer, 'evaluate', ['use_ajax' => $use_ajax]);
    }

    // Allow other modules to alter the build.
    $build['#use_ajax'] = $use_ajax;
    $this->moduleHandler()->alter('lms_answer_details', $build, $lms_answer, $cacheability);
    unset($build['#use_ajax']);

    $cacheability->applyTo($build);

    if ($use_ajax) {
      $response = new AjaxResponse();
      $response->addCommand(new OpenModalDialogCommand($this->t('Answer details: @activity', [
        '@activity' => $lms_answer->getActivity()->label(),
      ]), $build, ['width' => '80%']));
      return $response;
    }

    return $build;
  }

  /**
   * Code saver.
   */
  private function getCurrentUser(): UserInterface {
    return $this->entityTypeManager()->getStorage('user')->load($this->currentUser()->id());
  }

  /**
   * Reset test progress for course editors.
   */
  public function resetTest(Course $group, Request $request): RedirectResponse {
    $this->trainingManager->resetTraining($group->id(), (string) $this->currentUser()->id());
    $this->messenger()->addStatus($this->t('Your course progress has been reset so you can view the latest updates.'));

    $destination = $request->query->get('destination');
    if ($destination !== NULL && $destination !== '') {
      // Prevent open redirect vulnerabilities by ensuring it's a relative path.
      if (\str_starts_with($destination, '/')) {
        return new RedirectResponse($destination);
      }
    }

    return $this->redirect('entity.group.canonical', ['group' => $group->id()]);
  }

}
