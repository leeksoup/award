<?php

declare(strict_types=1);

namespace Drupal\Tests\lms;

use Drupal\Component\Utility\Random;

/**
 * Contains methods for answering activity types.
 */
trait LmsAnswerActivityTrait {

  /**
   * Generate a random word.
   */
  private function randomWord(int $length): string {
    $random = new Random();
    return $random->word($length);
  }

  /**
   * Generates a random sentence.
   */
  private function randomSentence(int $min_length, int $min_word_length = 3, int $max_word_length = 8): string {
    $sentence = '';
    do {
      $sentence .= ' ' . $this->randomWord(\rand($min_word_length, $max_word_length));
    } while (\strlen($sentence) < $min_length);
    return $sentence;
  }

  /**
   * Answer free text activity.
   */
  private function answerFreeText(): array {
    $answer = $this->randomSentence(30, 3, 8);
    $this->setFormElementValue('input', 'edit-answer-0', $answer);
    return [
      'answer' => $answer,
      'evaluated' => FALSE,
    ];
  }

  /**
   * Answer free text with feedback activity.
   */
  private function answerFreeTextFeedback(array $activity_item, int $max_score): array {
    if (
      \array_key_exists('minimum_characters', $activity_item['values']) &&
      $activity_item['values']['minimum_characters'] > 0
    ) {
      $insufficient_count = $activity_item['values']['minimum_characters'] - 1;
      $answer = $this->randomWord($insufficient_count);
      $this->setFormElementValue('input', 'answer', $answer);
      $this->pressButton('Check Answer');
      $error_element = $this->assertSession()->waitForElementVisible('css', '.messages--error');
      $this->assertElementTextContains(
        $error_element,
        // Character counting is somewhat complex in that plugin so not
        // checking the validation error message for an exact match.
        \sprintf('We are looking for a minimum of %d characters, and your answer is', $activity_item['values']['minimum_characters']),
        'Minimum character validation was not triggered.'
      );
      $answer = $this->randomSentence($activity_item['values']['minimum_characters'] + 10);
    }
    else {
      $answer = $this->randomSentence(20);
    }

    // Randomly add checked phrases.
    foreach ($activity_item['values']['phrase_feedback'] as $item) {
      if (\rand(0, 1) === 1) {
        $phrases = \explode('|', $item['phrase']);
        foreach ($phrases as $phrase) {
          if (\rand(0, 1) === 1) {
            $answer .= ' ' . $phrase;
          }
        }
      }
    }

    $this->setFormElementValue('input', 'answer', $answer);
    $this->pressButton('Check Answer');
    $feedback_element = $this->assertSession()->waitForElementVisible('css', '.answer-feedback-wrapper');

    // Perform assertions and calculate score.
    $correct_count = 0;
    $total_count = 0;
    foreach ($activity_item['values']['phrase_feedback'] as $item) {
      $total_count++;
      $found = FALSE;
      foreach (\explode('|', $item['phrase']) as $phrase) {
        if (\strpos($answer, $phrase) !== FALSE) {
          $found = TRUE;
          break;
        }
      }
      if ($found) {
        $this->assertElementTextContains($feedback_element, $item['feedback_present'], 'Success feedback not found on success');
        $this->assertElementTextNotContains($feedback_element, $item['feedback_absent'], 'Failure feedback found on success');
        $correct_count++;
      }
      else {
        $this->assertElementTextNotContains($feedback_element, $item['feedback_present'], 'Success feedback found on failure');
        $this->assertElementTextContains($feedback_element, $item['feedback_absent'], 'Failure feedback not found on failure');
      }
    }
    return [
      'answer' => $answer,
      'score' => \round($max_score * $correct_count / $total_count),
      'evaluated' => TRUE,
    ];
  }

  /**
   * Answer true / false activity.
   */
  private function answerTrueFalse(array $activity_item, int $max_score): array {
    $answer = (string) \mt_rand(0, 1);
    $this->setFormElementValue('radios', 'edit-answer', $answer);
    return [
      'answer' => $answer,
      'score' => $activity_item['values']['bool_expected'] === (bool) $answer ? $max_score : 0,
      'evaluated' => TRUE,
    ];
  }

