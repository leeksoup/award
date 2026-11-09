<?php

declare(strict_types=1);

namespace Drupal\lms_free_text_model\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\lms\Entity\Answer;
use Drupal\lms\Entity\Bundle\CourseInterface;
use Drupal\lms\TrainingManager;
use Drupal\lms_free_text_model\ModelResponseSubmission;
use Drupal\lms_free_text_model\Plugin\ActivityAnswer\FreeTextModelResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays saved responses without providing a learner resubmission form.
 */
final class ModelResponseController extends ControllerBase {

  public function __construct(
    private readonly ModelResponseSubmission $submission,
    private readonly TrainingManager $trainingManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('lms_free_text_model.submission'),
      $container->get('lms.training_manager'),
    );
  }

  /**
   * Requires the answer's owner, matching course, plugin, and course access.
   */
  public function access(GroupInterface $group, Answer $lms_answer, AccountInterface $account): AccessResultInterface {
    $matches = $group instanceof CourseInterface
      && !$lms_answer->isNew()
      && $lms_answer->getUserId() === (string) $account->id()
      && $lms_answer->getLessonStatus()->getCourseStatus()->getCourseId() === (string) $group->id()
      && $this->submission->applies($lms_answer);
    return AccessResult::allowedIf($matches)
      ->andIf($group->access('take', $account, TRUE))
      ->setCacheMaxAge(0);
  }

  /**
   * Displays the answer and model after a successful submission.
   */
  public function review(CourseInterface $group, Answer $lms_answer): array {
    $plugin = $this->trainingManager->getActivityAnswerPlugin($lms_answer->getActivity());
    assert($plugin instanceof FreeTextModelResponse);
    $build = $plugin->evaluationDisplay($lms_answer);
    $build['continue'] = [
      '#type' => 'link',
      '#title' => $this->t('Continue'),
      '#url' => $this->submission->continuation($lms_answer),
      '#attributes' => ['class' => ['button', 'button--primary']],
      '#weight' => 100,
    ];
    $build['#cache']['max-age'] = 0;
    return $build;
  }

}
