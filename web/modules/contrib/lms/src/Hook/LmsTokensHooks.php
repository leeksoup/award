<?php

declare(strict_types=1);

namespace Drupal\lms\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\lms\Entity\AnswerInterface;
use Drupal\lms\Entity\ActivityInterface;

/**
 * Token hook implementations for Drupal LMS.
 */
final class LmsTokensHooks {

  use StringTranslationTrait;

  #[Hook('token_info')]
  public function tokenInfo(): array {
    return [
      'types' => [
        'lms_answer' => [
          'name' => $this->t("LMS Answer"),
          'description' => $this->t("Tokens related to LMS Answer entities."),
          'needs-data' => 'lms_answer',
        ],
        'lms_activity' => [
          'name' => $this->t("LMS Activity"),
          'description' => $this->t("Tokens related to LMS Activity entities."),
          'needs-data' => 'lms_activity',
        ],
      ],
      'tokens' => [
        'lms_answer' => [
          'label' => [
            'name' => $this->t('Label'),
            'description' => $this->t('The label of the answer.'),
          ],
        ],
        'lms_activity' => [
          'label' => [
            'name' => $this->t('Label'),
            'description' => $this->t('The label of the activity.'),
          ],
        ],
      ],
    ];
  }

  /**
   * Implements hook_tokens().
   */
  #[Hook('tokens')]
  public function tokens(string $type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata): array {
    $replacements = [];
    if (
      $type === 'lms_answer' &&
      \array_key_exists('lms_answer', $data) &&
      $data['lms_answer'] instanceof AnswerInterface
    ) {
      foreach ($tokens as $name => $original) {
        if ($name === 'label') {
          $replacements[$original] = $data['lms_answer']->label();
          $bubbleable_metadata
            ->addCacheableDependency($data['lms_answer']->getOwner())
            ->addCacheableDependency($data['lms_answer']->getActivity());
        }
      }
    }
    elseif (
      $type === 'lms_activity' &&
      \array_key_exists('lms_activity', $data) &&
      $data['lms_activity'] instanceof ActivityInterface
    ) {
      foreach ($tokens as $name => $original) {
        if ($name === 'label') {
          $replacements[$original] = $data['lms_activity']->label();
        }
      }
    }
    return $replacements;
  }

}
