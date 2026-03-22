<?php

namespace Drupal\Tests\anu_lms\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Test the courses pages.
 *
 * @group anu_lms
 */
class CoursePageTest extends WebDriverTestBase {

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
   * Test everything related to courses page.
   */
  public function testCoursesPageCreation() {
    $account = $this->drupalCreateUser([], 'test', TRUE);
    $this->drupalLogin($account);

    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();

    // 1. Course category taxonomy.
    $this->drupalGet('admin/structure/taxonomy/manage/course_category/add');

    // Add "People" term.
    $page->fillField('Name', 'People');
    $page->pressButton('Save');

    // Add "Animals" term.
    $page->fillField('Name', 'Animals');
    $page->pressButton('Save');

    // 2. Course topic taxonomy.
    $this->drupalGet('admin/structure/taxonomy/manage/course_topics/add');

    // Add "History" term.
    $page->fillField('Name', 'History');
    $page->pressButton('Save');

    // Add "Science" term.
    $page->fillField('Name', 'Science');
    $page->pressButton('Save');

    // 3. Create courses.
    $courses = [
      [
        'category' => '1',
        'courses' => [
          [
            'title' => 'British',
            'topic' => '3',
          ], [
            'title' => 'England',
            'topic' => '4',
          ],
        ],
      ], [
        'category' => '2',
        'courses' => [
          [
            'title' => 'Birds',
            'topic' => '3',
          ], [
            'title' => 'Snakes',
            'topic' => '4',
          ], [
            'title' => 'Fishes',
            'topic' => '4',
          ],
        ],
      ],
    ];
    foreach ($courses as $group) {
      foreach ($group['courses'] as $course) {
        $this->drupalGet('node/add/course');

        $page->fillField('Title', $course['title']);

        // Upload image.
        $imagePath = __DIR__ . '/assets/example.png';
        $page->attachFileToField('Add a new file', $imagePath);
        $assert->assertWaitOnAjaxRequest();
        $page->fillField('Alternative text', 'Example image');

        // Set category.
        $page->selectFieldOption('Category', $group['category']);

        // Set topic.
        $page->selectFieldOption('Topics', $course['topic']);

        // Set alias.
        $page->findById('edit-path-0')->click();
        $page->fillField('URL alias', '/course/' . strtolower($course['title']));

        // Go to "Modules" tab.
        $page->findLink('Modules')->click();

        // Set title for 1st Module.
        $page->fillField('field_course_module[0][subform][field_module_title][0][value]', 'Module 1');

        // Add an empty lesson.
        $page->pressButton('Add new lesson');
        $assert->assertWaitOnAjaxRequest();
        $page->fillField('field_course_module[0][subform][field_module_lessons][form][0][title][0][value]', 'Lesson 1');
        $page->pressButton('Create lesson');
        $assert->assertWaitOnAjaxRequest();

        // Save the course.
        $page->pressButton('Save');
      }
    }

    // 4. Create courses page.
    $this->drupalGet('node/add/courses_landing_page');
    $page->fillField('Title', 'Demo Courses');

    // Set alias.
    $page->findById('edit-path-0')->click();
    $page->fillField('URL alias', '/courses/demo');

    // Save the courses page.
    $page->pressButton('Save');

    // Make sure that all categories, topics, courses are there.
    $this->checkCategories(['Animals', 'People']);
    $this->checkTopics(['History', 'Science']);
    $this->checkCourses(['British', 'England', 'Birds', 'Snakes', 'Fishes']);

    // Filter by "Animals" category.
    $page->find('css', 'nav[aria-label="categories"] span:contains("Animals")')->click();
    $this->checkCourses(['Birds', 'Snakes', 'Fishes']);
    $this->checkTopics(['History', 'Science']);

    // Filter by "People" category.
    $page->find('css', 'nav[aria-label="categories"] span:contains("People")')->click();
    $this->checkCourses(['British', 'England']);

    // Hit "All categories".
    $page->find('css', 'nav[aria-label="categories"] span:contains("All categories")')->click();

    // Filter by "History" topic.
    $page->find('css', 'nav[aria-label="topics"] span:contains("History")')->click();
    $this->checkCourses(['British', 'Birds']);

    // Filter by "Science" topic.
    $page->find('css', 'nav[aria-label="topics"] span:contains("Science")')->click();
    $this->checkCourses(['England', 'Snakes', 'Fishes']);

    // 5. Choose "Animals" category for "Demo Courses" courses page.
    $course = $this->drupalGetNodeByTitle('Demo Courses');
    $this->drupalGet('node/' . $course->id() . '/edit');
    $page->checkField('Animals');

    // Save the courses page.
    $page->pressButton('Save');

    // Make sure that all categories, topics, courses are there.
    $this->checkCategories(['Animals', 'People']);
    $this->checkTopics(['History', 'Science']);
    $this->checkCourses(['British', 'England', 'Birds', 'Snakes', 'Fishes']);

    // 6. Choose "History" topic for "Demo Courses" courses page.
    $this->drupalGet('node/' . $course->id() . '/edit');
    $page->uncheckField('Animals');
    $page->checkField('History');

    // Save the courses page.
    $page->pressButton('Save');

    // Make sure that all categories, topics, courses are there.
    $this->checkCategories(['Animals', 'People']);
    $this->checkTopics(['History', 'Science']);
    $this->checkCourses(['British', 'England', 'Birds', 'Snakes', 'Fishes']);

    // 7. Choose "People" category, "History" topic for "Demo Courses" courses
    // page.
    $this->drupalGet('node/' . $course->id() . '/edit');
    $page->checkField('People');
    $page->checkField('History');

    // Save the courses page.
    $page->pressButton('Save');

    // Make sure that no categories, topics and only one course are there.
    $this->checkCategories([]);
    $this->checkTopics([]);
    $this->checkCourses(['British']);

    // 8. Untick all checkboxes for "Demo Courses" courses page.
    $this->drupalGet('node/' . $course->id() . '/edit');
    $page->uncheckField('People');
    $page->uncheckField('History');

    // Save the courses page.
    $page->pressButton('Save');

    // 9. Set "Courses must be completed in this order" for "People" category.
    $this->drupalGet('taxonomy/term/1/sort-courses');
    $page->checkField('Courses must be completed in this order');
    $page->pressButton('Save');

    // Make sure that "Courses must be completed in this order" is ticked.
    $checkbox = $page->findField('Courses must be completed in this order');
    $this->assertTrue($checkbox->isChecked());

    // Make sure that "England" course was locked, all others unlocked.
    $this->drupalGet('courses/demo');

    $englandCourse = $page->find('css', '[data-test="anu-lms-courses-list"] h3:contains("England")');
    $this->assertTrue($englandCourse->hasClass('locked'));

    $unclockedCourses = [
      'British',
      'Birds',
      'Snakes',
      'Fishes',
    ];

    foreach ($unclockedCourses as $course) {
      $elem = $page->find('css', '[data-test="anu-lms-courses-list"] h3:contains("' . $course . '")');
      $this->assertFalse($elem->hasClass('locked'));
    }
  }

