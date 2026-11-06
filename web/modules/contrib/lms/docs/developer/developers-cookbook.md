# Developer's Cookbook

This page offers you some practical, copy-and-paste-friendly code snippets for common tasks when developing with or extending the Drupal LMS module. These recipes assume you are familiar with the core services and entity types described in the **[Module Architecture](module-architecture.md)** page.

## Programmatically Enroll a User in a Course

**Use Case:** You need to enroll a user in a course automatically, for example, after a Drupal Commerce purchase or based on a custom workflow.

    use Drupal\user\UserInterface;
    use Drupal\lms\Entity\Bundle\Course;
    use Drupal\lms_classes\ClassHelper;
    
    /**
     * Enrolls a user into the default class of a course.
     *
     * @param \Drupal\lms\Entity\Bundle\Course $course
     *   The course to enroll the user in.
     * @param \Drupal\user\UserInterface $user
     *   The user to enroll.
     */
    function enroll_user_in_course(Course $course, UserInterface $user): void {
      // Find the default class associated with the course.
      // This example assumes the first class is the default.
      $classes = ClassHelper::getClasses($course);
      if (empty($classes)) {
        \Drupal::logger('my_module')->error('Course @course has no classes to enroll users into.', ['@course' => $course->label()]);
        return;
      }
      $default_class = reset($classes);
    
      // Add the user as a member to the class.
      // The second parameter can specify roles, e.g., ['group_roles' => ['lms_class-member']].
      if ($default_class->getMember($user) === FALSE) {
        $default_class->addMember($user);
      }
    }

**Explanation:** This function uses the `ClassHelper` to find the classes associated with a course. It then adds the user as a member to the first class found. In a real-world scenario, you might have more complex logic to select the correct class.

## Get a Student's Score for a Specific Lesson

**Use Case:** You are building a custom report or a dashboard and need to display a user's score for a particular lesson within a course.

    use Drupal\user\UserInterface;
    use Drupal\lms\Entity\Bundle\Course;
    use Drupal\lms\Entity\LessonInterface;
    use Drupal\lms\TrainingManager;
    
    /**
     * Gets a user's score for a specific lesson within a course.
     *
     * @param \Drupal\lms\Entity\Bundle\Course $course
     *   The course entity.
     * @param \Drupal\lms\Entity\LessonInterface $lesson
     *   The lesson entity.
     * @param \Drupal\user\UserInterface $user
     *   The user account.
     *
     * @return int|null
     *   The score as a percentage, or NULL if the lesson is not completed/evaluated.
     */
    function get_lesson_score(Course $course, LessonInterface $lesson, UserInterface $user): ?int {
      /** @var \Drupal\lms\TrainingManager $training_manager */
      $training_manager = \Drupal::service('lms.training_manager');
    
      $course_status = $training_manager->loadCourseStatus($course, $user, ['current' => TRUE]);
      if (!$course_status) {
        return NULL; // User has not started the course.
      }
    
      $lesson_status = $training_manager->loadLessonStatus($course_status->id(), $lesson->id());
      if (!$lesson_status || !$lesson_status->isEvaluated()) {
        return NULL; // Lesson not completed or not yet evaluated.
      }
    
      return $lesson_status->getScore();
    }

**Explanation:** The function first loads the user's main `CourseStatus` for the given course. It then uses the ID of that status entity to load the specific `LessonStatus`. The final score is retrieved from the lesson status.  
  

## Get a Student's Current Location in a Course

**Use Case:** You need to determine exactly where a student is in a course to build a custom "Resume Course" link or a dashboard widget.

    use Drupal\user\UserInterface;
    use Drupal\lms\Entity\Bundle\Course;
    use Drupal\lms\TrainingManager;
    
    /**
     * Gets a user's current lesson and activity within a course.
     *
     * @return array|null
     *   An associative array with 'lesson' and 'activity' entities, or NULL.
     */
    function get_student_current_location(Course $course, UserInterface $user): ?array {
      /** @var \Drupal\lms\TrainingManager $training_manager */
      $training_manager = \Drupal::service('lms.training_manager');
    
      $course_status = $training_manager->getCurrentCourseStatus($course, $user);
      $lesson_status = $course_status->getCurrentLessonStatus();
    
      if (!$lesson_status) {
        return NULL; // Course started, but no lesson status yet.
      }
    
      $activity = $lesson_status->getCurrentActivity();
    
      return [
        'lesson' => $lesson_status->getLesson(),
        'activity' => $activity,
      ];
    }
    

**Explanation:** This function retrieves the user's current `CourseStatus`. From there, it gets the `LessonStatus` and the current `Activity`, providing a complete picture of the user's position. Note that `TrainingManager::getCurrentCourseStatus()` will create and save a new `CourseStatus` (i.e. enroll the user in the course) if one doesn't already exist, so calling it has a side effect the first time it runs for a given user/course pair. If you only want to check whether a student has started a course without enrolling them, use `loadCourseStatus()` instead (as in the previous recipe) and handle the `NULL` case.  
  

## Create a Custom Block with a User's In-Progress Courses

