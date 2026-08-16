<?php

declare(strict_types=1);

namespace Drupal\Tests\lms;

use Behat\Mink\Element\DocumentElement;
use Behat\Mink\Element\NodeElement;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Render\Markup;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\lms\Entity\AnswerInterface;
use Drupal\lms\Entity\CourseStatusInterface;
use Drupal\lms\Entity\LessonStatusInterface;
use Drupal\lms\LmsContentImporter;

/**
 * Contains methods useful in tests.
 */
trait LmsTestHelperTrait {

  use LmsAnswerActivityTrait;

  /**
   * Get field name - field type mapping.
   *
   * @todo Move this to class const once PHP 8.1 is no longer supported.
   */
  private function getTypesMapping(): array {
    return [
      // "input" type is default and used if no mapping is found.
      'bool_expected' => 'radios',
      'mandatory' => 'checkbox',
      'correct' => 'checkbox',
      'backwards_navigation' => 'checkbox',
      'auto_repeat_failed' => 'checkbox',
      'status' => 'checkbox',
      'free_navigation' => 'checkbox',
      'randomization' => 'radios',

      // Default mapping.
      'radios' => 'radios',
      'checkbox' => 'checkbox',
      'select' => 'select',
      'input' => 'input',
    ];
  }

  /**
   * Answer data.
   */
  private array $answers = [];

  /**
   * Dev use only - screenshot delta.
   */
  private int $screenShotDelta = 0;

  /**
   * Get existing activity - answer plugin definitions.
   */
  private function getActivityAnswerPluginDefinitions(): array {
    return $this->container->get('plugin.manager.activity_answer')->getDefinitions();
  }

  /**
   * Helper method to set LMS entity reference values.
   */
  private function setLmsReferenceField(string $field_name, array $values): void {
    if ($field_name === 'activities') {
      $entity_type = 'lms_activity';
      // This is a trait so phpstan may be right or not depending on use place.
      // @phpstan-ignore function.alreadyNarrowedType
      $bag = \property_exists($this, 'activityData') ? $this->activityData : [];
    }
    elseif ($field_name === 'lessons') {
      $entity_type = 'lms_lesson';
      // This is a trait so phpstan may be right or not depending on use place.
      // @phpstan-ignore function.alreadyNarrowedType
      $bag = \property_exists($this, 'lessonData') ? $this->lessonData : [];
    }
    else {
      throw new \InvalidArgumentException(\sprintf("Unsupported field: %s", $field_name));
    }
    $modal_view_selector = \sprintf('[role="dialog"].modal-view-%s-selection', $field_name);

    $assert_session = $this->assertSession();

    foreach ($values as $item) {
      $this->pressButton(\sprintf('Reference %s', $field_name));
      $modal = $assert_session->waitForElementVisible('css', $modal_view_selector);
      if (\array_key_exists('entity', $item)) {
        $entity = $item['entity'];
        unset($item['entity']);
      }
      else {
        $entity = $this->getEntityByProperties($entity_type, [
          'name' => $this->getItemByUuid($item['target_uuid'], $bag, 'name'),
        ]);
        unset($item['target_uuid']);
      }

      $selector = \sprintf('[value="%s"]', \implode(':', [
        $entity->getEntityTypeId(),
        $entity->bundle(),
        $entity->id(),
      ]));
      // If the next line fails, probably the view doesn't show
      // enough entities.
      $modal->find('css', $selector)->check();

      $modal->find('css', '.ui-dialog-buttonset > .lms-add-references')->click();
      $assert_session->waitForElementRemoved('css', $modal_view_selector);

      // Open parameters modal.
      $selector = \sprintf('[data-drupal-selector="edit-%s-table-%d-actions-edit-parameters"]', $field_name, $entity->id());
      $assert_session->waitForElementVisible('css', $selector)->click();
      $selector = \sprintf('[role="dialog"].modal-entity-%s', \strtr($entity_type, '_', '-'));
      $modal = $assert_session->waitForElementVisible('css', $selector);
      // Set parameter values.
      foreach ($item as $property => $value) {
        // Warning: it may potentially happen that there is more than one
        // field with the same name on the page.
        $this->setFormElementValue($property, $property, $value);
      }

      // Submit and wait for the modal to close.
      $modal->find('css', 'button.form-submit')->click();
      $assert_session->waitForElementRemoved('css', $selector);
    }
  }