  /**
   * Test everything related to old courses page.
   */
  public function testOldCoursesPageCreation() {
    $account = $this->drupalCreateUser([], 'test', TRUE);
    $this->drupalLogin($account);

    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();

    // 1. Course category taxonomy.
    $this->drupalGet('admin/structure/taxonomy/manage/course_category/add');

    // Add "People" term.
    $page->fillField('Name', 'People');
    $page->pressButton('Save');

    // Add "Animals" term.
    $page->fillField('Name', 'Animals');
    $page->pressButton('Save');

    // 2. Create courses.
    $courses = [
      [
        'category' => '1',
        'titles' => [
          'British', 'England',
        ],
      ], [
        'category' => '2',
        'titles' => [
          'Birds', 'Fishes', 'Snakes',
        ],
      ],
    ];
    foreach ($courses as $course) {
      foreach ($course['titles'] as $title) {
        $this->drupalGet('node/add/course');

        $page->fillField('Title', $title);

        // Upload image.
        $imagePath = __DIR__ . '/assets/example.png';
        $page->attachFileToField('Add a new file', $imagePath);
        $assert->assertWaitOnAjaxRequest();
        $page->fillField('Alternative text', 'Example image');

        // Set category.
        $page->selectFieldOption('Category', $course['category']);

        // Set alias.
        $page->findById('edit-path-0')->click();
        $page->fillField('URL alias', '/course/' . strtolower($title));

        // Go to "Modules" tab.
        $page->findLink('Modules')->click();

        // Set title for 1st Module.
        $page->fillField('field_course_module[0][subform][field_module_title][0][value]', 'Module 1');

        // Add an empty lesson.
        $page->pressButton('Add new lesson');
        $assert->assertWaitOnAjaxRequest();
        $page->fillField('field_course_module[0][subform][field_module_lessons][form][0][title][0][value]', 'Lesson 1');
        $page->pressButton('Create lesson');
        $assert->assertWaitOnAjaxRequest();

        // Save the course.
        $page->pressButton('Save');
      }
    }

    // 3. Create courses page.
    $this->drupalGet('node/add/courses_page');

    $coursesPageTitle = 'Know more about people';
    $page->fillField('Title', $coursesPageTitle);

    // Set category.
    $page->selectFieldOption('Category', '1');

    // Set alias.
    $page->findById('edit-path-0')->click();
    $page->fillField('URL alias', '/courses/' . strtolower($coursesPageTitle));
    $page->pressButton('Save');

    // Make sure that the courses page was added.
    $title = $assert->waitForElementVisible('css', '#anu-application h1');
    $this->assertNotEmpty($title);
    $this->assertSame($coursesPageTitle, $title->getText());

    // 4. Make sure that "Know more about people" includes only 1 category and
    // 2 courses.
    $filterCategories = $page->findAll('css', '[data-test=anu-lms-courses-category-filter] span.MuiChip-label');
    $this->assertSame('All categories', $filterCategories[0]->getText());
    $this->assertSame('People', $filterCategories[1]->getText());
    $this->assertCount(2, $filterCategories);

    $coursesList = $page->findAll('css', '[data-test=anu-lms-courses-list] h3');
    $this->assertSame('British', $coursesList[0]->getText());
    $this->assertSame('England', $coursesList[1]->getText());
    $this->assertCount(2, $coursesList);

    // 4. Add a category "Animals" to "Know more about people" and make sure new
    // lessons and the category will be added to that courses page.
    $node = $this->drupalGetNodeByTitle('Know more about people');
    $this->drupalGet('node/' . $node->id() . '/edit');

    // Add "Animals" category.
    $page->findButton('Add Course category')->click();
    $assert->assertWaitOnAjaxRequest();
    $page->selectFieldOption('Category', '2');

    // Save changes.
    $page->pressButton('Save');

    // Make sure that category "Animals" was added.
    $filterCategories = $page->findAll('css', '[data-test=anu-lms-courses-category-filter] span.MuiChip-label');
    $this->assertSame('All categories', $filterCategories[0]->getText());
    $this->assertSame('People', $filterCategories[1]->getText());
    $this->assertSame('Animals', $filterCategories[2]->getText());
    $this->assertCount(3, $filterCategories);

    // Make sure that all courses are there.
    $categoriesTitles = $page->findAll('css', '[data-test=anu-lms-courses-list] h2');
    $this->assertSame('People', $categoriesTitles[0]->getText());
    $this->assertSame('Animals', $categoriesTitles[1]->getText());
    $this->assertCount(2, $categoriesTitles);

    // Make sure that all lessons are there.
    $coursesList = $page->findAll('css', '[data-test=anu-lms-courses-list] h3');
    $this->assertSame('British', $coursesList[0]->getText());
    $this->assertSame('England', $coursesList[1]->getText());
    $this->assertSame('Birds', $coursesList[2]->getText());
    $this->assertSame('Fishes', $coursesList[3]->getText());
    $this->assertSame('Snakes', $coursesList[4]->getText());
    $this->assertCount(5, $coursesList);

    // 5. Change order of categories to "Animals" -> "People" for "Know more
    // about people".
    $node = $this->drupalGetNodeByTitle('Know more about people');
    $this->drupalGet('node/' . $node->id() . '/edit');

    // Remove 1st one (People) and add that category at the end instead of sort
    // of categories.
    $paragraphToggleIcon = $assert->waitForElementVisible('css', '#edit-field-courses-content-0-top .paragraphs-dropdown-toggle');
    $paragraphToggleIcon->click();
    $removeButton = $assert->waitForElementVisible('css', '#field-courses-content-0-remove');
    $removeButton->click();
    $assert->assertWaitOnAjaxRequest();

    $page->findButton('Add Course category')->click();
    $assert->assertWaitOnAjaxRequest();
    $page->selectFieldOption('Category', '1');

    // Save changes.
    $page->pressButton('Save');

    // Make sure that category "Animals" is located before "People" in filters.
    $filterCategories = $page->findAll('css', '[data-test=anu-lms-courses-category-filter] span.MuiChip-label');
    $this->assertSame('All categories', $filterCategories[0]->getText());
    $this->assertSame('Animals', $filterCategories[1]->getText());
    $this->assertSame('People', $filterCategories[2]->getText());
    $this->assertCount(3, $filterCategories);

    // Make sure that category "Animals" is located before "People" in courses
    // list.
    $categoriesTitles = $page->findAll('css', '[data-test=anu-lms-courses-list] h2');
    $this->assertSame('Animals', $categoriesTitles[0]->getText());
    $this->assertSame('People', $categoriesTitles[1]->getText());
    $this->assertCount(2, $categoriesTitles);

    // Make sure that lessons from "Animals" category is located before lessons
    // from "People" category.
    $coursesList = $page->findAll('css', '[data-test=anu-lms-courses-list] h3');
    $this->assertSame('Birds', $coursesList[0]->getText());
    $this->assertSame('Fishes', $coursesList[1]->getText());
    $this->assertSame('Snakes', $coursesList[2]->getText());
    $this->assertSame('British', $coursesList[3]->getText());
    $this->assertSame('England', $coursesList[4]->getText());
    $this->assertCount(5, $coursesList);

    // 6. Set "Courses must be completed in this order" for "Animals" category.
    $this->drupalGet('taxonomy/term/2/sort-courses');
    $page->checkField('Courses must be completed in this order');
    $page->pressButton('Save');
    $this->drupalGet('taxonomy/term/2/sort-courses');

    // Go to the "Know more about people" page.
    $node = $this->drupalGetNodeByTitle('Know more about people');
    $this->drupalGet($node->toUrl());

    // Make sure that lessons from "Fishes" and "Snakes" courses are disabled.
    $coursesList = $page->findAll('css', '[data-test=anu-lms-courses-list] a');
    $this->assertSame('false', $coursesList[0]->getAttribute('aria-disabled'));
    $this->assertSame('true', $coursesList[1]->getAttribute('aria-disabled'));
    $this->assertSame('true', $coursesList[2]->getAttribute('aria-disabled'));
    $this->assertSame('false', $coursesList[3]->getAttribute('aria-disabled'));
    $this->assertSame('false', $coursesList[4]->getAttribute('aria-disabled'));

    // Make sure that "Fishes" and "Snakes" are disabled there as well.
    $courses = ['Fishes', 'Snakes'];
    foreach ($courses as $course) {
      $node = $this->drupalGetNodeByTitle($course);
      $this->drupalGet($node->toUrl());
      $body = $page->find('css', '.page-content');
      $this->assertNotEmpty($body);
      $this->assertStringContainsString('This course is locked.', $body->getText());
    }
  }

