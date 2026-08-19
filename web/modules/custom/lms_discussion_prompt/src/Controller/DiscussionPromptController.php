<?php

declare(strict_types=1);

namespace Drupal\lms_discussion_prompt\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Url;
use Drupal\lms\Controller\CourseControllerTrait;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Exception\TrainingException;
use Drupal\lms\TrainingManager;
use Drupal\lms_discussion_prompt\Service\DiscussionPromptManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Opens a discussion and records Discussion Prompt activity completion.
 */
final class DiscussionPromptController extends ControllerBase implements
  ContainerInjectionInterface {

  use CourseControllerTrait;

  public function __construct(
    protected readonly DiscussionPromptManager $discussionPromptManager,
    protected readonly TrainingManager $trainingManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get(DiscussionPromptManager::class),
      $container->get(TrainingManager::class),
    );
  }

  /**
   * Completes the activity and redirects to the linked discussion.
   */
  public function open(Course $group, int $lesson_delta, int $activity_delta): RedirectResponse {
    try {
      $discussion = $this->discussionPromptManager->completeActivity(
        $group,
        $lesson_delta,
        $activity_delta,
        $this->currentUser(),
      );
    }
    catch (TrainingException $e) {
      $url = $this->handleError($group, $e);
      return $this->redirect($url->getRouteName(), $url->getRouteParameters());
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t(
        'The discussion could not be opened. Please report the issue to a site administrator.',
      ));
      return $this->redirect('lms.group.answer_form', [
        'group' => $group->id(),
        'lesson_delta' => $lesson_delta,
        'activity_delta' => $activity_delta,
      ]);
    }

    $url = $discussion->toUrl();
    $url->setOption('query', [
      'return' => Url::fromRoute('lms.course.start', ['group' => $group->id()])->toString(),
    ]);

    return new RedirectResponse($url->toString());
  }

}
