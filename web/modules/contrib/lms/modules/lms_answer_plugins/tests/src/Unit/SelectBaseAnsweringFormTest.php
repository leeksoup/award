<?php

declare(strict_types=1);

namespace Drupal\Tests\lms_answer_plugins\Unit;

use Drupal\Core\Form\FormStateInterface;
use Drupal\lms\Entity\ActivityInterface;
use Drupal\lms\Entity\Answer;
use Drupal\lms_answer_plugins\Plugin\ActivityAnswer\Select;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group as AttributesGroup;

/**
 * Tests default value handling in SelectBase::answeringForm().
 *
 * @see https://www.drupal.org/project/lms/issues/3540959
 */
#[AttributesGroup('lms')]
final class SelectBaseAnsweringFormTest extends UnitTestCase {

  /**
   * Builds a Select plugin instance with a stubbed translation service.
   */
  private function buildPlugin(array $configuration): Select {
    $plugin = new Select($configuration, 'select', []);
    $plugin->setStringTranslation($this->getStringTranslationStub());
    return $plugin;
  }

  /**
   * Builds an answer mock returning the given stored data.
   */
  private function buildAnswer(array $data): Answer {
    $activity = $this->createMock(ActivityInterface::class);
    $activity->method('get')->with('answers')->willReturn([]);

    $answer = $this->createMock(Answer::class);
    $answer->method('getActivity')->willReturn($activity);
    $answer->method('getData')->willReturn($data);
    return $answer;
  }

  /**
   * A raw submitted checkboxes value must not re-check the first option.
   */
  public function testCheckboxesDefaultValueIsNormalized(): void {
    $answer = $this->buildAnswer(['answer' => [0 => 0, 1 => 1, 2 => 0]]);

    $plugin = $this->buildPlugin(['selection_type' => 'multiple']);
    $form = [];
    $plugin->answeringForm($form, $this->createMock(FormStateInterface::class), $answer);

    self::assertSame([1], $form['answer']['#default_value']);
  }

  /**
   * A single-selection answer is passed through untouched.
   */
  public function testRadiosDefaultValueIsUnchanged(): void {
    $answer = $this->buildAnswer(['answer' => '1']);

    $plugin = $this->buildPlugin(['selection_type' => 'single']);
    $form = [];
    $plugin->answeringForm($form, $this->createMock(FormStateInterface::class), $answer);

    self::assertSame('1', $form['answer']['#default_value']);
  }

}