  /**
   * Check that categories are correct.
   *
   * @param array $items
   *   The array of categories.
   */
  private function checkCategories(array $items) {
    $page = $this->getSession()->getPage();

    $detectedItems = $page->findAll('css', 'nav[aria-label="categories"] span.MuiListItemText-primary');
    $this->assertCount(!empty($items) ? count($items) + 1 : 0, $detectedItems);

    array_unshift($items, 'All categories');

    foreach ($detectedItems as $i => $detectedItem) {
      $this->assertSame($items[$i], $detectedItem->getText());
    }
  }

  /**
   * Check that topics are correct.
   *
   * @param array $items
   *   The array of categories.
   */
  private function checkTopics(array $items) {
    $page = $this->getSession()->getPage();

    $detectedItems = $page->findAll('css', 'nav[aria-label="topics"] span.MuiListItemText-primary');
    $this->assertCount(!empty($items) ? count($items) + 1 : 0, $detectedItems);

    array_unshift($items, 'All topics');

    foreach ($detectedItems as $i => $detectedItem) {
      $this->assertSame($items[$i], $detectedItem->getText());
    }
  }

  /**
   * Check that courses are correct.
   *
   * @param array $items
   *   The array of categories.
   */
  private function checkCourses(array $items) {
    $page = $this->getSession()->getPage();

    $amount = $page->find('css', '[data-test="anu-lms-amount-courses"]');
    $this->assertSame(count($items) . ' Course' . (count($items) > 1 ? 's' : ''), $amount->getText());

    $list = $page->findAll('css', '[data-test="anu-lms-courses-list"] h3');

    foreach ($list as $i => $course) {
      $this->assertSame($items[$i], $course->getText());
    }
  }

}
