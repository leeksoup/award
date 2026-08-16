<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\migrate\source;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reads current Anu lesson_checklist paragraph revisions from this site.
 *
 * @MigrateSource(
 *   id = "anu_lesson_checklist"
 * )
 */
final class AnuLessonChecklist extends SourcePluginBase {

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
      'paragraph_id' => $this->t('Checklist paragraph ID'),
      'title' => $this->t('Generated activity title'),
      'items' => $this->t('Ordered checklist item content'),
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
    return ['paragraph_id' => ['type' => 'integer']];
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator(): \Iterator {
    $storage = $this->entityTypeManager->getStorage('paragraph');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'lesson_checklist')
      ->sort('id')
      ->execute();

    foreach ($storage->loadMultiple($ids) as $checklist) {
      $items = [];
      if ($checklist->hasField('field_checklist_items')) {
        foreach ($checklist->get('field_checklist_items')->referencedEntities() as $delta => $item) {
          $text = $item->hasField('field_checkbox_option')
            ? (string) $item->get('field_checkbox_option')->value
            : '';
          $description = $item->hasField('field_lesson_text_content')
            ? (string) $item->get('field_lesson_text_content')->value
            : '';
          $items[] = [
            'delta' => $delta,
            'text' => $text,
            'description' => $description,
          ];
        }
      }

      $plain_title = trim(strip_tags((string) ($items[0]['text'] ?? '')));
      $title = $plain_title === ''
        ? 'Checklist ' . $checklist->id()
        : 'Checklist: ' . mb_strimwidth($plain_title, 0, 220, '…');

      yield [
        'paragraph_id' => (int) $checklist->id(),
        'title' => (string) $title,
        'items' => $items,
        'status' => 1,
        'uid' => 1,
        'created' => $checklist->hasField('created') ? (int) $checklist->get('created')->value : 0,
        'changed' => $checklist->hasField('changed') ? (int) $checklist->get('changed')->value : 0,
      ];
    }
  }

}
