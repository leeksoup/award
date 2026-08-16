<?php

declare(strict_types=1);

namespace Drupal\Tests\lms\FunctionalJavascript;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Url;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\Tests\lms\LmsTestHelperTrait;
use Drupal\lms\Entity\CourseStatus;
use Drupal\lms\Entity\LessonStatusInterface;
use Drupal\lms_classes\Service\ClassNameGenerator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * General javascript test of LMS basic features.
 */
#[Group('lms')]
#[RunTestsInSeparateProcesses]
final class GeneralLmsTest extends WebDriverTestBase {

  use LmsTestHelperTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'dblog',
    'block',
    'page_cache',
    'dynamic_page_cache',
    'lms',
    'lms_classes',
    'lms_answer_plugins',
    'lms_answer_comments',
    'lms_hooks_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'claro';

  /**
   * Test user data.
   */
  private array $userData;

  /**
   * Test activity data.
   */
  private array $activityData;

  /**
   * Test lesson data.
   */
  private array $lessonData;

  /**
   * Test Course data.
   */
  private array $courseData;

  /**
   * Test activity types data.
   */
  private array $activityTypesData;

  /**
   * Test user accounts.
   */
  private array $users = [];

  /**
   * Was watchdog test executed?
   */
  private bool $watchdogTestRan = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Set source data.
    $this->setSourceData();

    // Import test config.
    $this->importTestConfig();