  /**
   * Returns test data property for a given UUID.
   */
  private function getItemByUuid(string $uuid, array $bag, ?string $property = NULL): array|string {
    foreach ($bag as $item) {
      if ($item['uuid'] === $uuid) {
        return $property === NULL ? $item : $item['values'][$property];
      }
    }

    throw new \InvalidArgumentException(\sprintf("UUID %s doesn't exist in test data.", $uuid));
  }

  /**
   * Helper method to filter entity data by owner user UUID.
   */
  private function filterByOwnerUuid(array $filterable, string $uuid): array {
    return \array_filter($filterable, static fn ($item) => $item['owner_uuid'] === $uuid);
  }

  /**
   * Dev helper method to create a screenshot quickly.
   */
  private function screenShot(): void {
    if (!\is_dir($this->htmlOutputDirectory)) {
      return;
    }
    if ($this->screenShotDelta === 0) {
      for ($i = 0;; $i++) {
        $filename = $this->htmlOutputDirectory . '/test_' . $i . '.jpeg';
        if (!\file_exists($filename)) {
          break;
        }
        \unlink($filename);
      }
    }
    $this->createScreenshot($this->htmlOutputDirectory . '/test_' . $this->screenShotDelta . '.jpeg');
    $this->screenShotDelta++;
  }

  /**
   * Import test config.
   */
  private function importTestConfig(string $directory = ''): void {
    if ($directory === '') {
      $directory = __DIR__ . '/../config';
    }
    $active = $this->container->get('config.storage');
    $sync = $this->container->get('config.storage.sync');
    $this->copyConfig($active, $sync);
    $file_system = $this->container->get('file_system');
    $config_sync_dir = Settings::get('config_sync_directory');
    // Path in the project dir: /tests/config.
    foreach ($file_system->scanDirectory($directory, '/.*\.yml/') as $file) {
      $file_system->copy($file->uri, $config_sync_dir);
    }
    $this->configImporter()->import();
  }

  /**
   * Get entity ID by properties.
   */
  private function getEntityIdByProperties(string $entity_type_id, array $properties): string {
    $query = $this->container->get('entity_type.manager')->getStorage($entity_type_id)->getQuery();
    $query->accessCheck(FALSE);
    foreach ($properties as $property => $value) {
      $query->condition($property, $value);
    }
    $results = $query->execute();
    if (\count($results) === 0) {
      throw new \Exception(\sprintf('Unable to find entity type %s with %s', $entity_type_id, print_r($properties, TRUE)));
    }
    return \reset($results);
  }

  /**
   * Get entity by properties.
   */
  private function getEntityByProperties(string $entity_type_id, array $properties): ContentEntityInterface {
    /** @var \Drupal\Core\Entity\ContentEntityInterface */
    return $this->container->get('entity_type.manager')->getStorage($entity_type_id)->load($this->getEntityIdByProperties($entity_type_id, $properties));
  }

  /**
   * Set entity form field value.
   */
  private function setEntityFormField(string $field, mixed $value, array $path = []): void {
    // Simplest option - set value column only.
    if (!\is_array($value)) {
      $value = [
        'value' => $value,
      ];
    }

    // Multiple elements case.
    if (\is_numeric(\array_keys($value)[0])) {
      $count = \count($value);
      foreach ($value as $internal_delta => $item_value) {
        $item_path = $path;
        $item_path[] = $internal_delta;
        $this->setEntityFormField($field, $item_value, $item_path);
        $next = $internal_delta + 1;
        if ($next < $count) {
          $this->pressButton('Add another item');
          $this->assertSession()->waitForElementVisible('css', '[name="' . $field . '_' . $next . '_remove_button"]');
        }
      }
      return;
    }

    // Always add zero delta if not already added by multi value logic.
    if (\count($path) === 0) {
      $path[0] = '0';
    }

    foreach ($value as $column => $column_value) {
      $element_path = $path;
      $element_path[] = $column;

      // More nesting?
      if (\is_array($column_value)) {
        $this->setEntityFormField($field, $column_value, $element_path);
        continue;
      }

      // Set element value.
      $type = $this->getTypesMapping()[$field] ?? 'input';
      if ($type === 'radios') {
        $selector = \strtr('edit-' . $field, '_', '-');
      }
      else {
        // Exception: single value checkboxes don't have deltas in their path.
        if ($type === 'checkbox') {
          \array_shift($element_path);
        }

        $selector = $field;
        foreach ($element_path as $path_component) {
          $selector .= '[' . $path_component . ']';
        }
      }

      $this->setFormElementValue($type, $selector, $column_value);
    }
  }

