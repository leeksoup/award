<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Routing;

use Drupal\commerce_lms_entitlements\Controller\SubscriptionCheckoutController;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/** Routes contributed subscription callbacks through the hardened controller. */
final class RouteSubscriber extends RouteSubscriberBase {

  /** {@inheritdoc} */
  protected function alterRoutes(RouteCollection $collection): void {
    foreach (['commerce_paypal_subscriptions.checkout.create', 'commerce_paypal_subscriptions.checkout.approve'] as $route_name) {
      if ($route = $collection->get($route_name)) {
        $method = str_ends_with($route_name, '.create') ? 'onCreate' : 'onApprove';
        $route->setDefault('_controller', SubscriptionCheckoutController::class . '::' . $method);
      }
    }
  }

}