**Use Case:** You want to display a list of a user's currently active courses in a sidebar or on a dashboard page.

    // In src/Plugin/Block/MyCoursesBlock.php
    
    namespace Drupal\my_module\Plugin\Block;
    
    use Drupal\Core\Block\Attribute\Block;
    use Drupal\Core\Block\BlockBase;
    use Drupal\Core\Entity\EntityTypeManagerInterface;
    use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
    use Drupal\Core\Session\AccountInterface;
    use Drupal\Core\StringTranslation\TranslatableMarkup;
    use Drupal\lms\Entity\CourseStatusInterface;
    use Symfony\Component\DependencyInjection\ContainerInterface;
    
    #[Block(
      id: 'my_in_progress_courses',
      admin_label: new TranslatableMarkup('My In-Progress Courses'),
      category: new TranslatableMarkup('LMS'),
    )]
    class MyCoursesBlock extends BlockBase implements ContainerFactoryPluginInterface {
      // ... (Constructor and create() method for dependency injection,
      // injecting EntityTypeManagerInterface as $this->entityTypeManager
      // and AccountInterface as $this->currentUser) ...
    
      public function build() {
        $course_status_storage = $this->entityTypeManager->getStorage('lms_course_status');
        $course_status_ids = $course_status_storage->getQuery()
            ->accessCheck(TRUE)
            ->condition('uid', $this->currentUser->id())
            ->condition('status', CourseStatusInterface::STATUS_PROGRESS)
            ->execute();
        $course_statuses = $course_status_storage->loadMultiple($course_status_ids);
    
        if (empty($course_statuses)) {
            return ['#markup' => $this->t('You have no courses in progress.')];
        }
    
        $items = [];
        foreach ($course_statuses as $status) {
            $items[] = $status->getCourse()->toLink()->toRenderable();
        }
    
        return [
            '#theme' => 'item_list',
            '#items' => $items,
            '#cache' => [
                'contexts' => ['user'],
                'tags' => ['user:' . $this->currentUser->id() . ':course_status_list'],
            ],
        ];
      }
    }

**Explanation:** This block plugin injects the \`EntityTypeManager\` and \`CurrentUser\` services, and declares itself with the modern \`#[Block(...)]\` PHP attribute — every real block plugin in this module (e.g. \`CourseNavBlock\`, \`CourseStartLink\`) uses this attribute syntax rather than the older \`@Block(...)\` docblock annotation. In the \`build()\` method, it queries the \`lms_course_status\` entity storage directly for statuses owned by the current user with a \`status\` of \`CourseStatusInterface::STATUS_PROGRESS\` (there is no \`TrainingManager::loadUserCourseStatuses()\` helper method). It then creates a simple render array (an item list) of links to those courses and applies the necessary cache tags and contexts.  
  

## Get All Students Enrolled in a Course

**Use Case:** You need a list of all students in a course for a custom report or to send a bulk email. This requires the **LMS Classes** sub-module.

    use Drupal\lms\Entity\Bundle\Course;
    use Drupal\lms_classes\ClassHelper;
    
    /**
     * Gets all student User entities for a given course.
     *
     * @return \Drupal\user\UserInterface[]
     *   An array of user entities.
     */
    function get_all_students_in_course(Course $course): array {
      $students = [];
      $classes = ClassHelper::getClasses($course);
    
      foreach ($classes as $class) {
        $members = $class->getMembers(['lms_class-member']);
        foreach ($members as $membership) {
          $user = $membership->getUser();
          $students[$user->id()] = $user;
        }
      }
    
      return $students;
    }
    

**Explanation:** Since students are members of classes, this function iterates through all classes associated with the course and uses the Group module's `getMembers()` method to retrieve all users with the "member" role.  
  

## Access Activity Configuration from a Plugin

**Use Case:** Inside your custom Activity-Answer plugin, you need to access a configuration value that was set on the Activity entity by a teacher (e.g., a "minimum characters" setting on a Free Text activity).

    // Inside your plugin class (e.g., MyCustomPlugin.php)
    use Drupal\lms\Entity\Answer;
    
    public function getScore(Answer $answer): float {
      // Get the parent Activity entity from the Answer.
      $activity = $answer->getActivity();
    
      // Access the value of a field on the Activity.
      if (!$activity->get('field_custom_setting')->isEmpty()) {
        $custom_setting = $activity->get('field_custom_setting')->value;
        // Now use $custom_setting in your scoring logic...
      }
      
      return 1.0;
    }
    

**Explanation:** The `Answer` entity provides a direct link to its parent `Activity` entity via the `getActivity()` method. Once you have the activity object, you can access any of its field values just like any other Drupal content entity.

## Subscribe to the QaContentEvent

**Use Case:** Your custom module has test content that depends on the LMS module's test content. You need to perform actions (like creating relationships) after the core test content has been created by the `lms:create-test-content` Drush command.

    // In my_module.services.yml
    services:
      my_module.qa_content_subscriber:
        class: Drupal\my_module\EventSubscriber\MyModuleQaContentSubscriber
        tags:
          - { name: event_subscriber }
    
    // In src/EventSubscriber/MyModuleQaContentSubscriber.php
    namespace Drupal\my_module\EventSubscriber;
    
    use Drupal\lms\Event\QaContentEvent;
    use Symfony\Component\EventDispatcher\EventSubscriberInterface;
    
    class MyModuleQaContentSubscriber implements EventSubscriberInterface {
      public static function getSubscribedEvents() {
        return [
          QaContentEvent::NAME => 'onLmsQaContentCreation',
        ];
      }
    
      public function onLmsQaContentCreation(QaContentEvent $event) {
        if ($event->getModule() === 'my_module') {
          // Your custom logic here.
          // For example, enroll the test student in your module's test course.
        }
      }
    }

**Explanation:** First, you define your event subscriber as a service in your module's \`services.yml\` file. Then, you create the subscriber class. The \`getSubscribedEvents()\` method tells Drupal to call your \`onLmsQaContentCreation()\` method whenever the \`QaContentEvent\` is dispatched. Inside your method, you can check if the event was triggered for your module and then run your custom setup logic.

[**Previous page: Error Handling & Troubleshooting**](error-handling-troubleshooting.md)

[**Next page: Testing & Deployment**](testing-and-deployment.md)