  /**
   * Helper method to set any form field.
   */
  private function setFormElementValue(string $field, string $selector, mixed $value): void {
    $type = $this->getTypesMapping()[$field] ?? 'input';

    if ($type === 'radios') {
      if ($value === TRUE) {
        $value = '1';
      }
      elseif ($value === FALSE) {
        $value = '0';
      }
      $selector .= '-' . $value;
    }
    $field = $this->getSession()->getPage()->findField($selector);
    if ($field === NULL) {
      $this->screenShot();
      self::fail(\sprintf('Form field named %s not found.', $selector));
    }

    if ($type === 'checkbox') {
      if ($value === TRUE) {
        $field->check();
      }
      else {
        $field->uncheck();
      }
    }
    elseif ($type === 'radios' || $type === 'select') {
      $field->selectOption($value);
    }
    else {
      $field->setValue((string) $value);
    }
  }

  /**
   * Answer an activity.
   */
  private function answerActivity(array $activity_item, int $max_score, string $student_id, string $course_id): void {
    $uuid = $activity_item['uuid'];

    $this->answers[$course_id][$student_id][$uuid] = match($activity_item['type']) {
      'free_text' => $this->answerFreeText(),
      'free_text_feedback' => $this->answerFreeTextFeedback($activity_item, $max_score),
      'true_false' => $this->answerTrueFalse($activity_item, $max_score),
      'true_false_feedback' => $this->answerTrueFalseFeedback($activity_item, $max_score),
      'no_answer' => $this->answerNoAnswer($max_score),
      'select_single' => $this->answerSelectSingle($activity_item, $max_score),
      'select_single_feedback' => $this->answerSelectSingleFeedback($activity_item, $max_score),
      'select_multiple' => $this->answerSelectMultiple($activity_item, $max_score),
      'select_multiple_feedback' => $this->answerSelectMultipleFeedback($activity_item, $max_score),
      'fill_in_the_blanks' => $this->answerFillInTheBlanks($activity_item, $max_score),
      'fill_in_the_blanks_feedback' => $this->answerFillInTheBlanksFeedback($activity_item, $max_score),
      default => throw new \InvalidArgumentException(\sprintf('Unsupported activity type: %s', $activity_item['type'])),
    };

    // 0 max score activities are always set as evaluated.
    if ($max_score === 0) {
      $this->answers[$course_id][$student_id][$uuid]['evaluated'] = TRUE;
      $this->answers[$course_id][$student_id][$uuid]['score'] = 0;
    }

    // We need to use the css selector as button ID and value are subject
    // to change.
    $this->pressButton('[data-drupal-selector="edit-submit"]', 'css');
  }

  /**
   * Get answer.
   */
  private function getAnswerData(string $activity_uuid, string $student_id, string $course_id): array {
    return $this->answers[$course_id][$student_id][$activity_uuid];
  }

  /**
   * Used in both answer evaluation methods.
   */
  private function getAnswerEvaluateParameters(array $activity_item, array $lesson_item, string $student_id, string $course_id): array {
    $course_status_id = $this->getEntityIdByProperties('lms_course_status', [
      'uid' => $student_id,
      CourseStatusInterface::COURSE_FIELD => $course_id,
    ]);
    $lesson = $this->getEntityByProperties('lms_lesson', [
      'name' => $lesson_item['values']['name'],
    ]);
    $lesson_status_id = $this->getEntityIdByProperties('lms_lesson_status', [
      'course_status' => $course_status_id,
      LessonStatusInterface::LESSON_FIELD => $lesson->id(),
    ]);

    $activity = $this->getEntityByProperties('lms_activity', [
      'name' => $activity_item['values']['name'],
    ]);

    $answer_id = $this->getEntityIdByProperties('lms_answer', [
      'user_id' => $student_id,
      'lesson_status' => $lesson_status_id,
      AnswerInterface::ACTIVITY_FIELD => $activity->id(),
    ]);
    return [
      $lesson,
      $activity,
      $answer_id,
    ];
  }

