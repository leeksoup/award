<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\ModalSubform;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lms\Attribute\ModalSubform;
use Drupal\lms\Plugin\ModalSubformInterface;
use Drupal\views\ViewExecutable;
use Drupal\views\ViewExecutableFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Modal or parent LMS entity form.
 */
#[ModalSubform(
  id: 'view',
)]
final class ViewForm extends PluginBase implements ModalSubformInterface, ContainerFactoryPluginInterface {

  /**
   * The view with the form.
   */
  private ViewExecutable $view;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    ViewExecutableFactory $viewsExecutableFactory,
  ) {
    $this->validateConfiguration($configuration);

    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $view_entity = $entityTypeManager->getStorage('view')->load($configuration['view_id']);
    $this->view = $viewsExecutableFactory->get($view_entity);
    $this->view->setDisplay($configuration['display_id']);
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
      $container->get('entity_type.manager'),
      $container->get('views.executable')
    );
  }

  /**
   * Validate plugin configuration.
   */
  private function validateConfiguration(array $configuration): void {
    foreach (['view_id', 'display_id'] as $parameter) {
      if (
        !\array_key_exists($parameter, $configuration) ||
        !\is_string($configuration[$parameter])
      ) {
        throw new \InvalidArgumentException(\sprintf('%s parameter missing or invalid.', $parameter));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $currentUser): bool {
    return $this->view->access($this->configuration['display_id'], $currentUser);
  }

  /**
   * {@inheritdoc}
   */
  public function getDialogId(): string {
    return '#modal-view-' . \strtr($this->configuration['view_id'], '_', '-');
  }

  /**
   * {@inheritdoc}
   */
  public function getSubmissionData(): array {
    $output = ['reference_entity_data' => []];
    foreach ($this->configuration['input']['lms_select'] as $item) {
      $params = \explode(':', $item);
      $entity = $this->entityTypeManager->getStorage($params[0])->load($params[2]);
      if ($entity->getEntityTypeId() !== $this->configuration['type']) {
        throw new \InvalidArgumentException(\sprintf('Trying to reference an invalid entity type: %s .', $params[0]));
      }
      $output['reference_entity_data'][$params[2]] = [
        'bundle' => $entity->bundle(),
        'entity_id' => $params[2],
        'label' => $entity->label(),
      ];
    }
    return $output;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form_state_additions = []): array {
    // Set parent data that got modified by ModalSubformController.
    $view_request = $this->view->getRequest();
    $view_request->query->set('parent', $this->configuration['parent']);

    $args = [];
    $this->view->preExecute($args);
    $this->view->execute($this->configuration['display_id']);

    $query = $this->configuration;
    unset($query['input']);
    // The only way to pass the query to the view through Views AJAX seems to
    // be in the arguments.
    $this->view->args['query'] = Json::encode($query);
    return $this->view->buildRenderable($this->configuration['display_id'], $args, FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle(): string|TranslatableMarkup {
    $display_title = $this->view->getDisplay()->getOption('title');
    return $display_title ?? $this->t('Select references');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormState(): ?FormStateInterface {
    return NULL;
  }

}
