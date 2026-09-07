<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Plugin\QueueWorker;

use Drupal\commerce_lms_entitlements\PayPalPlanCatalog;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Audits live PayPal offer mappings outside the cron request.
 *
 * @QueueWorker(
 *   id = "commerce_lms_entitlements_live_plan_audit",
 *   title = @Translation("Commerce LMS live PayPal plan audit"),
 *   cron = {"time" = 30}
 * )
 */
final class LivePlanAuditWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private PayPalPlanCatalog $planCatalog,
    private LoggerChannelInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /** {@inheritdoc} */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('commerce_lms_entitlements.paypal_plan_catalog'),
      $container->get('logger.channel.commerce_lms_entitlements'),
    );
  }

  /** {@inheritdoc} */
  public function processItem($data): void {
    foreach ($this->planCatalog->auditLiveOffers() as $offer_id => $result) {
      foreach ($result['errors'] as $error) {
        $this->logger->warning('Live PayPal plan audit failed for offer @offer: @error', [
          '@offer' => $offer_id,
          '@error' => $error,
        ]);
      }
    }
  }

}
