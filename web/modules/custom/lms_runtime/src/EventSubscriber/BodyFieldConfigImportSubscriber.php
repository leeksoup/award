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
   * Rejects UUID-driven deletion and recreation of body field configuration.
   */
  public function validateBodyFields(ConfigImporterEvent $event): void {
    $recreated = array_intersect(
      $event->getChangelist('create'),
      $event->getChangelist('delete'),
    );
    foreach ([
      'field.storage.lms_activity.body',
      'field.field.lms_activity.content.body',
      'field.field.lms_activity.checklist.body',
    ] as $name) {
      if (in_array($name, $recreated, TRUE)) {
        $event->getConfigImporter()->logError(sprintf(
          'Configuration import would delete and recreate %s. Match its sync UUID to the active UUID before importing, or restore the field data from a backup.',
          $name,
        ));
      }
    }
  }

}
