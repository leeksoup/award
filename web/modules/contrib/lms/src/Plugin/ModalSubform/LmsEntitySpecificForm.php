<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\ModalSubform;

use Drupal\Core\Form\FormInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lms\Attribute\ModalSubform;
use Drupal\lms\Form\Modal\LmsBundleSelectionForm;
use Drupal\lms\Form\Modal\LmsReferenceParametersForm;

/**
 * Modal LMS entity bundle selection form.
 */
#[ModalSubform(
  id: 'lms_entity_param',
)]
final class LmsEntitySpecificForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  protected function validateConfiguration(array $configuration): void {
    foreach (['type', 'form'] as $parameter) {
      if (
        !\array_key_exists($parameter, $configuration) ||
        !\is_string($configuration[$parameter])
      ) {
        throw new \InvalidArgumentException(\sprintf('%s parameter missing or invalid.', $parameter));
      }
    }

    if ($configuration['form'] === 'parameters') {
      parent::validateConfiguration($configuration);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $currentUser): bool {
    // Admin permission.
    if ($currentUser->hasPermission('administer lms')) {
      return TRUE;
    }

    // Bundle selection - needs create access.
    if ($this->configuration['form'] === 'bundle') {
      return $currentUser->hasPermission(\sprintf('create %s entities', $this->configuration['type']));
    }

    // Parameters form - edit access.
    elseif ($this->configuration['form'] === 'parameters') {
      return parent::access($currentUser);
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle(): TranslatableMarkup {
    $entity_type = $this->entityTypeManager->getDefinition($this->configuration['type']);
    $label = $entity_type->getLabel();
    if ($this->configuration['form'] === 'bundle') {
      return $this->t('Select @label type', [
        '@label' => $label,
      ]);
    }
    if ($this->configuration['form'] === 'parameters') {
      return $this->t('@label parameters', [
        '@label' => $label,
      ]);
    }
    return $this->t('N/A');
  }

  /**
   * {@inheritdoc}
   */
  protected function getFormObject(): FormInterface {
    $form_object = NULL;
    if ($this->configuration['form'] === 'bundle') {
      $form_object = $this->classResolver->getInstanceFromDefinition(LmsBundleSelectionForm::class);
    }
    elseif ($this->configuration['form'] === 'parameters') {
      $form_object = $this->classResolver->getInstanceFromDefinition(LmsReferenceParametersForm::class);
    }

    if ($form_object === NULL) {
      throw new \InvalidArgumentException(\sprintf('%s parameter is invalid.', 'form'));
    }

    $form_object->setEntityTypeId($this->configuration['type']);

    return $form_object;
  }

  /**
   * {@inheritdoc}
   */
  public function getSubmissionData(): array {
    // For entity forms we can rely on set form state storage
    // where we can set entity data on save.
    return $this->formState->cleanValues()->getValues();
  }

}
