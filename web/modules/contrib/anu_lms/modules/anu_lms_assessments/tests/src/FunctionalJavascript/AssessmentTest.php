<?php

namespace Drupal\Tests\anu_lms\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Test the assessments.
 *
 * @group anu_lms
 */
class AssessmentTest extends WebDriverTestBase {

  // Include methods from ManageContentHelperTrait.
  use ManageContentHelperTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'anu_lms',
    'anu_lms_assessments',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'claro';

  /**
   * Set to TRUE to strict check all configuration saved.
   *
   * @var bool
   *
   * @see \Drupal\Core\Config\Testing\ConfigSchemaChecker
   */
  protected $strictConfigSchema = FALSE;

  /**
   * Block: Question: Short answer.
   *
   * @var string
   */
  protected const QUESTION_SHORT_ANSWER = 'Question: Short answer';

  /**
   * Block: Question: Long answer.
   *
   * @var string
   */
  protected const QUESTION_LONG_ANSWER = 'Question: Long answer';

  /**
   * Block: Question: Single choice.
   *
   * @var string
   */
  protected const QUESTION_SINGLE_CHOICE = 'Question: Single choice';

  /**
   * Block: Question: Multiple choice.
   *
   * @var string
   */
  protected const QUESTION_MULTIPLE_CHOICE = 'Question: Multiple choice';

  /**
   * Block: Question: Scale.
   *
   * @var string
   */
  protected const QUESTION_SCALE = 'Question: Scale';

