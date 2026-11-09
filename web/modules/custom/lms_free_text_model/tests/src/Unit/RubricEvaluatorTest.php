<?php

declare(strict_types=1);

namespace Drupal\Tests\lms_free_text_model\Unit;

use Drupal\lms_free_text_model\ModelResponseEvaluator;
use Drupal\lms_free_text_model\RubricEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Tests literal alternatives, bounded regex, and weighted partial credit.
 *
 * @group lms_free_text_model
 */
final class RubricEvaluatorTest extends TestCase {

  /**
   * Blank patterns are skipped; relative weights determine partial credit.
   */
  public function testWeightedScoringAndBlankPatterns(): void {
    $checks = [
      ['pattern' => 'tradition|Church', 'mode' => 'phrases', 'weight' => 1, 'nudge' => 'Address the objection.'],
      ['pattern' => '\\?', 'mode' => 'regex', 'weight' => 3, 'nudge' => 'Ask a question.'],
      ['pattern' => '', 'mode' => 'regex', 'weight' => 999, 'nudge' => 'Never shown.'],
    ];
    $result = (new RubricEvaluator())->evaluate('The CHURCH teaches this.', 5, $checks);
    self::assertSame(0.25, $result['score']);
    self::assertSame(['Ask a question.'], $result['nudges']);
    self::assertFalse($result['all_passed']);
    self::assertSame(0.25, (new ModelResponseEvaluator())->score('The CHURCH teaches this.', 5, $checks));
    self::assertSame(0.0, (new ModelResponseEvaluator())->score('Church?', 50, $checks));
    self::assertSame(1.0, (new ModelResponseEvaluator())->score('Long enough', 5, []));
    self::assertSame(1.0, (new ModelResponseEvaluator())->score('Long enough', 5, [['pattern' => '']]));
  }

  /**
   * Regex bodies accept the defaults and preserve literal alternative syntax.
   */
  public function testPatternsAndValidation(): void {
    $evaluator = new RubricEvaluator();
    $verse = ['mode' => 'regex', 'pattern' => '\\b([1-3]\\s?)?[A-Z][a-z]+\\.?\\s\\d+:\\d+', 'weight' => 1];
    self::assertNull($evaluator->error($verse));
    self::assertSame(1.0, $evaluator->evaluate('Consider 1 John 3:16.', 1, [$verse])['score']);
    self::assertNotNull($evaluator->error(['mode' => 'regex', 'pattern' => '[', 'weight' => 1]));
    self::assertNotNull($evaluator->error(['pattern' => 'word', 'weight' => 0]));
    self::assertNotNull($evaluator->error(['pattern' => 'word', 'weight' => -1]));
    self::assertNotNull($evaluator->error(['mode' => 'regex', 'pattern' => 'word', 'weight' => INF]));
    self::assertSame(0.0, $evaluator->evaluate('word', 1, [['mode' => 'regex', 'pattern' => '[']])['score']);
    foreach (['a~b', 'a\\~b'] as $pattern) {
      self::assertSame(1.0, $evaluator->evaluate('a~b', 1, [['mode' => 'regex', 'pattern' => $pattern]])['score']);
    }
    // Phrase mode remains literal, including question marks and brackets.
    self::assertSame(1.0, $evaluator->evaluate('A [WORD] here', 1, [['pattern' => '[word]|other']])['score']);
    self::assertSame(0.0, $evaluator->evaluate('word here', 1, [['pattern' => '[word]|other']])['score']);
    self::assertSame(1.0, $evaluator->evaluate('value 0', 1, [['pattern' => '0']])['score']);
  }

}
