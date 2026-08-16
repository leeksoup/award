<?php

declare(strict_types=1);

namespace Drupal\lms\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Menu\LocalTaskManagerInterface;
use Drupal\Core\Url;

/**
 * Controller for lesson type admin pages.
 */
final class LessonBundleListController extends ControllerBase {

  public function __construct(
    protected readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    protected readonly LocalTaskManagerInterface $localTaskManager,
  ) {}

  /**
   * Bundle overview page callback.
   *
   * Lists all lesson type bundles with links to manage their fields and
   * form display settings.
   */
  public function overview(): array {
    $bundles = $this->entityTypeBundleInfo->getBundleInfo('lms_lesson');

    // Collect first-level local tasks registered on the field UI base route
    // so that the operations list stays in sync with available tabs.
    $tasks = $this->localTaskManager->getDefinitions();
    $bundle_tasks = \array_filter($tasks, static fn($t) =>
      ($t['base_route'] ?? '') === 'lms.lesson_type.settings' && ($t['parent_id'] ?? NULL) === NULL
    );

    $header = [
      $this->t('Name'),
      $this->t('Operations'),
    ];

    $rows = [];
    foreach ($bundles as $bundle_id => $bundle_info) {
      $operations = [];
      foreach ($bundle_tasks as $task_id => $task) {
        $operations[$task_id] = [
          'title' => $task['title'],
          'url' => Url::fromRoute($task['route_name'], ['bundle' => $bundle_id]),
        ];
      }

      $rows[] = [
        'name' => $bundle_info['label'],
        'operations' => [
          'data' => [
            '#type' => 'operations',
            '#links' => $operations,
          ],
        ],
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No lesson types found.'),
    ];
  }

}
