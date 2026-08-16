<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\Field\FieldWidget;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\EntityReferenceAutocompleteWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lms\Entity\Activity;
use Drupal\lms\Entity\ActivityType;
use Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem;

/**
 * Plugin implementation of the 'entity_reference_autocomplete' widget.
 */
#[FieldWidget(
  id: 'lms_reference',
  label: new TranslatableMarkup('LMS reference'),
  description: new TranslatableMarkup('Entity autocomplete with additional data.'),
  field_types: ['lms_reference'],
)]
final class LMSReferenceItemWidget extends EntityReferenceAutocompleteWidget {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    $element['data'] = [
      '#type' => 'details',
      '#title' => $this->t('Parameters'),
    ];

    if (!$items->offsetExists($delta)) {
      $data = [];
    }
    else {
      $item = $items->get($delta);
      \assert($item instanceof LMSReferenceItem);
      $data = $item->get('data')->getValue();
      if ($data === NULL) {
        $data = [];
      }
    }

    $element['target_id']['#title_display'] = 'before';

    $target_type = $this->fieldDefinition->getSetting('target_type');
    if ($target_type === 'lms_activity') {
      $this->setActivityDataElements($element);
    }
    elseif ($target_type === 'lms_lesson') {
      $this->setLessonDataElements($element);
    }

    // Apply default values.
    foreach (\array_keys($element['data']) as $sub_property) {
      if (\array_key_exists($sub_property, $data)) {
        $element['data'][$sub_property]['#default_value'] = $data[$sub_property];
      }
    }

    $element['data']['#tree'] = TRUE;
    $element['data']['#weight'] = 100;

    return $element;
  }

  /**
   * LMS Activity data elements.
   *
   * @param mixed[] $element
   *   Form element.
   */
  private function setActivityDataElements(array &$element): void {
    $element['target_id']['#title'] = $this->t('Activity');
    $element['data']['max_score'] = [
      '#type' => 'number',
      '#min' => 0,
      '#max' => 100,
      '#title' => $this->t('Maximum score'),
      '#description' => $this->t('Setting this value to 0 will set all answers to the activity as evaluated.'),
      '#default_value' => 5,
    ];
    $element['target_id']['#ajax'] = [
      'callback' => [static::class, 'updateMaxScore'],
      'event' => 'autocompleteclose',
    ];
  }

  /**
   * LMS Lesson data elements.
   *
   * @param mixed[] $element
   *   Form element.
   */
  private function setLessonDataElements(array &$element): void {
    $element['target_id']['#title'] = $this->t('Lesson');
    $element['data']['mandatory'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Mandatory'),
      '#default_value' => TRUE,
    ];
    $element['data']['auto_repeat_failed'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-repeat if failed'),
      '#description' => $this->t("If a student didn't get the score required to pass this lesson, it will be restarted when trying to navigate to the next one."),
      '#default_value' => FALSE,
    ];
    $element['data']['required_score'] = [
      '#type' => 'number',
      '#min' => 0,
      '#max' => 100,
      '#title' => $this->t('Required score [%]'),
      '#default_value' => 50,
    ];
    $element['data']['time_limit'] = [
      '#type' => 'number',
      '#min' => 0,
      '#title' => $this->t('Time limit [min]'),
      '#description' => $this->t('After this much time has passed, the lesson will be finished with all remaining activities unanswered.'),
      '#default_value' => 0,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $values = parent::massageFormValues($values, $form, $form_state);
    return $values;
  }

  /**
   * Update max score value on new elements.
   */
  public static function updateMaxScore(array $form, FormStateInterface $form_state): ?AjaxResponse {
    $trigger = $form_state->getTriggeringElement();
    $entity_id = EntityAutocomplete::extractEntityIdFromAutocompleteInput($trigger['#value']);
    if ($entity_id === NULL) {
      return NULL;
    }
    $entity = Activity::load($entity_id);
    if ($entity === NULL) {
      return NULL;
    }

    // Value.
    $activity_type = ActivityType::load($entity->bundle());
    $default_max_score = $activity_type->getDefaultMaxScore();

    // Selector.
    $parents = $trigger['#parents'];
    \array_pop($parents);
    $parents[] = 'data';
    $parents[] = 'max_score';
    $selector = 'input[name="' . \array_shift($parents) . '[' . \implode('][', $parents) . ']"]';

    // Command.
    $response = new AjaxResponse();
    $response->addCommand(new InvokeCommand($selector, 'val', [$default_max_score]));
    return $response;
  }

}