  /**
   * Answer true / false with feedback activity.
   */
  private function answerTrueFalseFeedback(array $activity_item, int $max_score): array {
    $return = $this->answerTrueFalse($activity_item, $max_score);

    $this->pressButton('Check Answer');
    $feedback_element = $this->assertSession()->waitForElementVisible('css', '.answer-feedback-wrapper');
    if ($return['score'] === 0) {
      $this->assertElementTextContains($feedback_element, $activity_item['values']['feedback_if_wrong']['value'], 'Failure feedback not found on fail');
    }
    else {
      $this->assertElementTextContains($feedback_element, $activity_item['values']['feedback_if_correct']['value'], 'Success feedback not found on success');
    }

    return $return;
  }

  /**
   * No answer activity.
   */
  private function answerNoAnswer(int $max_score): array {
    return [
      'answer' => NULL,
      'score' => $max_score,
      'evaluated' => TRUE,
    ];
  }

  /**
   * Answer select single activity.
   */
  private function answerSelectSingle(array $activity_item, int $max_score): array {
    $answers_count = \count($activity_item['values']['answers']);
    $answer = (string) \mt_rand(0, $answers_count - 1);
    $this->setFormElementValue('radios', 'edit-answer', $answer);
    return [
      'answer' => $answer,
      'score' => $activity_item['values']['answers'][$answer]['correct'] ? $max_score : 0,
      'evaluated' => TRUE,
    ];
  }

  /**
   * Answer select single with feedback activity.
   */
  private function answerSelectSingleFeedback(array $activity_item, int $max_score): array {
    $return = $this->answerSelectSingle($activity_item, $max_score);

    $this->pressButton('Check Answer');
    $feedback_element = $this->assertSession()->waitForElementVisible('css', '.answer-feedback-wrapper');
    if ($return['score'] === 0) {
      $this->assertElementTextContains($feedback_element, $activity_item['values']['feedback_if_wrong']['value'], 'Failure feedback not found on fail');
    }
    else {
      $this->assertElementTextContains($feedback_element, $activity_item['values']['feedback_if_correct']['value'], 'Success feedback not found on success');
    }

    return $return;
  }

  /**
   * Answer select multiple activity.
   */
  private function answerSelectMultiple(array $activity_item, int $max_score): array {
    $max_answers_count = \count($activity_item['values']['answers']);
    $possible_answers = [];
    for ($i = 0; $i < $max_answers_count; $i++) {
      $possible_answers[$i] = $i;
    }
    $given_answers_count = \mt_rand(0, $max_answers_count);
    // @todo array_rand is not very flexible, find a more optimal way.
    if ($given_answers_count === 0) {
      $answer = [];
    }
    elseif ($given_answers_count === 1) {
      $answer = [\array_rand($possible_answers, $given_answers_count)];
    }
    else {
      $answer = \array_rand($possible_answers, $given_answers_count);
    }

    $points = 0;
    $max_points = 0;
    foreach ($possible_answers as $delta) {
      $correct = $activity_item['values']['answers'][$delta]['correct'];
      if ($correct) {
        $max_points++;
      }
      if (\in_array($delta, $answer, TRUE)) {
        $value = TRUE;
        if ($correct) {
          $points++;
        }
        else {
          $points--;
        }
      }
      else {
        $value = FALSE;
      }
      $this->setFormElementValue('checkbox', 'edit-answer-' . $delta, $value);
    }

    if ($max_points === 0) {
      if ($points === 0) {
        $score = 1;
      }
      else {
        $score = 0;
      }
    }
    else {
      if ($points < 0) {
        $points = 0;
      }
      $score = $points / $max_points;
    }

    return [
      'answer' => $answer,
      'score' => (int) \round($max_score * $score),
      'evaluated' => TRUE,
    ];
  }

  /**
   * Answer select multiple with feedback activity.
   */
  private function answerSelectMultipleFeedback(array $activity_item, int $max_score): array {
    $return = $this->answerSelectMultiple($activity_item, $max_score);

    $this->pressButton('Check Answer');
    $feedback_element = $this->assertSession()->waitForElementVisible('css', '.answer-feedback-wrapper');
    if ($return['score'] !== $max_score) {
      $this->assertElementTextContains($feedback_element, $activity_item['values']['feedback_if_wrong']['value'], 'Failure feedback not found on fail');
    }
    else {
      $this->assertElementTextContains($feedback_element, $activity_item['values']['feedback_if_correct']['value'], 'Success feedback not found on success');
    }

    return $return;
  }

