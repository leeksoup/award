<?php

declare(strict_types=1);

namespace Drupal\lms_forum_prompt\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\lms\Controller\CourseControllerTrait;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Exception\TrainingException;
use Drupal\lms\TrainingManager;
use Drupal\lms_forum_prompt\Service\ForumPromptManager;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Opens a forum topic and records Forum Prompt activity completion.
 */
final class ForumPromptController extends ControllerBase {

  use CourseControllerTrait;

  public function __construct(
    protected readonly ForumPromptManager $forumPromptManager,
    protected readonly TrainingManager $trainingManager,
  ) {}

  /**
   * Completes the activity and redirects to the linked forum topic.
   */
  public function open(Course $group, int $lesson_delta, int $activity_delta): RedirectResponse {
    try {
      $topic = $this->forumPromptManager->completeActivity(
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
        'The discussion topic could not be opened. Please report the issue to a site administrator.',
      ));
      return $this->redirect('lms.group.answer_form', [
        'group' => $group->id(),
        'lesson_delta' => $lesson_delta,
        'activity_delta' => $activity_delta,
      ]);
    }

    $url = $topic->toUrl();
    $url->setOption('query', [
      'return' => Url::fromRoute('lms.course.start', ['group' => $group->id()])->toString(),
    ]);

    return new RedirectResponse($url->toString());
  }

}
