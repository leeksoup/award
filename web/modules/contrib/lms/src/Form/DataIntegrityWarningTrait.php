<?php

declare(strict_types=1);

namespace Drupal\lms\Form;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lms\DataIntegrityChecker;

/**
 * Contains common in progress warning message methods.
 */
trait DataIntegrityWarningTrait {

  use StringTranslationTrait;

  /**
   * Course progress checker service.
   *
   * Needs to be protected to allow serialization.
   */
  protected DataIntegrityChecker $integrityChecker;

  /**
   * A bit less boilerplate this way hopefully.
   */
  public function setDataIntegrityChecker(DataIntegrityChecker $integrity_checker): self {
    $this->integrityChecker = $integrity_checker;
    return $this;
  }

  /**
   * Add progress waring message to a renderable.
   */
  private function addDataWarning(array &$element, TranslatableMarkup $message): void {
    $element['lms_progress_warning'] = [
      '#theme' => 'status_messages',
      '#message_list' => [
        'warning' => [$message],
      ],
      '#status_headings' => [
        'warning' => $this->t('Warning'),
      ],
      '#weight' => -100,
    ];
  }

}