  /**
   * Answer fill in the blanks activity.
   *
   * @param array $activity_item
   *   The activity item array.
   * @param int $max_score
   *   The maximum score.
   *
   * @return array
   *   The answer result array.
   */
  private function answerFillInTheBlanks(array $activity_item, int $max_score): array {
    // Extract correct answers from the question text.
    $question_text = $activity_item['values']['field_question_text'];
    \preg_match_all('/\[([^\]]+)\]/', $question_text, $matches);
    $correct_answers = $matches[1];

    // Get distraction words if present.
    $distraction_words = $activity_item['values']['field_distraction_words'] ?? [];
    $all_words = \array_merge($correct_answers, \array_column($distraction_words, 'value'));

    // Sort words alphabetically (matching plugin behavior).
    \sort($all_words, \SORT_STRING);

    // Build mapping of correct answers: drop_zone_id => draggable_id.
    $correct_mapping = [];
    $used_ids = [];
    foreach ($correct_answers as $index => $word) {
      $drop_zone_id = $index + 1;
      // Find the ID for this word in the sorted list.
      foreach ($all_words as $word_index => $available_word) {
        $draggable_id = $word_index + 1;
        if ($available_word === $word && !\in_array($draggable_id, $used_ids, TRUE)) {
          $correct_mapping[$drop_zone_id] = $draggable_id;
          $used_ids[] = $draggable_id;
          break;
        }
      }
    }

    // Randomly select answers for each drop zone.
    $correct_count = 0;
    $student_answers = [];
    $page = $this->getSession()->getPage();
    foreach ($correct_mapping as $dropzone_id => $correct_draggable_id) {
      $draggable_id = \array_rand($all_words);

      // Avoid repetitions.
      unset($all_words[$draggable_id]);

      $draggable_id++;
      $draggable = $page->find('css', "[data-draggable-id='{$draggable_id}']");
      self::assertNotNull($draggable, 'Draggable element not found.');
      $dropzone = $page->find('css', "[data-drop-zone-id='{$dropzone_id}']");
      self::assertNotNull($dropzone, 'Dropzone element not found.');
      $draggable->dragTo($dropzone);

      // dragTo() can silently fail (element snaps back). Retry until the drop
      // zone actually contains the draggable, or assert on timeout.
      $placed = $this->assertSession()->waitForElement(
        'css',
        "[data-drop-zone-id='{$dropzone_id}'] [data-draggable-id='{$draggable_id}']",
      );
      if ($placed === NULL) {
        // One retry — re-fetch both elements to avoid stale references.
        $draggable = $page->find('css', "[data-draggable-id='{$draggable_id}']");
        self::assertNotNull($draggable, 'Draggable element not found on retry.');
        $dropzone = $page->find('css', "[data-drop-zone-id='{$dropzone_id}']");
        self::assertNotNull($dropzone, 'Dropzone element not found on retry.');
        $draggable->dragTo($dropzone);
        $this->assertSession()->waitForElement(
          'css',
          "[data-drop-zone-id='{$dropzone_id}'] [data-draggable-id='{$draggable_id}']",
        );
      }

      if ($correct_draggable_id === $draggable_id) {
        $correct_count++;
      }
      $student_answers[$dropzone_id] = $draggable_id;
    }

    // Calculate score using the same logic as DragAndDropBase::getScore().
    $score_ratio = \count($correct_mapping) > 0 ? $correct_count / \count($correct_mapping) : 0.0;
    $calculated_score = (int) \round($max_score * $score_ratio);

    return [
      'answer' => \json_encode($student_answers),
      'score' => $calculated_score,
      'evaluated' => TRUE,
    ];
  }

  /**
   * Answer fill in the blanks with feedback activity.
   *
   * @param array $activity_item
   *   The activity item array.
   * @param int $max_score
   *   The maximum score.
   *
   * @return array
   *   The answer result array.
   */
  private function answerFillInTheBlanksFeedback(array $activity_item, int $max_score): array {
    $return = $this->answerFillInTheBlanks($activity_item, $max_score);

    $this->pressButton('Check Answer');
    $feedback_element = $this->assertSession()->waitForElementVisible('css', '.answer-feedback-wrapper');
    if ($feedback_element === NULL) {
      $this->screenShot();
      self::fail('No feedback element found.');
    }

    if ($return['score'] === $max_score) {
      $this->assertElementTextContains($feedback_element, $activity_item['values']['field_feedback_if_correct']['value'], 'Success feedback not found on success');
    }
    else {
      $this->assertElementTextContains($feedback_element, $activity_item['values']['field_feedback_if_wrong']['value'], 'Failure feedback not found on fail');
    }

    return $return;
  }

}
