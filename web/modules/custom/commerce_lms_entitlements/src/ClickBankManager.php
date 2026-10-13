<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Accepts ClickBank INS and reconciles it with independently fetched orders.
 *
 * Neither the browser return nor decrypted INS is billing authority. The
 * Orders API must corroborate every state transition before fulfillment.
 */
final class ClickBankManager {
  private const API_URL = 'https://api.clickbank.com/rest/1.3/orders2';

  public function __construct(
    private Connection $database,
    private ConfigFactoryInterface $configFactory,
    private QueueFactory $queue,
    private TimeInterface $time,
    private ClientInterface $httpClient,
    private EntitlementManager $entitlements,
    private LoggerInterface $logger,
  ) {}

  public function isConfigured(): bool {
    $config = $this->configFactory->get('commerce_lms_entitlements.clickbank');
    return (bool) $config->get('enabled')
      && (string) $config->get('vendor') !== ''
      && (string) $config->get('sku') !== ''
      && (string) $config->get('offer_id') !== ''
      && (string) Settings::get('commerce_lms_entitlements_clickbank_ins_secret', '') !== ''
      && (string) Settings::get('commerce_lms_entitlements_clickbank_api_key', '') !== '';
  }

  /** Creates a 24-hour intent; only its opaque token leaves the server. */
  public function createIntent(string $email): string {
    if (!$this->isConfigured() || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new \DomainException('ClickBank checkout is unavailable or the learner email is invalid.');
    }
    $token = bin2hex(random_bytes(32));
    $now = $this->time->getRequestTime();
    $this->database->insert('commerce_lms_clickbank_intent')->fields([
      'token_hash' => hash('sha256', $token),
      'offer_id' => (string) $this->configFactory->get('commerce_lms_entitlements.clickbank')->get('offer_id'),
      'learner_email' => mb_strtolower(trim($email)),
      'created' => $now,
      'expires' => $now + 86400,
    ])->execute();
    return $token;
  }

