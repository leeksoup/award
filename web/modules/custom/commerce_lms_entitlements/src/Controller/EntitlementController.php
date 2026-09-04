<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Displays purchaser-scoped and operator entitlement audit tables. */
final class EntitlementController extends ControllerBase {
  public function __construct(private Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function mine(): array {
    return $this->table($this->database->select('commerce_lms_entitlement', 'e')->fields('e')->condition('purchaser_uid', $this->currentUser()->id())->orderBy('created', 'DESC')->execute()->fetchAll(), TRUE);
  }

  public function admin(): array {
    return $this->table($this->database->select('commerce_lms_entitlement', 'e')->fields('e')->orderBy('changed', 'DESC')->execute()->fetchAll(), FALSE);
  }

  private function table(array $records, bool $allow_cancel): array {
    $rows = [];
    foreach ($records as $record) {
      $row = [(string) $record->eid, $record->offer_id, $record->purchase_type, $record->status, $record->learner_uid ?: $this->t('Invitation pending'), $record->access_through ? $this->dateFormatter()->format($record->access_through, 'short') : $this->t('—')];
      if ($allow_cancel) {
        $row[] = !in_array($record->status, ['expired', 'guarantee_refunded', 'guarantee_refund_pending'], TRUE) ? ['data' => ['#type' => 'link', '#title' => $this->t('Cancel'), '#url' => Url::fromRoute('commerce_lms_entitlements.cancel', ['eid' => $record->eid])]] : $this->t('—');
      }
      $rows[] = $row;
    }
    $header = [$this->t('ID'), $this->t('Offer'), $this->t('Type'), $this->t('Status'), $this->t('Learner'), $this->t('Access through')];
    if ($allow_cancel) { $header[] = $this->t('Operations'); }
    return ['table' => ['#type' => 'table', '#header' => $header, '#rows' => $rows, '#empty' => $this->t('No entitlement records found.')]];
  }
}
