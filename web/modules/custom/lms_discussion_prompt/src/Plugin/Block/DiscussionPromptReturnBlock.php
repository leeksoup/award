<?php

declare(strict_types=1);

namespace Drupal\lms_discussion_prompt\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Provides a return link for discussion prompt pages.
 */
#[Block(
  id: 'lms_discussion_prompt_return',
  admin_label: new TranslatableMarkup('Discussion Prompt return link'),
)]
final class DiscussionPromptReturnBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $return = \Drupal::request()->query->get('return');
    if (!\is_string($return) || !\str_starts_with($return, '/course/')) {
      return [];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['lms-discussion-prompt-return']],
      'link' => Link::fromTextAndUrl(
        $this->t('When finished, click here to return to the lesson'),
        Url::fromUserInput($return),
      )->toRenderable(),
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

}

