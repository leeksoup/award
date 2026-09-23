<?php

declare(strict_types=1);

namespace Drupal\lms_discussion_prompt\Value;

use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Contains the result of completing a Discussion Prompt activity.
 */
final class DiscussionPromptCompletion {

  public function __construct(
    public readonly NodeInterface $discussion,
    public readonly Url $returnUrl,
  ) {}

}