  /**
   * Evaluate answer using results page modal form.
   */
  private function evaluateAnswerModal(array $activity_item, array $lesson_item, string $student_id, string $course_id): void {
    $assert_session = $this->assertSession();
    // Drupal CI adds /web in front of URIs and this assert fails.
    if (FALSE) {
      $url = Url::fromRoute('lms.group.results', [
        'group' => $course_id,
        'user' => $student_id,
      ])->toString();
      $assert_session->addressEquals($url);
    }

    [$lesson, $activity, $answer_id] = $this->getAnswerEvaluateParameters($activity_item, $lesson_item, $student_id, $course_id);

    $details_uri = Url::fromRoute('lms.answer.details', [
      'lms_answer' => $answer_id,
      'js' => 'nojs',
    ])->toString();
    $this->getSession()->getPage()->find('css', '[href="' . $details_uri . '"]')->click();

    $modal = $assert_session->waitForElementVisible('css', '[role="dialog"]');
    $answer_data = &$this->answers[$course_id][$student_id][$activity_item['uuid']];
    $this->assertElementTextContains($modal, $answer_data['answer'], \sprintf('Answer %s not found on evaluation modal.', $answer_data['answer']));

    $max_score = $this->container->get('lms.training_manager')->getActivityMaxScore($lesson, $activity);
    $answer_data['score'] = (string) \mt_rand(0, $max_score);
    $modal->fillField('score', $answer_data['score']);

    $modal->fillField('comment[comment_body][0][value]', \sprintf('Teacher comment to %s', $activity_item['uuid']));

    // Click the right button (there are 3, of which one is hidden).
    $modal->find('css', 'button.form-submit')->click();
    // Modal should be closed.
    $assert_session->waitForElementRemoved('css', '[role="dialog"]');
  }

  /**
   * Evaluate answer no JS.
   */
  private function evaluateAnswerNoJs(array $activity_item, array $lesson_item, string $student_id, string $course_id): void {
    [$lesson, $activity, $answer_id] = $this->getAnswerEvaluateParameters($activity_item, $lesson_item, $student_id, $course_id);

    $this->drupalGet(Url::fromRoute('lms.answer.evaluate', [
      'lms_answer' => $answer_id,
      'js' => 'nojs',
    ]));

    $answer_data = &$this->answers[$course_id][$student_id][$activity_item['uuid']];

    $this->assertSession()->pageTextContains($answer_data['answer']);

    $page = $this->getSession()->getPage();
    $answer_data = &$this->answers[$course_id][$student_id][$activity_item['uuid']];
    $max_score = $this->container->get('lms.training_manager')->getActivityMaxScore($lesson, $activity);
    $answer_data['score'] = (string) \mt_rand(0, $max_score);
    $page->fillField('score', $answer_data['score']);
    $page->fillField('comment[comment_body][0][value]', \sprintf('Teacher comment to %s', $activity_item['uuid']));
    $this->pressButton('.ui-dialog-buttonset button', 'css');
  }

  /**
   * Calculate course score.
   *
   * NOTE: Will not calculate properly with randomization enabled or
   * for not evaluated course attempts use only if course is finished
   * and evaluated.
   *
   * @return array
   *   Array containing pass / fail boolean and score in percents.
   */
  private function calculateCourseResult(array $course_item, string $student_id): array {
    $course_id = $this->getEntityIdByProperties('group', [
      'label' => $course_item['values']['label'],
    ]);
    $answer_data = $this->answers[$course_id][$student_id];

    $passed = TRUE;
    $max_score = 0;
    $score_weighted_sum = 0;
    foreach ($course_item['lessons'] as $course_lesson_item) {
      $lesson_item = $this->getItemByUuid($course_lesson_item['target_uuid'], $this->lessonData);
      $lesson_max_score = 0;
      $lesson_score = 0;
      foreach ($lesson_item['activities'] as $lesson_activity_item) {
        $lesson_max_score += $lesson_activity_item['max_score'];
        $lesson_score += $answer_data[$lesson_activity_item['target_uuid']]['score'];
      }

      // Lesson score.
      if ($lesson_max_score === 0) {
        $lesson_score = 100;
      }
      else {
        $lesson_score = (int) \round($lesson_score / $lesson_max_score * 100);
      }

      $max_score += $lesson_max_score;
      $score_weighted_sum += $lesson_score * $lesson_max_score;

      if ($lesson_score < $course_lesson_item['required_score']) {
        $passed = FALSE;
      }
    }

    return [
      $passed,
      (int) \round($score_weighted_sum / $max_score),
    ];
  }

