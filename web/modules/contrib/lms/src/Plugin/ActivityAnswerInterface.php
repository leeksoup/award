<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lms\Entity\ActivityType;
use Drupal\lms\Entity\Answer;

/**
 * Interface for Activity-answer plugins.
 */
interface ActivityAnswerInterface extends PluginInspectionInterface {

  /**
   * Get plugin id.
   */
  public function getId(): string;

  /**
   * Indicates if answer should me evaluated on save or not.
   *
   * NOTE: Answers with zero max score will always be set as evaluated,
   * bypassing this method check.
   */
  public function evaluatedOnSave(Answer $activity): bool;

  /**
   * Score logic for specified activity.
   *
   * @return float
   *   Achieved score value ranging from 0 to 1.
   */
  public function getScore(Answer $answer): float;

  /**
   * Check if the given answer is correct.
   */
  public function isCorrect(Answer $answer): bool;

  /**
   * Modify answering form.
   */
  public function answeringForm(array &$form, FormStateInterface $form_state, Answer $answer): void;

  /**
   * Submit answering form.
   */
  public function submitAnsweringForm(array &$form, FormStateInterface $form_state, Answer $answer): void;

  /**
   * Validate answering form.
   */
  public function validateAnsweringForm(array &$form, FormStateInterface $form_state, Answer $answer): void;

  /**
   * Display user answer for teacher evaluation.
   *
   * Used only for activities that can be evaluated.
   *
   * @return array
   *   Renderable array.
   */
  public function evaluationDisplay(Answer $answer): array;

  /**
   * Perform install tasks.
   *
   * Executed after activity type config entity has been saved. Use to
   * install necessary field, form and display config.
   */
  public function install(ActivityType $activity_type): void;

}
