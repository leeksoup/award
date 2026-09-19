<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Controller;

use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\commerce_lms_entitlements\SubscriptionCheckoutRecovery;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_paypal_subscriptions\Controller\CheckoutController;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Adds durable correlation and server-side finalization to checkout. */
final class SubscriptionCheckoutController extends CheckoutController {

  private EntitlementManager $entitlementManager;

  private SubscriptionCheckoutRecovery $checkoutRecovery;

  /** {@inheritdoc} */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->entitlementManager = $container->get('commerce_lms_entitlements.manager');
    $instance->checkoutRecovery = $container->get('commerce_lms_entitlements.subscription_checkout_recovery');
    return $instance;
  }

  /** Adds the sealed entitlement token to PayPal's subscription request. */
  public function onCreate(OrderInterface $commerce_order, PaymentGatewayInterface $commerce_payment_gateway, Request $request): JsonResponse {
    $response = parent::onCreate($commerce_order, $commerce_payment_gateway, $request);
    $custom_id = $this->entitlementManager->checkoutCustomIdForOrder((int) $commerce_order->id());
    if ($custom_id === '') {
      throw new \RuntimeException('Subscription checkout did not create a correlation token.');
    }
    $data = $response->getData(TRUE);
    $data['custom_id'] = $custom_id;
    $response->setData($data);
    return $response;
  }

  /** Finalizes locally before the browser can lose the normal return request. */
  public function onApprove(RouteMatchInterface $route_match, Request $request): JsonResponse {
    $order = $route_match->getParameter('commerce_order');
    $gateway = $route_match->getParameter('commerce_payment_gateway');
    $subscription_id = $request->request->get('subscriptionID');
    $paypal_order_id = $request->request->get('orderID');
    if (!$order instanceof OrderInterface || !$gateway instanceof PaymentGatewayInterface
      || !is_string($subscription_id) || !is_string($paypal_order_id)) {
      throw new \DomainException('The PayPal approval callback is incomplete.');
    }
    $this->checkoutRecovery->finalize($order, $gateway, $subscription_id, FALSE, $paypal_order_id);
    $redirect_url = Url::fromRoute('commerce_checkout.form', [
      'commerce_order' => $order->id(),
      'step' => 'complete',
    ])->toString();
    return new JsonResponse(['redirectUrl' => $redirect_url]);
  }

}
