<?php

declare(strict_types=1);

namespace Drupal\lms\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a ActivityAnswer attribute for plugin discovery.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ActivityAnswer extends AttributeBase {

  public function __construct(
    string $id,
    public readonly ?TranslatableMarkup $name,
    public readonly ?string $deriver = NULL,
  ) {
    parent::__construct($id);
  }

}
