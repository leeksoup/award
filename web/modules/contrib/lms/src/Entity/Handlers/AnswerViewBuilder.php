<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Handlers;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\lms\Entity\Answer;
use Drupal\lms\TrainingManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Answer view builder handler.
 */
final class AnswerViewBuilder extends EntityViewBuilder {

  use StringTranslationTrait;

  /**
   * The training manager.
   */
  private TrainingManager $trainingManager;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    $instance = parent::createInstance($container, $entity_type);
    $instance->injectServices($container);
    return $instance;
  }

  /**
   * Injects services.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The dependency injection container.
   */
  public function injectServices(ContainerInterface $container): void {
    $this->trainingManager = $container->get('lms.training_manager');
  }

  /**
   * {@inheritdoc}
   */
  public function view(EntityInterface $entity, $view_mode = 'full', $langcode = NULL) {
    \assert($entity instanceof Answer);

    $build = [];

    // Render answer using activity-answer plugin.
    $plugin = $this->trainingManager->getActivityAnswerPlugin($entity->getActivity());
    if ($plugin !== NULL) {
      $build['user_answer'] = $plugin->evaluationDisplay($entity);
      $build['user_answer']['#weight'] = -100;
      if ($entity->isEvaluated()) {
        $build['score'] = [
          '#weight' => -99,
          '#type' => 'item',
          '#title' => $this->t('Score:'),
          '#plain_text' => $entity->getScore(),
        ];
      }
    }

    $build += parent::view($entity, $view_mode, $langcode);

    return $build;
  }

}