  public function paymentUrl(string $token): string {
    $config = $this->configFactory->get('commerce_lms_entitlements.clickbank');
    $vendor = (string) $config->get('vendor');
    $sku = (string) $config->get('sku');
    if (!preg_match('/^[a-zA-Z0-9_-]{1,20}$/', $vendor)
      || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $sku)) {
      throw new \DomainException('ClickBank vendor or SKU is invalid.');
    }
    return 'https://' . $vendor . '.pay.clickbank.net/?' . http_build_query(['cbitems' => $sku, 'v1' => $token]);
  }

  /** Decrypts the INS v8 envelope, rejecting malformed or irrelevant input. */
  public function decodeNotification(string $body): array {
    if (!$this->isConfigured()) {
      throw new \DomainException('ClickBank INS is not configured.');
    }
    $config = $this->configFactory->get('commerce_lms_entitlements.clickbank');
    return ClickBankInsDecoder::decode(
      $body,
      (string) Settings::get('commerce_lms_entitlements_clickbank_ins_secret', ''),
      (string) $config->get('vendor'),
    );
  }

  /** Persists a deduplicated notification before acknowledging ClickBank. */
  public function acceptNotification(array $event): void {
    $type = (string) $event['transactionType'];
    if ($type === 'TEST' || (str_starts_with($type, 'TEST_') && !$this->allowTestTransactions())) {
      return;
    }
    $receipt = (string) $event['receipt'];
    $time = (string) ($event['transactionTime'] ?? '');
    $event_id = hash('sha256', implode('|', [$receipt, $type, $time]));
    $now = $this->time->getRequestTime();
    try {
      $this->database->insert('commerce_lms_clickbank_event')->fields([
        'event_id' => $event_id,
        'receipt' => $receipt,
        'event_type' => $type,
        // Keep only correlation data. The authoritative buyer and learner
        // details are fetched or resolved separately during reconciliation.
        'payload' => json_encode([
          'receipt' => $receipt,
          'transactionType' => $type,
          'transactionTime' => $time,
        ], JSON_THROW_ON_ERROR),
        'status' => 'queued',
        'created' => $now,
        'changed' => $now,
      ])->execute();
    }
    catch (IntegrityConstraintViolationException) {
      return;
    }
    $this->queue->get('commerce_lms_clickbank_event')->createItem(['event_id' => $event_id]);
  }

  public function processEvent(string $event_id): void {
    $row = $this->database->select('commerce_lms_clickbank_event', 'e')->fields('e')->condition('event_id', $event_id)->execute()->fetchAssoc();
    if (!$row || in_array($row['status'], ['processed', 'review'], TRUE)) {
      return;
    }
    $event = json_decode((string) $row['payload'], TRUE, 512, JSON_THROW_ON_ERROR);
    try {
      $this->reconcileReceipt((string) $row['receipt'], $event);
      $this->markEvent($event_id, 'processed');
    }
    catch (\DomainException $e) {
      $this->markEvent($event_id, 'review');
      $this->logger->warning('ClickBank receipt @receipt needs review: @reason', ['@receipt' => $row['receipt'], '@reason' => $e->getMessage()]);
    }
    catch (\Throwable $e) {
      $this->markEvent($event_id, 'failed');
      throw $e;
    }
  }

  private function markEvent(string $event_id, string $status): void {
    $this->database->update('commerce_lms_clickbank_event')->fields(['status' => $status, 'changed' => $this->time->getRequestTime()])->condition('event_id', $event_id)->execute();
  }

  /** Fetches the authoritative receipt; may also be called by staff retry. */
  public function reconcileReceipt(string $receipt, ?array $notification = NULL): void {
    if (!$this->isConfigured() || !preg_match('/^[A-Za-z0-9-]{8,64}$/', $receipt)) {
      throw new \DomainException('ClickBank receipt or configuration is invalid.');
    }
    $lock = \Drupal::lock();
    $lock_name = 'commerce_lms_clickbank:' . hash('sha256', $receipt);
    if (!$lock->acquire($lock_name, 120.0)) {
      throw new \RuntimeException('ClickBank receipt is already being processed.');
    }
    try {
      $this->reconcileLockedReceipt($receipt, $notification);
    }
    finally {
      $lock->release($lock_name);
    }
  }

  /** Serializes sales and reversals for the same receipt. */
  private function reconcileLockedReceipt(string $receipt, ?array $notification): void {
    $config = $this->configFactory->get('commerce_lms_entitlements.clickbank');
    $sku = (string) $config->get('sku');
    // Do not supply sku: a multi-item receipt must fail closed rather than
    // being filtered down to one seemingly valid line item.
    $records = $this->fetchOrders(self::API_URL . '/' . rawurlencode($receipt));
    $records = $this->orderRecords($records);
    if (!$records) {
      throw new \RuntimeException('ClickBank Orders API returned no records for receipt.');
    }
    $sale = NULL;
    $reversal = NULL;
    $mixed_order = FALSE;
    foreach ($records as $record) {
      if ((string) ($record['receipt'] ?? '') !== $receipt
        || (string) ($record['vendor'] ?? '') !== (string) $config->get('vendor')
        || strtoupper((string) ($record['role'] ?? 'VENDOR')) !== 'VENDOR') {
        continue;
      }
      $items = $this->items($record);
      $type = (string) ($record['transactionType'] ?? '');
      if (count($items) !== 1
        || (string) ($items[0]['itemNo'] ?? '') !== $sku
        || (int) ($items[0]['quantity'] ?? 0) !== 1
        || !empty($items[0]['recurring'])) {
        if (in_array($type, ['SALE', 'TEST_SALE'], TRUE)) {
          $mixed_order = TRUE;
        }
        continue;
      }
      if (in_array($type, $this->allowTestTransactions() ? ['RFND', 'CGBK', 'INSF', 'TEST_RFND'] : ['RFND', 'CGBK', 'INSF'], TRUE)) {
        $reversal = $record;
      }
      elseif ($type === 'SALE' || ($type === 'TEST_SALE' && $this->allowTestTransactions())) {
        $sale = $record;
      }
    }
    if ($mixed_order) {
      throw new \DomainException('Multi-item or unsupported ClickBank sale requires staff review.');
    }
    if ($notification && !in_array((string) ($notification['transactionType'] ?? ''), array_filter([
      $sale['transactionType'] ?? NULL,
      $reversal['transactionType'] ?? NULL,
    ]), TRUE)) {
      throw new \RuntimeException('ClickBank INS event is not yet corroborated by Orders API.');
    }
    if (!$sale && !$reversal) {
      throw new \DomainException('Receipt has no supported one-item lifetime transaction.');
    }
    $existing = $this->purchase($receipt);
    if ($reversal) {
      $status = in_array($reversal['transactionType'], ['RFND', 'TEST_RFND'], TRUE) ? 'refunded' : 'chargeback';
      $this->upsertPurchase($receipt, $sku, (string) $config->get('vendor'), $status, $existing, $reversal);
      if (!empty($existing['eid'])) {
        $this->entitlements->reverseClickBank((int) $existing['eid'], $status);
      }
      return;
    }
    if ($existing && in_array($existing['status'], ['refunded', 'chargeback'], TRUE)) {
      return;
    }
    if (!$sale || !filter_var((string) ($sale['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
      throw new \DomainException('Verified ClickBank sale has no buyer email.');
    }
    $token = $this->sellerVariable($sale, 'v1');
    $hash = $token !== '' ? hash('sha256', $token) : '';
    $intent = $hash !== '' ? $this->database->select('commerce_lms_clickbank_intent', 'i')->fields('i')->condition('token_hash', $hash)->execute()->fetchAssoc() : FALSE;
    $sale_time = strtotime((string) ($sale['transactionTime'] ?? ''));
    if (!$intent || !$sale_time || $sale_time < (int) $intent['created'] || $sale_time > (int) $intent['expires']
      || (string) $intent['offer_id'] !== (string) $config->get('offer_id')) {
      $this->upsertPurchase($receipt, $sku, (string) $config->get('vendor'), 'review', $existing, $sale, 'missing_or_expired_intent');
      throw new \DomainException('Missing, expired, or mismatched learner checkout intent.');
    }
    $purchase = $this->upsertPurchase($receipt, $sku, (string) $config->get('vendor'), !empty($existing['eid']) ? 'active' : 'pending', $existing, $sale);
    if (!empty($purchase['eid'])) {
      $this->entitlements->fulfillClickBank((int) $purchase['eid']);
      return;
    }
    if (!$purchase || in_array($purchase['status'], ['refunded', 'chargeback'], TRUE)) {
      return;
    }
    if (empty($purchase['eid'])) {
      $transaction = $this->database->startTransaction();
      try {
        $eid = $this->entitlements->activateClickBank((string) $intent['offer_id'], (string) $intent['learner_email']);
        $this->database->update('commerce_lms_clickbank_purchase')->fields([
          'eid' => $eid, 'status' => 'active', 'reason' => NULL, 'intent_hash' => $hash, 'changed' => $this->time->getRequestTime(),
        ])->condition('receipt', $receipt)->execute();
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
      unset($transaction);
    }
    else {
      $eid = (int) $purchase['eid'];
    }
    $this->entitlements->fulfillClickBank($eid);
  }

  private function purchase(string $receipt): ?array {
    return $this->database->select('commerce_lms_clickbank_purchase', 'p')->fields('p')->condition('receipt', $receipt)->execute()->fetchAssoc() ?: NULL;
  }

  private function upsertPurchase(string $receipt, string $sku, string $vendor, string $status, ?array $existing, array $record, ?string $reason = NULL): array {
    $now = $this->time->getRequestTime();
    $buyer_email = trim((string) ($record['email'] ?? ''));
    $affiliate = trim((string) ($record['affiliate'] ?? ''));
    $values = [
      'sku' => $sku, 'vendor' => $vendor,
      'buyer_email' => mb_strtolower($buyer_email !== '' ? $buyer_email : (string) ($existing['buyer_email'] ?? '')),
      'affiliate' => $affiliate !== '' ? $affiliate : (string) ($existing['affiliate'] ?? ''),
      'status' => $status,
      'reason' => $reason,
      'changed' => $now,
    ];
    if ($existing) {
      $this->database->update('commerce_lms_clickbank_purchase')->fields($values)->condition('receipt', $receipt)->execute();
    }
    else {
      try {
        $this->database->insert('commerce_lms_clickbank_purchase')->fields(['receipt' => $receipt, 'created' => $now] + $values)->execute();
      }
      catch (IntegrityConstraintViolationException) {
        // A concurrent worker inserted the same receipt.
      }
    }
    return $this->purchase($receipt);
  }

  private function fetchOrders(string $url, array $query = []): array {
    $key = (string) Settings::get('commerce_lms_entitlements_clickbank_api_key', '');
    if ($key === '') {
      throw new \RuntimeException('ClickBank Orders API key is not configured.');
    }
    $response = $this->httpClient->request('GET', $url, [
      'headers' => ['Authorization' => $key, 'Accept' => 'application/json'],
      'query' => $query,
      'timeout' => 12,
    ]);
    $decoded = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($decoded)) {
      throw new \RuntimeException('ClickBank Orders API returned non-JSON data.');
    }
    return $decoded;
  }

  /** Handles single-record and documented orderData list response shapes. */
  private function orderRecords(array $data): array {
    $rows = $data['orderData'] ?? $data;
    $rows = $rows['item'] ?? $rows;
    if (isset($rows['receipt'])) {
      return [$rows];
    }
    return is_array($rows) && array_is_list($rows) ? $rows : [];
  }

  private function items(array $record): array {
    $items = $record['lineItemData'] ?? [];
    $items = $items['item'] ?? $items;
    if (isset($items['itemNo'])) {
      return [$items];
    }
    return is_array($items) && array_is_list($items) ? $items : [];
  }

  private function sellerVariable(array $record, string $name): string {
    $vars = $record['vendorVariables'] ?? [];
    if (!is_array($vars)) {
      return '';
    }
    if (isset($vars[$name])) {
      return (string) $vars[$name];
    }
    foreach (($vars['item'] ?? $vars) as $item) {
      if (is_array($item) && ($item['name'] ?? '') === $name) {
        return (string) ($item['value'] ?? '');
      }
    }
    return '';
  }

  private function allowTestTransactions(): bool {
    return (bool) $this->configFactory->get('commerce_lms_entitlements.clickbank')->get('allow_test_transactions')
      && (bool) Settings::get('commerce_lms_entitlements_clickbank_allow_test_transactions', FALSE);
  }

  /** Pages through recent transactions to recover missed INS deliveries. */
  public function reconcileRecent(int $days = 3): void {
    if (!$this->isConfigured()) {
      return;
    }
    $vendor = (string) $this->configFactory->get('commerce_lms_entitlements.clickbank')->get('vendor');
    $sku = (string) $this->configFactory->get('commerce_lms_entitlements.clickbank')->get('sku');
    $start = gmdate('Y-m-d', $this->time->getRequestTime() - max(1, min($days, 30)) * 86400);
    $end = gmdate('Y-m-d', $this->time->getRequestTime());
    for ($page = 1; $page <= 100; $page++) {
      $key = (string) Settings::get('commerce_lms_entitlements_clickbank_api_key', '');
      $response = $this->httpClient->request('GET', self::API_URL . '/list', [
        'headers' => ['Authorization' => $key, 'Accept' => 'application/json', 'Page' => (string) $page],
        'query' => ['startDate' => $start, 'endDate' => $end, 'role' => 'VENDOR', 'vendor' => $vendor],
        'timeout' => 20,
      ]);
      $decoded = json_decode((string) $response->getBody(), TRUE);
      if (!is_array($decoded)) {
        throw new \RuntimeException('ClickBank list response was not JSON.');
      }
      foreach ($this->orderRecords($decoded) as $record) {
        $receipt = (string) ($record['receipt'] ?? '');
        $items = $this->items($record);
        if (preg_match('/^[A-Za-z0-9-]{8,64}$/', $receipt)
          && (string) ($record['vendor'] ?? '') === $vendor
          && in_array((string) ($record['transactionType'] ?? ''), $this->allowTestTransactions()
            ? ['SALE', 'RFND', 'CGBK', 'INSF', 'TEST_SALE', 'TEST_RFND']
            : ['SALE', 'RFND', 'CGBK', 'INSF'], TRUE)
          && count($items) === 1 && (string) ($items[0]['itemNo'] ?? '') === $sku) {
          $this->queue->get('commerce_lms_clickbank_receipt')->createItem(['receipt' => $receipt]);
        }
      }
      if ($response->getStatusCode() !== 206) {
        return;
      }
    }
    throw new \RuntimeException('ClickBank pagination exceeded 100 pages; narrow reconciliation window.');
  }
}
