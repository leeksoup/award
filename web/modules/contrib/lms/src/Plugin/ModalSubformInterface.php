<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines modal subform plugins.
 */
interface ModalSubformInterface {

  /**
   * Get the HTML ID of the opened dialog.
   */
  public function getDialogId(): string;

  /**
   * Check if the current user can access this modal form.
   */
  public function access(AccountInterface $currentUser): bool;

  /**
   * Get form state and form array for a built form.
   *
   * @return array
   *   The form.
   */
  public function buildForm(array $form_state_additions = []): array;

  /**
   * Get title of the modal.
   */
  public function getTitle(): string|TranslatableMarkup;

  /**
   * Gets form submission data for the parent form.
   */
  public function getSubmissionData(): array;

  /**
   * Get stored form state.
   */
  public function getFormState(): ?FormStateInterface;

}