  /**
   * Test the lesson with questions.
   */
  public function testLessonWithQuestions() {
    $account = $this->drupalCreateUser([], 'test', TRUE);
    $this->drupalLogin($account);

    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();

    // 1. Create a quiz.
    $this->drupalGet('node/add/module_lesson');

    $page->fillField('Title', 'Demo lesson');

    // Set alias.
    $page->findById('edit-path-0')->click();
    $page->fillField('URL alias', '/demo-lesson');

    // Add all types of questions (1 per page, 2 on the last page).
    $this->addBlock(static::QUESTION_SHORT_ANSWER);
    $this->addPage();
    $this->addBlock(static::QUESTION_LONG_ANSWER);
    $this->addPage();
    $this->addBlock(static::QUESTION_SINGLE_CHOICE);
    $this->addPage();
    $this->addBlock(static::QUESTION_MULTIPLE_CHOICE);
    $this->addPage();
    $this->addBlock(static::QUESTION_SCALE);
    $this->addPage();
    $this->addBlock(static::QUESTION_SHORT_ANSWER);
    $this->addBlock(static::QUESTION_SHORT_ANSWER, 'business');

    // Save.
    $page->pressButton('Save');

    // 2. Fill the question on 1st page and submit it.
    $shortAnswerField = $this->getQuestionField('How are you?');
    $this->assertNotEmpty($shortAnswerField);
    $shortAnswerField->setValue('Bad');

    // Submit question.
    $this->submitQuestion();

    // Make sure that input has disabled and "suggested answer" has appeared.
    $this->assertTrue($shortAnswerField->hasAttribute('disabled'));
    $suggestedAnswer = $page->find('css', 'strong:contains("Suggested answer")');
    $this->assertSame('Suggested answer Fine', $suggestedAnswer->getParent()->getText());

    // Go to the next step.
    $nextButton = $assert->waitForElementVisible('css', '#anu-application button[data-test=anu-lms-navigation-next]');
    $nextButton->click();

    // 3. Fill the question on 2nd page and submit it.
    $longAnswerField = $this->getQuestionField('How is the weather?', FALSE, 'textarea');
    $this->assertNotEmpty($longAnswerField);
    $longAnswerField->setValue('It is rainy.');

    // Submit question.
    $this->submitQuestion();

    // Make sure that input has disabled and "suggested answer" has appeared.
    $this->assertTrue($longAnswerField->hasAttribute('disabled'));
    $suggestedAnswer = $page->find('css', 'strong:contains("Suggested answer")');
    $this->assertSame('Suggested answer It is sunny.', $suggestedAnswer->getParent()->getText());

    // Go to the next step.
    $nextButton = $assert->waitForElementVisible('css', '#anu-application button[data-test=anu-lms-navigation-next]');
    $nextButton->click();

    // 4. Fill the question on 3rd page and submit it.
    $singleChoiceField = $this->getQuestionField('Which OS is older?', TRUE);
    $this->assertCount(2, $singleChoiceField);
    $singleChoiceField[1]->click();

    // Submit question.
    $this->submitQuestion();

    // Make sure that radio buttons have disabled and have correct states.
    $this->assertTrue($singleChoiceField[0]->hasAttribute('disabled'));
    $this->assertTrue($singleChoiceField[1]->hasAttribute('disabled'));
    $singleChoice1Wrapper = $page->find('css', 'span[data-radio-option="Which OS is older?:0"]');
    $singleChoice2Wrapper = $page->find('css', 'span[data-radio-option="Which OS is older?:1"]');
    $this->assertSame('anu-lms-radio-correct-error', $singleChoice1Wrapper->getAttribute('data-test'));
    $this->assertSame('anu-lms-radio-incorrect-error', $singleChoice2Wrapper->getAttribute('data-test'));

    // Go to the next step.
    $nextButton = $assert->waitForElementVisible('css', '#anu-application button[data-test=anu-lms-navigation-next]');
    $nextButton->click();

    // 5. Fill the question on 4th page and submit it.
    $multipleChoiceField = $this->getQuestionField('Which languages do you know?', TRUE);
    $this->assertCount(2, $multipleChoiceField);
    $multipleChoiceField[0]->check();
    $multipleChoiceField[1]->check();

    // Submit question.
    $this->submitQuestion();

    // Make sure that radio buttons have disabled and have correct states.
    $this->assertTrue($multipleChoiceField[0]->hasAttribute('disabled'));
    $this->assertTrue($multipleChoiceField[1]->hasAttribute('disabled'));
    $multipleChoice1Wrapper = $page->find('css', 'span[data-checkbox-option="Which languages do you know?:0"]');
    $multipleChoice2Wrapper = $page->find('css', 'span[data-checkbox-option="Which languages do you know?:1"]');
    $this->assertSame('anu-lms-checkbox-success', $multipleChoice1Wrapper->getAttribute('data-test'));
    $this->assertSame('anu-lms-checkbox-success', $multipleChoice2Wrapper->getAttribute('data-test'));

    // Go to the next step.
    $nextButton = $assert->waitForElementVisible('css', '#anu-application button[data-test=anu-lms-navigation-next]');
    $nextButton->click();

    // 6. Fill the question on 5th page and submit it.
    $scaleField = $this->getQuestionField('How many child had Franklin?');
    $this->assertNotEmpty($scaleField);

    // Set value to "3" for "material-ui slider item".
    $jsScript = <<<JS
      const slider = document.querySelector('.MuiSlider-root');
      const fiberKey = Object.keys(slider).find((key) => key.startsWith('__reactFiber$'));
      slider[fiberKey].return.memoizedProps.onChange(null, 3);
    JS;

    $this
      ->getSession()
      ->executeScript($jsScript);

    // Submit question.
    $this->submitQuestion();

    // Make sure that radio buttons have disabled and have correct states.
    $this->assertSame('3', $scaleField->getAttribute('value'));

    // Go to the next step.
    $nextButton = $assert->waitForElementVisible('css', '#anu-application button[data-test=anu-lms-navigation-next]');
    $nextButton->click();

    // 7. Fill the question on 6th page and submit it.
    $shortAnswer1Field = $this->getQuestionField('How are you?');
    $shortAnswer2Field = $this->getQuestionField('How is your business?');
    $shortAnswer1Field->setValue('Bad');
    $shortAnswer2Field->setValue('Well');

    // Submit question.
    $this->submitQuestion();

    // Make sure that inputs have disabled and "suggested answer" have appeared.
    $this->assertTrue($shortAnswerField->hasAttribute('disabled'));
    $suggestedAnswers = $page->findAll('css', 'strong:contains("Suggested answer")');
    $this->assertCount(2, $suggestedAnswers);
    $this->assertSame('Suggested answer Fine', $suggestedAnswers[0]->getParent()->getText());
    $this->assertSame('Suggested answer Fine', $suggestedAnswers[1]->getParent()->getText());
  }

