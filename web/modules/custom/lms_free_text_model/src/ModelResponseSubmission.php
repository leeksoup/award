<?php

declare(strict_types=1);

namespace Drupal\lms_free_text_model;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\lms\Entity\Answer;
use Drupal\lms\TrainingManager;
use Drupal\lms_free_text_model\Plugin\ActivityAnswer\FreeTextModelResponse;

/**
 * Preserves LMS navigation while inserting a saved-answer review page.
 */
final class ModelResponseSubmission {

  public function __construct(
    private readonly TrainingManager $trainingManager,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * Checks the plugin rather than assuming a particular activity bundle name.
   */
  public function applies(Answer $answer): bool {
    return $this->trainingManager->getActivityAnswerPlugin($answer->getActivity()) instanceof FreeTextModelResponse;
  }

  /**
   * Checks persisted progress even if a stale cached form holds a new entity.
   */
  public function validateUnsubmitted(FormStateInterface $form_state): void {
    $answer = $form_state->getFormObject()->getEntity();
    if (!$answer->isNew() || $this->trainingManager->loadAnswer($answer->getLessonStatus(), $answer->getActivity()) !== NULL) {
      $form_state->setErrorByName('answer', new TranslatableMarkup('This answer has already been submitted.'));
    }
  }

  /**
   * Runs after the normal LMS submit handler, including its database saves.
   */
  public function afterSubmit(FormStateInterface $form_state): void {
    if (!$form_state->get('lms_free_text_model_submitted')) {
      // LMS can redirect on overtime without submitting an answer.
      return;
    }
    $answer = $form_state->getFormObject()->getEntity();
    $next = $form_state->getRedirect();
    if (!$answer instanceof Answer || $answer->isNew() || !$next instanceof Url || !$next->isRouted()) {
      return;
    }
    $this->tempStoreFactory->get('lms_free_text_model')->set($answer->uuid(), [
      'route' => $next->getRouteName(),
      'parameters' => $next->getRouteParameters(),
      'options' => $next->getOptions(),
    ]);
    $form_state->setIgnoreDestination(TRUE);
    $form_state->setRedirect('lms_free_text_model.review', [
      'group' => $answer->getLessonStatus()->getCourseStatus()->getCourseId(),
      'lms_answer' => $answer->id(),
    ]);
  }

  /**
   * Returns the server-generated next step, scoped to this user's tempstore.
   */
  public function continuation(Answer $answer): Url {
    $next = $this->tempStoreFactory->get('lms_free_text_model')->get($answer->uuid());
    if (is_array($next)) {
      return Url::fromRoute($next['route'], $next['parameters'], $next['options']);
    }
    // A bookmarked review can outlive tempstore. Do not save the answer again.
    return Url::fromRoute('entity.group.canonical', [
      'group' => $answer->getLessonStatus()->getCourseStatus()->getCourseId(),
    ]);
  }

}
