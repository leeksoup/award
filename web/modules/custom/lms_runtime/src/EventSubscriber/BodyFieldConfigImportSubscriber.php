<?php

declare(strict_types=1);

namespace Drupal\lms_runtime\EventSubscriber;

use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigImporterEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Prevents a config import from replacing the shared activity body field.
 */
final class BodyFieldConfigImportSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::IMPORT_VALIDATE => 'validateBodyFields'];
  }

  /**
   * Rejects deletion of shared body field configuration.
   */
  public function validateBodyFields(ConfigImporterEvent $event): void {
    $deleted = $event->getChangelist('delete');
    foreach ([
      'field.storage.lms_activity.body',
      'field.field.lms_activity.content.body',
      'field.field.lms_activity.checklist.body',
      'field.field.lms_activity.discussion_prompt.body',
    ] as $name) {
      if (in_array($name, $deleted, TRUE)) {
        $event->getConfigImporter()->logError(sprintf(
          'Configuration import would delete %s and detach its data. Keep the field in sync configuration with the active UUID before importing.',
          $name,
        ));
      }
    }
  }

}
