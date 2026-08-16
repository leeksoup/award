<?php

declare(strict_types=1);

namespace Drupal\lms\PageCache;

use Drupal\Core\PageCache\RequestPolicyInterface;
use Drupal\Core\Session\SessionConfigurationInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Disables page cache for course pages when the visitor has an active session.
 *
 * Anonymous course progress lives in the private tempstore (session-scoped).
 * Visitors without a session have no progress and can be served from cache.
 */
class DisallowLmsCoursePageCache implements RequestPolicyInterface {

  public function __construct(
    private readonly SessionConfigurationInterface $sessionConfiguration,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function check(Request $request): ?string {
    $path = $request->getPathInfo();

    $isLmsPath =
      preg_match('#^/course/\d+/\d+(/\d+)?$#', $path) ||
      preg_match('#^/group/\d+$#', $path);

    if (!$isLmsPath) {
      return NULL;
    }

    if ($this->sessionConfiguration->hasSession($request)) {
      return self::DENY;
    }

    return NULL;
  }

}