  /**
   * Test the assessments features.
   */
  public function testAssessments() {
    $account = $this->drupalCreateUser([], 'test', TRUE);
    $this->drupalLogin($account);

    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();

    // 1. Create a quiz.
    $this->drupalGet('node/add/module_assessment');

    $page->fillField('Title', 'Demo quiz');

    // Set alias.
    $page->findById('edit-path-0')->click();
    $page->fillField('URL alias', '/demo-quiz');

    // Add all types of questions.
    $this->addBlock(static::QUESTION_SHORT_ANSWER);
    $this->addBlock(static::QUESTION_LONG_ANSWER);
    $this->addBlock(static::QUESTION_SINGLE_CHOICE);
    $this->addBlock(static::QUESTION_MULTIPLE_CHOICE);
    $this->addBlock(static::QUESTION_SCALE);

    // Save.
    $page->pressButton('Save');

    // 2. Make sure that all questions are there.
    $shortAnswerField = $this->getQuestionField('How are you?');
    $this->assertNotEmpty($shortAnswerField);

    $longAnswerField = $this->getQuestionField('How is the weather?', FALSE, 'textarea');
    $this->assertNotEmpty($longAnswerField);

    $singleChoiceField = $this->getQuestionField('Which OS is older?', TRUE);
    $this->assertCount(2, $singleChoiceField);

    $multipleChoiceField = $this->getQuestionField('Which languages do you know?', TRUE);
    $this->assertCount(2, $multipleChoiceField);

    $scaleField = $this->getQuestionField('How many child had Franklin?');
    $this->assertNotEmpty($scaleField);

    // 3. Fill all mentioned above fields and submit the quiz.
    $shortAnswerField->setValue('Bad');
    $longAnswerField->setValue('It is rainy.');
    $singleChoiceField[1]->click();
    $multipleChoiceField[0]->check();
    $multipleChoiceField[1]->check();

    // Set value to "3" for "material-ui slider item".
    $jsScript = <<<JS
      const slider = document.querySelector('.MuiSlider-root');
      const fiberKey = Object.keys(slider).find((key) => key.startsWith('__reactFiber$'));
      slider[fiberKey].return.memoizedProps.onChange(null, 3);
    JS;

    $this
      ->getSession()
      ->executeScript($jsScript);

    // Make sure "scale" field was updated.
    $this->assertSame('3', $scaleField->getAttribute('value'));

    // Make sure that "Finish" button is disabled.
    $finishButton = $assert->waitForElementVisible('css', '#anu-application button[data-test=anu-lms-navigation-finish]');
    $this->assertTrue($finishButton->hasClass('Mui-disabled'));

    // Submit the quiz.
    $submitButton = $page->find('css', '[data-test=anu-lms-quiz-submit]');
    $submitButton->click();
    $assert->waitForElementRemoved('css', '#anu-application button[data-test=anu-lms-quiz-submit]');

    // Make sure all fields are disabled.
    $this->assertTrue($shortAnswerField->hasAttribute('disabled'));
    $this->assertTrue($longAnswerField->hasAttribute('disabled'));
    $this->assertTrue($singleChoiceField[0]->hasAttribute('disabled'));
    $this->assertTrue($singleChoiceField[1]->hasAttribute('disabled'));
    $this->assertTrue($multipleChoiceField[0]->hasAttribute('disabled'));
    $this->assertTrue($multipleChoiceField[1]->hasAttribute('disabled'));

    $slider = $assert->waitForElementVisible('css', '.MuiSlider-root');
    $this->assertNotEmpty($slider);
    $this->assertTrue($slider->hasClass('Mui-disabled'));

    // Make sure that "Finish" button is enabled.
    $finishButton = $assert->waitForElementVisible('css', '#anu-application button[data-test=anu-lms-navigation-finish]:not(.Mui-disabled)');
    $this->assertNotEmpty($finishButton);

    // Make sure that "Submit" button is disappeared.
    $submitButton = $assert->waitForElementVisible('css', '#anu-application button[data-test=anu-lms-quiz-submit]');
    $this->assertEmpty($submitButton);

    // Make sure that "suggested answer" tips appear.
    $suggestedAnswers = $page->findAll('css', 'strong:contains("Suggested answer")');
    $this->assertCount(2, $suggestedAnswers);
    $this->assertSame('Suggested answer Fine', $suggestedAnswers[0]->getParent()->getText());
    $this->assertSame('Suggested answer It is sunny.', $suggestedAnswers[1]->getParent()->getText());

    // Make sure that "correct answer" tips appear.
    $correctAnswers = $page->findAll('css', 'strong:contains("Correct answer:")');
    $this->assertCount(1, $correctAnswers);
    $this->assertSame('Correct answer: 2', $correctAnswers[0]->getParent()->getText());

    // Make sure that radio buttons of "single choice" question have correct
    // states.
    $singleChoice1Wrapper = $page->find('css', 'span[data-radio-option="Which OS is older?:0"]');
    $singleChoice2Wrapper = $page->find('css', 'span[data-radio-option="Which OS is older?:1"]');
    $this->assertSame('anu-lms-radio-correct-error', $singleChoice1Wrapper->getAttribute('data-test'));
    $this->assertSame('anu-lms-radio-incorrect-error', $singleChoice2Wrapper->getAttribute('data-test'));

    // Make sure that checkboxes of "multiple choice" question have correct
    // states.
    $multipleChoice1Wrapper = $page->find('css', 'span[data-checkbox-option="Which languages do you know?:0"]');
    $multipleChoice2Wrapper = $page->find('css', 'span[data-checkbox-option="Which languages do you know?:1"]');
    $this->assertSame('anu-lms-checkbox-success', $multipleChoice1Wrapper->getAttribute('data-test'));
    $this->assertSame('anu-lms-checkbox-success', $multipleChoice2Wrapper->getAttribute('data-test'));

    // Make sure that "score" is right.
    $scored = $page->find('css', 'h5:contains("You scored")');
    $this->assertSame('You scored 3 out of 5.', $scored->getText());

    // 4. Set "Hide correct answers" checkbox for the quiz.
    $assessment = $this->drupalGetNodeByTitle('Demo quiz');
    $this->drupalGet('node/' . $assessment->id() . '/edit');

    // Go to "Settings" tab.
    $page->findLink('Settings')->click();
    $page->checkField('Hide correct answers');

    // Save the quiz.
    $page->pressButton('Save');

    // 5. Make sure that "Suggested/correct answer" tips will not be appeared
    // and radio/checkboxes will not change color after submitting the form.
    $submitButton = $page->find('css', '[data-test=anu-lms-quiz-submit]');
    $submitButton->click();
    $assert->waitForElementRemoved('css', '#anu-application button[data-test=anu-lms-quiz-submit]');

    // Make sure that "suggested answer" tips aren't there.
    $suggestedAnswers = $page->findAll('css', 'strong:contains("Suggested answer")');
    $this->assertCount(0, $suggestedAnswers);

    // Make sure that "correct answer" tips aren't there.
    $correctAnswers = $page->findAll('css', 'strong:contains("Correct answer:")');
    $this->assertCount(0, $correctAnswers);

    // Make sure that radio buttons of "single choice" don't have any
    // states.
    $singleChoice1Wrapper = $page->find('css', 'span[data-radio-option="Which OS is older?:0"]');
    $singleChoice2Wrapper = $page->find('css', 'span[data-radio-option="Which OS is older?:1"]');
    $this->assertSame('anu-lms-radio', $singleChoice1Wrapper->getAttribute('data-test'));
    $this->assertSame('anu-lms-radio', $singleChoice2Wrapper->getAttribute('data-test'));

    // Make sure that checkboxes of "multiple choice" question don't have any
    // states.
    $multipleChoice1Wrapper = $page->find('css', 'span[data-checkbox-option="Which languages do you know?:0"]');
    $multipleChoice2Wrapper = $page->find('css', 'span[data-checkbox-option="Which languages do you know?:1"]');
    $this->assertSame('anu-lms-checkbox', $multipleChoice1Wrapper->getAttribute('data-test'));
    $this->assertSame('anu-lms-checkbox', $multipleChoice2Wrapper->getAttribute('data-test'));

    // 6. Set "Prevent multiple submissions" checkbox for the quiz.
    $this->drupalGet('node/' . $assessment->id() . '/edit');

    // Go to "Settings" tab.
    $page->findLink('Settings')->click();
    $page->checkField('Prevent multiple submissions');

    // Save the quiz.
    $page->pressButton('Save');

    // 7. Make sure that a user will be able to send form only once.
    $account = $this->drupalCreateUser([], 'test-2', TRUE);
    $this->drupalLogin($account);

    // Open the page with quiz again.
    $this->drupalGet('demo-quiz');

    $shortAnswerField = $this->getQuestionField('How are you?');
    $longAnswerField = $this->getQuestionField('How is the weather?', FALSE, 'textarea');
    $singleChoiceField = $this->getQuestionField('Which OS is older?', TRUE);
    $multipleChoiceField = $this->getQuestionField('Which languages do you know?', TRUE);

    $shortAnswerField->setValue('Bad');
    $longAnswerField->setValue('It is rainy.');
    $singleChoiceField[1]->click();
    $multipleChoiceField[0]->check();
    $multipleChoiceField[1]->check();

    // Submit the quiz.
    $submitButton = $page->find('css', '[data-test=anu-lms-quiz-submit]');
    $submitButton->click();
    $confirmButton = $page->find('css', '.MuiDialogActions-root button:last-child');
    $confirmButton->click();
    $assert->waitForElementRemoved('css', '#anu-application button[data-test=anu-lms-quiz-submit]');

    // Open the page with quiz again.
    $this->drupalGet('demo-quiz');

    // Make sure that all questions have disabled.
    $this->assertTrue($shortAnswerField->hasAttribute('disabled'));
    $this->assertTrue($longAnswerField->hasAttribute('disabled'));
    $this->assertTrue($singleChoiceField[0]->hasAttribute('disabled'));
    $this->assertTrue($singleChoiceField[1]->hasAttribute('disabled'));
    $this->assertTrue($multipleChoiceField[0]->hasAttribute('disabled'));
    $this->assertTrue($multipleChoiceField[1]->hasAttribute('disabled'));

    $slider = $assert->waitForElementVisible('css', '.MuiSlider-root');
    $this->assertNotEmpty($slider);
    $this->assertStringContainsString('Mui-disabled', $slider->getAttribute('class'));

    // Make sure that "Submit" button has disappeared.
    $submitButton = $page->find('css', '[data-test=anu-lms-quiz-submit]');
    $this->assertEmpty($submitButton);
  }

