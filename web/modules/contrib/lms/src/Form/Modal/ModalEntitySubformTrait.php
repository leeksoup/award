<?php

declare(strict_types=1);

namespace Drupal\lms\Form\Modal;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Url;

/**
 * Adapts LMS entity forms to modals.
 *
 * @todo Investigate if this logic can be moved to the entity modal form
 * plugin so it will work with all entity forms.
 */
trait ModalEntitySubformTrait {

  /**
   * Form alterations.
   */
  private function adaptFormToModal(array &$form, FormStateInterface $form_state): void {
    $query = self::getQuery($form_state);
    if ($query === NULL) {
      return;
    }

    // Parent query needs to be added to every element #ajax.
    self::deepMergeParentQuery($form, $query);

    // We don't want delete action in a modal edit form.
    $form['actions']['delete']['#access'] = FALSE;

    // We need to modify submit element name not to have duplicate
    // data-drupal-selector in DOM (scroll issues) and add AJAX callback.
    $submit_name = 'create_' . $this->entity->getEntityTypeId();
    $form['actions'][$submit_name] = $form['actions']['submit'];
    if ($this->entity->isNew()) {
      $form['actions'][$submit_name]['#value'] = $this->t('Create and add');
    }
    else {
      $form['actions'][$submit_name]['#value'] = $this->t('Update');
    }
    unset($form['actions']['submit']);
    $form['actions'][$submit_name]['#ajax'] = [
      'url' => Url::fromRoute('lms.modal_subform_endpoint'),
      'callback' => [\get_class($this), 'ajaxSubmit'],
      'options' => [
        'query' => [
          'plugin_id' => 'entity',
          'rebuild_parent' => TRUE,
          'dialog_operation' => 'close',
          'type' => $this->entity->getEntityTypeId(),
          'id' => $this->entity->id(),
        ] + $query,
      ],
    ];
  }

  /**
   * Ajax callback.
   */
  public static function ajaxSubmit(array $form, FormStateInterface $form_state): AjaxResponse {
    return new AjaxResponse();
  }

  /**
   * Get / set query.
   */
  private static function getQuery(FormStateInterface $form_state): ?array {
    $query = NULL;
    if ($form_state->has('modal_query')) {
      $query = $form_state->get('modal_query');
    }
    else {
      $build_info = $form_state->getBuildInfo();
      if (
        \array_key_exists('query', $build_info) &&
        // Don't modify the form if we don't have parent form data set
        // (this means the request is coming from top level form to itself
        // so it should not be altered in any way).
        \array_key_exists('parent', $build_info['query'])
      ) {
        $form_state->set('modal_query', $build_info['query']);
        $query = $build_info['query'];
      }
    }

    if ($query !== NULL && !\array_key_exists('bundle', $query)) {
      $form_object = $form_state->getFormObject();
      \assert($form_object instanceof EntityFormInterface);
      $query['bundle'] = $form_object->getEntity()->bundle();
    }

    return $query;
  }

  /**
   * Act on modal form entity save.
   */
  private function modalSave(FormStateInterface $form_state): bool {
    if (!$form_state->has('modal_query')) {
      return FALSE;
    }

    $form_state->set('reference_entity_data', [
      [
        'entity_type_id' => $this->entity->getEntityTypeId(),
        'entity_id' => $this->entity->id(),
        'bundle' => $this->entity->bundle(),
        'label' => $this->entity->label(),
      ],
    ]);
    return TRUE;
  }

  /**
   * Merge parent query into element and all its children elements.
   */
  private static function deepMergeParentQuery(array &$element, array $query, bool $processing = FALSE): void {
    // If an element has process callback, ajax can be added there (example:
    // managed_file). We need to process after that happens.
    if (
      !$processing &&
      \array_key_exists('#process', $element) &&
      $element['#process'][0] !== '::processForm'
    ) {
      $element['#process'][] = [self::class, 'processElement'];
    }
    elseif (
      \array_key_exists('#ajax', $element) &&
      !\array_key_exists('url', $element['#ajax'])
    ) {
      $element['#ajax']['url'] = Url::fromRoute('lms.modal_subform_endpoint');
      if (!\array_key_exists('options', $element['#ajax'])) {
        $element['#ajax']['options'] = [];
      }
      if (!\array_key_exists('query', $element['#ajax']['options'])) {
        $element['#ajax']['options']['query'] = [];
      }
      $element['#ajax']['options']['query'] += $query;
      $element['#ajax']['options']['query']['plugin_id'] = 'entity';
    }
    foreach (Element::children($element) as $child_key) {
      self::deepMergeParentQuery($element[$child_key], $query);
    }
  }

  /**
   * Element process callback.
   */
  public static function processElement(array &$element, FormStateInterface $form_state): array {
    $query = self::getQuery($form_state);
    self::deepMergeParentQuery($element, $query, TRUE);
    return $element;
  }

}
