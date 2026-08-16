<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Url;
use Drupal\lms\Form\Modal\ModalEntitySubformTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Activity add / edit form.
 */
final class ActivityForm extends ContentEntityForm {

  use ModalEntitySubformTrait;

  /**
   * The path validator.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected PathValidatorInterface $pathValidator;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->pathValidator = $container->get('path.validator');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);
    $this->adaptFormToModal($form, $form_state);

    // Add a Cancel button returning to the destination or fallback collection.
    $destination = $this->getRequest()->query->get('destination');
    $has_destination = FALSE;
    $cancel_url = Url::fromRoute('entity.lms_activity.collection');

    if ($destination !== NULL && \str_starts_with($destination, '/')) {
      $destination_url = $this->pathValidator->getUrlIfValidWithoutAccessCheck($destination);

      if ($destination_url !== FALSE && $destination_url->getRouteName() === 'lms.group.answer_form') {
        $has_destination = TRUE;
        $cancel_url = Url::fromUserInput($destination);
      }
    }

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $cancel_url,
      '#attributes' => ['class' => ['button']],
      '#weight' => 10,
    ];

    if (!\array_key_exists('delete', $form['actions'])) {
      return $form;
    }

    // Hide Delete if returning to the frontend, and ensure it is last.
    if ($has_destination) {
      $form['actions']['delete']['#access'] = FALSE;
    }
    $form['actions']['delete']['#weight'] = 20;

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
      $this->messenger()->addStatus($this->t('Activity @label has been created.', [
        '@label' => $this->entity->label(),
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('Activity @label has been updated.', [
        '@label' => $this->entity->label(),
      ]));
    }

    $form_state->setRedirect('entity.lms_activity.collection');

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  protected function getNewRevisionDefault() {
    return TRUE;
  }

}
