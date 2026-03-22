<?php

namespace Drupal\anu_lms\Normalizer;

use Drupal\node\NodeInterface;
use Drupal\rest_entity_recursive\Normalizer\ContentEntityNormalizer;

/**
 * Converts the Drupal node object structure to a JSON array structure.
 */
abstract class NodeNormalizerBase extends ContentEntityNormalizer {

  /**
   * List of node bundles supported by the current normalizer.
   *
   * @var array
   */
  protected array $supportedBundles;

  /**
   * {@inheritdoc}
   */
  public function supportsNormalization($data, $format = NULL, array $context = []): bool {
    if (parent::supportsNormalization($data, $format)) {
      return $data instanceof NodeInterface && in_array($data->bundle(), $this->supportedBundles);
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function normalize($entity, $format = NULL, array $context = []): array|bool|string|int|float|null|\ArrayObject {
    $normalized = parent::normalize($entity, $format, $context);
    $normalized['path'] = $entity->toUrl('canonical')->toString();
    return $normalized;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedTypes(?string $format): array {
    return [NodeInterface::class => TRUE];
  }

}
