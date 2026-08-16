<?php

declare(strict_types=1);

namespace Drupal\lms;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lms\Entity\Bundle\Course;

/**
 * Check if a stored revision should be loaded for the current request.
 */
class RevisionLoadChecker {

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly RouteMatchInterface $routeMatch,
    protected readonly AccountInterface $currentUser,
  ) {}

  /**
   * Returns TRUE if the stored revision should be loaded, FALSE for current.
   *
   * On the answer form route, editors see the current revision so they can
   * verify the live content while learners always see the revision that was
   * active when they started the course.
   */
  public function shouldLoadRevision(): bool {
    if ($this->configFactory->get('lms.settings')->get('use_revisions') !== TRUE) {
      return FALSE;
    }

    if ($this->routeMatch->getRouteName() !== 'lms.group.answer_form') {
      return TRUE;
    }

    $group = $this->routeMatch->getParameter('group');
    if (!$group instanceof Course) {
      return TRUE;
    }

    return !$group->access('update', $this->currentUser);
  }

}
