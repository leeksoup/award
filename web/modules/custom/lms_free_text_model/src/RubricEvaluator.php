<?php

declare(strict_types=1);

namespace Drupal\lms_free_text_model;

/**
 * Evaluates literal alternatives or bounded PCRE checks without grading prose.
 */
final class RubricEvaluator {

  /**
   * Compiles a regex body, retaining escaped delimiters and bounding PCRE work.
   */
  public function expression(string $pattern): string {
    $escaped = '';
    $slashes = 0;
    foreach (str_split($pattern) as $character) {
      if ($character === '~' && $slashes % 2 === 0) {
        $escaped .= '\\';
      }
      $escaped .= $character;
      $slashes = $character === '\\' ? $slashes + 1 : 0;
    }
    return '~(*LIMIT_MATCH=100000)(*LIMIT_DEPTH=1000)(?:' . $escaped . ')~iu';
  }

  /**
   * Returns an author-facing validation error, or NULL.
   */
  public function error(array $check): ?string {
    if (trim((string) ($check['pattern'] ?? '')) === '') {
      return NULL;
    }
    if (!in_array($check['mode'] ?? 'phrases', ['phrases', 'regex'], TRUE)) {
      return 'Choose phrases or regular expression.';
    }
    $weight = (float) ($check['weight'] ?? 1);
    if (!is_finite($weight) || $weight <= 0 || $weight > 1000000) {
      return 'The points weight must be greater than zero and at most 1000000.';
    }
    if (($check['mode'] ?? 'phrases') === 'regex'
      && @preg_match($this->expression((string) $check['pattern']), '') === FALSE) {
      return 'The regular expression is invalid. Enter a regex body without delimiters.';
    }
    if (($check['mode'] ?? 'phrases') === 'phrases'
      && array_filter(array_map('trim', explode('|', $check['pattern'])), static fn(string $phrase): bool => $phrase !== '') === []) {
      return 'Enter at least one nonempty phrase, or leave the pattern blank to skip it.';
    }
    return NULL;
  }

  /**
   * Returns the score fraction, failed-check nudges, and whether all passed.
   */
  public function evaluate(string $answer, int $minimum, array $checks): array {
    $length_score = (new ModelResponseEvaluator())->score($answer, $minimum);
    $answer = html_entity_decode(strip_tags($answer), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $total = 0.0;
    $earned = 0.0;
    $nudges = [];
    $all_passed = TRUE;
    foreach (array_slice($checks, 0, 5) as $check) {
      $pattern = trim((string) ($check['pattern'] ?? ''));
      if ($pattern === '') {
        continue;
      }
      $error = $this->error($check);
      // Invalid imported configuration fails closed rather than earning credit.
      $weight = (float) ($check['weight'] ?? 1);
      if (!is_finite($weight) || $weight <= 0 || $weight > 1000000) {
        $weight = 1.0;
      }
      $total += $weight;
      $passed = FALSE;
      if ($error === NULL && $length_score > 0) {
        if (($check['mode'] ?? 'phrases') === 'regex') {
          $passed = @preg_match($this->expression($pattern), $answer) === 1;
        }
        else {
          foreach (explode('|', $pattern) as $phrase) {
            $phrase = trim($phrase);
            if ($phrase !== '' && mb_stripos($answer, $phrase, 0, 'UTF-8') !== FALSE) {
              $passed = TRUE;
              break;
            }
          }
        }
      }
      if ($passed) {
        $earned += $weight;
      }
      else {
        $all_passed = FALSE;
        $nudge = trim((string) ($check['nudge'] ?? ''));
        if ($nudge !== '') {
          $nudges[] = $nudge;
        }
      }
    }
    return [
      'score' => $length_score === 0.0 ? 0.0 : ($total > 0 ? $earned / $total : 1.0),
      'nudges' => $nudges,
      'all_passed' => $length_score > 0 && $all_passed,
    ];
  }

}
