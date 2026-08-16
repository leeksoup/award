<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\views\field;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\Render\ViewsRenderPipelineMarkup;
use Drupal\views\ResultRow;

/**
 * Field handler to display selection checkboxes.
 *
 * @ingroup views_field_handlers
 */
#[ViewsField(id: 'lms_entity_selection')]
final class LmsEntitySelection extends FieldPluginBase {

  /**
   * Returns the ID for a result row.
   *
   * @param \Drupal\views\ResultRow $row
   *   The result row.
   *
   * @return string
   *   The row ID, in the form ENTITY_TYPE:ENTITY_ID.
   */
  public function getRowId(ResultRow $row) {
    return $this->getEntity($row)->id();
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    return ViewsRenderPipelineMarkup::create($this->getValue($values));
  }

  /**
   * {@inheritdoc}
   */
  public function getValue(ResultRow $row, $field = NULL) {
    return '<!--form-item-' . $this->options['id'] . '--' . $this->getEntity($row)->id() . '-->';
  }

  /**
   * Return entity ID from a views result row.
   *
   * @see \Drupal\views\Form\ViewsFormMainForm
   */
  // phpcs:ignore
  public function form_element_row_id(int $row_id): string {
    return $this->getEntity($this->view->result[$row_id])->id();
  }

  /**
   * Form builder.
   */
  public function viewsForm(array &$form, FormStateInterface $form_state): void {
    if (\count($this->view->result) === 0) {
      return;
    }

    $form[$this->options['id']]['#tree'] = TRUE;
    $form[$this->options['id']]['#printed'] = TRUE;

    foreach ($this->view->result as $row) {
      $entity = $this->getEntity($row);

      $form[$this->options['id']][$entity->id()] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Select this item'),
        '#title_display' => 'invisible',
        '#return_value' => \implode(':', [
          $entity->getEntityTypeId(),
          $entity->bundle(),
          $entity->id(),
        ]),
        '#default_value' => NULL,
      ];
    }

    unset($form['actions']['submit']);

    // Normally we should throw an exception in the following cases but
    // views admin UI is broken otherwise as preview doesn't have that data.
    if (\count($this->view->args) === 0) {
      return;
    }
    $query = Json::decode(\end($this->view->args));
    if (!\array_key_exists('type', $query)) {
      return;
    }

    $button_name = 'add_references_' . $query['type'];
    $form['actions'][$button_name] = [
      '#type' => 'button',
      '#value' => $this->t('Add references'),
      // Class for styling and automated tests.
      '#attributes' => ['class' => ['lms-add-references']],
      '#ajax' => [
        'url' => Url::fromRoute('lms.modal_subform_endpoint'),
        'options' => [
          'query' => [
            'plugin_id' => 'view',
            'dialog_operation' => 'close',
            'rebuild_parent' => TRUE,
          ] + $query,
        ],
        'callback' => [\get_class($this), 'ajaxSubmit'],
      ],
    ];
  }

  /**
   * Ajax callback.
   */
  public static function ajaxSubmit(array $form, FormStateInterface $form_state): AjaxResponse {
    // All is handled by the parent form.
    return new AjaxResponse();
  }

  /**
   * {@inheritdoc}
   */
  public function clickSortable() {
    return FALSE;
  }

}
