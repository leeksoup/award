<?php

declare(strict_types=1);

namespace Drupal\Tests\lms_free_text_model\Unit;

use Drupal\lms_free_text_model\ModelResponseEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Tests scoring and model-response rendering without a Drupal bootstrap.
 *
 * @group lms_free_text_model
 */
final class ModelResponseEvaluatorTest extends TestCase {

  /**
   * The evaluator under test.
   */
  private ModelResponseEvaluator $evaluator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->evaluator = new ModelResponseEvaluator();
  }

  /**
   * Empty answers score zero even when no minimum is configured.
   */
  public function testEmptyAnswerScoresZero(): void {
    self::assertSame(0.0, $this->evaluator->score('', 0));
    self::assertSame(0.0, $this->evaluator->score('', 50));
  }

  /**
   * Any non-empty answer earns full credit when the minimum is zero.
   */
  public function testAnyAnswerScoresFullCreditWhenMinimumIsZero(): void {
    self::assertSame(1.0, $this->evaluator->score('a', 0));
    self::assertSame(1.0, $this->evaluator->score('   a   ', 0));
  }

  /**
   * Answers are scored on countable length, not phrase matches.
   */
  public function testMinimumLengthGrantsFullScore(): void {
    self::assertSame(0.0, $this->evaluator->score('short', 10));
    self::assertSame(1.0, $this->evaluator->score('long enough', 10));
    self::assertSame(1.0, $this->evaluator->score('exactly10!', 10));
    $unmatched = 'no required phrase here at all';
    self::assertSame(1.0, $this->evaluator->score($unmatched, 10));
  }

  /**
   * Length counting matches the existing free-text feedback validator.
   */
  public function testCountableLengthMatchesFeedbackPlugin(): void {
    self::assertSame(5, $this->evaluator->countableLength('  hello  '));
    self::assertSame(5, $this->evaluator->countableLength('<p>hello</p>'));
    self::assertSame(2, $this->evaluator->countableLength("a\r\nb"));
    self::assertSame(2, $this->evaluator->countableLength("a\rb"));
    self::assertSame(3, $this->evaluator->countableLength("a\nb"));
    self::assertSame(1, $this->evaluator->countableLength('é'));
    $marked_up = '<p><strong>ab</strong></p>';
    self::assertSame(1.0, $this->evaluator->score($marked_up, 2));
    self::assertSame(0.0, $this->evaluator->score($marked_up, 3));
  }

  /**
   * The model response is always the authored HTML, under a plain heading.
   */
  public function testFeedbackUsesNeutralMarkupAndAdminHtml(): void {
    $body = '<p><strong>Model response:</strong> A sample.</p>';
    $body .= '<p><strong>Explanation:</strong> Why.</p>';
    $build = $this->evaluator->feedbackRenderArray(
      'Compare your response:',
      $body,
    );

    self::assertArrayNotHasKey('#type', $build);
    self::assertArrayNotHasKey('#attributes', $build);
    self::assertSame(
      ['lms_free_text_model/model_response'],
      $build['#attached']['library'],
    );
    self::assertSame('container', $build['content']['#type']);
    self::assertSame(
      ['model-response-feedback'],
      $build['content']['#attributes']['class'],
    );
    self::assertSame('h4', $build['content']['heading']['#tag']);
    self::assertSame(
      ['model-response-heading'],
      $build['content']['heading']['#attributes']['class'],
    );
    self::assertSame(
      'Compare your response:',
      $build['content']['heading']['#value'],
    );
    self::assertSame('div', $build['content']['body']['#tag']);
    self::assertSame(
      ['model-response-body'],
      $build['content']['body']['#attributes']['class'],
    );
    self::assertSame($body, $build['content']['body']['#value']);
    $this->assertNeutral($build);
  }

  /**
   * A blank heading is omitted and a blank body suppresses the feedback.
   */
  public function testEmptyHeadingAndBody(): void {
    $body_only = $this->evaluator->feedbackRenderArray('   ', '<p>Shown</p>');
    self::assertArrayNotHasKey('heading', $body_only['content']);
    self::assertSame('<p>Shown</p>', $body_only['content']['body']['#value']);

    $heading_only = $this->evaluator->feedbackRenderArray(
      '  Compare your response:  ',
      '   ',
    );
    self::assertArrayNotHasKey('body', $heading_only['content']);
    self::assertSame(
      'Compare your response:',
      $heading_only['content']['heading']['#value'],
    );

    self::assertSame([], $this->evaluator->feedbackRenderArray(NULL, NULL));
    self::assertSame([], $this->evaluator->feedbackRenderArray('  ', ''));
  }

  /**
   * Heading text is escaped. The body stays raw for the render filter.
   */
  public function testHeadingIsPlainText(): void {
    $build = $this->evaluator->feedbackRenderArray(
      '<em>Look</em> & "more"',
      '<p>Keep</p>',
    );
    self::assertSame(
      '&lt;em&gt;Look&lt;/em&gt; &amp; &quot;more&quot;',
      $build['content']['heading']['#value'],
    );
    self::assertSame('<p>Keep</p>', $build['content']['body']['#value']);
    $this->assertNeutral($build);
  }

  /**
   * Asserts the render array does not use correct or incorrect styling.
   */
  private function assertNeutral(array $build): void {
    $encoded = json_encode($build);
    self::assertIsString($encoded);
    self::assertStringNotContainsString('correct-answer', $encoded);
    self::assertStringNotContainsString('wrong-answer', $encoded);
    self::assertStringNotContainsString('matched-phrases', $encoded);
    self::assertStringNotContainsString('unmatched-phrases', $encoded);
    self::assertStringNotContainsString('phrase-match', $encoded);
    self::assertStringNotContainsString('phrase-mismatch', $encoded);
  }

}
