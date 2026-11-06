<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\Field\FieldType;

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
final class StartLinkFieldItemList extends FieldItemList {

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
    // The actual render array is built in view(), the stored value is unused.
    // Layout builder checks if the field is empty before passing to formatter
    // hence the stub value.
    $this->list[0] = $this->createItem(0, 'Stub');
  }

  /**
   * {@inheritdoc}
   */
  public function view($display_options = []) {
    $course = $this->getEntity();

    // Somehow on layout edition when an entity is not available,
    // the generateSampleItems() method is not called so we have
    // to call it here for new entities to have a proper preview.
    if ($course->isNew()) {
      return $this->generateSampleItems();
    }

    $block = \Drupal::service('plugin.manager.block')->createInstance('lms_start_link', []);
    $current_user = \Drupal::currentUser();

    // Manually create context from the entity this field is attached to.
    $block->setContext('group', new Context(new EntityContextDefinition('entity:group'), $course));
    $block->setContext('user', new Context(new EntityContextDefinition('entity:user'), $current_user));

    if (!$block->access($current_user)) {
      return [];
    }

    // Cacheability is already applied by the block.
    return $block->build();
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
