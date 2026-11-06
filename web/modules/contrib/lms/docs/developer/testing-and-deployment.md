# Testing and Deployment

## Test Environment Setup

To set up a testing environment:

1.  Use the DDEV repository for local setup and testing (see [https://github.com/graber-1/drupal\_lms\_ddev](https://github.com/graber-1/drupal_lms_ddev)):
    
        ddev start
        ddev composer install
        ddev composer sid
    
    The `ddev composer sid` command is a shortcut that sets up a full test site for you, including running a Drush command that imports course content for testing:  
    `ddev drush lms:create-test-content --module=lms`
    
2.  Test with different user accounts (all default users have a password of 123456):
    
    -   **LMS Admin**: Full access
    -   **LMS Teacher**: Can create content and evaluate answers
    -   **LMS Student**: Can take courses (by default this is just the Authenticated user role)  
           
        
        If you're setting up the site with User 1 or another Admin account, make sure you give your account the LMS Admin role so that it works correctly with the LMS module functions.
        
3.  If needed, you can reset a user's course progress, either from the course's **Students** tab, or from the command line:
    
        ddev drush lms:reset-course 1 3  # Reset course 1 for user 3
    

## Writing Tests for Custom Functionality

When adding functionality to Drupal LMS, it's extremely helpful to include automated tests to ensure everything works as expected.  This has a couple of important benefits:

1.  It can reduce overall development time when developing new features, since testing them fully can be complex and needs to be redone with every code update; and  
      
2.  It protects any future code changes that may affect the intended functionality from going unnoticed.  
      

The best approach is to create a functional JavaScript test that simulates user interaction. The LMS module provides a core test class and a helper trait to make this process easier:

### 1: Set Up Your Module's File Structure

Organize your custom module (e.g., `my_lms_custom`) with the following directory structure to include testing:

    my_lms_custom/
    ├── my_lms_custom.info.yml
    ├── ... custom module code
    └── tests/
        ├── data/
        │   ├── activity_types.yml
        │   ├── activities.yml
        │   ├── lessons.yml
        │   ├── courses.yml
        │   └── users.yml
        └── src/
            └── FunctionalJavascript/
                └── MyLmsCustomTest.php

### 2: Create Test YAML Files

In the `tests/data/` directory, create YAML files that define the entities your test will need. You can use the files in `lms/tests/data/` as a template. Note that `activity_types.yml` is required: `LmsTestHelperTrait::createTestContent()` always calls `installActivityTypes()`, which reads `tests/data/activity_types.yml` from your module unconditionally.

### 3: Create the Test Class

Your test class should extend Drupal's `WebDriverTestBase` and use the `LmsTestHelperTrait`, which provides essential setup and interaction methods.

The `lms/tests/src/FunctionalJavascript/GeneralLmsTest.php` file is the best reference for a complete example.

    // In tests/src/FunctionalJavascript/MyLmsCustomTest.php
    
    namespace Drupal\Tests\my_lms_custom\FunctionalJavascript;
    
    use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
    use Drupal\Tests\lms\LmsTestHelperTrait;
    
    class MyLmsCustomTest extends WebDriverTestBase {
      use LmsTestHelperTrait;
    
      protected static $modules = [
        // Include lms and all its dependencies, plus your custom module.
        'lms',
        'lms_classes', // optional
        'lms_answer_plugins',
        'my_lms_custom',
      ];
    
      protected $defaultTheme = 'claro';
    
      protected function setUp(): void {
        parent::setUp();
        // The LmsTestHelperTrait provides these methods to load your YAML data.
        $this->setSourceData(); 
        $this->createTestContent('my_lms_custom');
      }
    
      public function testMyCustomFunctionality() {
        // Your test logic here. You can use helper methods from the trait, like:
        // $this->answerActivity($activity_item, ...);
        // $this->evaluateCourse($course_id, $student_id);
      }
    }

### 4\. Add Teardown

Implement proper teardown to clean up test data. To ensure your tests do not interfere with each other, it's crucial to clean up any entities created during setup. Because LMS entities have complex relationships, deleting them can be slow. A best practice is to queue them for deletion in your test's `tearDown()` method, allowing the queue worker to handle the cleanup efficiently in the background.

    protected function tearDown(): void {
      $this->container->get('queue')
        ->get('lms_delete_entities')
        ->createItem(['entity_type' => 'lms_answer', 'ids' => $this->answerIds]);

      parent::tearDown();
    }

### 5: Handle Additional Setup with QaContentEvent

If your test requires setup beyond simple entity creation (e.g., creating relationships), you can subscribe to the `QaContentEvent`. This event is dispatched by the `lms:create-test-content` Drush command after the YAML content has been imported.

### 6: Run Your Custom Test

You can run your new functional test from the command line using the project's `ddev phpunit` command, which runs PHPUnit against this repository's own `phpunit.xml` configuration. That command (see `.ddev/commands/web/phpunit`) only looks under `web/modules/contrib/`, so your module needs to live there (not in `web/modules/custom/`) for the test to be found:

    ddev phpunit my_lms_custom/tests/src/FunctionalJavascript/MyLmsCustomTest.php

**Note:** Make sure to replace `my_lms_custom` with the actual machine name of your module, and adjust the path to your test file accordingly.

Another testing consideration is for accessibility, especially for public educational institutions that must meet accessibility standards.  You may also want to test fully with screen readers and keyboard navigation.

## Deployment Considerations

When deploying a Drupal LMS site, there are several module-specific considerations beyond a standard Drupal deployment.

### Database Updates for Data Integrity

Because student progress is critical data, it's essential to handle updates carefully. If a code change requires altering existing progress records, use `hook_update_N()`. The `TrainingManager` provides a safe method for this:

-   **Fixing Data Inconsistencies:** If you find that a student's progress data has become corrupted, you can use the `updateUserCourseData()` method to recalculate and fix their status entities. This is much safer than direct database queries.  
      

### Performance Optimizations

-   **Entity Query Optimization:** The `lms_course_status` and `lms_lesson_status` entities can grow very large on an active site. If you build custom queries, it's good practice to use ranges (e.g., `$query->range(0, 50)`) to limit results. Consider adding custom database indexes via `hook_schema_alter()` if you have frequently queried field combinations.  
      
-   **Caching Strategies:** The course navigation block is heavily cached. It uses the `user` and `url.path` cache contexts. When building custom blocks or components related to a course, make sure you add these contexts to your render arrays to maintain correct cache variations.  
      
-   **AJAX Optimization:** The module uses targeted AJAX replacements to minimize data transfer and avoid rebuilding entire forms. Follow this pattern in any custom AJAX implementations.  
      

### Security Considerations

-   **Access Control for Custom Entities:** If you create custom entities that integrate with LMS, always implement `hook_entity_access()` or a custom access control handler.  You can use `AccessResult::allowedIfHasPermission()` for permission checks.  
      
-   **Sanitizing Answer Data:** Be particularly careful with plugins that handle student input (like "Free Text"). Although Drupal's systems provide a strong baseline, always sanitize data before it is stored or rendered, especially if you are creating custom evaluation or reporting tools.  
      

### Upgrading and Maintenance

-   **Use Maintenance Mode for Major Updates:** When deploying updates that might alter the structure of courses or activities, always use Drupal's maintenance mode to prevent students from accessing content while changes are in progress.  
      
-   **Orphaned Data Cleanup:** Depending on your site's custom logic, it may be possible to create orphaned answer entities (e.g., if a lesson status is deleted improperly). It's a best practice to implement `hook_cron()` to periodically clean up any such data.  
      

Drupal LMS is unique in that it allows you to leverage Drupal's entire ecosystem of modules with full compatibility, giving you almost unlimited options for content, media handling, accessibility, community building, internationalization, and much more.  We're excited to see what you do with it!

[**Previous page: Developer's Cookbook**](developers-cookbook.md)

**[Back to main Drupal LMS  
documentation page](../index.md)**