    // Create test users.
    foreach ($this->userData as $name => $values) {
      $this->users[$name] = $this->drupalCreateUser([], NULL, FALSE, $values);
    }
  }

  /**
   * Test runner.
   *
   * To increase performance and avoid recreating test environment on every
   * run, execute all tests from one method.
   *
   * @todo Move test methods except watchdogTest() to separate files in
   * a subdirectory, load and execute in the order defined here.
   *
   * NOTE: Order of execution matters.
   */
  public function testLms(): void {
    // Resize window so we don't get any annoying out of viewport issues
    // and screenshots show all.
    $this->getSession()->resizeWindow(1024, 2048);

    // Test admin UI by setting up LMS and creating some test content.
    $this->adminTest();

    // Test teacher UI by creating teacher content.
    $this->teacherCourseCreationTest();

    // Test various LMS entity constraints.
    $this->testLmsEntityConstraints();

    // Test student - side functionality on the first course.
    $this->course1StudentTest();

    // Test anonymous user - side functionality on the first course.
    $this->course1AnonymousTest();

    // Test course evaluation and results.
    $this->course1EvaluationTest();

    // Test navigation block - depends on course1StudentTest for cache.
    $this->navigationBlockTest();

    // Test student - side functionality on the second course.
    $this->course2StudentTest();

    // Test exam functionality.
    $this->examTest();

    // Test modals in Course creation logic.
    $this->lmsReferenceTableTest();

    // Data integrity checking tests.
    $this->testDataIntegrityChecks();

    // Revision reference mode test.
    $this->revisionReferenceModeTest();

    /*
     * At this point the state of the site is quite changed so it may be
     * hard to figure out what we have for the next tests.
     */
    $this->deleteTestContent()->createTestContent();

    // Test lesson activity randomization.
    $this->randomActivitiesTest();

    // Test LMS Hooks.
    $this->hooksTest();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Always display watchdog information on tear down.
    $this->watchdogTest();

    // In case of an error, warning or failure display last HTML state.
    $this->storeCurrentHtml(TRUE);

    parent::tearDown();
  }

  /**
   * Admin user test.
   */
  private function adminTest(): void {
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $this->drupalLogin($this->users['admin']);
    $uuid = $this->users['admin']->get('uuid')->value;

    // Activity type creation test.
    $this->drupalGet(Url::fromRoute('entity.lms_activity_type.collection'));

    foreach ($this->activityTypesData as $activity_type_data) {
      $page->clickLink('Add activity type');
      $this->setFormElementValue('input', 'name', $activity_type_data['name']);
      $this->pressButton('Edit');
      $this->setFormElementValue('input', 'id', $activity_type_data['id']);
      $this->setFormElementValue('select', 'pluginId', $activity_type_data['pluginId']);

      // Handle plugin configuration if present.
      if (\count($activity_type_data['pluginConfiguration']) !== 0) {
        $assert_session->waitForElementVisible('css', '[name^="pluginConfiguration"]');
        foreach ($activity_type_data['pluginConfiguration'] as $config_key => $config_value) {
          $this->setFormElementValue('select', 'pluginConfiguration[' . $config_key . ']', $config_value);
        }
      }

      $this->pressButton('edit-submit');
    }
    $page_text = \strip_tags($page->getHtml());
    foreach ($this->activityTypesData as $activity_type_data) {
      self::assertTrue(\strpos($page_text, (string) $activity_type_data['name']) !== FALSE, \sprintf('The %s activity type is not installed.', $activity_type_data['name']));
    }

    // Activities creation test (admin).
    $this->drupalGet(Url::fromRoute('entity.lms_activity.collection'));
    $assert_session->linkExists('Add activity');
    foreach ($this->filterByOwnerUuid($this->activityData, $uuid) as $item) {
      $this->drupalGet(Url::fromRoute('entity.lms_activity.add_form', [
        'lms_activity_type' => $item['type'],
      ]));
      foreach ($item['values'] as $field => $value) {
        $this->setEntityFormField($field, $value);
      }
      $this->pressButton('edit-submit');
    }

    $this->drupalGet(Url::fromRoute('entity.lms_activity.collection'));
    foreach ($this->filterByOwnerUuid($this->activityData, $uuid) as $item) {
      $assert_session->pageTextContains($item['values']['name']);
    }

  }

  /**
   * Teacher test.
   */
  private function teacherCourseCreationTest(): void {
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $uuid = $this->users['teacher']->get('uuid')->value;
    $admin_uuid = $this->users['admin']->get('uuid')->value;

    $this->drupalLogin($this->users['teacher']);

    // Activities creation test.
    $this->drupalGet(Url::fromRoute('entity.lms_activity.collection'));
    $this::assertTrue($page->hasLink('Add activity'));
    foreach ($this->filterByOwnerUuid($this->activityData, $uuid) as $item) {
      $this->drupalGet(Url::fromRoute('entity.lms_activity.add_form', [
        'lms_activity_type' => $item['type'],
      ]));
      foreach ($item['values'] as $field => $value) {
        $this->setEntityFormField($field, $value);
      }
      $this->pressButton('edit-submit');
    }

    $this->drupalGet(Url::fromRoute('entity.lms_activity.collection'));
    foreach ($this->filterByOwnerUuid($this->activityData, $uuid) as $item) {
      $assert_session->pageTextContains($item['values']['name']);
    }

    // Test access.
    foreach ($this->filterByOwnerUuid($this->activityData, $admin_uuid) as $item) {
      $assert_session->pageTextNotContains($item['values']['name']);
    }
    foreach ($this->filterByOwnerUuid($this->activityData, $admin_uuid) as $item) {
      $activity_id = $this->getEntityIdByProperties('lms_activity', ['name' => $item['values']['name']]);
      $this->drupalGet(Url::fromRoute('entity.lms_activity.edit_form', [
        'lms_activity' => $activity_id,
      ]));
      $assert_session->pageTextContains('Access denied');
      $assert_session->pageTextNotContains($item['values']['name']);
    }

    // Create lessons.
    $this->drupalGet(Url::fromRoute('entity.lms_lesson.collection'));
    $this::assertTrue($page->hasLink('Add lesson'));

    foreach ($this->filterByOwnerUuid($this->lessonData, $uuid) as $item) {
      $this->drupalGet(Url::fromRoute('entity.lms_lesson.add_form', [
        'lms_lesson_type' => 'lesson',
      ]));
      foreach ($item['values'] as $field => $value) {
        $this->setEntityFormField($field, $value);
      }
      $this->setLmsReferenceField('activities', $item['activities']);
      $this->pressButton('edit-submit');
    }
    $this->drupalGet(Url::fromRoute('entity.lms_lesson.collection'));

    foreach ($this->filterByOwnerUuid($this->lessonData, $uuid) as $item) {
      $assert_session->pageTextContains($item['values']['name']);
    }

    // Create courses.
    $this->drupalGet(Url::fromRoute('entity.group.collection'));
    $this::assertTrue($page->hasLink('Add group'));
    foreach ($this->filterByOwnerUuid($this->courseData, $uuid) as $item) {
      $this->drupalGet(Url::fromRoute('entity.group.add_form', [
        'group_type' => 'lms_course',
      ]));
      foreach ($item['values'] as $field => $value) {
        $this->setEntityFormField($field, $value);
      }
      $this->setLmsReferenceField('lessons', $item['lessons']);
      $this->pressButton('edit-submit');
    }

    $this->drupalGet(Url::fromRoute('entity.group.collection'));
    foreach ($this->filterByOwnerUuid($this->courseData, $uuid) as $item) {
      $assert_session->pageTextContains($item['values']['label']);
      /** @var \Drupal\lms\Entity\Bundle\Course */
      $course = $this->getEntityByProperties('group', [
        'label' => $item['values']['label'],
      ]);
      $assert_session->pageTextContains(ClassNameGenerator::generateRandomClassName($course));
    }

  }

  /**
   * Test entity constraints.
   */
  private function testLmsEntityConstraints(): void {
    $assert_session = $this->assertSession();

    $this->drupalLogin($this->users['teacher']);

    $tested_lesson_id = $this->getEntityIdByProperties('lms_lesson', ['name' => $this->lessonData[1]['values']['name']]);
    $this->drupalGet(Url::fromRoute('entity.lms_lesson.edit_form', [
      'lms_lesson' => $tested_lesson_id,
    ]));
    $this->setEntityFormField('status', FALSE);
    $this->pressButton('edit-submit');
    $course_labels = [];
    foreach ($this->courseData as $course_item) {
      foreach ($course_item['lessons'] as $lesson_item) {
        if ($lesson_item['target_uuid'] === $this->lessonData[1]['uuid']) {
          $course_labels[] = $course_item['values']['label'];
          break;
        }
      }
    }
    $assert_session->pageTextContains('This lesson cannot be unpublished as it is a part of the following published courses: ' . \implode(', ', $course_labels));

    // Temporarily remove "Activities selection" view status filter.
    $view_storage = $this->container->get('entity_type.manager')->getStorage('view');
    $view = $view_storage->load('activities_selection');
    $original_display = $modified_display = $view->get('display');
    unset($modified_display['default']['display_options']['filters']['status']);
    $view->set('display', $modified_display)->save();

    $unpublished_activity_name = 'Unpublished activity';
    $this->drupalGet(Url::fromRoute('entity.lms_activity.add_form', [
      'lms_activity_type' => 'no_answer',
    ]));
    $this->setEntityFormField('name', $unpublished_activity_name);
    $this->setEntityFormField('status', FALSE);
    $this->pressButton('edit-submit');
    $activity = $this->getEntityByProperties('lms_activity', ['name' => $unpublished_activity_name]);
    $key = \count($this->activityData) + 1;
    $this->activityData[$key] = [
      'uuid' => $activity->uuid(),
      'values' => ['name' => $unpublished_activity_name],
    ];
    $this->drupalGet(Url::fromRoute('entity.lms_lesson.edit_form', [
      'lms_lesson' => $tested_lesson_id,
    ]));
    $this->setLmsReferenceField('activities', [['target_uuid' => $activity->uuid()]]);
    $this->pressButton('edit-submit');
    $assert_session->pageTextContains(\sprintf('The "%s" activity cannot be referenced. Either publish it first or unpublish the parent (this) lesson.', $unpublished_activity_name));

    // Cleanup changes.
    $view->set('display', $original_display)->save();
    unset($this->activityData[$key]);
    $activity->delete();
  }

  /**
   * Course one - test answering questions and navigation.
   */
  private function course1StudentTest(): void {
    $assert_session = $this->assertSession();

    $this->drupalLogin($this->users['student']);
    $this->takeCourse1((string) $this->users['student']->id(), enroll: TRUE);

    $assert_session->pageTextContains('Course completed, please wait for evaluation.');
  }

  /**
   * Course one - anonymous user takes the course using session tempstore.
   */
  private function course1AnonymousTest(): void {
    $this->drupalLogout();
    // Anonymous users with 'take course' permission see 'Start' directly
    // without needing to enroll.
    $this->takeCourse1('0');

    // Answers are auto-evaluated for anonymous users, so the course resolves
    // to passed or failed immediately rather than waiting for teacher grading.
    $page = $this->getSession()->getPage();
    $passed = $page->hasContent('Course completed.');
    $failed = $page->hasContent('Course failed.');
    self::assertTrue($passed || $failed, 'Expected course to be passed or failed after anonymous completion.');
  }

  /**
   * Navigate to course one and answer all activities as the given user.
   *
   * @param string $userId
   *   User ID used to track answers; pass '0' for anonymous.
   * @param bool $enroll
   *   Whether to click Enroll and submit before starting.
   */
  private function takeCourse1(string $userId, bool $enroll = FALSE): void {
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $course_id = $this->getEntityIdByProperties('group', ['label' => $this->courseData[1]['values']['label']]);
    $this->drupalGet(Url::fromRoute('entity.group.canonical', [
      'group' => $course_id,
    ]));
    if ($enroll) {
      $page->clickLink('Enroll');
      $this->pressButton('edit-submit');
    }
    $page->clickLink('Start');

    foreach ($this->courseData[1]['lessons'] as $lesson_delta => $course_lesson_item) {
      $lesson_item = $this->getItemByUuid($course_lesson_item['target_uuid'], $this->lessonData);
      foreach ($lesson_item['activities'] as $activity_delta => $lesson_activity_item) {
        $activity_item = $this->getItemByUuid($lesson_activity_item['target_uuid'], $this->activityData);
        // @see Drupal\lms\Controller\CourseController::activityFormTitle().
        $assert_session->pageTextContains($lesson_item['values']['name'] . ' - ' . $activity_item['values']['name']);

        // Backwards navigation button check.
        if ($lesson_delta === 0 && $activity_delta === 0) {
          $this::assertFalse($page->hasLink('edit-back'), "Backwards navigation shouldn't be possible on the first activity.");
        }
        elseif ($lesson_item['values']['backwards_navigation']) {
          $this::assertTrue($page->hasLink('edit-back'), 'Backwards navigation should be enabled.');
        }
        else {
          $this::assertFalse($page->hasLink('edit-back'), 'Backwards navigation should be disabled.');
        }

        $this->answerActivity($activity_item, (int) $lesson_activity_item['max_score'], $userId, $course_id);
      }
    }
  }

  /**
   * Course evaluation and results test.
   */
  private function course1EvaluationTest(): void {
    $page = $this->getSession()->getPage();

    $assert_session = $this->assertSession();

    $this->drupalLogin($this->users['teacher']);

    // Tested course.
    $course_item = $this->courseData[1];

    $course_id = $this->getEntityIdByProperties('group', ['label' => $course_item['values']['label']]);
    $student_id = $this->users['student']->id();

    // Results page.
    $this->drupalGet(Url::fromRoute('lms.group.results', [
      'group' => $course_id,
      'user' => $student_id,
    ]));

    // General status.
    $status_element = $page->find('css', '[data-lms-selector="course-status"]');
    $this::assertEquals($status_element->getText(), CourseStatus::getStatusText(CourseStatus::STATUS_NEEDS_EVALUATION));

    $lesson_wrappers = $page->findAll('css', '.lesson-score-details');
    $this::assertEquals(\count($lesson_wrappers), \count($course_item['lessons']), 'The number of lessons in results should match the number of lessons in the course.');
    foreach ($lesson_wrappers as $wrapper) {
      $wrapper->click();
    }

    foreach ($course_item['lessons'] as $lesson_delta => $course_lesson_item) {
      $lesson_item = $this->getItemByUuid($course_lesson_item['target_uuid'], $this->lessonData);

      foreach ($lesson_item['activities'] as $lesson_activity_item) {
        $activity_item = $this->getItemByUuid($lesson_activity_item['target_uuid'], $this->activityData);

        $this->assertElementTextContains($lesson_wrappers[$lesson_delta], $activity_item['values']['name'], \sprintf('Activity %s not found in lesson results.', $activity_item['values']['name']));

        $answer_data = $this->getAnswerData($activity_item['uuid'], $student_id, $course_id);
        if ($answer_data['evaluated'] === FALSE) {
          $this->evaluateAnswerModal($activity_item, $lesson_item, $student_id, $course_id);
        }
        // Zero max score activities are always set as evaluated.
        elseif ((int) $lesson_activity_item['max_score'] === 0) {
          [, , $answer_id] = $this->getAnswerEvaluateParameters($activity_item, $lesson_item, $student_id, $course_id);
          $details_uri = Url::fromRoute('lms.answer.details', [
            'lms_answer' => $answer_id,
            'js' => 'nojs',
          ])->toString();
          $this->getSession()->getPage()->find('css', '[href="' . $details_uri . '"]')->click();
          $modal = $assert_session->waitForElementVisible('css', '[role="dialog"]');
          $this->assertElementTextContains($modal, 'This answer is already evaluated.', 'Zero max score activity should show as already evaluated.');
          $modal->find('css', '.ui-dialog-titlebar-close')->click();
          $assert_session->waitForElementRemoved('css', '[role="dialog"]');
        }
      }
    }

    $result = $this->calculateCourseResult($course_item, $student_id);
    if ($result[0]) {
      $status = CourseStatus::STATUS_PASSED;
    }
    else {
      $status = CourseStatus::STATUS_FAILED;
    }
    $expected_status = CourseStatus::getStatusText($status) . ' (' . $result[1] . '%)';
    $status_element = $page->find('css', '[data-lms-selector="course-status"]');
    $answer_scores = [];
    foreach ($this->answers[$course_id][$student_id] as $answer) {
      $answer_scores[] = $answer['score'];
    }
    $this::assertEquals($status_element->getText(), $expected_status, 'Unexpected status visible on page. Answer data:' . \PHP_EOL . Yaml::encode($this->answers[$course_id][$student_id]));

    // Check if teacher comments are visible as the student.
    $this->drupalLogin($this->users['student']);
    $this->drupalGet(Url::fromRoute('lms.group.self_results', [
      'group' => $course_id,
    ]));
    $lesson_wrappers = $page->findAll('css', '.lesson-score-details');
    foreach ($lesson_wrappers as $wrapper) {
      $wrapper->click();
    }

    $comment_count = 0;
    foreach ($course_item['lessons'] as $lesson_delta => $course_lesson_item) {
      $lesson_item = $this->getItemByUuid($course_lesson_item['target_uuid'], $this->lessonData);
      foreach ($lesson_item['activities'] as $lesson_activity_item) {
        $activity_item = $this->getItemByUuid($lesson_activity_item['target_uuid'], $this->activityData);
        $answer_data = $this->getAnswerData($activity_item['uuid'], $student_id, $course_id);
        if ($answer_data['evaluated'] === FALSE) {
          [, , $answer_id] = $this->getAnswerEvaluateParameters($activity_item, $lesson_item, $student_id, $course_id);
          $details_uri = Url::fromRoute('lms.answer.details', [
            'lms_answer' => $answer_id,
            'js' => 'nojs',
          ])->toString();
          $page->find('css', '[href="' . $details_uri . '"]')->click();
          $modal = $assert_session->waitForElementVisible('css', '[role="dialog"]');
          $this->assertElementTextContains($modal, \sprintf('Teacher comment to %s', $activity_item['uuid']), 'Teacher comment is not visible.');
          $student_reply = \sprintf('Student reply to %s', $activity_item['uuid']);
          $modal->fillField('comment[comment_body][0][value]', $student_reply);
          $modal->find('css', 'button.form-submit')->click();
          $comment_count += 2;
          $assert_session->waitForElementRemoved('css', '[role="dialog"]');
        }
      }
    }

    // Check if comment count matches the expected.
    self::assertEquals(
      $comment_count,
      $this->container->get('entity_type.manager')->getStorage('comment')->getQuery()->accessCheck(FALSE)->count()->execute(),
      \sprintf('There should be exactly %d comments.', $comment_count)
    );
  }

  /**
   * Course 2 test.
   */
  private function course2StudentTest(): void {
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $this->drupalLogin($this->users['student']);

    // Test additional course options on the second test course.
    $test_course_data = $this->courseData[2];
    $course_id = $this->getEntityIdByProperties('group', ['label' => $test_course_data['values']['label']]);
    $this->drupalGet(Url::fromRoute('entity.group.canonical', [
      'group' => $course_id,
    ]));
    $page->clickLink('Enroll');
    $this->pressButton('edit-submit');
    $page->clickLink('Start');

    // Try to go to an arbitrary activity.
    $arbitrary_activity_route_parameters = [
      'group' => $course_id,
      'lesson_delta' => 1,
      'activity_delta' => 2,
    ];
    $this->drupalGet(Url::fromRoute('lms.group.answer_form', $arbitrary_activity_route_parameters));
    $assert_session->pageTextContains('This course does not allow free navigation.');

    $activity_count = 0;
    $back_nav_count = 0;
    $first_title = '';
    $arbitrary_activity_title = '';
    $previous_deltas = [];
    $previous_lesson_item = NULL;
    foreach ($test_course_data['lessons'] as $lesson_delta => $course_lesson_item) {
      $lesson_item = $this->getItemByUuid($course_lesson_item['target_uuid'], $this->lessonData);
      foreach ($lesson_item['activities'] as $activity_delta => $lesson_activity_item) {
        $activity_item = $this->getItemByUuid($lesson_activity_item['target_uuid'], $this->activityData);
        // @see Drupal\lms\Controller\CourseController::activityFormTitle().
        $title = $lesson_item['values']['name'] . ' - ' . $activity_item['values']['name'];
        $assert_session->pageTextContains($title);

        // Current nav element.
        $activity_element_text = $page->find('css', '.lms-activity-title--current')->getText();
        self::assertStringContainsString($activity_item['values']['name'], $activity_element_text);

        // Assign values needed for later asserts.
        if ($first_title === '') {
          $first_title = $title;
        }
        if (
          $lesson_delta === $arbitrary_activity_route_parameters['lesson_delta'] &&
          $activity_delta === $arbitrary_activity_route_parameters['activity_delta']
        ) {
          $arbitrary_activity_title = $title;
        }

        // Different backwards navigation button check compared to previous
        // test method - go back if allowed and resubmit.
        $back_button = $page->findLink('edit-back');

        // Back button should be visible only if the target
        // and the current lesson allows backwards navigation.
        $back_button_displayed = FALSE;
        if ($lesson_item['values']['backwards_navigation']) {
          if ($activity_delta > 0) {
            $back_button_displayed = TRUE;
          }
          elseif ($previous_lesson_item !== NULL && $previous_lesson_item['values']['backwards_navigation']) {
            $back_button_displayed = TRUE;
          }
        }
        if ($back_button_displayed) {
          self::assertNotNull($back_button, 'Back button should be displayed.');
        }
        else {
          self::assertNull($back_button, 'Back button should not be displayed.');
        }

        $previous_activity_selector = '';
        if (\count($previous_deltas) !== 0) {
          $href = Url::fromRoute('lms.group.answer_form', [
            'group' => $course_id,
            'lesson_delta' => $previous_deltas['lesson'],
            'activity_delta' => $previous_deltas['activity'],
          ])->toString();
          $previous_activity_selector = '.lms-activity-title[href="' . $href . '"]';
        }
        if ($back_button !== NULL) {
          // Previous nav element.
          self::assertNotNull($page->find('css', $previous_activity_selector), \sprintf('Selector %s not found.', $previous_activity_selector));

          $this->pressButton('#edit-back', 'css');
          $back_nav_count++;
          $this->pressButton('edit-submit');
        }
        elseif ($previous_activity_selector !== '') {
          self::assertNull($page->find('css', $previous_activity_selector), \sprintf('Selector %s found.', $previous_activity_selector));
        }

        // Set previous activity parameters.
        $previous_deltas = [
          'lesson' => $lesson_delta,
          'activity' => $activity_delta,
        ];

        // Answer the activity.
        $this->answerActivity($activity_item, (int) $lesson_activity_item['max_score'], $this->users['student']->id(), $course_id);
        $activity_count++;
      }
      $previous_lesson_item = $lesson_item;
    }

    // At least one lesson must allow backwards navigation.
    $this::assertTrue($back_nav_count > 0, "Back navigation wasn't ever used.");

    // Evaluate course programmatically as that's already tested.
    $this->evaluateCourse($course_id, $this->users['student']->id());

    $this->drupalGet(Url::fromRoute('entity.group.canonical', [
      'group' => $course_id,
    ]));
    $page->clickLink('Revisit');

    // Travel all the way back to the first activity.
    for ($i = $activity_count; $i > 1; $i--) {
      $this->pressButton('#edit-back', 'css');
    }
    $assert_session->pageTextContains($first_title);

    // Now arbitrary activity should be accessible.
    $this->drupalGet(Url::fromRoute('lms.group.answer_form', $arbitrary_activity_route_parameters));
    $assert_session->pageTextContains($arbitrary_activity_title);
  }

  /**
   * Exam test.
   */
  private function examTest(): void {
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $this->drupalLogin($this->users['student']);

    // Test additional course options on the second test course.
    $test_course_data = $this->courseData[3];
    $course_id = $this->getEntityIdByProperties('group', ['label' => $test_course_data['values']['label']]);
    $this->drupalGet(Url::fromRoute('entity.group.canonical', [
      'group' => $course_id,
    ]));
    $page->clickLink('Enroll');
    $this->pressButton('edit-submit');
    $start_time = \time();
    $page->clickLink('Start');

    \sleep(1);
    $timer_element = $page->find('css', '[data-lms-close-time]');
    $close_time = (int) $timer_element->getAttribute('data-lms-close-time');
    $time_limit = $test_course_data['lessons'][0]['time_limit'] * 60;
    $expected_lock_time = $start_time + $time_limit;

    $difference = \abs($close_time - $expected_lock_time);
    // Allow a 2 second difference to account for a longer request etc.
    self::assertLessThan(2, $difference, 'Lesson close time is different than expected.');

    $lesson_item = $this->getItemByUuid($test_course_data['lessons'][0]['target_uuid'], $this->lessonData);

    // Answer all activities except the last one.
    for ($i = 0; $i < \count($lesson_item['activities']) - 1; $i++) {
      $lesson_activity_item = $lesson_item['activities'][$i];
      $activity_item = $this->getItemByUuid($lesson_activity_item['target_uuid'], $this->activityData);
      $this->answerActivity($activity_item, (int) $lesson_activity_item['max_score'], $this->users['student']->id(), $course_id);
    }

    // Wait until the time is finished.
    // @todo Evil but is there a different solution really?
    \sleep($time_limit);
    $assert_session->pageTextContains('Lesson is over time, course finished.');

    // Evaluate course programmatically so further tests are not affected.
    $this->evaluateCourse($course_id, $this->users['student']->id());
  }

  /**
   * Navigation block test.
   */
  private function navigationBlockTest(): void {
    $student = $this->users['student'];
    $page = $this->getSession()->getPage();
    $cache = $this->container->get('cache.default');

    $course_id = $this->getEntityIdByProperties('group', ['label' => $this->courseData[1]['values']['label']]);
    $lesson_id = $this->getEntityIdByProperties('lms_lesson', ['name' => $this->lessonData[1]['values']['name']]);
    $structure_cid = 'steps_block_data_cache_structure_' . $course_id;

    $activity_url = Url::fromRoute('lms.group.answer_form', [
      'group' => $course_id,
      'lesson_delta' => 0,
      'activity_delta' => 0,
    ]);

    // Verify structure cache exists (created by course1StudentTest).
    $this->drupalLogin($student);
    $this->drupalGet($activity_url);
    self::assertNotFalse($cache->get($structure_cid), 'Structure cache should exist from previous test.');

    // Check some additional details.
    $nav_block = $page->find('css', '[data-component-id="lms:course_navigation"]');
    self::assertNotNull($nav_block, 'Nav block not found on the page.');

    // Expand all lesson elements. Wait for a bit to ensure details elements
    // reach their final state.
    foreach ($nav_block->findAll('css', 'details') as $details_element) {
      if (!$details_element->hasAttribute('open')) {
        $details_element->click();
      }
    }

    $answered_count = 0;
    foreach ($this->courseData[1]['lessons'] as $lesson_delta => $course_lesson_item) {
      $lesson_item = $this->getItemByUuid($course_lesson_item['target_uuid'], $this->lessonData);
      foreach ($lesson_item['activities'] as $activity_delta => $lesson_activity_item) {
        $answered_count++;
        if ($lesson_delta === 0 && $activity_delta === 0) {
          // No href on the current element.
          $selector = '.lms-activity-title--current';
        }
        else {
          $href = Url::fromRoute('lms.group.answer_form', [
            'group' => $course_id,
            'lesson_delta' => $lesson_delta,
            'activity_delta' => $activity_delta,
          ])->toString();
          $selector = '.lms-activity-title[href="' . $href . '"]';
        }
        $activity_item = $this->getItemByUuid($lesson_activity_item['target_uuid'], $this->activityData);
        $activity_element = $nav_block->find('css', $selector);
        self::assertNotNull($activity_element, "Activity element doesn't exist.");
        $this->assertElementTextContains($activity_element, $activity_item['values']['name'], 'Invalid activity element text title: ' . $selector);

        $answer_data = $this->getAnswerData($lesson_activity_item['target_uuid'], $student->id(), $course_id);
        $score_str = $answer_data['score'] . ' / ' . $lesson_activity_item['max_score'];
        $this->assertElementTextContains($activity_element, $score_str, 'Invalid activity element text score');
      }
    }

    self::assertElementTextContains($nav_block, \sprintf('%d of %d total answered', $answered_count, $answered_count), 'Missing / incorrect completed activity count.');

    // Test cache invalidation on lesson save.
    $lesson = $this->container->get('entity_type.manager')->getStorage('lms_lesson')->load($lesson_id);
    $lesson->save();
    self::assertFalse($cache->get($structure_cid), 'Structure cache should be invalidated.');

    // Test render cache varies by user context.
    $this->container->get('cache.render')->deleteAll();
    $this->drupalGet($activity_url);
    $student_content = $page->find('css', '.lms-course-navigation-container')->getOuterHtml();

    $this->drupalLogin($this->users['teacher']);
    $this->drupalGet($activity_url);
    $teacher_content = $page->find('css', '.lms-course-navigation-container')->getOuterHtml();

    self::assertNotEquals($student_content, $teacher_content, 'Block should vary by user.');
    // Reset progress for teacher as it's not expected by next tests.
    $this->container->get('lms.training_manager')->resetTraining($course_id, $this->users['teacher']->id());

    // Test SDC components are rendered correctly.
    self::assertStringContainsString('lms-lesson-item', $teacher_content, 'Lesson SDC should render.');
    self::assertStringContainsString('lms-activity-item', $teacher_content, 'Activity SDC should render.');
  }

  /**
   * LMS Reference table widget test - complex nesting scenario.
   */
  private function lmsReferenceTableTest(): void {
    // Some data that will be verified.
    $course_name = 'Table Widget Test Course';
    $lesson_names = [
      $this->lessonData[2]['values']['name'],
      'Table Widget Test Lesson',
    ];
    $activity_names = [
      'Table Widget Test Activity 1',
      'Table Widget Test Activity 2',
    ];
    $required_score_value = '80';

    // Test as a teacher user to properly check access.
    $this->drupalLogin($this->users['teacher']);

    // ## Creation test.
    $this->drupalGet(Url::fromRoute('entity.group.add_form', [
      'group_type' => 'lms_course',
    ]));
    $this->setEntityFormField('label', $course_name);

    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    // Add an existing lesson.
    $this->pressButton('Reference lessons');
    $modal_selector = '[role="dialog"].modal-view-lessons-selection';
    $modal = $assert_session->waitForElementVisible('css', $modal_selector);
    $lesson = $this->getEntityByProperties('lms_lesson', [
      'name' => $lesson_names[0],
    ]);
    $selector = \sprintf('[value="%s"]', \implode(':', [
      $lesson->getEntityTypeId(),
      $lesson->bundle(),
      $lesson->id(),
    ]));
    $modal->find('css', $selector)->check();

    $modal->find('css', '.ui-dialog-buttonset > .lms-add-references')->click();
    $assert_session->waitForElementRemoved('css', $modal_selector);

    // Create a new lesson.
    $this->pressButton('Create lesson');
    $lesson_modal_selector = '[role="dialog"].modal-entity-lms-lesson';
    $lesson_modal = $assert_session->waitForElementVisible('css', $lesson_modal_selector);
    // Create 2 activities in a nested modal.
    $activity_ids = [];
    foreach ($activity_names as $activity_name) {
      $lesson_modal->find('named', ['button', 'Create activity'])->click();

      $activity_modal_selector = '[role="dialog"].modal-entity-lms-activity';
      $activity_modal = $assert_session->waitForElementVisible('css', $activity_modal_selector);
      $activity_modal->find('css', '[name="bundle"]')->selectOption('no_answer');
      $activity_form = $assert_session->waitForElementVisible('css', 'form.lms-activity-form');
      $activity_form->find('css', '[name="name[0][value]"]')->setValue($activity_name);
      $activity_modal->find('css', '.ui-dialog-buttonset > button')->click();
      $assert_session->waitForElementRemoved('css', $activity_modal_selector);
      // Assert focus returned to the button that opened the activity modal.
      // See js/dialog-scroll-fix.js.
      $this->checkButtonFocus('Create activity');
      // Check if activity has been created.
      $activity = $this->getEntityByProperties('lms_activity', [
        'name' => $activity_name,
      ]);
      $activity_ids[] = $activity->id();
    }

    // Save the lesson.
    $lesson_modal->find('css', '.ui-dialog-buttonset > button')->click();
    // Whoops, we forgot to set the name (validation test).
    $messages = $assert_session->waitForElementVisible('css', $lesson_modal_selector . ' [data-drupal-messages]');
    self::assertTrue(\strpos($messages->getText(), 'Name field is required') !== FALSE);
    // Correct and save again.
    $lesson_modal->find('css', '[name="name[0][value]"]')->setValue($lesson_names[1]);
    $lesson_modal->find('css', '.ui-dialog-buttonset > button')->click();
    $assert_session->waitForElementRemoved('css', $lesson_modal_selector);
    // Assert focus returned to the button that opened the lesson modal.
    // See js/dialog-scroll-fix.js.
    $this->checkButtonFocus('Create lesson');

    // Set parameters for the newly created lesson and verify the lesson
    // got created at the same time.
    $lesson = $this->getEntityByProperties('lms_lesson', [
      'name' => $lesson_names[1],
    ]);
    foreach ($lesson->get('activities') as $delta => $item) {
      self::assertEquals($item->get('target_id')->getValue(), $activity_ids[$delta]);
    }
    $selector = \sprintf('[data-drupal-selector="edit-lessons-table-%d-actions-edit-parameters"]', $lesson->id());
    $page->find('css', $selector)->click();
    $parameters_modal_selector = '[role="dialog"].modal-entity-lms-lesson';
    $parameters_modal = $assert_session->waitForElementVisible('css', $parameters_modal_selector);
    $parameters_modal->find('css', '[name="required_score"]')->setValue('80');
    $parameters_modal->find('css', '.ui-dialog-buttonset > button')->click();
    $assert_session->waitForElementRemoved('css', $parameters_modal_selector);

    // Save course, verify data.
    $this->pressButton('[data-drupal-selector="group-lms-course-add-form"] #edit-submit', 'css');
    $assert_session->pageTextContains(\sprintf('Course %s has been created', $course_name));
    $course = $this->getEntityByProperties('group', [
      'label' => $course_name,
    ]);
    $lesson_items = $course->get('lessons');
    self::assertEquals(\count($lesson_names), $lesson_items->count());
    // Check if our modified max score got saved.
    /** @var \Drupal\lms\Plugin\Field\FieldType\LMSReferenceItem */
    $item = $lesson_items->get(1);
    self::assertEquals($item->getRequiredScore(), (int) $required_score_value);

    // ## Edition test.
    $this->drupalGet(Url::fromRoute('entity.group.edit_form', [
      'group' => $course->id(),
    ]));
    $selector = \sprintf('[data-drupal-selector="edit-lessons-table-%d-actions-edit-entity"]', $lesson->id());
    $page->find('css', $selector)->click();
    $lesson_modal_selector = '[role="dialog"].modal-entity-lms-lesson';
    $lesson_modal = $assert_session->waitForElementVisible('css', $lesson_modal_selector);

    // Rename lesson.
    $last_lesson_key = \count($lesson_names) - 1;
    $lesson_names[$last_lesson_key] = $lesson->label() . ' Renamed';
    $lesson_modal->find('css', '[name="name[0][value]"]')->setValue($lesson_names[$last_lesson_key]);

    // Rename the last of the lesson activities.
    // @phpstan-ignore variable.undefined
    $selector = \sprintf('[data-drupal-selector="edit-activities-table-%d-actions-edit-entity"]', $activity->id());
    $page->find('css', $selector)->click();
    $activity_modal_selector = '[role="dialog"].modal-entity-lms-activity';
    $activity_modal = $assert_session->waitForElementVisible('css', $activity_modal_selector);
    // No bundle selection this time, the modal should contain the name field.
    $last_activity_key = \count($activity_names) - 1;
    // @phpstan-ignore variable.undefined
    $activity_names[$last_activity_key] = $activity->label() . ' Renamed';
    $activity_modal->find('css', '[name="name[0][value]"]')->setValue($activity_names[$last_activity_key]);
    $activity_modal->find('css', '.ui-dialog-buttonset > button')->click();
    $assert_session->waitForElementRemoved('css', $activity_modal_selector);
    self::assertGreaterThan(0, \strpos($lesson_modal->getText(), $activity_names[$last_activity_key]), 'Changed activity title not visible on the lesson modal');

    // Try referencing one more activity.
    $activity_names[] = $this->activityData[2]['values']['name'];
    $lesson_modal->find('css', '[data-drupal-selector="edit-activities-reference-item"]')->click();
    $modal_selector = '[role="dialog"].modal-view-activities-selection';
    $modal = $assert_session->waitForElementVisible('css', $modal_selector);
    $activity = $this->getEntityByProperties('lms_activity', [
      'name' => \end($activity_names),
    ]);
    $selector = \sprintf('[value="%s"]', \implode(':', [
      $activity->getEntityTypeId(),
      $activity->bundle(),
      $activity->id(),
    ]));
    $modal->find('css', $selector)->check();
    $modal->find('css', '.ui-dialog-buttonset > .lms-add-references')->click();
    $assert_session->waitForElementRemoved('css', $modal_selector);
    self::assertGreaterThan(0, \strpos($lesson_modal->getText(), $activity->label()), 'Added activity title not visible on the lesson modal');

    // Save lesson, verify data.
    // NOTE: verify data by visiting pages rather than loading entities
    // as the test runner is running in a single request so static cache
    // will show edited entities state from before the changes were made
    // here.
    $lesson_modal->find('css', '.ui-dialog-buttonset > button')->click();
    $assert_session->waitForElementRemoved('css', $lesson_modal_selector);
    $assert_session->pageTextContains($lesson_names[$last_lesson_key]);
    // There were no changes to the course itself but let's save it to verify
    // there are no errors.
    $this->pressButton('[data-drupal-selector="group-lms-course-edit-form"] #edit-submit', 'css');
    $assert_session->pageTextContains(\sprintf('Course %s has been updated', $course_name));

    $lesson_id = $this->getEntityIdByProperties('lms_lesson', [
      'name' => $lesson_names[$last_lesson_key],
    ]);
    $this->drupalGet(Url::fromRoute('entity.lms_lesson.edit_form', [
      'lms_lesson' => $lesson_id,
    ]));
    $table_text = $page->find('css', '[data-lms-selector="lms-reference-table-lms-activity"]')->getText();
    foreach ($activity_names as $delta => $activity_name) {
      $position = 0;
      $title_position = \strpos($table_text, $activity_name);
      self::assertGreaterThan($position, $title_position, 'Expected activity not referenced by the test lesson or out of order.');
      $position = $title_position;
    }

    // Removing lesson activity.
    $activity_remove_selector = '[name="remove-lms_activity-' . $activity->id() . '"]';
    $page->find('css', $activity_remove_selector)->click();
    $assert_session->waitForElementRemoved('css', $activity_remove_selector);
    $this->pressButton('[data-drupal-selector="edit-submit"]', 'css');
    $assert_session->pageTextContains(\sprintf('Lesson %s has been updated', $lesson_names[$last_lesson_key]));
    $this->drupalGet(Url::fromRoute('entity.lms_lesson.edit_form', [
      'lms_lesson' => $lesson_id,
    ]));
    $assert_session->pageTextNotContains($activity->label());
    self::assertNull($page->find('css', $activity_remove_selector));
  }

  /**
   * Data integrity checks test.
   */
  private function testDataIntegrityChecks(): void {
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->users['admin']);

    // We are not supposed to be able to delete a used activity or lesson.
    $this->drupalGet(Url::fromRoute('entity.lms_lesson.delete_form', [
      'lms_lesson' => $this->getEntityIdByProperties('lms_lesson', [
        'name' => $this->lessonData[1]['values']['name'],
      ]),
    ]));
    $submit = $page->find('css', '[data-drupal-selector="edit-submit"]');
    self::assertEquals('disabled', $submit->getAttribute('disabled'));
    $this->drupalGet(Url::fromRoute('entity.lms_activity.delete_form', [
      'lms_activity' => $this->getEntityIdByProperties('lms_activity', [
        'name' => $this->activityData[1]['values']['name'],
      ]),
    ]));
    $submit = $page->find('css', '[data-drupal-selector="edit-submit"]');
    self::assertEquals('disabled', $submit->getAttribute('disabled'));

    // Unused activity - no disabled attribute.
    $this->drupalGet(Url::fromRoute('entity.lms_activity.delete_form', [
      'lms_activity' => $this->getEntityIdByProperties('lms_activity', [
        'name' => $this->activityData[6]['values']['name'],
      ]),
    ]));
    $submit = $page->find('css', '[data-drupal-selector="edit-submit"]');
    self::assertEquals(NULL, $submit->getAttribute('disabled'));

    // All courses are currently finished and evaluated - no warnings.
    $course_id = $this->getEntityIdByProperties('group', ['label' => $this->courseData[1]['values']['label']]);
    $lesson_id = $this->getEntityIdByProperties('lms_lesson', ['name' => $this->lessonData[1]['values']['name']]);
    $this->drupalGet(Url::fromRoute('entity.group.edit_form', [
      'group' => $course_id,
    ]));
    $warnings = $page->findAll('css', '.messages--warning');
    self::assertEquals(0, \count($warnings), 'No warnings expected');
    $this->drupalGet(Url::fromRoute('entity.lms_lesson.edit_form', [
      'lms_lesson' => $lesson_id,
    ]));
    $warnings = $page->findAll('css', '.messages--warning');
    self::assertEquals(0, \count($warnings), 'No warnings expected');

    // Warnings on started course and lesson pages.
    $this->drupalGet(Url::fromRoute('entity.group.canonical', [
      'group' => $course_id,
    ]));
    $page->clickLink('Start');
    $this->drupalGet(Url::fromRoute('entity.group.edit_form', [
      'group' => $course_id,
    ]));
    $warnings = $page->findAll('css', '.messages--warning');
    self::assertGreaterThan(0, \count($warnings), 'Warnings expected');
    $this->drupalGet(Url::fromRoute('entity.lms_lesson.edit_form', [
      'lms_lesson' => $lesson_id,
    ]));
    $warnings = $page->findAll('css', '.messages--warning');
    self::assertGreaterThan(0, \count($warnings), 'Warnings expected');
    // Second lesson wasn't started yet, no warnings expected.
    $this->drupalGet(Url::fromRoute('entity.lms_lesson.edit_form', [
      'lms_lesson' => $this->getEntityIdByProperties('lms_lesson', [
        'name' => $this->lessonData[2]['values']['name'],
      ]),
    ]));
    $warnings = $page->findAll('css', '.messages--warning');
    self::assertEquals(0, \count($warnings), 'No warnings expected');
  }

  /**
   * Revision reference mode test.
   */
  private function revisionReferenceModeTest(): void {
    // Enable reference mode.
    $this->drupalLogin($this->users['admin']);
    $this->drupalGet(Url::fromRoute('lms.settings.main'));
    $this->setFormElementValue('checkbox', 'edit-use-revisions', TRUE);
    $this->pressButton('edit-submit');
    $this->drupalGet(Url::fromRoute('system.performance_settings'));
    $this->pressButton('edit-clear');

    $this->drupalLogin($this->users['teacher']);

    // Edit some entities, new revisions will be created.
    $revision_labels = [
      'group' => 'Course revision 1',
      'lms_lesson' => 'Lesson revision 1',
      'lms_activity' => 'Activity revision 1',
    ];
    $source_data_indexes = [
      'group' => 1,
      'lms_lesson' => 1,
      'lms_activity' => 3,
    ];

    $test_entity_data = [];
    $test_entity_ids = [];
    foreach ($revision_labels as $entity_type_id => $label) {
      $label_field = $entity_type_id === 'group' ? 'label' : 'name';
      $test_entity_data[$entity_type_id] = $this->getSourceData($entity_type_id, $source_data_indexes[$entity_type_id]);
      $test_entity_ids[$entity_type_id] = $this->getEntityIdByProperties($entity_type_id, [
        $label_field => $test_entity_data[$entity_type_id]['values'][$label_field],
      ]);
      $this->drupalGet(Url::fromRoute('entity.' . $entity_type_id . '.edit_form', [
        $entity_type_id => $test_entity_ids[$entity_type_id],
      ]));
      $this->setEntityFormField($label_field, $revision_labels[$entity_type_id]);
      $this->pressButton('edit-submit');
    }

    // Enable free navigation so the teacher can jump to any activity delta.
    $this->drupalGet(Url::fromRoute('entity.group.edit_form', [
      'group' => $test_entity_ids['group'],
    ]));
    $this->setEntityFormField('free_navigation', TRUE);
    $this->pressButton('edit-submit');

    // Remove last activity from the tested lesson.
    $this->drupalGet(Url::fromRoute('entity.lms_lesson.edit_form', [
      'lms_lesson' => $test_entity_ids['lms_lesson'],
    ]));
    $last_delta = \count($test_entity_data['lms_lesson']['activities']) - 1;
    // Check if we're not trying to remove the tested activity,
    // test would fail then.
    self::assertNotEquals($test_entity_data['lms_lesson']['activities'][$last_delta]['target_uuid'], $test_entity_data['lms_activity']['uuid'], 'Attempting to remove the tested activity, please change the source data activity index.');
    $activity_item = $this->getItemByUuid($test_entity_data['lms_lesson']['activities'][$last_delta]['target_uuid'], $this->activityData);
    $last_activity_id = $this->getEntityIdByProperties('lms_activity', [
      'name' => $activity_item['values']['name'],
    ]);
    $this->pressButton('edit-activities-table-' . $last_activity_id . '-actions-remove-reference');
    $this->pressButton('edit-submit');

    // Determine test lesson and activity delta.
    $test_lesson_delta = NULL;
    foreach ($test_entity_data['group']['lessons'] as $delta => $lesson_data_item) {
      if ($lesson_data_item['target_uuid'] === $test_entity_data['lms_lesson']['uuid']) {
        $test_lesson_delta = $delta;
        break;
      }
    }
    $test_activity_delta = NULL;
    foreach ($test_entity_data['lms_lesson']['activities'] as $delta => $activity_data_item) {
      if ($activity_data_item['target_uuid'] === $test_entity_data['lms_activity']['uuid']) {
        $test_activity_delta = $delta;
        break;
      }
    }

    // Login as the student and assert that old revision values are displayed.
    $this->drupalLogin($this->users['student']);
    $activity_url = Url::fromRoute('lms.group.answer_form', [
      'group' => $test_entity_ids['group'],
      'lesson_delta' => $test_lesson_delta,
      'activity_delta' => $test_activity_delta,
    ]);
    $this->drupalGet($activity_url);

    $page = $this->getSession()->getPage();

    foreach ([
      'h1.page-title' => $revision_labels['lms_lesson'] . ' - ' . $revision_labels['lms_activity'],
      '.lms-lesson-title--current' => $revision_labels['lms_lesson'],
      '.lms-activity-title--current' => $revision_labels['lms_activity'],
    ] as $selector => $not_expected) {
      $element = $page->find('css', $selector);
      $this->assertElementTextNotContains($element, $not_expected, 'Revision loading check:');
    }
    foreach ([
      // Course title is the only exception - use revision data.
      '.lms-course-navigation-title' => $revision_labels['group'],
      'h1.page-title' => $test_entity_data['lms_lesson']['values']['name'] . ' - ' . $test_entity_data['lms_activity']['values']['name'],
      '.lms-lesson-title--current' => $test_entity_data['lms_lesson']['values']['name'],
      '.lms-activity-title--current' => $test_entity_data['lms_activity']['values']['name'],
    ] as $selector => $expected) {
      $element = $page->find('css', $selector);
      $this->assertElementTextContains($element, $expected, 'Revision loading check:');
    }
    $activity_element_count = \count($page->findAll('css', '.lms-lesson-item--current .lms-activity-item'));
    $expected_activity_count = \count($test_entity_data['lms_lesson']['activities']);
    self::assertEquals($expected_activity_count, $activity_element_count, \sprintf('%d activities expected.', $expected_activity_count));

    // Login as the teacher and assert that current revision values
    // are displayed.
    $this->drupalLogin($this->users['teacher']);
    $this->drupalGet($activity_url);

    foreach ([
      'h1.page-title' => $test_entity_data['lms_lesson']['values']['name'] . ' - ' . $test_entity_data['lms_activity']['values']['name'],
      '.lms-lesson-title--current' => $test_entity_data['lms_lesson']['values']['name'],
      '.lms-activity-title--current' => $test_entity_data['lms_activity']['values']['name'],
    ] as $selector => $not_expected) {
      $element = $page->find('css', $selector);
      $this->assertElementTextNotContains($element, $not_expected, 'Revision loading check:');
    }
    foreach ([
      'h1.page-title' => $revision_labels['lms_lesson'] . ' - ' . $revision_labels['lms_activity'],
      '.lms-lesson-title--current' => $revision_labels['lms_lesson'],
      '.lms-activity-title--current' => $revision_labels['lms_activity'],
    ] as $selector => $expected) {
      $element = $page->find('css', $selector);
      $this->assertElementTextContains($element, $expected, 'Revision loading check:');
    }
    $activity_element_count = \count($page->findAll('css', '.lms-lesson-item--current .lms-activity-item'));
    // One activity was removed from the lesson in the current revision.
    $expected_activity_count = \count($test_entity_data['lms_lesson']['activities']) - 1;
    self::assertEquals($expected_activity_count, $activity_element_count, \sprintf('%d activities expected.', $expected_activity_count));

    $this->drupalLogin($this->users['student']);

    // Reset course progress and check if new versions are used.
    $this->container->get('lms.training_manager')->resetTraining($test_entity_ids['group'], $this->users['student']->id());
    $this->drupalGet(Url::fromRoute('entity.group.canonical', [
      'group' => $test_entity_ids['group'],
    ]));
    $page->clickLink('Start');

    foreach ($test_entity_data['group']['lessons'] as $lesson_delta => $course_lesson_item) {
      $lesson_item = $this->getItemByUuid($course_lesson_item['target_uuid'], $this->lessonData);
      foreach ($lesson_item['activities'] as $activity_delta => $lesson_activity_item) {
        if (
          $lesson_delta === $test_lesson_delta &&
          $activity_delta === $test_activity_delta
        ) {
          // We are exactly where entities with new revisions are.
          break 2;
        }
        $activity_item = $this->getItemByUuid($lesson_activity_item['target_uuid'], $this->activityData);
        $this->answerActivity($activity_item, (int) $lesson_activity_item['max_score'], $this->users['student']->id(), $test_entity_ids['group']);
      }
    }

    foreach ([
      '.lms-course-navigation-title' => $revision_labels['group'],
      'h1.page-title' => $revision_labels['lms_lesson'] . ' - ' . $revision_labels['lms_activity'],
      '.lms-lesson-title--current' => $revision_labels['lms_lesson'],
      '.lms-activity-title--current' => $revision_labels['lms_activity'],
    ] as $selector => $expected) {
      $element = $page->find('css', $selector);
      $this->assertElementTextContains($element, $expected, 'Revision loading check:');
    }
    foreach ([
      'h1.page-title' => $test_entity_data['lms_lesson']['values']['name'] . ' - ' . $test_entity_data['lms_activity']['values']['name'],
      '.lms-lesson-title--current' => $test_entity_data['lms_lesson']['values']['name'],
      '.lms-activity-title--current' => $test_entity_data['lms_activity']['values']['name'],
    ] as $selector => $not_expected) {
      $element = $page->find('css', $selector);
      $this->assertElementTextNotContains($element, $not_expected, 'Revision loading check:');
    }
    $activity_element_count = \count($page->findAll('css', '.lms-lesson-item--current .lms-activity-item'));
    // One activity was removed from the lesson.
    $expected_activity_count = \count($test_entity_data['lms_lesson']['activities']) - 1;
    self::assertEquals($expected_activity_count, $activity_element_count, \sprintf('%d activities expected.', $expected_activity_count));
  }

  /**
   * Test random activities feature.
   */
  private function randomActivitiesTest(): void {
    $page = $this->getSession()->getPage();

    $course_item = $this->courseData[1];
    $course_id = $this->getEntityIdByProperties('group', ['label' => $course_item['values']['label']]);
    $student_id = $this->users['student']->id();

    $this->container->get('lms.training_manager')->resetTraining($course_id, $student_id);
    unset($this->answers[$course_id][$student_id]);

    $this->drupalLogin($this->users['teacher']);

    $lesson_item = $this->getItemByUuid($course_item['lessons'][0]['target_uuid'], $this->lessonData);
    $lesson_id = $this->getEntityIdByProperties('lms_lesson', ['name' => $lesson_item['values']['name']]);

    $this->drupalGet(Url::fromRoute('entity.lms_lesson.edit_form', [
      'lms_lesson' => $lesson_id,
    ]));

    $this->setEntityFormField('randomization', '2');
    $random_activities_count = \count($lesson_item['activities']) - 1;
    $this->setEntityFormField('random_activities', (string) $random_activities_count);
    $this->pressButton('edit-submit');

    $this->drupalLogin($this->users['student']);

    $this->drupalGet(Url::fromRoute('entity.group.canonical', [
      'group' => $course_id,
    ]));
    $page->clickLink('Enroll');
    $this->pressButton('edit-submit');
    $page->clickLink('Start');

    $course_status = $this->getEntityByProperties('lms_course_status', [
      'course' => $course_id,
      'uid' => $student_id,
    ]);
    $lesson_status = $this->getEntityByProperties('lms_lesson_status', [
      'course_status' => $course_status->id(),
      LessonStatusInterface::LESSON_FIELD => $lesson_id,
    ]);
    \assert($lesson_status instanceof LessonStatusInterface);

    $selected_activities = $lesson_status->getActivities();
    self::assertEquals($random_activities_count, \count($selected_activities), \sprintf('Expected %d random activities.', $random_activities_count));

    foreach ($selected_activities as $activity) {
      $activity_item = NULL;
      foreach ($this->activityData as $item) {
        if ($item['values']['name'] === $activity->label()) {
          $activity_item = $item;
          break;
        }
      }
      self::assertNotNull($activity_item, \sprintf('Activity %s not found.', $activity->label()));

      $lesson_activity_item = NULL;
      foreach ($lesson_item['activities'] as $item) {
        if ($item['target_uuid'] === $activity_item['uuid']) {
          $lesson_activity_item = $item;
          break;
        }
      }
      self::assertNotNull($lesson_activity_item, \sprintf('Lesson activity reference %s not found.', $activity_item['uuid']));
      $max_score = (int) $lesson_activity_item['max_score'];

      $this->answerActivity($activity_item, $max_score, $student_id, $course_id);
    }

    $answer_count = \count($this->answers[$course_id][$student_id]);
    self::assertEquals($random_activities_count, $answer_count, \sprintf('Expected exactly %d activities to be answered.', $random_activities_count));

    $second_lesson_item = $this->getItemByUuid($course_item['lessons'][1]['target_uuid'], $this->lessonData);
    // Assert that after answering all random activities,
    // the second lesson is displayed.
    $this->assertSession()->pageTextContains($second_lesson_item['values']['name']);
  }

  /**
   * Test LMS hooks.
   */
  private function hooksTest(): void {
    $page = $this->getSession()->getPage();

    $course_item = $this->courseData[1];
    $course_id = $this->getEntityIdByProperties('group', ['label' => $course_item['values']['label']]);
    $student_id = $this->users['student']->id();
    $this->container->get('lms.training_manager')->resetTraining($course_id, $student_id);
    $this->drupalLogin($this->users['student']);
    $this->drupalGet(Url::fromRoute('entity.group.canonical', [
      'group' => $course_id,
    ]));
    $this->container->get('keyvalue.database')->get('lms_test')->set('lms_initialize_lesson', TRUE);
    $page->clickLink('Start');

    $warning = $page->find('css', '.messages--warning');
    self::assertNotNull($warning, 'No warning element found on the page.');
    $this->assertElementTextContains($warning, 'No lesson access, you have been redirected', 'Different warning displayed.');
    $this->assertSession()->addressMatches('/^.*\/user\/' . $student_id . '$/');
  }

}