  /**
   * Code saver.
   */
  private function assertElementTextContains(NodeElement $element, string $text, string $message): void {
    // Implement waiting as the element may not be fully loaded
    // (fast test runner platforms).
    $result = $element->waitFor(5, function (NodeElement $element) use ($text) {
      return \strpos($element->getText(), $text) !== FALSE;
    });
    if ($result === FALSE) {
      $message .= \sprintf(' expected to find "%s" in "%s"', $text, $element->getText());
    }
    self::assertTrue($result, $message);
  }

  /**
   * Code saver.
   */
  private function assertElementTextNotContains(NodeElement $element, string $text, string $message): void {
    // Implement waiting as the element may not be fully loaded
    // (fast test runner platforms).
    $result = $element->waitFor(5, function (NodeElement $element) use ($text) {
      return \strpos($element->getText(), $text) === FALSE;
    });
    if ($result === FALSE) {
      $message .= \sprintf(' not expected to find "%s" in "%s"', $text, $element->getText());
    }
    self::assertTrue($result, $message);
  }

  /**
   * Scoring all unevaluated answers and recalculate statuses.
   */
  private function evaluateCourse(string $course_id, string $student_id): void {
    $course_status = $this->getEntityByProperties('lms_course_status', [
      CourseStatusInterface::COURSE_FIELD => $course_id,
      'uid' => $student_id,
    ]);
    \assert($course_status instanceof CourseStatusInterface);
    $training_manager = $this->container->get('lms.training_manager');
    foreach ($this->container->get('entity_type.manager')->getStorage('lms_lesson_status')->loadByProperties([
      'course_status' => $course_status->id(),
    ]) as $lesson_status) {
      \assert($lesson_status instanceof LessonStatusInterface);
      /** @var \Drupal\lms\Entity\AnswerInterface */
      foreach ($this->container->get('entity_type.manager')->getStorage('lms_answer')->loadByProperties([
        'lesson_status' => $lesson_status->id(),
      ]) as $answer) {
        if (!$answer->isEvaluated()) {
          $max_score = $training_manager->getActivityMaxScore($lesson_status->getLesson(), $answer->getActivity());
          $answer
            ->setScore($max_score)
            ->setEvaluated(TRUE)
            ->save();
        }
      }
      $training_manager->updateLessonStatus($lesson_status);
    }
    $training_manager->updateCourseStatus($course_status);
    // Clear entity cache.
    $this->container->get('cache.entity')->deleteAll();
  }

  /**
   * Set source data.
   */
  private function setSourceData(): void {
    foreach ([
      'activityTypesData' => __DIR__ . '/../data/activity_types.yml',
      'activityData' => __DIR__ . '/../data/activities.yml',
      'lessonData' => __DIR__ . '/../data/lessons.yml',
      'courseData' => __DIR__ . '/../data/courses.yml',
      'userData' => __DIR__ . '/../data/users.yml',
    ] as $property => $source_file) {
      if (\property_exists($this, $property)) {
        $this->{$property} = Yaml::decode(\file_get_contents($source_file));
      }
    }
  }

  /**
   * Get source data from a bag.
   */
  private function getSourceData(string $property, ?int $delta = NULL): array {
    $map = [
      'lms_activity' => 'activityData',
      'lms_lesson' => 'lessonData',
      'group' => 'courseData',
      'user' => 'userData',
    ];
    if (\array_key_exists($property, $map)) {
      $property = $map[$property];
    }
    if (!\property_exists($this, $property)) {
      throw new \InvalidArgumentException('Invalid bag.');
    }
    return $delta === NULL ? $this->{$property} : $this->{$property}[$delta];
  }

