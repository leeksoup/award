<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/** Captures and restores the immutable local side of PayPal approval. */
final class SubscriptionCheckoutSnapshot {

  public function __construct(private EntityTypeManagerInterface $entityTypeManager) {}

  /** Captures the exact one-item checkout state sent to PayPal. */
  public function capture(OrderInterface $order): array {
    $items = $order->getItems();
    $total = $order->getTotalPrice();
    if (count($items) !== 1 || !$total) {
      throw new \DomainException('A subscription checkout snapshot requires one priced order item.');
    }
    $item = reset($items);
    $unit_price = $item->getUnitPrice();
    if (!$unit_price || Calculator::compare($item->getQuantity(), '1') !== 0) {
      throw new \DomainException('A subscription checkout snapshot requires quantity one and a unit price.');
    }

    $coupon_uuids = [];
    if ($order->hasField('coupons')) {
      foreach ($order->get('coupons')->referencedEntities() as $coupon) {
        $coupon_uuids[] = (string) $coupon->uuid();
      }
    }

    return [
      'version' => 1,
      'order_id' => (int) $order->id(),
      'gateway_id' => (string) ($order->get('payment_gateway')->target_id ?? ''),
      'total' => $total->toArray(),
      'coupon_uuids' => $coupon_uuids,
      'order_adjustments' => $this->normalizeAdjustments($order->getAdjustments()),
      'item' => [
        'id' => (int) $item->id(),
        'purchased_entity_id' => (int) $item->getPurchasedEntityId(),
        'quantity' => (string) $item->getQuantity(),
        'unit_price' => $unit_price->toArray(),
        'adjustments' => $this->normalizeAdjustments($item->getAdjustments()),
      ],
    ];
  }

  /** Restores a mutated draft order to its PayPal-approved checkout state. */
  public function restore(OrderInterface $order, array $snapshot): Price {
    $this->validateSnapshot($order, $snapshot);
    $item_snapshot = $snapshot['item'];
    $items = $order->getItems();
    $item = reset($items);

    $item->setQuantity((string) $item_snapshot['quantity']);
    $item->setUnitPrice(Price::fromArray($item_snapshot['unit_price']), TRUE);
    $item->setAdjustments($this->denormalizeAdjustments($item_snapshot['adjustments']));
    $item->save();

    $order->setAdjustments($this->denormalizeAdjustments($snapshot['order_adjustments']));
    if ($order->hasField('coupons')) {
      $coupon_ids = [];
      $coupon_storage = $this->entityTypeManager->getStorage('commerce_promotion_coupon');
      foreach ($snapshot['coupon_uuids'] as $uuid) {
        $matches = $coupon_storage->loadByProperties(['uuid' => $uuid]);
        if (count($matches) !== 1) {
          throw new \DomainException(sprintf('Checkout coupon %s is missing or ambiguous.', $uuid));
        }
        $coupon_ids[] = (int) reset($matches)->id();
      }
      $order->set('coupons', $coupon_ids);
    }

    $order->setRefreshState(OrderInterface::REFRESH_SKIP);
    $order->save();
    $expected = Price::fromArray($snapshot['total']);
    $actual = $order->getTotalPrice();
    if (!$actual || !$actual->equals($expected)) {
      throw new \DomainException(sprintf(
        'Restored checkout total %s does not match the approved total %s.',
        $actual ? (string) $actual : '(empty)',
        (string) $expected,
      ));
    }
    return $expected;
  }

  /** Validates fields that must not change even during recovery. */
  private function validateSnapshot(OrderInterface $order, array $snapshot): void {
    if (($snapshot['version'] ?? NULL) !== 1 || (int) ($snapshot['order_id'] ?? 0) !== (int) $order->id()) {
      throw new \DomainException('The subscription checkout snapshot does not belong to this order.');
    }
    if ($order->getState()->value !== 'draft') {
      throw new \DomainException('Only a draft subscription order can be restored.');
    }
    $gateway_id = (string) ($order->get('payment_gateway')->target_id ?? '');
    if ($gateway_id === '' || $gateway_id !== (string) ($snapshot['gateway_id'] ?? '')) {
      throw new \DomainException('The subscription checkout payment gateway changed after approval began.');
    }
    $items = $order->getItems();
    if (count($items) !== 1) {
      throw new \DomainException('The subscription order item structure changed after approval began.');
    }
    $item = reset($items);
    $item_snapshot = $snapshot['item'] ?? [];
    if ((int) $item->id() !== (int) ($item_snapshot['id'] ?? 0)
      || (int) $item->getPurchasedEntityId() !== (int) ($item_snapshot['purchased_entity_id'] ?? 0)) {
      throw new \DomainException('The subscription order item changed after approval began.');
    }
    if (empty($snapshot['total']['number']) || empty($snapshot['total']['currency_code'])
      || empty($item_snapshot['unit_price']['number']) || empty($item_snapshot['unit_price']['currency_code'])
      || Calculator::compare((string) ($item_snapshot['quantity'] ?? '0'), '1') !== 0) {
      throw new \DomainException('The subscription checkout snapshot is incomplete.');
    }
  }

  /** Converts Adjustment value objects into JSON-safe arrays. */
  private function normalizeAdjustments(array $adjustments): array {
    return array_map(static function (Adjustment $adjustment): array {
      return [
        'type' => $adjustment->getType(),
        'label' => (string) $adjustment->getLabel(),
        'amount' => $adjustment->getAmount()->toArray(),
        'percentage' => $adjustment->getPercentage(),
        'source_id' => $adjustment->getSourceId(),
        'included' => $adjustment->isIncluded(),
        'locked' => $adjustment->isLocked(),
      ];
    }, $adjustments);
  }

  /** Recreates Commerce Adjustment value objects from a saved snapshot. */
  private function denormalizeAdjustments(array $adjustments): array {
    return array_map(static function (array $adjustment): Adjustment {
      $adjustment['amount'] = Price::fromArray($adjustment['amount']);
      return new Adjustment($adjustment);
    }, $adjustments);
  }

}