  /**
   * Add a content block.
   *
   * @param string $type
   *   Type of block.
   * @param string|null $option
   *   Option of "values" for block.
   */
  private function addBlock($type, $option = NULL) {
    $assert = $this->assertSession();

    $this->findLastButton('Add block')->click();
    $assert->assertWaitOnAjaxRequest();

    // Choose the requested paragraph.
    $assert
      ->waitForElementVisible('css', 'img[title="' . $type . '"]')
      ->click();
    $assert->assertWaitOnAjaxRequest();

    // Fill values for a block.
    if ($type === static::QUESTION_SHORT_ANSWER) {
      if ($option === 'business') {
        $this->findLastField('Question')->setValue('How is your business?');
      }
      else {
        $this->findLastField('Question')->setValue('How are you?');
      }

      $this->findLastField('Correct answer')->setValue('Fine');
    }
    elseif ($type === static::QUESTION_LONG_ANSWER) {
      $this->findLastField('Question')->setValue('How is the weather?');
      $this->findLastField('Correct answer')->setValue('It is sunny.');
    }
    elseif ($type === static::QUESTION_SINGLE_CHOICE) {
      $this->findLastField('Question')->setValue('Which OS is older?');

      // Add 1st option.
      $this->findLastField('Option')->setValue('Fedora');
      $this->findLastField('Is correct')->check();
      $this->findLastButton('Add Single/Multi-choice item')->click();

      $assert->assertWaitOnAjaxRequest();

      // Add 2nd option.
      $this->findLastField('Option')->setValue('Ubuntu');
    }
    elseif ($type === static::QUESTION_MULTIPLE_CHOICE) {
      $this->findLastField('Question')->setValue('Which languages do you know?');

      // Add 1st option.
      $this->findLastField('Option')->setValue('Spanish');
      $this->findLastField('Is correct')->check();
      $this->findLastButton('Add Single/Multi-choice item')->click();

      $assert->assertWaitOnAjaxRequest();

      // Add 2nd option.
      $this->findLastField('Option')->setValue('English');
      $this->findLastField('Is correct')->check();
    }
    elseif ($type === static::QUESTION_SCALE) {
      $this->findLastField('Question')->setValue('How many child had Franklin?');
      $this->findLastField('From')->setValue('1');
      $this->findLastField('to')->setValue('8');
      $this->findLastField('Correct value')->setValue('2');
    }
  }

  /**
   * Add a page.
   */
  private function addPage() {
    $assert = $this->assertSession();
    $this->findLastButton('Add Page')->click();
    $assert->assertWaitOnAjaxRequest();
  }

  /**
   * Submit the question.
   */
  private function submitQuestion() {
    $page = $this->getSession()->getPage();
    $assert = $this->assertSession();

    $nextButton = $assert->waitForElementVisible('css', '#anu-application button[data-test=anu-lms-navigation-next]');

    // Make sure "Next button" has disabled.
    if ($nextButton) {
      $this->assertTrue($nextButton->hasClass('Mui-disabled'));
    }

    while ($submitButton = $page->find('css', '#anu-application button[data-test=anu-lms-quiz-submit]')) {
      $submitButton->click();
      $assert->waitForElementRemoved('css', '#anu-application button[data-test=anu-lms-quiz-submit]');
    }

    // Make sure "Next button" has enabled.
    if ($nextButton) {
      $nextButton = $assert->waitForElementVisible('css', '#anu-application button[data-test=anu-lms-navigation-next]:not(.Mui-disabled)');
      $this->assertNotEmpty($nextButton);
    }
  }

}
