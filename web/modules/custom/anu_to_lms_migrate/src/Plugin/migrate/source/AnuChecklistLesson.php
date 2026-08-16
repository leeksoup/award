<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\migrate\source;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reads Anu lessons and their checklists in page/content order.
 *
 * Lessons without a checklist are deliberately excluded from this first slice.
 *
 * @MigrateSource(
 *   id = "anu_checklist_lesson"
 * )
 */
final class AnuChecklistLesson extends SourcePluginBase {

  /**
   * Constructs the source plugin.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MigrationInterface $migration,
    StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $state);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MigrationInterface $migration = NULL,
  ): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('state'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function fields(): array {
    return [
      'nid' => $this->t('Source lesson node ID'),
      'title' => $this->t('Lesson title'),
      'description' => $this->t('Lesson description'),
      'checklists' => $this->t('Ordered checklist paragraph IDs'),
      'status' => $this->t('Published status'),
      'uid' => $this->t('Author ID'),
      'created' => $this->t('Created timestamp'),
      'changed' => $this->t('Changed timestamp'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds(): array {
    return ['nid' => ['type' => 'integer']];
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator(): \Iterator {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'module_lesson')
      ->sort('nid')
      ->execute();

    foreach ($storage->loadMultiple($ids) as $lesson) {
      $checklists = [];
      if ($lesson->hasField('field_module_lesson_content')) {
        foreach ($lesson->get('field_module_lesson_content')->referencedEntities() as $section) {
          if (!$section->hasField('field_lesson_section_content')) {
            continue;
          }
          foreach ($section->get('field_lesson_section_content')->referencedEntities() as $block) {
            if ($block->bundle() !== 'lesson_checklist') {
              continue;
            }
            $checklists[] = ['paragraph_id' => (int) $block->id()];
          }
        }
      }

      if ($checklists === []) {
        continue;
      }

      yield [
        'nid' => (int) $lesson->id(),
        'title' => (string) $lesson->label(),
        'description' => '',
        'checklists' => $checklists,
        'status' => (int) $lesson->isPublished(),
        'uid' => (int) ($lesson->getOwnerId() ?: 1),
        'created' => (int) $lesson->getCreatedTime(),
        'changed' => (int) $lesson->getChangedTime(),
      ];
    }
  }

}
