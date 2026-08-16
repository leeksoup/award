<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Handlers;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\lms\Entity\ActivityInterface;
use Drupal\lms\Entity\CourseStatusInterface;
use Drupal\lms\Entity\LessonInterface;
use Drupal\lms\Entity\LessonStatusInterface;
use Drupal\lms\Exception\TrainingException;
use Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem;
use Drupal\lms\TrainingManager;
use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

/**
 * Lesson handler base class.
 */
abstract class LmsLessonHandlerBase implements LmsLessonHandlerInterface {

  use StringTranslationTrait;

  public function __construct(
    #[AutowireCallable(service: 'entity.form_builder', method: 'getForm')]
    protected \Closure $getEntityForm,
    #[AutowireCallable(service: 'datetime.time', method: 'getRequestTime')]
    protected \Closure $getRequestTime,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly TrainingManager $trainingManager,
    protected readonly AccountInterface $currentUser,
  ) {}

  /**
   * Initialize lesson.
   *
   * Creates a new lesson status linked to a Course Status.
   */
  public function initializeLesson(CourseStatusInterface $course_status, LMSReferenceItem $lesson_item): LessonStatusInterface {
    $lesson_status = $course_status->isNew() ? NULL : $this->trainingManager->loadLessonStatus($course_status->id(), $lesson_item->target_id);
    // If there already was an attempt, restart.
    if ($lesson_status !== NULL) {
      // @todo Save lesson attempt data for analytical purposes or push to
      // a LRS when needed before deletion.
      $answers = $this->entityTypeManager->getStorage('lms_answer')->loadByProperties([
        'lesson_status' => $lesson_status->id(),
      ]);
      foreach ($answers as $answer) {
        $answer->delete();
      }
      $lesson_status->delete();
    }

    // Create new lesson status.
    $lesson_status = $this->entityTypeManager->getStorage('lms_lesson_status')->create([
      'course_status' => $course_status,
      LessonStatusInterface::LESSON_FIELD => $lesson_item->target_id,
      'required_score' => $lesson_item->getRequiredScore(),
      'mandatory' => $lesson_item->isMandatory(),
      'finished' => 0,
    ]);
    \assert($lesson_status instanceof LessonStatusInterface);

    $this->setLessonStatusActivities($lesson_status);

    return $lesson_status;
  }

