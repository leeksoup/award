<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\lms\Config\PluginConfigInstaller;
use Drupal\lms\Entity\ActivityType;
use Drupal\lms\Entity\Answer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Activity-answer plugin base class.
 */
abstract class ActivityAnswerBase extends PluginBase implements ActivityAnswerInterface, ContainerFactoryPluginInterface {

  /**
   * CSS classes for correct and wrong answers.
   *
   * Included here for PHP 8.1 support.
   *
   * @todo Move to Drupal\lms_answer_plugins\Plugin\WithFeedbackPluginTrait
   *   once PHP 8.1 will be no longer supported.
   */
  public const CLASS_CORRECT = 'correct-answer';
  public const CLASS_WRONG = 'wrong-answer';

  /**
   * The plugin config installer.
   *
   * @var \Drupal\lms\Config\PluginConfigInstaller
   */
  protected PluginConfigInstaller $pluginConfigInstaller;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Injects services.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The dependency injection container.
   */
  public function injectServices(ContainerInterface $container): void {
    $this->pluginConfigInstaller = $container->get('plugin.config_installer.activity_answer');
    $this->entityTypeManager = $container->get('entity_type.manager');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->injectServices($container);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return $this->pluginDefinition['id'];
  }

  /**
   * {@inheritdoc}
   */
  public function evaluatedOnSave(Answer $answer): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getScore(Answer $answer): float {
    return 1;
  }

  /**
   * {@inheritdoc}
   */
  public function isCorrect(Answer $answer): bool {
    // Since ActivityAnswerInterface::getScore() returns float, we can
    // check if the given answer is correct by mapping score to percentages
    // - anything equal to or above 0.995 will evaluate to 100%.
    return $this->getScore($answer) >= 0.995;
  }

  /**
   * {@inheritdoc}
   */
  public function answeringForm(array &$form, FormStateInterface $form_state, Answer $answer): void {
    // No additional form elements by default.
  }

  /**
   * {@inheritdoc}
   */
  public function submitAnsweringForm(array &$form, FormStateInterface $form_state, Answer $answer): void {
    $form_state->addCleanValueKey('langcode');
    $form_state->addCleanValueKey('back');
    $form_state->cleanValues();
    $answer->setData($form_state->getValues());
  }

  /**
   * {@inheritdoc}
   */
  public function validateAnsweringForm(array &$form, FormStateInterface $form_state, Answer $answer): void {
    // No validation by default.
  }

  /**
   * {@inheritdoc}
   */
  public function evaluationDisplay(Answer $answer): array {
    $data = $answer->getData();
    if (\count($data) === 0) {
      $answer_renderable = ['#markup' => $this->t('No answer')];
    }
    else {
      $answer_renderable = $this->getAnswerRenderable($answer);
    }

    return [
      // Render activity.
      'activity' => $this->entityTypeManager->getViewBuilder('lms_activity')->view($answer->getActivity(), 'activity'),
      // Add answer.
      'answer' => [
        '#type' => 'fieldset',
        '#title' => $this->t('Student answer'),
        'answer' => $answer_renderable,
      ],
    ];
  }

  /**
   * Helper method to get answer renderable to override in child classes.
   */
  protected function getAnswerRenderable(Answer $answer): array {
    $data = $answer->getData();

    if (
      \array_key_exists('answer', $data) &&
      \is_string($data['answer'])
    ) {
      $answer_renderable = ['#markup' => $data['answer']];
    }
    else {
      $answer_renderable = ['#markup' => $this->t('No implementation')];
    }

    return $answer_renderable;
  }

  /**
   * {@inheritdoc}
   */
  public function install(ActivityType $activity_type): void {
    $this->pluginConfigInstaller->install($this->getPluginDefinition(), $activity_type->id());
  }

  /**
   * Default implementation for configurable plugins.
   *
   * Code saver - this method may not be needed in many plugin implementations.
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Default implementation for configurable plugins.
   *
   * This method will not be needed in most configurable plugin cases as
   * configuration saving takes place in the activity type form.
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {}

}
