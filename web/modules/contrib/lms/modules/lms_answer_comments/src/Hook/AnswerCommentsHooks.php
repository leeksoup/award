<?php

declare(strict_types=1);

namespace Drupal\lms_answer_comments\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\comment\Plugin\Field\FieldType\CommentItemInterface;
use Drupal\lms\Entity\AnswerInterface;
use Drupal\lms_answer_comments\Form\AnswerCommentForm;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;

/**
 * Contains answer comment hooks.
 */
final class AnswerCommentsHooks {

  use StringTranslationTrait;

  public const COMMENT_TYPE = 'lms_answer_comment';
  public const FIELD_NAME = 'comments';

  public function __construct(
    #[Autowire(service: AccountInterface::class, lazy: TRUE)]
    protected readonly AccountInterface $currentUser,
    #[AutowireCallable(service: FormBuilderInterface::class, method: 'buildForm', lazy: TRUE)]
    protected \Closure $buildForm,
  ) {}

  #[Hook('entity_base_field_info')]
  public function entityBaseFieldInfo(EntityTypeInterface $entity_type): array {
    $fields = [];
    if ($entity_type->id() === 'lms_answer') {
      $fields[self::FIELD_NAME] = BaseFieldDefinition::create('comment')
        ->setSetting('comment_type', self::COMMENT_TYPE)
        ->setSetting('preview', DRUPAL_DISABLED)
        ->setDisplayConfigurable('view', TRUE)
        // Set commenting to closed as we need to implement our own UI.
        ->setDefaultValue(CommentItemInterface::CLOSED)
        ->setDisplayOptions('view', [
          'type' => 'comment_default',
          'label' => 'above',
        ]);
    }

    return $fields;
  }

  #[Hook('lms_answer_details_alter')]
  public function lmsAnswerDetailsAlter(array &$build, AnswerInterface $answer, CacheableMetadata $cacheability): void {
    // Add comment form if we don't already have the evaluation form.
    if (\array_key_exists('evaluation_form', $build)) {
      return;
    }
    if (!$this->currentUser->hasPermission('post comments')) {
      return;
    }

    $form_object = new AnswerCommentForm($answer);
    $form_state = (new FormState())->setFormState([
      'use_ajax' => $build['#use_ajax'],
    ]);
    $build['comment_form'] = ($this->buildForm)($form_object, $form_state);
  }

  #[Hook('form_lms_answer_evaluate_form_alter')]
  public function answerEvaluateFormAlter(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser->hasPermission('post comments')) {
      return;
    }

    AnswerCommentForm::setFormFields($form, $form_state);

    // Allow commenting without scoring.
    $form['score']['#required'] = FALSE;
    $form['actions']['submit']['#value'] = $this->t('Evaluate / comment');
  }

}