  /**
   * Sets activities and max score of a lesson status.
   */
  protected function setLessonStatusActivities(LessonStatusInterface $lesson_status, ?string $current_activity_delta = NULL): void {
    $lesson = $lesson_status->getLesson();
    $activity_items = $lesson->get(LessonInterface::ACTIVITIES);

    if (\count($activity_items) === 0) {
      throw new \Exception(\sprintf('Lesson "%s" (%d) with no activities attempted.', $lesson->label(), $lesson->id()));
    }

    $randomization = $lesson->getRandomization();
    if ($randomization > 0) {
      // Convert to an array so shuffle can be used.
      $as_array = [];
      foreach ($activity_items as $delta => $item) {
        $as_array[$delta] = $item;
      }
      $activity_items = $as_array;
      unset($as_array);

      \shuffle($activity_items);
      if ($randomization === 2) {
        $random_activities = $lesson->getRandomActivitiesCount();
        $activity_items = \array_slice($activity_items, 0, $random_activities);
      }
    }

    $activity_ids = [];
    $max_score = 0;
    /** @var \Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem $item */
    foreach ($activity_items as $item) {
      $activity_ids[] = $item->target_id;
      $max_score += $item->getMaxScore();
    }

    $lesson_status->setActivityIds($activity_ids);
    $lesson_status->setMaxScore($max_score);

    if ($current_activity_delta === NULL) {
      $current_activity_delta = 0;
    }
    $lesson_status->setCurrentActivityDelta($current_activity_delta);
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(
    CourseStatusInterface $course_status,
    ?LessonStatusInterface $previous_lesson_status,
    LMSReferenceItem $previous_lesson_item,
  ): void {
    $course = $course_status->getCourse();
    if ($course->hasFreeNavigation($course_status->getUser())) {
      return;
    }

    $mandatory = $previous_lesson_status === NULL ? $previous_lesson_item->isMandatory() : $previous_lesson_status->isMandatory();
    // This lesson is not mandatory - next lesson can always be taken.
    if (!$mandatory) {
      return;
    }

    if ($previous_lesson_status === NULL) {
      throw new TrainingException($course_status, TrainingException::REQUIRED_NOT_STARTED);
    }

    if (!$previous_lesson_status->isFinished()) {
      throw new TrainingException($course_status, TrainingException::REQUIRED_NOT_FINISHED);
    }

    if ($previous_lesson_item->autoRepeatFailed()) {
      if ($previous_lesson_status->isEvaluated()) {
        $score = $previous_lesson_status->getScore();
      }
      // We need to calculate scores from answers in case some are
      // not evaluated yet.
      else {
        $points = 0;
        foreach ($this->trainingManager->getAnswersByActivityId($previous_lesson_status, $previous_lesson_status->getActivityIds()) as $answer) {
          $points += $answer->getScore();
        }
        $max_score = $previous_lesson_status->getMaxScore();
        $score = $max_score === 0 ? 1 : $points / $max_score;
        $score = (int) \round($score * 100);
      }

      if ($score < $previous_lesson_status->getRequiredScore()) {
        throw new TrainingException($course_status, TrainingException::INSUFFICIENT_SCORE);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getAnswerForm(LessonStatusInterface $lesson_status): array {
    $build = [];

    $lesson = $lesson_status->getLesson();

    // @todo Implement a solution to be able to set
    // lesson / training availability,
    // preferably on the Course level - lessons reference field.
    $activity = $lesson_status->getCurrentActivity();
    \assert($activity instanceof ActivityInterface);
    $build['activity_build'] = $this->entityTypeManager->getViewBuilder('lms_activity')->view($activity, 'activity');

    // Answer form.
    $answer = $this->trainingManager->loadAnswer($lesson_status, $activity);
    if ($answer === NULL) {
      $answer = $this->trainingManager->createAnswer($lesson_status, $activity);
    }
    $build['form'] = ($this->getEntityForm)($answer, 'default', [
      'group' => $lesson_status->getCourseStatus()->getCourse(),
      'lms_lesson' => $lesson,
      'lms_activity' => $activity,
    ]);

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function buildResults(array &$element, LessonStatusInterface $lesson_status): void {
    if ($lesson_status->isNew()) {
      $element['status'] = ['#markup' => $this->t('Not started')];
      return;
    }

    $element['answers'] = [
      '#theme' => 'table',
      '#header' => [
        'activity' => $this->t('Activity'),
        'answered' => $this->t('Answered'),
        'evaluated' => $this->t('Evaluated'),
        'score' => $this->t('Score'),
      ],
      '#rows' => [],
      '#empty' => $this->t('This lesson has no activities'),
    ];
    $answers = $this->trainingManager->getAnswersByActivityId($lesson_status);

    $course_status = $lesson_status->getCourseStatus();
    $details_access = $course_status->getCourse()->hasPermission('grade students', $this->currentUser) || (string) $this->currentUser->id() === $course_status->getUserId();
    if ($details_access) {
      $element['answers']['#header']['details'] = $this->t('Answer');
    }

    foreach ($lesson_status->getActivities() as $activity) {
      $activity_id = $activity->id();

      $row = [
        'activity' => $activity->label(),
        'answered' => $this->t('No'),
        'evaluated' => [
          'data' => $this->t('N/A'),
        ],
        'score' => [
          'data' => $this->t('N/A'),
        ],
      ];
      if ($details_access) {
        $row['details'] = ['data' => $this->t('N/A')];
      }

      if (\array_key_exists($activity_id, $answers)) {
        $row['answered'] = $this->t('Yes');
        if ($answers[$activity_id]->isEvaluated()) {
          $row['evaluated']['data'] = $this->t('Yes');
          // Show score of evaluated answers only.
          $row['score']['data'] = $answers[$activity_id]->getScore();
        }
        else {
          $row['evaluated']['data'] = $this->t('No');
        }

        if ($details_access) {
          $answer_id = $answers[$activity_id]->id();
          $row['evaluated']['data-lms-selector'] = 'evaluated-' . $answer_id;
          $row['score']['data-lms-selector'] = 'score-' . $answer_id;
          $row['details'] = [
            'data' => [
              '#type' => 'link',
              '#title' => $this->t('Details'),
              '#url' => Url::fromRoute('lms.answer.details', [
                'lms_answer' => $answer_id,
                'js' => 'nojs',
              ]),
              '#attributes' => ['class' => ['use-ajax']],
            ],
          ];
        }
      }

      $element['answers']['#rows'][] = $row;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateStatus(LessonStatusInterface $lesson_status): bool {
    $activity_ids = $lesson_status->getActivityIds();
    $activities = $this->entityTypeManager->getStorage('lms_activity')->loadMultiple($activity_ids);
    $activities_count = \count($activities);
    if ($activities_count === 0) {
      throw new \Exception(\sprintf('Trying to calculate lesson status %d for a lesson with no activities.', $lesson_status->id()));
    }

    $answers_by_activity_id = $this->trainingManager->getAnswersByActivityId($lesson_status, $activity_ids);

    $max_score = $lesson_status->getMaxScore();
    $all_answered = TRUE;
    $all_evaluated = TRUE;
    $activities_count = 0;
    $answered_count = 0;
    $evaluated_count = 0;
    $obtained_score = 0;
    foreach ($activities as $activity) {
      $activities_count++;
      if (!\array_key_exists($activity->id(), $answers_by_activity_id)) {
        $all_answered = FALSE;
        continue;
      }

      $answer = $answers_by_activity_id[$activity->id()];
      $answered_count++;
      if (!$answer->isEvaluated()) {
        $all_evaluated = FALSE;
        continue;
      }

      $evaluated_count++;
      $obtained_score += $answer->getScore();
    }

    if ($max_score === 0) {
      $percentage = $evaluated_count / $activities_count;
    }
    else {
      $percentage = $obtained_score / $max_score;
    }
    $percentage = (int) \round($percentage * 100);

    if (
      (int) $lesson_status->get('given_answers')->value === $answered_count &&
      $lesson_status->getScore() === $percentage &&
      $lesson_status->isEvaluated() === $all_evaluated
    ) {
      return FALSE;
    }

    // Set lesson status values.
    $lesson_status->set('given_answers', $answered_count);
    $lesson_status->setScore($percentage);

    if ($all_answered && !$lesson_status->isFinished()) {
      $lesson_status->setFinished(($this->getRequestTime)());
    }

    // Calculate passed percentage.
    $pass_percentage = (int) \round($answered_count / $activities_count * 100);
    $lesson_status->setPassPercentage($pass_percentage);

    $lesson_status->setEvaluated($all_evaluated);
    $lesson_status->save();
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterLessonForm(array &$form, FormStateInterface $form_state): void {
    // @see \Drupal\lms\Entity\Lesson::baseFieldDefinitions
    $form['random_activities']['#states'] = [
      'visible' => [
        // Value 2 corresponds to random activities.
        ':input[name="randomization"]' => ['value' => 2],
      ],
    ];
  }

}
