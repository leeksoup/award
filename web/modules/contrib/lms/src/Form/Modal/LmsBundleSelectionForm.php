<?php

declare(strict_types=1);

namespace Drupal\lms\Form\Modal;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Modal LMS entity bundle selection form.
 */
final class LmsBundleSelectionForm extends FormBase {

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
    return 'lms_bundle_selection_form_' . $this->entityTypeId;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $query = $form_state->getBuildInfo()['query'];
    $bundle_info = $this->entityTypeBundleInfo->getBundleInfo($query['type']);
    if (\count($bundle_info) === 1) {
      return [];
    }

    $bundle_options = [];
    foreach ($bundle_info as $bundle_id => $data) {
      $bundle_options[$bundle_id] = $data['label'];
    }

    $query['plugin_id'] = 'entity';
    $query['dialog_operation'] = 'replace';
    $form['bundle'] = [
      '#type' => 'radios',
      '#title' => $this->t('Bundle'),
      '#options' => $bundle_options,
      '#ajax' => [
        'url' => Url::fromRoute('lms.modal_subform_endpoint'),
        'options' => [
          'query' => $query,
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Obsolete but required by parent.
  }

}
