<?php

namespace Drupal\Tests\anu_lms\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Test the courses.
 *
 * @group anu_lms
 */
class CourseTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'anu_lms',
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Rebuild router to get all custom routes.
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Test linear progress feature for courses.
   */
  public function testLinearProgressForCourses() {
    $account = $this->drupalCreateUser([], 'test', TRUE);
    $this->drupalLogin($account);

    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();

    // 1. Create a course with "linear progress".
    $this->drupalGet('node/add/course');
    $page->fillField('Title', 'Linear progress course demo');

    // Upload image.
    $imagePath = __DIR__ . '/assets/example.png';
    $page->attachFileToField('Add a new file', $imagePath);
    $assert->assertWaitOnAjaxRequest();
    $page->fillField('Alternative text', 'Example image');

    // Set alias.
    $page->findById('edit-path-0')->click();
    $page->fillField('URL alias', '/course/linear');

    // Go to "Modules" tab.
    $page->findLink('Modules')->click();

    // Set module name.
    $page->fillField('field_course_module[0][subform][field_module_title][0][value]', 'Demo Module');

    // Add lessons.
    $this->addLesson('Lesson 1', 'new');
    $this->addLesson('Lesson 2', 'new');
    $this->addLesson('Lesson 3', 'new');

    // Go to "Settings" tab.
    $page->findLink('Settings')->click();

    // Enable linear progress.
    $page->checkField('Enable linear progress');

    // Create the course.
    $page->pressButton('Save');

    // 2. Attempt to open 2nd lesson directly. It should be rejected because
    // that lesson is restricted for the current user.
    $lesson2 = $this->drupalGetNodeByTitle('Lesson 2');
    $lesson2Url = $lesson2->toUrl()->toString();
    $this->drupalGet($lesson2Url);

    // Make sure that the user was redirected to the user page.
    $this->assertStringEndsWith('/user/2', $this->getUrl());

    // 3. Attempt to open 3rd lesson directly. It should be rejected because
    // that lesson is restricted for the current user.
    $lesson3 = $this->drupalGetNodeByTitle('Lesson 3');
    $lesson3Url = $lesson3->toUrl()->toString();
    $this->drupalGet($lesson3Url);

    // 4. Go to 1st lesson.
    $lesson1 = $this->drupalGetNodeByTitle('Lesson 1');
    $lesson1Url = $lesson1->toUrl()->toString();
    $this->drupalGet($lesson1Url);

    // Tap "Next".
    $assert->waitForElementVisible('css', '[data-test=anu-lms-navigation-next]')->click();

    // Make sure that "Lesson 2" is opened.
    $currentLesson = $assert->waitForElementVisible('css', 'h6:contains("Lesson 2")');
    $this->assertNotEmpty($currentLesson);

    // Make sure that the user is located on "Lesson 2".
    $this->assertStringEndsWith($lesson2Url . '#page-1', $this->getUrl());

    // Tap "Next".
    $assert->waitForElementVisible('css', '[data-test=anu-lms-navigation-next]')->click();

    // Make sure that "Lesson 3" is opened.
    $currentLesson = $assert->waitForElementVisible('css', 'h6:contains("Lesson 3")');
    $this->assertNotEmpty($currentLesson);

    // Make sure that the user is located on "Lesson 3".
    $this->assertStringEndsWith($lesson3Url . '#page-1', $this->getUrl());

    // 5. Disable "Enable linear progress" for the course.
    $course = $this->drupalGetNodeByTitle('Linear progress course demo');
    $this->drupalGet('node/' . $course->id() . '/edit');

    // Go to "Settings" tab.
    $page->findLink('Settings')->click();

    // Enable linear progress.
    $page->uncheckField('Enable linear progress');

    // Save the course.
    $page->pressButton('Save');

    // 6. Create a new user.
    $account = $this->drupalCreateUser([], 'student');
    $this->drupalLogin($account);

    // 7. Attempt to open 2nd lesson directly. Now it should be available.
    $this->drupalGet($lesson2Url);

    // Make sure that the user is on the "Lesson 2" page.
    $this->assertStringEndsWith($lesson2Url . '#page-1', $this->getUrl());

    // 8. Attempt to open 3rd lesson directly. Now it should be available.
    $this->drupalGet($lesson3Url);

    // Make sure that the user is on the "Lesson 3" page.
    $this->assertStringEndsWith($lesson3Url . '#page-1', $this->getUrl());
  }

  /**
   * Test "courses creation" for the general case.
   */
  public function testGeneralCoursesCreation() {
    $account = $this->drupalCreateUser([], 'test', TRUE);
    $this->drupalLogin($account);

    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();

    // 1. Course category taxonomy.
    $this->drupalGet('admin/structure/taxonomy/manage/course_category/add');

    // Add "People" term.
    $page->fillField('Name', 'People');
    $page->pressButton('Save');
    $statusMessage = $page->find('css', '.messages--status');
    $this->assertNotEmpty($statusMessage);
    $this->assertStringContainsString('Created new term', $statusMessage->getText());

    // Add "Animals" term.
    $page->fillField('Name', 'Animals');
    $page->pressButton('Save and go to list');
    $statusMessage = $page->find('css', '.messages--status');
    $this->assertNotEmpty($statusMessage);
    $this->assertStringContainsString('Created new term', $statusMessage->getText());

    // Make sure that 2 items ("People" and "Animals") were added.
    $firstItem = $page->findById('edit-terms-tid10-term');
    $this->assertNotEmpty($firstItem);
    $this->assertSame('People', $firstItem->getText());
    $secondItem = $page->findById('edit-terms-tid20-term');
    $this->assertNotEmpty($secondItem);
    $this->assertSame('Animals', $secondItem->getText());

    // 2. Course label taxonomy.
    $this->drupalGet('admin/structure/taxonomy/manage/course_label/add');

    // Add "Modern" term.
    $page->fillField('Name', 'Modern');
    $page->pressButton('Save');
    $statusMessage = $assert->waitForElementVisible('css', '.messages--status');
    $this->assertNotEmpty($statusMessage);
    $this->assertStringContainsString('Created new term', $statusMessage->getText());

    // Add "Prehistoric" term.
    $page->fillField('Name', 'Prehistoric');
    $page->pressButton('Save and go to list');
    $statusMessage = $assert->waitForElementVisible('css', '.messages--status');
    $this->assertNotEmpty($statusMessage);
    $this->assertStringContainsString('Created new term', $statusMessage->getText());

    // Make sure that 2 items ("Modern" and "Prehistoric") were added.
    $firstItem = $page->findById('edit-terms-tid30-term');
    $this->assertNotEmpty($firstItem);
    $this->assertSame('Modern', $firstItem->getText());
    $secondItem = $page->findById('edit-terms-tid40-term');
    $this->assertNotEmpty($secondItem);
    $this->assertSame('Prehistoric', $secondItem->getText());

    // 3. Lessons.
    $lessons = ['British', 'England', 'Wolf', 'Rabbit'];
    foreach ($lessons as $lesson) {
      $this->drupalGet('node/add/module_lesson');
      $page->fillField('Title', $lesson);

      // Set alias.
      $page->findById('edit-path-0')->click();
      $page->fillField('URL alias', '/lesson/' . strtolower($lesson));
      $page->pressButton('Save');

      // Make sure that the lesson was added.
      $title = $assert->waitForElementVisible('css', '#anu-application .MuiTypography-subtitle2');
      $this->assertNotEmpty($title);
      $this->assertSame($lesson, $title->getText());
    }

    // 4. Set "Courses must be completed in this order" for People.
    $this->drupalGet('taxonomy/term/1/sort-courses');
    $page->checkField('Courses must be completed in this order');
    $page->pressButton('Save');

    // Make sure that "Courses must be completed in this order" is ticked.
    $checkbox = $page->findField('Courses must be completed in this order');
    $this->assertTrue($checkbox->isChecked());

    // 5. Create a course "People in 21st century".
    $this->drupalGet('node/add/course');
    $page->fillField('Title', 'People in 21st century');

    // Upload image.
    $imagePath = __DIR__ . '/assets/example.png';
    $page->attachFileToField('Add a new file', $imagePath);
    $assert->assertWaitOnAjaxRequest();
    $page->fillField('Alternative text', 'Example image');

    // Set alias.
    $page->findById('edit-path-0')->click();
    $page->fillField('URL alias', '/course/people-21');

    // Go to "Modules" tab.
    $page->findLink('Modules')->click();

    // Set module name (without lessons).
    $page->fillField('field_course_module[0][subform][field_module_title][0][value]', 'Demo Module');

    // Wait when ajax request will be finished.
    $assert->assertWaitOnAjaxRequest();

    // Create the course.
    $page->pressButton('Save');

    // Make sure that course was created and doesn't have any lessons.
    $body = $page->find('css', '.page-content');
    $this->assertNotEmpty($body);
    $this->assertStringContainsString('There are no lessons in this course yet.', $body->getText());

    // 6. Edit course "People in 21st century".
    $node = $this->drupalGetNodeByTitle('People in 21st century');

    // Go to "course edit" page.
    $this->drupalGet('node/' . $node->id() . '/edit');

    // Go to "Modules" tab.
    $page->findLink('Modules')->click();

    // Delete default module adding form to test a case when no modules
    // are added.
    $paragraphToggleIcon = $assert->waitForElementVisible('css', '#field-course-module-0-item-wrapper .paragraphs-dropdown-toggle');
    $paragraphToggleIcon->click();
    $removeDefaultCourseButton = $assert->waitForElementVisible('css', '#field-course-module-0-remove');
    $removeDefaultCourseButton->click();
    $assert->assertWaitOnAjaxRequest();

    // Save the course.
    $page->pressButton('Save');

    // Make sure that the course doesn't have any lessons.
    $body = $page->find('css', '.page-content');
    $this->assertNotEmpty($body);
    $this->assertStringContainsString('There are no lessons in this course yet.', $body->getText());

    // Go to "course edit" page.
    $this->drupalGet('node/' . $node->id() . '/edit');

    // Set "People" as category.
    $page->selectFieldOption('Category', '1');

    // Set "Modern" as label.
    $page->selectFieldOption('Label', '3');

    // Go to "Modules" tab.
    $page->findLink('Modules')->click();

    // Hit "Add Course module".
    $page->pressButton('Add Module');
    $assert->assertWaitOnAjaxRequest();

    // Set module name.
    $page->fillField('field_course_module[0][subform][field_module_title][0][value]', 'Demo Module');

    // Add lessons.
    $this->addLesson('British (1)');
    $this->addLesson('England (2)');

    // Save the course.
    $page->pressButton('Save');

    // Go to edit page again.
    $this->drupalGet('node/' . $node->id() . '/edit');

    // Go to "Settings" tab.
    $page->findLink('Settings')->click();

    // Make sure that linear progress is disabled and ticked.
    $checkbox = $page->findField('Enable linear progress');
    $this->assertTrue($checkbox->hasAttribute('disabled'));
    $this->assertTrue($checkbox->isChecked());

    // Clear "dynamic_page" cache to get the latest version of all nodes.
    $this->container->get('cache.dynamic_page_cache')->invalidateAll();

    // Go "People in 21st century" course.
    $this->drupalGet('course/people-21');

    // Check the updated "People in 21st century" course page and make sure
    // that 2nd lesson is restricted.
    $restrictedMenuItem = $page->find('css', '#anu-application a[data-menu-lesson-name="England"]');
    $this->assertFalse($restrictedMenuItem->hasAttribute('href'));

    // Make sure that "Modern" label are there.
    $app = $assert->waitForElementVisible('css', '#anu-application');
    $this->assertNotEmpty($app);
    $this->assertStringContainsString('MODERN', $app->getText());

    // 7. Another one edit course "People in 21st century".
    // Unset "Courses must be completed in this order" for People.
    $this->drupalGet('taxonomy/term/1/sort-courses');
    $page->uncheckField('Courses must be completed in this order');
    $page->pressButton('Save');

    // Go to edit page again.
    $this->drupalGet('node/' . $node->id() . '/edit');

    // Go to "Settings" tab.
    $page->findLink('Settings')->click();

    // Make sure that linear progress is not disabled.
    $checkbox = $page->findField('Enable linear progress');
    $this->assertFalse($checkbox->hasAttribute('disabled'));

    // Disable linear progress.
    $page->uncheckField('Enable linear progress');

    // Save the course.
    $page->pressButton('Save');

    // Check the updated "People in 21st century" course page and make sure
    // that 2nd lesson isn't locked.
    $restrictedMenuItem = $page->find('css', '#anu-application a[data-menu-lesson-name="England"]');
    $this->assertTrue($restrictedMenuItem->hasAttribute('href'));

    // 8. Create course "Animals in ancient times".
    $this->drupalGet('node/add/course');
    $page->fillField('Title', 'Animals in ancient times');

    // Upload image.
    $imagePath = __DIR__ . '/assets/example.png';
    $page->attachFileToField('Add a new file', $imagePath);
    $assert->assertWaitOnAjaxRequest();
    $page->fillField('Alternative text', 'Example image');

    // Set alias.
    $page->findById('edit-path-0')->click();
    $page->fillField('URL alias', '/course/animals-in-ancient-times');

    // Set "Animals" as category.
    $page->selectFieldOption('Category', '2');

    // Set "Prehistoric" as label.
    $page->selectFieldOption('Label', '4');

    // Go to "Modules" tab.
    $page->findLink('Modules')->click();

    // Set module name.
    $page->fillField('field_course_module[0][subform][field_module_title][0][value]', 'Demo Module 1');

    // Add lessons.
    $this->addLesson('Wolf (3)');
    $this->addLesson('Rabbit (4)');

    // Collapse 1st module to avoid collisions during filling 2nd module.
    $page->pressButton('Collapse');
    $assert->assertWaitOnAjaxRequest();

    // Add 2nd module.
    $page->pressButton('Add Module');
    $assert->assertWaitOnAjaxRequest();

    // Set module name.
    $page->fillField('field_course_module[1][subform][field_module_title][0][value]', 'Demo Module 2');

    // Add lessons.
    $this->addLesson('Tiger', 'new');
    $this->addLesson('Crocodile', 'new');
    $this->addLesson('Shark', 'new');

    // Open "edit" mode for 1st module, otherwise latest lesson in the list
    // isn't saved. Probably a bug in paragraphs module.
    $page->pressButton('Edit');
    $assert->assertWaitOnAjaxRequest();

    // Create the course.
    $page->pressButton('Save');
    $assert->assertWaitOnAjaxRequest();

    // Make sure that all modules and lessons are there.
    $module1 = $page->find('css', '#anu-application [data-menu-module-name="Demo Module 1"]');
    $this->assertNotEmpty($module1);
    $lesson11 = $page->find('css', '#anu-application [data-menu-lesson-name="Wolf"]');
    $this->assertNotEmpty($lesson11);
    $lesson12 = $page->find('css', '#anu-application [data-menu-lesson-name="Rabbit"]');
    $this->assertNotEmpty($lesson12);

    // Make sure that a lesson from 2nd module isn't visible.
    $lesson21 = $page->find('css', '#anu-application [data-menu-lesson-name="Tiger"]');
    $this->assertFalse($lesson21->isVisible());

    // Expand 2nd module.
    $module2 = $page->find('css', '#anu-application [data-menu-module-name="Demo Module 2"]');
    $this->assertNotEmpty($module2);
    $module2->click();

    // Check lessons from 2nd module for existence.
    $lesson21 = $page->find('css', '#anu-application [data-menu-lesson-name="Tiger"]');
    $this->assertNotEmpty($lesson21);
    $lesson22 = $page->find('css', '#anu-application [data-menu-lesson-name="Crocodile"]');
    $this->assertNotEmpty($lesson22);
    $lesson23 = $page->find('css', '#anu-application [data-menu-lesson-name="Shark"]');
    $this->assertNotEmpty($lesson23);

    // 9. Edit course "Animals in ancient times".
    $node = $this->drupalGetNodeByTitle('Animals in ancient times');
    $this->drupalGet('node/' . $node->id() . '/edit');

    // Go to "Modules" tab.
    $page->findLink('Modules')->click();

    // Go to "Settings" tab.
    $page->findLink('Settings')->click();

    // Set "Finish button" field.
    $page->fillField('Course finish button', '/home');

    // Save the course.
    $page->pressButton('Save');

    // Make sure that now "Wolf" lesson is opened.
    $currentActive = $assert->waitForElementVisible('css', '#anu-application div[data-test=anu-lms-navigation-item-status-active]');
    $this->assertSame('Wolf', $currentActive->getText());

    // Go to "Rabbit" lesson.
    $next = $assert->waitForElementVisible('css', '#anu-application button[data-test=anu-lms-navigation-next]');
    $this->assertNotEmpty($next);
    $next->click();

    // Make sure that now "Rabbit" lesson is opened.
    $currentActive = $assert->waitForElementVisible('css', '#anu-application div[data-test=anu-lms-navigation-item-status-active]');
    $this->assertSame('Rabbit', $currentActive->getText());

    // Go to "Tiger" lesson.
    $next = $assert->waitForElementVisible('css', '#anu-application button[data-test=anu-lms-navigation-next]');
    $this->assertNotEmpty($next);
    $next->click();

    // Make sure that now "Tiger" lesson is opened.
    $currentActive = $assert->waitForElementVisible('css', '#anu-application div[data-test=anu-lms-navigation-item-status-active]');
    $this->assertSame('Tiger', $currentActive->getText());

    // Go to "Crocodile" lesson.
    $next = $assert->waitForElementVisible('css', '#anu-application button[data-test=anu-lms-navigation-next]');
    $this->assertNotEmpty($next);
    $next->click();

    // Make sure that now "Crocodile" lesson is opened.
    $currentActive = $assert->waitForElementVisible('css', '#anu-application div[data-test=anu-lms-navigation-item-status-active]');
    $this->assertSame('Crocodile', $currentActive->getText());

    // Go to "Shark" lesson.
    $next = $assert->waitForElementVisible('css', '#anu-application button[data-test=anu-lms-navigation-next]');
    $this->assertNotEmpty($next);
    $next->click();

    // Make sure that now "Shark" lesson is opened.
    $currentActive = $assert->waitForElementVisible('css', '#anu-application div[data-test=anu-lms-navigation-item-status-active]');
    $this->assertSame('Shark', $currentActive->getText());

    // Go to "Finish".
    $next = $assert->waitForElementVisible('css', '#anu-application button[data-test=anu-lms-navigation-finish]');
    $this->assertNotEmpty($next);
    $next->click();

    // Make sure that a user is redirected to "/home" page.
    $this->assertStringEndsWith('/home', $this->getUrl());
  }

  /**
   * Add new or existing lesson to a module.
   *
   * @param string $name
   *   Lesson name.
   * @param string $type
   *   Type of lesson (new or existing).
   */
  private function addLesson($name, $type = 'existing') {
    $this->addModuleContent('lesson', $name, $type);
  }

  /**
   * Add new or existing quiz to a module.
   *
   * @param string $name
   *   Quiz name.
   * @param string $type
   *   Type of quiz (new or existing).
   */
  private function addQuiz($name, $type = 'existing') {
    $this->addModuleContent('quiz', $name, $type);
  }

  /**
   * Add new or existing entity to a module.
   *
   * @param string $entity
   *   Entity (lesson or quiz).
   * @param string $name
   *   Entity name.
   * @param string $type
   *   Type of entity (new or existing).
   */
  private function addModuleContent($entity, $name, $type) {
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();

    $page->pressButton('Add ' . $type . ' ' . $entity);
    $assert->assertWaitOnAjaxRequest();

    // This construction used instead of "fillField" because that page already
    // include "Title" (of the course). In this case search by "Title" label
    // always returns exactly that field, not title field of the entity.
    // Possible to use search by input name, but that way does the tests more
    // complicated and less obvious.
    $fieldLabel = $type === 'existing' ? ucfirst($entity) : 'Title';
    $field = $page->findAll('named', ['field', $fieldLabel]);
    if (count($field)) {
      $end = end($field);
      $end->setValue($name);
    }

    // Confirm adding.
    $page->pressButton(($type === 'existing' ? 'Add ' : 'Create ') . $entity);
    $assert->assertWaitOnAjaxRequest();
  }

}
