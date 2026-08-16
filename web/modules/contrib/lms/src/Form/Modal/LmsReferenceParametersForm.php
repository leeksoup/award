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
  private string $entityTypeId;

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
      $container->get('entity_type.bundle.info')
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
    if ($query['type'] === 'lms_lesson') {
      $this->setLessonDataElements($form);
    }
    elseif ($query['type'] === 'lms_activity') {
      $this->setActivityDataElements($form);
    }

    // Set existing values.
    if (\array_key_exists('data_path', $query)) {
      $input = $form_state->getBuildInfo()['input'];
      $data_path = $query['data_path'];
      \array_push($data_path, 'actions', 'parameters');
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
   * Ajax callback.
   */
  public static function ajaxSubmit(array $form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $trigger = $form_state->getTriggeringElement();
    $selector_value = $trigger['#ajax']['options']['query']['selector'];
    $selector = '[data-lms-selector="' . $selector_value . '"]';
    $values = $form_state->cleanValues()->getValues();
    $response->addCommand(new InvokeCommand($selector, 'val', [Json::encode($values)]));
    return $response;
  }

  /**
   * LMS Lesson data elements.
   *
   * @param mixed[] $element
   *   Form element.
   */
  private function setLessonDataElements(array &$element): void {
    $element['mandatory'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Mandatory'),
      '#default_value' => TRUE,
    ];
    $element['auto_repeat_failed'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-repeat if failed'),
      '#description' => $this->t("If a student didn't get the score required to pass this lesson, it will be restarted when trying to navigate to the next one."),
      '#default_value' => FALSE,
    ];
    $element['required_score'] = [
      '#type' => 'number',
      '#min' => 0,
      '#max' => 100,
      '#title' => $this->t('Required score [%]'),
      '#default_value' => 50,
    ];
    $element['time_limit'] = [
      '#type' => 'number',
      '#min' => 0,
      '#title' => $this->t('Time limit [min]'),
      '#description' => $this->t('After this much time has passed, the lesson will be finished with all remaining activities unanswered.'),
      '#default_value' => 0,
    ];
  }

  /**
   * LMS Activity data elements.
   *
   * @param mixed[] $element
   *   Form element.
   */
  private function setActivityDataElements(array &$element): void {
    $element['max_score'] = [
      '#type' => 'number',
      '#min' => 0,
      '#max' => 100,
      '#title' => $this->t('Maximum score'),
      '#description' => $this->t('Setting this value to 0 will set all answers to the activity as evaluated.'),
      '#default_value' => 5,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Obsolete but required by parent.
  }

}
