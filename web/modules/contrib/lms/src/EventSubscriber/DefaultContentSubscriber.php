<?php

declare(strict_types=1);

namespace Drupal\lms\EventSubscriber;

use Drupal\Core\DefaultContent\PreExportEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to default content-related events.
 */
class DefaultContentSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [PreExportEvent::class => 'preExport'];
  }

  /**
   * Reacts before an entity is exported.
   *
   * The lms_reference field type is a plain entity reference plus a
   * serialized data map, so core's entity_reference callback already does
   * everything needed: generic property export (including data) then
   * target_id -> UUID rewriting and dependency tracking.
   *
   * @param \Drupal\Core\DefaultContent\PreExportEvent $event
   *   The event object.
   */
  public function preExport(PreExportEvent $event): void {
    $callbacks = $event->getCallbacks();
    $event->setCallback('field_item:lms_reference', $callbacks['field_item:entity_reference']);
  }

}
