<?php

declare(strict_types=1);

namespace Drupal\lms_answer_comments\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\comment\Entity\Comment;
use Drupal\lms\Entity\AnswerInterface;
use Drupal\lms_answer_comments\Hook\AnswerCommentsHooks;

/**
 * LMS Answer comment form builder class.
 */
final class AnswerCommentForm implements FormInterface {

  private const FORM_SELECTOR = 'answer-comment-form';

  /**
   * OC.
   */
  public function __construct(
    protected readonly AnswerInterface $answer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'answer_comment_form';
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // Not needed but required by the interface.
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Not needed but required by the interface.
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attributes']['data-lms-selector'] = self::FORM_SELECTOR;

    $form['messages'] = [
      '#theme' => 'status_messages',
      '#status_headings' => [
        'error' => new TranslatableMarkup('Error message'),
        'warning' => new TranslatableMarkup('Warning message'),
      ],
      '#message_list' => [],
      '#weight' => -100,
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => new TranslatableMarkup('Comment'),
        '#submit' => [],
      ],
    ];
    if ($form_state->get('use_ajax') === TRUE) {
      $form['actions']['submit']['#ajax'] = [
        'callback' => [self::class, 'closeModal'],
      ];
    }
    $form['#validate'] = [];

    self::setFormFields($form, $form_state);

    return $form;
  }

  /**
   * Prepare comment entity.
   */
  private static function prepareComment(string $answer_id): Comment {
    return Comment::create([
      'comment_type' => AnswerCommentsHooks::COMMENT_TYPE,
      'entity_id' => $answer_id,
      'entity_type' => 'lms_answer',
      'field_name' => AnswerCommentsHooks::FIELD_NAME,
      'status' => TRUE,
      'uid' => \Drupal::currentUser()->id(),
    ]);
  }

  /**
   * Set Answer comment form fields.
   */
  public static function setFormFields(array &$form, FormStateInterface $form_state): void {
    $form['actions']['submit']['#submit'][] = [self::class, 'saveComment'];
    $form['#validate'][] = [self::class, 'validateComment'];
    $form['comment'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['comment-form-wrapper']],
      '#parents' => ['comment'],
      '#tree' => TRUE,
    ];

    /** @var \Drupal\Core\Entity\EntityFormInterface */
    $form_object = $form_state->getFormObject();
    $comment = self::prepareComment($form_object->getEntity()->id());
    $display = EntityFormDisplay::collectRenderDisplay($comment, 'default');
    $display->buildForm($comment, $form['comment'], $form_state);
  }

  /**
   * Page update callback.
   */
  public static function closeModal(array $form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    // Display errors if any.
    if ($form_state::hasAnyErrors()) {
      $form['messages']['#message_list'] = \Drupal::messenger()->all();
      \Drupal::messenger()->deleteAll();
      $selector = '[data-lms-selector="' . self::FORM_SELECTOR . '"]';
      $response->addCommand(new ReplaceCommand($selector, $form));
      return $response;
    }

    $response->addCommand(new CloseModalDialogCommand());
    return $response;
  }

  /**
   * Answer getter.
   */
  public function getEntity(): AnswerInterface {
    return $this->answer;
  }

  /**
   * Static validate handler to be reused in answer evaluation form.
   */
  public static function validateComment(array $form, FormStateInterface $form_state): void {
    /** @var \Drupal\Core\Entity\EntityFormInterface */
    $form_object = $form_state->getFormObject();
    $comment = self::prepareComment($form_object->getEntity()->id());
    $display = EntityFormDisplay::collectRenderDisplay($comment, 'default');
    $input_fields = $display->extractFormValues($comment, $form['comment'], $form_state);

    $comment_empty = TRUE;
    unset($input_fields['langcode']);
    foreach ($input_fields as $field) {
      if (!$comment->get($field)->isEmpty()) {
        $comment_empty = FALSE;
        break;
      }
    }

    if ($comment_empty) {
      return;
    }

    // Set comment subject before validation.
    if ($comment->get('subject')->isEmpty()) {
      /** @var \Drupal\Core\Entity\EntityFormInterface */
      $form_object = $form_state->getFormObject();

      $answer = $form_object->getEntity();
      \assert($answer instanceof AnswerInterface);
      $subject = $answer->label();
      if (\strlen($subject) > 64) {
        $subject = \substr($subject, 0, 62) . '..';
      }
      $comment->set('subject', $subject);
    }

    $violations = $comment->validate();
    if ($violations->count() !== 0) {
      // Stolen from Drupal\Core\Entity\ContentEntityForm::flagViolations().
      // Unfortunately we cannot just extend CommentForm here as this logic
      // is used in 2 different forms.
      /** @var \Symfony\Component\Validator\ConstraintViolationInterface $violation */
      foreach ($violations->getEntityViolations() as $violation) {
        $form_state->setErrorByName(\str_replace('.', '][', $violation->getPropertyPath()), $violation->getMessage());
      }
      $display->flagWidgetsErrorsFromViolations($violations, $form, $form_state);

      // Set a general error message in case a violation is not an entity
      // violation and the widget is not displayed on the form.
      // @see Drupal\Core\Entity\Entity\EntityFormDisplay::flagWidgetsErrorsFromViolations().
      if (!$form_state::hasAnyErrors()) {
        $messages = [];
        foreach ($violations as $violation) {
          $invalid_value = $violation->getInvalidValue();
          if ($invalid_value instanceof FieldItemListInterface) {
            $messages[] = $invalid_value->getFieldDefinition()->getLabel() . ': ' . $violation->getMessage();
          }
          else {
            $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
          }
        }
        $form_state->setErrorByName('comment', \implode(', ', $messages));
      }
      return;
    }

    $form_state->set('comment', $comment);
  }

  /**
   * Save comment submit handler.
   */
  public static function saveComment(array $form, FormStateInterface $form_state): void {
    $comment = $form_state->get('comment');
    if ($comment === NULL) {
      return;
    }

    $comment->save();
  }

}
