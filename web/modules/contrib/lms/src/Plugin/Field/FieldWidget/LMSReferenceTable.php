<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\Field\FieldWidget;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\lms\Entity\ActivityTypeInterface;
use Drupal\lms\Entity\Handlers\LmsEntityAccessControlHandlerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'lms_reference_table' widget.
 */
#[FieldWidget(
  id: 'lms_reference_table',
  label: new TranslatableMarkup('LMS reference table'),
  description: new TranslatableMarkup('Improved LMS entity reference widget.'),
  field_types: ['lms_reference'],
  multiple_values: TRUE,
)]
final class LMSReferenceTable extends WidgetBase {

  private const TABLEDRAG_CLASS = 'lms-items-order-weight';

  /**
   * Constructor.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    array $third_party_settings,
    protected EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'view_data' => '',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('view')->loadMultiple() as $view_id => $view) {
      $candidate_displays = [];
      $matching_displays = [];
      foreach ($view->get('display') as $display_id => $display) {
        if (
          \array_key_exists('display_options', $display) &&
          \array_key_exists('fields', $display['display_options'])) {
          foreach ($display['display_options']['fields'] as $field) {
            if ($field['plugin_id'] === 'lms_entity_selection') {
              $matching_displays[$display_id] = $display;
              continue;
            }
          }
        }
        if (
          \array_key_exists('defaults', $display) &&
          (
            !\array_key_exists('fields', $display['defaults']) ||
            $display['defaults']['fields'] === TRUE
          )
        ) {
          $candidate_displays[$display_id] = $display;
        }
      }

      if (\count($matching_displays) !== 0) {
        foreach ($matching_displays as $display_id => $display) {
          $options[$view_id . '.' . $display_id] = $this->t('@view : @display', [
            '@view' => $view->label(),
            '@display' => $display['display_title'],
          ]);
        }
        if (\count($candidate_displays) !== 0 && \array_key_exists('default', $matching_displays)) {
          foreach ($candidate_displays as $display_id => $display) {
            $options[$view_id . '.' . $display_id] = $this->t('@view : @display', [
              '@view' => $view->label(),
              '@display' => $display['display_title'],
            ]);
          }
        }
      }
    }
    if (\count($options) === 0) {
      $form['info'] = [
        '#theme_wrappers' => ['container' => []],
        '#markup' => $this->t('There are no views with the "LMS selection for adding to parent" field. Please create one or add this field to an existing view.'),
      ];
      return $form;
    }

    $form['view_data'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity selection view'),
      '#options' => $options,
      '#default_value' => $this->getSetting('view_data'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $view_data = $this->getSetting('view_data');
    if ($view_data === NULL || $view_data === '') {
      return [$this->t('No view selected.')];
    }
    [$view_id, $display_id] = \explode('.', $view_data);
    $view = $this->entityTypeManager->getStorage('view')->load($view_id);
    if ($view === NULL) {
      return [$this->t('Invalid view selected.')];
    }

    // If display doesn't exist, this method will result in a syntax error.
    $display = $view->getDisplay($display_id);

    return [
      $this->t('Selected view: @view (@display)', [
        '@view' => $view->label(),
        '@display' => $display['display_title'],
      ]),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $field_state = static::getWidgetState($element['#field_parents'], $field_name, $form_state);
    $target_type = $this->fieldDefinition->getSetting('target_type');

    $label = $target_type === 'lms_lesson' ? $this->t('lesson') : $this->t('activity');
    $label_plural = $target_type === 'lms_lesson' ? $this->t('lessons') : $this->t('activities');

    // Standard form_element theme wrapper doesn't work well for a table widget.
    // We also cannot add multiple header rows to a table element so..
    $element['#theme_wrappers'] = ['fieldset'];
    unset($element['#description']);

    $table = [
      '#type' => 'table',
      '#header' => [
        'title' => $this->t('Title'),
        'type' => $this->t('Type'),
        'actions' => $this->t('Actions'),
        'weight' => $this->t('Weight'),
      ],
      '#tableselect' => FALSE,
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => self::TABLEDRAG_CLASS,
        ],
      ],
      '#empty' => $this->t('No referenced @label_plural.', ['@label_plural' => $label_plural]),
      '#attributes' => [
        'data-lms-selector' => 'lms-reference-table-' . \strtr($target_type, ['_' => '-']),
      ],
    ];

    $bundle_info = $this->entityTypeBundleInfo->getBundleInfo($target_type);
    if (\count($bundle_info) === 1) {
      unset($table['#header']['type']);
    }

    if (!\array_key_exists('references', $field_state)) {
      $field_state['references'] = [];
      foreach ($items as $item_delta => $item) {
        $entity = $item->entity;
        $id = $entity->id();
        $field_state['references'][$id] = [
          'weight' => $item_delta,
          'label' => $entity->label(),
          'bundle' => $entity->bundle(),
          'parameters' => Json::encode($item->data),
          'access' => $entity->access('edit', $this->currentUser),
        ];
      }
    }

    // Adjust weights from submitted values and remove elements not present
    // in input.
    $path = $element['#field_parents'];
    $path[] = $field_name;
    $form_state->cleanValues();
    $element_values = $form_state->getValue($path);
    if (\is_array($element_values) && \is_array($element_values['table'])) {
      $ordered_references = [];
      foreach ($element_values['table'] as $entity_id => $row_values) {
        if (\array_key_exists($entity_id, $field_state['references'])) {
          $ordered_references[$entity_id] = $field_state['references'][$entity_id];
          $ordered_references[$entity_id]['weight'] = $row_values['weight'];
        }
      }
      foreach (\array_keys($ordered_references) as $entity_id) {
        if (!\array_key_exists($entity_id, $element_values['table'])) {
          unset($ordered_references[$entity_id]);
        }
      }

      $field_state['references'] = $ordered_references;
    }

    // If modal form state is present in rebuild info, we need to adjust
    // references.
    $rebuild_info = $form_state->getRebuildInfo();
    $reference_data = $rebuild_info['modal_form_data']['reference_entity_data'] ?? NULL;
    if ($reference_data !== NULL) {
      $this->mergeReferences($target_type, $reference_data, $field_state);
    }

    static::setWidgetState($element['#field_parents'], $field_name, $form_state, $field_state);

    $entity = $items->getEntity();

    $parent_query = [
      'plugin_id' => 'entity',
      'type' => $entity->getEntityTypeId(),
      'bundle' => $entity->bundle(),
      'entity_id' => $entity->id(),
    ];

    foreach ($field_state['references'] as $entity_id => $reference) {
      $element_path = $path;
      \array_push($element_path, ...['table', $entity_id]);
      $parameters_selector = 'lms-parameters-' . \strtr($target_type, '_', '-') . '-' . $entity_id;

      $table[$entity_id] = [
        '#attributes' => ['class' => ['draggable']],
        '#weight' => $reference['weight'],
        'title' => [
          '#markup' => $reference['label'],
        ],
        'type' => [
          '#markup' => $bundle_info[$reference['bundle']]['label'],
        ],
        'actions' => [
          'parameters' => [
            '#type' => 'hidden',
            '#default_value' => $reference['parameters'],
            '#attributes' => ['data-lms-selector' => $parameters_selector],
          ],
          'edit_parameters' => [
            '#type' => 'button',
            '#value' => $this->t('Modify parameters'),
            '#ajax' => [
              'url' => Url::fromRoute('lms.modal_subform_endpoint'),
              'options' => [
                'query' => [
                  'plugin_id' => 'lms_entity_param',
                  'dialog_operation' => 'open',
                  'selector' => $parameters_selector,
                  'data_path' => $element_path,
                  'type' => $target_type,
                  'entity_id' => $entity_id,
                  'form' => 'parameters',
                ],
              ],
            ],
          ],
          'edit_entity' => [
            '#type' => 'button',
            '#value' => $this->t('Edit'),
            '#ajax' => [
              'url' => Url::fromRoute('lms.modal_subform_endpoint'),
              'options' => [
                'query' => [
                  'plugin_id' => 'entity',
                  'dialog_operation' => 'open',
                  'type' => $target_type,
                  'bundle' => $reference['bundle'],
                  'entity_id' => $entity_id,
                  'parent' => $parent_query,
                ],
              ],
              'callback' => [\get_class($this), 'referenceCreationAjax'],
              '#element_parents' => $path,
            ],
            '#access' => $reference['access'],
          ],
          'remove_reference' => [
            '#type' => 'submit',
            '#value' => $this->t('Remove'),
            '#submit' => [[$this, 'removeReference']],
            '#name' => \implode('-', ['remove', $target_type, $entity_id]),
            '#ajax' => [
              'url' => Url::fromRoute('lms.modal_subform_endpoint'),
              'options' => [
                'query' => $parent_query,
              ],
              'callback' => [\get_class($this), 'removeItemAjax'],
              '#element_parents' => $path,
            ],
          ],
        ],
        'weight' => [
          '#type' => 'weight',
          '#title' => $this->t('Weight for @title', [
            '@title' => $reference['label'],
          ]),
          '#title_display' => 'invisible',
          '#default_value' => $reference['weight'],
          '#attributes' => ['class' => [self::TABLEDRAG_CLASS]],
        ],
      ];

      if (\count($bundle_info) === 1) {
        unset($table[$entity_id]['type']);
      }
    }
    $element['table'] = $table;

    $element['create_item'] = [
      '#type' => 'button',
      '#value' => $this->t('Create @label', [
        '@label' => $label,
      ]),
      '#ajax' => [
        'url' => Url::fromRoute('lms.modal_subform_endpoint'),
        'options' => [
          'query' => [
            'dialog_operation' => 'open',
            'type' => $target_type,
            'parent' => $parent_query,
          ],
        ],
        'callback' => [\get_class($this), 'referenceCreationAjax'],
        '#element_parents' => $path,
      ],
      '#access' => $this->currentUser->hasPermission(LmsEntityAccessControlHandlerBase::getPermission('create', $target_type)),
    ];

    if (\count($bundle_info) === 1) {
      $element['create_item']['#ajax']['options']['query']['plugin_id'] = 'entity';
      $element['create_item']['#ajax']['options']['query']['bundle'] = \array_keys($bundle_info)[0];
    }
    else {
      $element['create_item']['#ajax']['options']['query']['plugin_id'] = 'lms_entity_param';
      $element['create_item']['#ajax']['options']['query']['form'] = 'bundle';
    }

    $element['reference_item'] = [
      '#type' => 'button',
      '#value' => $this->t('Reference @label', [
        '@label' => $label_plural,
      ]),
      '#ajax' => [
        'url' => Url::fromRoute('lms.modal_subform_endpoint'),
        'options' => [
          'query' => [
            'plugin_id' => 'view',
            'dialog_operation' => 'open',
            'type' => $target_type,
            'parent' => $parent_query,
          ],
        ],
        'callback' => [\get_class($this), 'referenceCreationAjax'],
        '#element_parents' => $path,
      ],
      '#access' => FALSE,
    ];
    if (
      $this->currentUser->hasPermission(LmsEntityAccessControlHandlerBase::getPermission('create', $target_type)) ||
      $this->currentUser->hasPermission(LmsEntityAccessControlHandlerBase::getPermission('use any', $target_type)) ||
      $this->currentUser->hasPermission(LmsEntityAccessControlHandlerBase::getPermission('edit', $target_type))
    ) {
      $element['reference_item']['#access'] = TRUE;
    }

    $view_data = $this->getSetting('view_data');
    if ($view_data === NULL || $view_data === '') {
      $element['reference_item']['#disabled'] = TRUE;
    }
    else {
      $element['reference_item']['#ajax']['options']['query'] += \array_combine(['view_id', 'display_id'], \explode('.', $view_data));
    }

    $element['#attached']['library'] = [
      'core/drupal.dialog.ajax',
      'lms/dialog-fix',
    ];

    return $element;
  }

  /**
   * Add a reference.
   */
  private function mergeReferences(string $entity_type_id, array $data, array &$field_state): void {
    $weight = 0;
    foreach ($field_state['references'] as $reference) {
      if ($reference['weight'] >= $weight) {
        $weight = $reference['weight'];
      }
    }

    foreach ($data as $item) {
      $entity_id = $item['entity_id'];
      unset($item['entity_id']);
      // If we're modifying an existing item, the only thing subject to
      // change is its label.
      if (\array_key_exists($entity_id, $field_state['references'])) {
        $field_state['references'][$entity_id]['label'] = $item['label'];
        continue;
      }

      $item['access'] = $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id)->access('edit', $this->currentUser);

      $weight++;
      $field_state['references'][$entity_id] = [
        'weight' => $weight,
      ] + $item;
      $field_state['references'][$entity_id]['parameters'] = Json::encode($this->getDefaultParameterValues($entity_type_id, $item['bundle']));
      $field_state['items_count']++;
    }
  }

  /**
   * Modal form submit AJAX callback - adding references.
   */
  public static function referenceCreationAjax(array $form, FormStateInterface $form_state): array {
    $trigger = $form_state->getTriggeringElement();
    $element_parents = $trigger['#ajax']['#element_parents'];
    $element = NestedArray::getValue($form, $element_parents);
    $selector = '[data-drupal-selector="' . $element['#attributes']['data-drupal-selector'] . '"]';
    return [new ReplaceCommand($selector, $element)];
  }

  /**
   * Remove reference.
   */
  public function removeReference(array $form, FormStateInterface $form_state): void {
    $form_state->setRebuild();
    $trigger = $form_state->getTriggeringElement();
    $entity_id = $trigger['#array_parents'][\count($trigger['#array_parents']) - 3];
    $parents = $trigger['#ajax']['#element_parents'];
    $field_name = \array_pop($parents);
    $field_state = static::getWidgetState($parents, $field_name, $form_state);
    unset($field_state['references'][$entity_id]);
    $field_state['items_count']--;
    static::setWidgetState($parents, $field_name, $form_state, $field_state);
  }

  /**
   * Standard AJAX callback - removing references.
   */
  public static function removeItemAjax(array $form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $trigger = $form_state->getTriggeringElement();
    $row_path = $trigger['#parents'];
    $row_path = \array_slice($row_path, 0, -2);
    $drupal_selector = implode('-', ['edit', ...$row_path]);
    $selector = '[data-drupal-selector="' . $drupal_selector . '"]';
    $response->addCommand(new RemoveCommand($selector));
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $item_values = [];
    if (!\is_array($values['table'])) {
      return $item_values;
    }
    foreach ($values['table'] as $entity_id => $item) {
      $data = $item['actions']['parameters'];
      if (\is_string($data)) {
        $data = Json::decode($item['actions']['parameters']);
      }
      else {
        $target_type = $this->fieldDefinition->getSetting('target_type');
        $data = $this->getDefaultParameterValues($target_type);
      }
      $item_values[] = [
        'target_id' => $entity_id,
        'data' => $data,
      ];
    }
    return $item_values;
  }

  /**
   * Default data values.
   *
   * @todo Make that all configurable?
   */
  private function getDefaultParameterValues(string $entity_type_id, ?string $bundle = NULL): array {
    $defaults = [];
    if ($entity_type_id === 'lms_activity') {
      $max_score = 5;
      // Get max score from bundle.
      if ($bundle !== NULL) {
        $activity_type = $this->entityTypeManager->getStorage('lms_activity_type')->load($bundle);
        if ($activity_type instanceof ActivityTypeInterface) {
          $max_score = $activity_type->getDefaultMaxScore();
        }
      }
      $defaults = [
        'max_score' => $max_score,
      ];
    }
    elseif ($entity_type_id === 'lms_lesson') {
      $defaults = [
        'mandatory' => TRUE,
        'auto_repeat_failed' => FALSE,
        'required_score' => 50,
        'time_limit' => 0,
      ];
    }

    return $defaults;
  }

}
