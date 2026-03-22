<?php

namespace Drupal\anu_lms\Normalizer;

use Drupal\image\Plugin\Field\FieldType\ImageItem;
use Drupal\rest_entity_recursive\Normalizer\ReferenceItemNormalizer;

/**
 * Class ImageItemNormalizer.
 *
 * Normalizer adds attributes image item.
 *
 * @package Drupal\rest_media_recursive\Normalizer
 */
class ImageItemNormalizer extends ReferenceItemNormalizer {

  /**
   * {@inheritdoc}
   */
  public function normalize($field_item, $format = NULL, array $context = []): array|string|int|float|bool|\ArrayObject|null {
    return parent::normalize($field_item, $format, $context) + [
      'title' => $field_item->get('title')->getValue(),
      'alt' => $field_item->get('alt')->getValue(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedTypes(?string $format): array {
    $supported_types = [];
    if ($format === 'json_recursive') {
      $supported_types[ImageItem::class] = TRUE;
    }
    return $supported_types;
  }

}
