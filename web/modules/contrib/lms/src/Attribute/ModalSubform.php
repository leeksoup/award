<?php

declare(strict_types=1);

namespace Drupal\lms\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;

/**
 * Defines a ModalSubform attribute for plugin discovery.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ModalSubform extends AttributeBase {

  public function __construct(
    string $id,
    public readonly ?string $deriver = NULL,
  ) {
    parent::__construct($id);
  }

}
