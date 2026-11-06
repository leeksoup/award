<?php

declare(strict_types=1);

namespace Drupal\lms\Form\Modal;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Modal LMS reference parameters form.
 */
final class LmsReferenceParametersForm extends FormBase {

  /**
   * The entity type ID for this form.
   */
  protected string $entityTypeId;

  /**
   * The constructor.
   */
  public function __construct(
    protected EntityTypeBundleInfoInterface $entityTypeBundleInfo,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get(EntityTypeBundleInfoInterface::class)
    );
  }

  /**
   * Set entity type ID for this form.
   */
  public function setEntityTypeId(string $entity_type_id): void {
    $this->entityTypeId = $entity_type_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'lms_reference_parameters_form_' . $this->entityTypeId;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $query = $form_state->getBuildInfo()['query'];
    $form += static::getParameterFormElements($query['type']);

    // Set existing values.
    if (\array_key_exists('data_path', $query)) {
      $input = $form_state->getBuildInfo()['input'];
      $data_path = $query['data_path'];
      \array_push($data_path, 'parameters', 'json');
      $data = NestedArray::getValue($input, $data_path);
      if ($data !== NULL) {
        foreach (Json::decode($data) as $parameter => $value) {
          if (\array_key_exists($parameter, $form)) {
            // Sanitize the value.
            if ($form[$parameter]['#type'] === 'checkbox') {
              $value = (bool) $value;
            }
            elseif ($form[$parameter]['#type'] === 'number') {
              if (
                !\array_key_exists('#step', $form[$parameter]) ||
                \strpos($form[$parameter]['#step'], '.') === FALSE
              ) {
                $value = (int) $value;
              }
              else {
                $value = (float) $value;
              }
            }
            else {
              throw new \InvalidArgumentException(\sprintf('Unsupported parameter element type (%s), please add some support.', $form[$parameter]['#type']));
            }

            $form[$parameter]['#default_value'] = $value;
          }
        }
      }
    }

    // We need to modify submit element name not to have duplicate
    // data-drupal-selector in DOM (scroll issues) and add AJAX callback.
    $button_name = 'modify_' . $query['type'];
    $form['actions'] = [
      '#type' => 'actions',
      $button_name => [
        '#type' => 'button',
        '#value' => $this->t('Update'),
        '#ajax' => [
          'url' => Url::fromRoute('lms.modal_subform_endpoint'),
          'options' => [
            'query' => [
              'plugin_id' => 'lms_entity_param',
              'form' => 'parameters',
              'dialog_operation' => 'close',
            ] + $query,
          ],
          'callback' => [\get_class($this), 'ajaxSubmit'],
        ],
      ],
    ];

    return $form;
  }

  /**
   * Parameter form elements for a given entity type.
   *
   * #hide_if_falsy hides the item in the reference table when the value is
   * falsy; it is ignored by the form renderer.
   *
   * @return array<string, array<string, mixed>>
   */
  public static function getParameterFormElements(string $entity_type_id): array {
    if ($entity_type_id === 'lms_lesson') {
      return [
        'mandatory' => [
          '#type' => 'checkbox',
          '#title' => t('Mandatory'),
          '#default_value' => TRUE,
        ],
        'auto_repeat_failed' => [
          '#type' => 'checkbox',
          '#title' => t('Auto-repeat if failed'),
          '#default_value' => FALSE,
          '#description' => t('If a student does not achieve the required score on this lesson, the lesson restarts when they attempt to continue to the next one.'),
          '#hide_if_falsy' => TRUE,
        ],
        'required_score' => [
          '#type' => 'number',
          '#title' => t('Required score'),
          '#field_suffix' => '%',
          '#default_value' => 50,
          '#min' => 0,
          '#max' => 100,
          '#description' => t('The minimum percentage a student must score to pass this lesson.'),
        ],
        'time_limit' => [
          '#type' => 'number',
          '#title' => t('Time limit'),
          '#field_suffix' => t('min'),
          '#default_value' => 0,
          '#min' => 0,
          '#description' => t('After this much time has passed, the lesson is closed automatically and remaining activities are left unanswered. Set to 0 for no time limit.'),
          '#hide_if_falsy' => TRUE,
        ],
      ];
    }
    if ($entity_type_id === 'lms_activity') {
      return [
        'max_score' => [
          '#type' => 'number',
          '#title' => t('Maximum score'),
          '#default_value' => 5,
          '#min' => 0,
          '#max' => 100,
          '#description' => t('The score this activity is marked out of. Set to 0 if the activity does not require evaluation.'),
        ],
      ];
    }
    return [];
  }

  /**
   * Format a single parameter value as a complete list item string.
   */
  public static function formatParameterItem(array $def, mixed $value): string {
    if ($def['#type'] === 'checkbox') {
      $display = (bool) $value ? (string) t('Yes') : (string) t('No');
    }
    else {
      $display = (string) $value;
      if (\array_key_exists('#field_suffix', $def)) {
        $display .= ' ' . (string) $def['#field_suffix'];
      }
    }
    return (string) $def['#title'] . ': ' . $display;
  }

  /**
   * Ajax callback.
   */
  public static function ajaxSubmit(array $_form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $trigger = $form_state->getTriggeringElement();
    $query = $trigger['#ajax']['options']['query'];
    $parent = '[data-lms-selector="' . $query['selector'] . '"]';
    $values = $form_state->cleanValues()->getValues();
    $response->addCommand(new InvokeCommand($parent . ' input[type="hidden"]', 'val', [Json::encode($values)]));
    $definitions = static::getParameterFormElements($query['type']);
    foreach ($definitions as $key => $def) {
      if (!\array_key_exists($key, $values)) {
        continue;
      }
      $value = $values[$key];
      $item_selector = $parent . ' .lms-param-item--' . $key;
      $hide = (\array_key_exists('#hide_if_falsy', $def) && (bool) $def['#hide_if_falsy']) && !(bool) $value;
      $response->addCommand(new InvokeCommand($item_selector, 'html', [$hide ? '' : static::formatParameterItem($def, $value)]));
      if (\array_key_exists('#hide_if_falsy', $def) && (bool) $def['#hide_if_falsy']) {
        $response->addCommand(new InvokeCommand($item_selector, $hide ? 'hide' : 'show', []));
      }
    }
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Obsolete but required by parent.
  }

}
