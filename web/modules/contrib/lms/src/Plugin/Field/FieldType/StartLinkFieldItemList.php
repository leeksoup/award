<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\Field\FieldType;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Link;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\TypedData\ComputedItemListTrait;
use Drupal\Core\Url;

/**
 * Field item list class for group's 'start_link' field.
 *
 * @see \Drupal\lms\Entity\Bundle\Course::bundleFieldDefinitions()
 */
final class StartLinkFieldItemList extends FieldItemList implements CacheableDependencyInterface {

  use CacheableDependencyTrait;
  use ComputedItemListTrait;

  /**
   * Computes the value of the field.
   *
   * @internal
   *   This method is called by the Typed Data API and should not be called
   *   directly. Phpstan doesn't work well with abstract method in a trait.
   *
   * @see \Drupal\Core\TypedData\ComputedItemListTrait::ensureComputedValue
   *
   * @phpstan-ignore-next-line
   */
  protected function computeValue(): void {
    // Compute the component props as a serialized string without rendering.
    $course = $this->getEntity();
    if ($course->isNew()) {
      return;
    }

    $block_manager = \Drupal::service('plugin.manager.block');
    $block = $block_manager->createInstance('lms_start_link', []);
    $current_user = \Drupal::service('current_user');

    // Manually create context from the entity this field is attached to.
    $group_context = new Context(new EntityContextDefinition('entity:group'), $course);
    $user_context = new Context(new EntityContextDefinition('entity:user'), $current_user);

    $block->setContext('group', $group_context);
    $block->setContext('user', $user_context);

    if ($block->access($current_user)) {
      $build = $block->build();
      // Bring forward the cacheability of this FieldItemList object.
      $this->setCacheability(CacheableMetadata::createFromRenderArray($build));
      $props = $build['#props'] ?? NULL;
      $this->list[0] = $this->createItem(0, \serialize($props));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function view($display_options = []) {
    // Somehow on layout edition when an entity is not available,
    // the generateSampleItems() method is not called so we have
    // to call it here for new entities to have a proper preview.
    if ($this->getEntity()->isNew()) {
      return $this->generateSampleItems();
    }

    // Build the render array for the component from the serialized props.
    $item = $this->first();
    if ($item === NULL) {
      return [];
    }

    $value = $item->get('value')->getValue();
    if (\is_string($value)) {
      $props = \unserialize($value);
      if (\is_array($props)) {
        $build = [
          '#type' => 'component',
          '#component' => 'lms:start_link',
          '#props' => $props,
        ];

        // Apply the stored cache metadata to the final render array.
        $cacheability = CacheableMetadata::createFromObject($this);
        $cacheability->applyTo($build);

        return $build;
      }
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function generateSampleItems($count = 1): array {
    return [
      '#type' => 'component',
      '#component' => 'lms:start_link',
      '#props' => [
        'link' => Link::fromTextAndUrl($this->t('Example link'), Url::fromRoute('<front>'))->toRenderable(),
        'action_info' => NULL,
      ],
    ];
  }

}
