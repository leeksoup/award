<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for modal subform plugins.
 */
abstract class ModalSubformBase extends PluginBase implements ModalSubformInterface, ContainerFactoryPluginInterface {

  /**
   * Form state of the built form.
   */
  protected ?FormStateInterface $formState = NULL;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly FormBuilderInterface $formBuilder,
    protected readonly ClassResolverInterface $classResolver,
  ) {
    $this->validateConfiguration($configuration);
    parent::__construct($configuration, $plugin_id, $plugin_definition);
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
      $container->get('class_resolver')
    );
  }

  /**
   * Validate plugin configuration.
   */
  protected function validateConfiguration(array $configuration): void {
    // Not required.
  }

  /**
   * Get form state for this modal subform or parent form.
   */
  protected function initializeFormState(array $form_state_additions): void {
    $this->formState = (new FormState())
      ->setRequestMethod('POST')
      ->setCached()
      ->disableRedirect();

    if (
      \array_key_exists('skip_validation', $form_state_additions) &&
      $form_state_additions['skip_validation'] === TRUE
    ) {
      $this->formState->setValidationComplete();
    }

    if (\array_key_exists('rebuild_info', $form_state_additions)) {
      $this->formState->setRebuildInfo($form_state_additions['rebuild_info']);
    }
    if (\array_key_exists('build_info', $form_state_additions)) {
      foreach ($form_state_additions['build_info'] as $property => $value) {
        $this->formState->addBuildInfo($property, $value);
      }
    }
  }

  /**
   * Get stored form state.
   */
  public function getFormState(): ?FormStateInterface {
    return $this->formState;
  }

  /**
   * Form object getter.
   */
  abstract protected function getFormObject(): FormInterface;

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form_state_additions = []): array {
    $this->initializeFormState($form_state_additions);
    $form_object = $this->getFormObject();
    $form = $this->formBuilder->buildForm($form_object, $this->formState);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle(): string|TranslatableMarkup {
    return $this->t('Modal subform');
  }

}
