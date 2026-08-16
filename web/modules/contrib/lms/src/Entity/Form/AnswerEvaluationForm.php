<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\lms\Controller\CourseControllerTrait;
use Drupal\lms\Entity\AnswerInterface;
use Drupal\lms\TrainingManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for Answer edit forms.
 */
final class AnswerEvaluationForm extends ContentEntityForm {

  use CourseControllerTrait;

  private const FORM_SELECTOR = 'answer-evaluation-form';

  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    protected readonly TrainingManager $trainingManager,
    protected readonly AccountInterface $currentUser,
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
      $container->get('lms.training_manager'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    \assert($this->entity instanceof AnswerInterface);

    $lesson_status = $this->entity->getLessonStatus();
    $form = parent::buildForm($form, $form_state);
    $form['#attributes']['data-lms-selector'] = self::FORM_SELECTOR;

    $lesson = $lesson_status->getLesson();
    $form_state->set('lms_lesson_id', $lesson->id());
    $max_score = $this->trainingManager->getActivityMaxScore($lesson, $this->entity->getActivity());

    $form['messages'] = [
      '#theme' => 'status_messages',
      '#status_headings' => [
        'error' => $this->t('Error message'),
        'warning' => $this->t('Warning message'),
      ],
      '#message_list' => [],
      '#weight' => -100,
    ];

    if ($this->entity->isEvaluated()) {
      $form['messages']['#message_list']['warning'] = [
        $this->t('This answer is already evaluated.'),
      ];
    }

    $form['score'] = [
      '#title' => $this->t('Score (0 to @max)', ['@max' => $max_score]),
      '#required' => TRUE,
      '#type' => 'number',
      '#min' => 0,
      '#max' => $max_score,
      '#step' => 1,
      '#default_value' => $this->entity->isEvaluated() ? $this->entity->getScore() : NULL,
    ];

    $form['actions']['submit']['#value'] = $this->t('Evaluate');

    if ($form_state->get('use_ajax') === TRUE) {
      $form['actions']['submit']['#ajax'] = [
        'callback' => [self::class, 'ajaxUpdatePage'],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    \assert($this->entity instanceof AnswerInterface);

    $score = $form_state->getValue('score');
    if ($score === '') {
      return;
    }

    $this->entity
      ->setScore((int) $score)
      ->setEvaluated(TRUE)
      ->save();
    $course_status = NULL;

    $lms_evaluation_results = [
      'answer' => [
        'id' => $this->entity->id(),
        'score' => $score,
      ],
    ];

    // If all answers of this lesson are evaluated, update lesson status.
    $lesson_status = $this->entity->getLessonStatus();
    if ($this->trainingManager->allAnswersEvaluated($lesson_status)) {
      $this->trainingManager->updateLessonStatus($lesson_status);
      $lms_evaluation_results['lesson_status'] = [
        'id' => $lesson_status->id(),
        'summary' => (string) $this->getLessonStatusSummary($lesson_status),
      ];

      // If all lesson statuses of the course status are evaluated,
      // update course status.
      $course_status = $lesson_status->getCourseStatus();
      if ($this->trainingManager->allLessonStatusesEvaluated($course_status)) {
        $this->trainingManager->updateCourseStatus($course_status);
        $lms_evaluation_results['course_status'] = $course_status;
      }
    }
    $form_state->set('lms_evaluation_results', $lms_evaluation_results);

    // Redirect if not using AJAX.
    if ($form_state->get('use_ajax') !== TRUE) {
      if ($course_status === NULL) {
        $course_status = $lesson_status->getCourseStatus();
      }
      $form_state->setRedirectUrl(Url::fromRoute('lms.group.results', [
        'group' => $course_status->getCourseId(),
        'user' => $course_status->getUserId(),
      ]));
    }
  }

  /**
   * Page update callback.
   */
  public static function ajaxUpdatePage(array $form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    // Display errors as that unfortunately doesn't work out of the box
    // in modals and messages are rendered during the next page request
    // instead.
    if ($form_state::hasAnyErrors()) {
      $form['messages']['#message_list'] = \Drupal::messenger()->all();
      \Drupal::messenger()->deleteAll();
      $selector = '[data-lms-selector="' . self::FORM_SELECTOR . '"]';
      $response->addCommand(new ReplaceCommand($selector, $form));
      return $response;
    }

    $results = $form_state->get('lms_evaluation_results');

    // The evaluation form may not always include a score, for example when
    // commenting is enabled.
    if ($results === NULL) {
      return $response->addCommand(new CloseModalDialogCommand());
    }

    $selector_pattern = '[data-lms-selector="%s"]';

    $selector = \sprintf($selector_pattern, 'evaluated-' . $results['answer']['id']);
    $response->addCommand(new HtmlCommand($selector, new TranslatableMarkup('Yes')));

    $selector = \sprintf($selector_pattern, 'score-' . $results['answer']['id']);
    $response->addCommand(new HtmlCommand($selector, $results['answer']['score']));

    if (\array_key_exists('lesson_status', $results)) {
      $selector = \sprintf($selector_pattern, 'lesson-status-' . $results['lesson_status']['id']);
      $response->addCommand(new HtmlCommand($selector, $results['lesson_status']['summary']));
    }
    if (\array_key_exists('course_status', $results)) {
      $selector = \sprintf($selector_pattern, 'course-status');
      $response->addCommand(new HtmlCommand($selector, $results['course_status']->getStatusAndScore()));
    }

    $response->addCommand(new CloseModalDialogCommand());
    return $response;
  }

}
