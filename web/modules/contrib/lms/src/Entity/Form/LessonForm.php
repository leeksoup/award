<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\lms\DataIntegrityChecker;
use Drupal\lms\Entity\LessonInterface;
use Drupal\lms\Form\DataIntegrityWarningTrait;
use Drupal\lms\Form\Modal\ModalEntitySubformTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Activity add / edit form.
 */
final class LessonForm extends ContentEntityForm {

  use ModalEntitySubformTrait;
  use DataIntegrityWarningTrait;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return parent::create($container)->setDataIntegrityChecker($container->get(DataIntegrityChecker::class));
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);

    /** @var \Drupal\lms\Entity\LessonInterface */
    $lesson = $this->entity;

    // Data integrity checking logic.
    $courses = $this->integrityChecker->checkLessonProgress($lesson);
    if (\count($courses) !== 0) {
      $links = [];
      foreach ($courses as $course) {
        $links[] = $course->toLink($course->label(), 'edit-form')->toString();
      }

      foreach ([
        'randomization',
        'random_activities',
        LessonInterface::ACTIVITIES,
      ] as $field) {
        // Not all lesson types have these fields.
        if (\array_key_exists($field, $form)) {
          $this->addDataWarning($form[$field], $this->formatPlural(
            \count($links),
            'This lesson has active students in the following course: @courses. Making structural or scoring changes to the field below may break their progress, unless you save with a new Revision.',
            'This lesson has active students in the following courses: @courses. Making structural or scoring changes to the field below may break their progress, unless you save with a new Revision.',
            ['@courses' => Markup::create(\implode(', ', $links))]
          ));
        }
      }
    }

    // Lesson handler alterations.
    $handler = $lesson->getLessonHandlerService();
    $handler->alterLessonForm($form, $form_state);

    // Modal logic.
    $this->adaptFormToModal($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $is_new = $this->entity->isNew();
    $result = parent::save($form, $form_state);

    if ($this->modalSave($form_state)) {
      return $result;
    }

    if ($is_new) {
      $this->messenger()->addStatus($this->t('Lesson @label has been created.', [
        '@label' => $this->entity->label(),
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('Lesson @label has been updated.', [
        '@label' => $this->entity->label(),
      ]));
    }

    $form_state->setRedirect('entity.lms_lesson.collection');

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  protected function getNewRevisionDefault() {
    /** @var \Drupal\lms\Entity\LessonInterface $lesson */
    $lesson = $this->entity;
    return $lesson->createNewRevisionByDefault();
  }

}
