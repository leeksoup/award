<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\ModalSubform;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lms\Attribute\ModalSubform;
use Drupal\lms\Plugin\ModalSubformBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Modal or parent LMS entity form.
 */
#[ModalSubform(
  id: 'entity',
)]
class EntityForm extends ModalSubformBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    FormBuilderInterface $formBuilder,
    ClassResolverInterface $classResolver,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $formBuilder, $classResolver);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('form_builder'),
      $container->get('class_resolver'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function validateConfiguration(array $configuration): void {
    parent::validateConfiguration($configuration);
    if (
      !\array_key_exists('type', $configuration) ||
      !\is_string($configuration['type'])
    ) {
      throw new \InvalidArgumentException('type parameter missing or invalid.');
    }
    // Either entity ID or bundle must be given.
    $entity_id = $configuration['entity_id'] ?? NULL;
    if ($this->getBundle($configuration) === NULL && $entity_id === NULL) {
      throw new \InvalidArgumentException('Either entity bundle or ID needs to be provided in configuration.');
    }
  }

  /**
   * Tiny helper method to get entity bundle form configuration.
   */
  private function getBundle(array $configuration): ?string {
    return $configuration['bundle'] ?? $configuration['input']['bundle'] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $currentUser): bool {
    if (!$this->entityTypeManager->hasHandler($this->configuration['type'], 'access')) {
      return FALSE;
    }
    $access_handler = $this->entityTypeManager->getHandler($this->configuration['type'], 'access');

    // Entity creation case.
    if (
      !\array_key_exists('entity_id', $this->configuration) ||
      $this->configuration['entity_id'] === ''
    ) {
      $bundle = $this->getBundle($this->configuration);
      if ($bundle === NULL) {
        throw new \InvalidArgumentException('Unable to determine entity bundle.');
      }
      return $access_handler->createAccess($bundle, $currentUser, []);
    }

    // Entity edition case.
    $entity = $this->entityTypeManager->getStorage($this->configuration['type'])->load($this->configuration['entity_id']);
    if ($entity === NULL) {
      throw new \InvalidArgumentException(\sprintf(
        'Invalid entity parameters provided: [%s, %d].',
        $this->configuration['type'],
        $this->configuration['entity_id']
      ));
    }
    return $access_handler->access($entity, 'update', $currentUser);
  }

  /**
   * {@inheritdoc}
   */
  public function getDialogId(): string {
    return '#modal-entity-' . \strtr($this->configuration['type'], '_', '-');
  }

  /**
   * {@inheritdoc}
   */
  public function getSubmissionData(): array {
    // For entity forms we can rely on set form state storage
    // where we can set entity data on save.
    return $this->formState->getStorage();
  }

  /**
   * {@inheritdoc}
   */
  protected function getFormObject(): FormInterface {
    $entity_storage = $this->entityTypeManager->getStorage($this->configuration['type']);
    if (
      !\array_key_exists('entity_id', $this->configuration) ||
      $this->configuration['entity_id'] === ''
    ) {
      $bundle = $this->getBundle($this->configuration);
      if ($bundle === NULL) {
        throw new \InvalidArgumentException('Unable to determine entity bundle.');
      }

      $bundle_key = $this->entityTypeManager->getDefinition($this->configuration['type'])->getKey('bundle');
      $entity = $entity_storage->create([
        $bundle_key => $bundle,
      ]);
    }
    else {
      $entity = $entity_storage->load($this->configuration['entity_id']);
    }

    if ($entity === NULL) {
      throw new \InvalidArgumentException(\sprintf(
        'Invalid entity parameters provided: [%s, %d].',
        $this->configuration['type'],
        $this->configuration['entity_id']
      ));
    }

    $form_operation = $entity->isNew() ? 'add' : 'edit';
    $form_object = $this->entityTypeManager->getFormObject($entity->getEntityTypeId(), $form_operation);
    $form_object->setEntity($entity);
    return $form_object;
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle(): string|TranslatableMarkup {
    if (\array_key_exists('entity_id', $this->configuration)) {
      return $this->t('Edit @entity_type', [
        '@entity_type' => $this->entityTypeManager->getDefinition($this->configuration['type'])->getLabel(),
      ]);
    }
    return $this->t('Create @entity_type', [
      '@entity_type' => $this->entityTypeManager->getDefinition($this->configuration['type'])->getLabel(),
    ]);
  }

}
