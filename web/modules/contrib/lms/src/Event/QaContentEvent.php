<?php

declare(strict_types=1);

namespace Drupal\lms\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Defines post - QA content creation event.
 */
final class QaContentEvent extends Event {

  public const NAME = 'lms_qa_content';

  /**
   * The constructor.
   *
   * @param string $module
   *   The Drupal module for which QA content is being installed.
   */
  public function __construct(
    private string $module,
  ) {}

  /**
   * Module getter.
   */
  public function getModule(): string {
    return $this->module;
  }

}
