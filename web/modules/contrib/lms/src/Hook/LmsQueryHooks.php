<?php

declare(strict_types=1);

namespace Drupal\lms\Hook;

use Drupal\Core\Database\Query\AlterableInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\lms\Entity\Handlers\LmsEntityAccessControlHandlerBase;

/**
 * DB query hooks.
 */
final class LmsQueryHooks {

  /**
   * The constructor.
   */
  public function __construct(
    protected readonly AccountInterface $currentUser,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  #[Hook('query_lms_activity_access_alter')]
  public function lmsActivityQueryAccessAlter(AlterableInterface $query): void {
    $this->lmsEntityQueryAccessAlter($query, 'lms_activity');
  }

  #[Hook('query_lms_lesson_access_alter')]
  public function lmsLessonQueryAccessAlter(AlterableInterface $query): void {
    $this->lmsEntityQueryAccessAlter($query, 'lms_lesson');
  }

  /**
   * Alter query to enforce permissions.
   */
  private function lmsEntityQueryAccessAlter(AlterableInterface $query, string $entity_type_id): void {
    if (!$query instanceof SelectInterface) {
      return;
    }
    $account = $query->getMetaData('account');
    if (!$account instanceof AccountInterface) {
      $account = $this->currentUser;
    }

    if (
      $account->hasPermission('administer lms') ||
      $account->hasPermission(LmsEntityAccessControlHandlerBase::getPermission('edit', $entity_type_id)) ||
      $account->hasPermission(LmsEntityAccessControlHandlerBase::getPermission('use all', $entity_type_id))
    ) {
      return;
    }

    $definition = $this->entityTypeManager->getDefinition($entity_type_id);
    $data_table = $definition->getDataTable();
    $base_table = $definition->getBaseTable();

    $tables = $query->getTables();
    $base_table_data = [];
    foreach ($tables as $table) {
      if ($table['table'] === $data_table) {
        $query->condition($table['alias'] . '.uid', $account->id());
        return;
      }
      elseif ($table['table'] === $base_table) {
        $base_table_data = $table;
      }
    }

    if (\count($base_table_data) === 0) {
      return;
    }

    $query->join($data_table, $data_table, $base_table_data['alias'] . '.id = ' . $data_table . '.id AND ' . $data_table . '.uid = :uid', [
      ':uid' => $account->id(),
    ]);
  }

}