  /**
   * Create test content.
   */
  private function createTestContent(string $module = 'lms'): self {
    // Some field storage definitions may not be updated in the test runner.
    drupal_flush_all_caches();
    $importer = $this->container->get(LmsContentImporter::class);
    $importer->installActivityTypes($module);

    if ($module !== '') {
      $importer->import($importer->getData($module), [
        'simple-passwords' => TRUE,
        'module' => $module,
      ]);
    }

    // Set passwords for login on users.
    if (
      !\property_exists($this, 'userData') ||
      !\property_exists($this, 'users')
    ) {
      return $this;
    }
    $users = $this->container->get('entity_type.manager')->getStorage('user')->loadMultiple();
    foreach ($users as $user) {
      foreach ($this->userData as $name => $item) {
        if ($item['uuid'] === $user->uuid()) {
          $user->pass_raw = '123456';
          $user->passRaw = $user->pass_raw;
          $this->users[$name] = $user;
          continue;
        }
      }
    }

    return $this;
  }

  /**
   * Delete test content.
   */
  private function deleteTestContent(): self {
    $this->drupalLogin($this->users['admin']);
    foreach ($this->container->get('entity_type.manager')->getStorage('group')->getQuery()->accessCheck(FALSE)->execute() as $group_id) {
      $this->drupalGet(Url::fromRoute('entity.group.delete_form', [
        'group' => $group_id,
      ]));
      $this->pressButton('edit-submit');
    }
    $this->drupalGet(Url::fromRoute('system.cron_settings'));
    $this->pressButton('edit-run');
    return $this;
  }

  /**
   * Watchdog cleanness test.
   */
  private function watchdogTest(): void {
    $query = $this->container->get('database')->select('watchdog', 'w');
    $results = $query
      ->condition('severity', RfcLogLevel::NOTICE, '<=')
      ->condition('type', ['php', 'modal_form'], 'IN')
      ->fields('w', [
        'type',
        'message',
        'variables',
        'severity',
        'location',
      ])
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $messages = [];
    foreach ($results as $result) {
      $variables = \unserialize($result['variables'], ['allowed_classes' => [Markup::class]]);
      $message = new FormattableMarkup($result['message'], $variables);
      $messages[] = \sprintf('Severity: %d, type: %s, location: %s, message: %s',
        $result['severity'],
        $result['type'],
        $result['location'],
        \strip_tags((string) $message)
      );
    }

    self::assertEmpty($messages, \implode(\PHP_EOL, $messages));
  }

  /**
   * Save current HTML state to a file.
   */
  private function storeCurrentHtml(bool $failure_only = FALSE): void {
    if ($failure_only) {
      // Not working on older phpunit..
      // @todo Remove the fix after D10 will no longer be supported.
      if (!\method_exists($this, 'status')) {
        return;
      }

      $status = $this->status();
      if (
        !$status->isError() &&
        !$status->isWarning() &&
        !$status->isFailure()
      ) {
        return;
      }
    }
    \file_put_contents($this->htmlOutputDirectory . '/current_html.html', $this->getSession()->getPage()->getHtml());
  }

  /**
   * Override the pressButton method.
   *
   * @todo Track https://www.drupal.org/project/drupal/issues/2936122
   */
  private function pressButton(string $selector, string $type = 'default'): void {
    $session = $this->getSession();
    $page = $session->getPage();
    $before = $page->getHtml();
    if ($type === 'default') {
      $button = $page->findButton($selector);
    }
    else {
      $button = $page->find($type, $selector);
    }
    self::assertNotNull($button, \sprintf('Button "%s" not found.', $selector));
    $button->press();
    $result = $page->waitFor(5, function (DocumentElement $page) use ($before, $session) {
      $page_html = $page->getHtml();
      return $page_html !== '' && \strcmp($page_html, $before) !== 0 && (bool) $session->evaluateScript('document.readyState === "complete"');
    });
    self::assertTrue($result, \sprintf("Pressing of the %s button didn't produce any results or page wasn't properly loaded afterwards.", $selector));
  }

  /**
   * Checks if the given selector is in focus.
   */
  private function checkButtonFocus(string $button_value): void {
    $session = $this->getSession();
    $page = $session->getPage();
    $result = $page->waitFor(2, function () use ($session, $button_value) {
      return $session->evaluateScript('document.activeElement?.value ?? null') === $button_value;
    });
    self::assertTrue($result, \sprintf('Button "%s" is not in focus.', $button_value));
  }

}
