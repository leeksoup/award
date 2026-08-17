<?php

declare(strict_types=1);

namespace Drupal\anu_to_lms_migrate\Plugin\Field\FieldFormatter;

use Drupal\anu_to_lms_migrate\VideoUrlNormalizer;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Renders normalized YouTube/Vimeo link fields as responsive iframes.
 */
#[FieldFormatter(
  id: 'safe_video_embed',
  label: new TranslatableMarkup('Safe video embed'),
  field_types: ['link'],
)]
final class SafeVideoEmbedFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    foreach ($items as $delta => $item) {
      $url = VideoUrlNormalizer::normalize((string) $item->uri);
      if ($url === NULL) {
        continue;
      }

      $elements[$delta] = [
        '#type' => 'html_tag',
        '#tag' => 'iframe',
        '#attributes' => [
          'src' => $url,
          'title' => $this->t('Lesson video'),
          'loading' => 'lazy',
          'allow' => 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share',
          'allowfullscreen' => 'allowfullscreen',
          'referrerpolicy' => 'strict-origin-when-cross-origin',
          'style' => 'aspect-ratio: 16 / 9; width: 100%; height: auto; border: 0;',
        ],
      ];
    }
    return $elements;
  }

}
