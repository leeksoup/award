<?php

namespace Drupal\anu_lms\Plugin\AnuLmsContentType;

use Drupal\anu_lms\AnuLmsContentTypePluginBase;
use Drupal\anu_lms\Course;
use Drupal\anu_lms\CourseProgress;
use Drupal\anu_lms\CoursesPage as CoursesPageService;
use Drupal\anu_lms\Event\CoursesPageDataGeneratedEvent;
use Drupal\anu_lms\Normalizer;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Plugin implementation for the courses_landing_page node view.
 *
 * @AnuLmsContentType(
 *   id = "courses_landing_page",
 *   label = @Translation("Courses Page with filter"),
 *   description = @Translation("Handle courses page content.")
 * )
 */
class CoursesLandingPage extends AnuLmsContentTypePluginBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected EventDispatcherInterface $dispatcher;

  /**
   * The normalizer.
   *
   * @var \Drupal\anu_lms\Normalizer
   */
  protected Normalizer $normalizer;

  /**
   * The Courses page service.
   *
   * @var \Drupal\anu_lms\CoursesPage
   */
  protected CoursesPageService $coursesPage;

  /**
   * The course page service.
   *
   * @var \Drupal\anu_lms\Course
   */
  protected Course $course;

  /**
   * The course progress manager.
   *
   * @var \Drupal\anu_lms\CourseProgress
   */
  protected CourseProgress $courseProgress;

  /**
   * Create an instance of the plugin.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('event_dispatcher'),
      $container->get('anu_lms.normalizer'),
      $container->get('anu_lms.courses_page'),
      $container->get('anu_lms.course'),
      $container->get('anu_lms.course_progress'),
    );
  }

  /**
   * Constructs the plugin.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin id.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher
   *   The event dispatcher.
   * @param \Drupal\anu_lms\Normalizer $normalizer
   *   The normalizer.
   * @param \Drupal\anu_lms\CoursesPage $courses_page
   *   The Courses Page service.
   * @param \Drupal\anu_lms\Course $course
   *   The Course service.
   * @param \Drupal\anu_lms\CourseProgress $course_progress
   *   The Course progress handler.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    EventDispatcherInterface $dispatcher,
    Normalizer $normalizer,
    CoursesPageService $courses_page,
    Course $course,
    CourseProgress $course_progress,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->dispatcher = $dispatcher;
    $this->normalizer = $normalizer;
    $this->coursesPage = $courses_page;
    $this->course = $course;
    $this->courseProgress = $course_progress;
  }

  /**
   * {@inheritdoc}
   */
  public function getAttachments(): array {
    return [
      'library' => ['anu_lms/courses_landing'],
    ];
  }

  /**
   * Get data for this node.
   *
   * @param \Drupal\node\NodeInterface $courses_page
   *   The courses page node.
   * @param string $langcode
   *   The language for the data.
   */
  public function getData(NodeInterface $courses_page, $langcode = NULL): array {
    // phpcs:disable
    // The logic:
    // 1. No categories or topics are defined in system - pass all courses
    //    existing in system.
    // 2. Categories are defined but topics aren't:
    //    2a. No categories are selected in page config - pass all courses.
    //    2b. Some categories are selected in page config - pass courses
    //        with these categories only.
    // 3. Topics are defined but categories aren't:
    //    3a. No topics are selected in page config - pass all courses.
    //    3b. Some topics are selected in page config - pass courses with
    //        these topics only.
    // 4. Categories and topics are defined in system:
    //    4a. No categories or topics are selected in page config - pass all
    //        courses.
    //    4b. Some categories are selected but no topics are - pass courses
    //        that have these categories or any topic.
    //    4c. Some topics are selected but no categories are - pass courses
    //        that have these topics or any category.
    //    4d. Some categories and some topics are selected - pass courses
    //        that have these categories or topics.
    // phpcs:enable
    //
    // When categories or filters are defined in system but not selected in
    // page config, it means there is no restriction and all existing
    // categories or filters will be used as filter options.
    // However it doesn't needed to show categories or topics that not actually
    // used in courses.
    // If none of categories or topics are selected in page config - courses
    // without category or topic will appear on the page.
    // If at least one of filter will have selected value - no courses without
    // category or topic will appear on the page.
    // If there is only one category or topic, don't pass it to frontend to
    // hide the filter.
    //
    // Getting selected categories.
    $selected_category_ids = array_column($courses_page->get('field_courses_page_categories')
      ->getValue(), 'target_id');

    // Getting all categories defined in system.
    $all_category_ids = \Drupal::entityQuery('taxonomy_term')
      ->accessCheck(TRUE)
      ->condition('vid', 'course_category')
      ->execute();
    $taxonomy_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    // Getting term objects for `course_page_categories` in normalized course.
    $all_categories = $taxonomy_storage->loadMultiple($all_category_ids);

    // Getting selected topics.
    $selected_topic_ids = array_column($courses_page->get('field_courses_page_topics')
      ->getValue(), 'target_id');
    // Getting all topics defined in system.
    $all_topic_ids = \Drupal::entityQuery('taxonomy_term')
      ->accessCheck(TRUE)
      ->condition('vid', 'course_topics')
      ->execute();

    // Gathering courses.
    $query = \Drupal::entityQuery('node');
    $query->accessCheck(TRUE);
    $query->condition('type', 'course');
    $query->condition('status', 1);

    if (empty($all_category_ids) && empty($all_topic_ids) ||
      !empty($all_category_ids) && empty($all_topic_ids) && empty($selected_category_ids) ||
      empty($all_category_ids) && !empty($all_topic_ids) && empty($selected_topic_ids) ||
      !empty($all_category_ids) && !empty($all_topic_ids) && empty($selected_category_ids) && empty($selected_topic_ids)) {
      // Getting all courses existing in system - no additional conditions.
    }
    elseif (!empty($all_category_ids) && empty($all_topic_ids) && !empty($selected_category_ids)) {
      // 2b - passing courses with selected categories only.
      $query->condition('field_course_category', $selected_category_ids, 'IN');
    }
    elseif (empty($all_category_ids) && !empty($all_topic_ids) && !empty($selected_topic_ids)) {
      // 3b - passing courses with selected topics only.
      $query->condition('field_course_topics', $selected_topic_ids, 'IN');
    }
    elseif (!empty($selected_category_ids) && !empty($selected_topic_ids)) {
      // 4d - passing courses that have selected categories or topics.
      $query->condition('field_course_category', $selected_category_ids, 'IN');
      $query->condition('field_course_topics', $selected_topic_ids, 'IN');
    }
    elseif (!empty($selected_category_ids)) {
      // 4b - passing courses that have selected categories or any topic.
      $group = $query->orConditionGroup()
        ->condition('field_course_category', $selected_category_ids, 'IN')
        ->condition('field_course_topics', $all_topic_ids, 'IN');
      $query->condition($group);
    }
    elseif (!empty($selected_topic_ids)) {
      // 4c - passing courses that have selected topics or any category.
      $group = $query->orConditionGroup()
        ->condition('field_course_category', $all_category_ids, 'IN')
        ->condition('field_course_topics', $selected_topic_ids, 'IN');
      $query->condition($group);
    }

    $nids = $query->execute();

    $node_storage = $this->entityTypeManager->getStorage('node');
    $courses = $node_storage->loadMultiple($nids);

    // Iterating over courses and collecting needed data.
    $normalized_courses = [];
    $applied_category_ids = [];
    $applied_topic_ids = [];
    foreach ($courses as $course) {
      $course_page_categories = empty($selected_category_ids) ? $all_categories : $taxonomy_storage->loadMultiple($selected_category_ids);
      $normalized_course = $this->normalizer->normalizeEntity($course, [
        'max_depth' => 1,
        // Pass the categories requested as context so additional logic
        // can be performed like the course being part of a sequence within
        // a category.
        // @see \Drupal\anu_lms\CourseProgress
        'course_page_categories' => $course_page_categories,
      ]);

      if (!empty($normalized_course)) {
        if ($this->course->isLinearProgressEnabled($course)) {
          $normalized_course['progress'] = $this->courseProgress->getCourseProgress($course);
        }

        if ($this->courseProgress->isLocked($course, $course_page_categories)) {
          $normalized_course['locked'] = ['value' => TRUE];
        }

        $normalized_course['lessons_count'] = $this->course->countLessons($course) + $this->course->countQuizzes($course);

        $normalized_courses[] = $normalized_course;

        // Gathering categories and topic that really applied to course.
        // Normalizer makes additional access check,
        // so here we will have final list.
        $course_category_ids = array_column($course->get('field_course_category')
          ->getValue(), 'target_id');
        $course_topic_ids = array_column($course->get('field_course_topics')
          ->getValue(), 'target_id');
        // Collecting only unique values.
        // Will be used to filter out categories and topics options.
        $applied_category_ids = array_unique(array_merge($applied_category_ids, $course_category_ids));
        $applied_topic_ids = array_unique(array_merge($applied_topic_ids, $course_topic_ids));
      }
    }

    // Getting normalized course page.
    // We don't need to have categories or topics now, so depth = 0.
    // We will get them manually based on actual usage.
    $normalized_courses_page = $this->normalizer->normalizeEntity($courses_page, ['max_depth' => 1]);

    $normalized_categories = [];
    foreach ($all_categories as $category) {
      if (!in_array($category->id(), $applied_category_ids)) {
        continue;
      }
      // Collecting normalized categories, only used ones.
      $normalized_categories[] = $this->normalizer->normalizeEntity($category, ['max_depth' => 1]);
    }

    // Prepopulating categories.
    // Passing categories only if there are more then 1.
    $normalized_courses_page['field_courses_page_categories'] = count($normalized_categories) > 1 ? $normalized_categories : [];

    // Getting topic term objects.
    $all_topics = $taxonomy_storage->loadMultiple($all_topic_ids);
    $normalized_topics = [];
    foreach ($all_topics as $topic) {
      if (!in_array($topic->id(), $applied_topic_ids)) {
        continue;
      }
      // Collecting normalized topics, only used ones.
      $normalized_topics[] = $this->normalizer->normalizeEntity($topic, ['max_depth' => 1]);
    }

    // Prepopulating topics.
    // Passing topics only if there are more then 1.
    $normalized_courses_page['field_courses_page_topics'] = count($normalized_topics) > 1 ? $normalized_topics : [];

    // Placing everything together.
    $pageData = [
      'courses_page' => $normalized_courses_page,
      'courses' => $normalized_courses,
      'courses_page_urls_by_course' => $this->coursesPage->getCoursesLandingPageUrlsByCourse($courses),
      'first_lesson_url_by_course' => $this->coursesPage->getFirstLessonUrlByCourse($courses),
    ];

    $event = new CoursesPageDataGeneratedEvent($pageData, $courses_page);
    $this->dispatcher->dispatch($event, CoursesPageDataGeneratedEvent::EVENT_NAME);
    return $event->getPageData();
  }

}
