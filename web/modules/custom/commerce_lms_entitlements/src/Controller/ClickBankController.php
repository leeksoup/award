<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Controller;

use Drupal\commerce_lms_entitlements\ClickBankManager;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Public ClickBank notification and post-checkout information endpoints. */
final class ClickBankController extends ControllerBase {
  public function __construct(private ClickBankManager $clickBank) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('commerce_lms_entitlements.clickbank'));
  }

  public function notification(Request $request): Response {
    try {
      $event = $this->clickBank->decodeNotification($request->getContent());
      $this->clickBank->acceptNotification($event);
    }
    catch (\DomainException) {
      return new Response('', 400);
    }
    catch (\Throwable $e) {
      $this->getLogger('commerce_lms_entitlements')->error('ClickBank INS persistence failed: @message', ['@message' => $e->getMessage()]);
      return new Response('', 503);
    }
    return new Response('', 204);
  }

  public function thanks(): array {
    return [
      '#markup' => $this->t('Thank you. ClickBank will email the purchaser a receipt. After payment is verified, the learner will receive a course invitation at the email address selected before checkout. If it does not arrive, please contact support with your ClickBank receipt. Refund requests are handled under ClickBank’s return policy.'),
      '#cache' => ['max-age' => 0],
    ];
  }
}
