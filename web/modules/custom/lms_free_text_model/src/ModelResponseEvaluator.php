<?php

declare(strict_types=1);

namespace Drupal\lms_free_text_model;

/**
 * Scores a free-text answer and builds its model-response feedback.
 *
 * Character counting matches Free Text with Feedback: HTML tags and Windows
 * newlines are removed, the value is trimmed, and the remaining length is
 * measured in characters. The feedback render array is shaped so it can be
 * merged into the LMS feedback container without replacing that container.
 */
final class ModelResponseEvaluator {

  /**
   * Counts characters the same way the minimum-length validator does.
   */
  public function countableLength(string $answer): int {
    $value = strip_tags($answer);
    $value = trim($value);
    $normalized = preg_replace('/\r\n?/', '', $value);
    if (!is_string($normalized)) {
      return 0;
    }
    return mb_strlen($normalized);
  }

  /**
   * Returns the fraction of the activity max score this answer earns.
   *
   * An empty answer scores 0. Any other answer whose countable length meets
   * the minimum scores 1. Submitted answers still require teacher grading;
   * this helper does not assign the teacher's grade.
   */
  public function score(string $answer, int $minimum_characters): float {
    if ($answer === '') {
      return 0.0;
    }
    if ($this->countableLength($answer) < $minimum_characters) {
      return 0.0;
    }
    return 1.0;
  }

  /**
   * Builds the feedback children for an accepted answer.
   *
   * The returned array has no top-level #type or #attributes. LMS merges it
   * into the existing feedback container with array_merge_recursive(), which
   * would turn duplicate scalar properties into arrays.
   *
   * The heading is plain text. The body is left as authored HTML; rendering
   * it in an html_tag element applies Xss::filterAdmin(), the same filter
   * used for phrase feedback.
   *
   * @return array<string, mixed>
   *   A render array, or an empty array when there is nothing to show.
   */
  public function feedbackRenderArray(?string $heading, ?string $body): array {
    $heading_text = $this->headingText($heading);
    $body_text = trim((string) $body);
    if ($heading_text === NULL && $body_text === '') {
      return [];
    }

    $content = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['model-response-feedback'],
      ],
    ];
    if ($heading_text !== NULL) {
      $content['heading'] = [
        '#type' => 'html_tag',
        '#tag' => 'h4',
        '#attributes' => [
          'class' => ['model-response-heading'],
        ],
        '#value' => htmlspecialchars(
          $heading_text,
          ENT_QUOTES | ENT_SUBSTITUTE,
          'UTF-8',
        ),
      ];
    }
    if ($body_text !== '') {
      $content['body'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'class' => ['model-response-body'],
        ],
        '#value' => $body_text,
      ];
    }

    return [
      '#attached' => [
        'library' => [
          'lms_free_text_model/model_response',
        ],
      ],
      'content' => $content,
    ];
  }

  /**
   * Normalizes the configured heading.
   */
  private function headingText(?string $heading): ?string {
    if ($heading === NULL) {
      return NULL;
    }
    $heading = trim($heading);
    return $heading === '' ? NULL : $heading;
  }

}
