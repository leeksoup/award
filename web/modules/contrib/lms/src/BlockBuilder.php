<?php

declare(strict_types=1);

namespace Drupal\lms;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Security\Attribute\TrustedCallback;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\lms\Entity\ActivityInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Entity\CourseStatusInterface;
use Drupal\lms\Entity\LessonStatusInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the course navigation block.
 */
class BlockBuilder {

  use StringTranslationTrait;

  private const CACHE_PREFIX = 'steps_block_data_cache_';

  /**
   * Instance cache for user's lesson statuses.
   */
  private ?array $lessonStatuses = NULL;

  /**
   * Instance cache for route parameters.
   */
  private ?array $routeParameters = NULL;

  /**
   * Instance cache for user's course status.
   */
  private ?CourseStatusInterface $courseStatus = NULL;

  /**
   * Static cache for course structure to reduce DB lookups across requests.
   */
  private ?array $courseStructureCache = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    #[Autowire(service: 'cache.default')]
    protected readonly CacheBackendInterface $cacheBackend,
    protected readonly TrainingManager $trainingManager,
    protected readonly RouteMatchInterface $currentRoute,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'csrf_token')]
    protected readonly CsrfTokenGenerator $csrfTokenGenerator,
    #[Autowire(service: 'redirect.destination')]
    protected readonly RedirectDestinationInterface $redirectDestination,
    #[Autowire(service: 'config.factory')]
    protected readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * Get route parameter.
   */
  private function getRouteParameter(string $parameter_name): ?int {
    if ($this->routeParameters === NULL) {
      $this->routeParameters = [];
      foreach (['lesson_delta', 'activity_delta'] as $parameter_key) {
        $parameter_value = $this->currentRoute->getParameter($parameter_key);
        $this->routeParameters[$parameter_key] = $parameter_value !== NULL ? (int) $parameter_value : NULL;
      }
    }
    return $this->routeParameters[$parameter_name] ?? NULL;
  }

  /**
   * Set initial object parameters for lesson status getter.
   */
  private function initialize(Course $course, AccountInterface $account): ?array {
    // Already initialized.
    if ($this->courseStructureCache !== NULL) {
      return $this->courseStructureCache;
    }

    $this->courseStatus = $this->trainingManager->loadCourseStatus($course, $account, ['current' => TRUE]);
    if ($this->courseStatus === NULL) {
      return NULL;
    }

    $course = $this->courseStatus->getCourse();
    $cache_id = self::CACHE_PREFIX . 'structure_' . $course->getRevisionId();
    $cache_item = $this->cacheBackend->get($cache_id);
    if ($cache_item !== FALSE) {
      $this->courseStructureCache = $cache_item->data;
    }
    else {
      $structure = $this->trainingManager->getOrderedLessons($course);
      $tags = ['group:' . $course->id()];
      foreach (\array_keys($structure) as $lesson_id) {
        $tags[] = 'lms_lesson:' . $lesson_id;
      }

      $this->cacheBackend->set($cache_id, $structure, Cache::PERMANENT, $tags);
      $this->courseStructureCache = $structure;
    }

    return $this->courseStructureCache;
  }

  /**
   * Lesson status getter.
   */
  private function getLessonStatus(string $lesson_id): ?LessonStatusInterface {
    // Set static cache.
    if ($this->lessonStatuses === NULL) {
      $this->lessonStatuses = [];

      $statuses = $this->trainingManager->loadLessonStatusMultiple($this->courseStatus->id(), \array_keys($this->courseStructureCache));
      foreach ($statuses as $status) {
        $lesson_target_id = $status->getLessonId();
        if ($lesson_target_id !== '') {
          $this->lessonStatuses[$lesson_target_id] = $status;
        }
      }
    }

    return \array_key_exists($lesson_id, $this->lessonStatuses) ? $this->lessonStatuses[$lesson_id] : NULL;
  }

  /**
   * Build the main course navigation block.
   *
   * @todo Come with a better solution for caching, at the moment cache is
   * invalidated on almost every request except reloading of the same page.
   */
  public function build(Course $course, AccountInterface $account): array {
    $structure = $this->initialize($course, $account);
    if ($structure === NULL) {
      return [];
    }

    $build = [
      '#type' => 'component',
      '#component' => 'lms:course_navigation',
      '#props' => [
        // Don't use revision here for breadcrumb consistency.
        'title' => $course->label(),
      ],
      '#slots' => [
        'lessons' => [],
      ],
    ];

    $course_id = $course->id();
    $user_id = $account->id();

    $current_lesson_route_delta = $this->getRouteParameter('lesson_delta');

    // Initialize course progress calculation and pre-load lesson statuses.
    $total_activities = 0;
    $answered_activities = 0;

    $overall_cache_meta = new CacheableMetadata();
    $overall_cache_meta->addCacheableDependency($this->courseStatus);
    $overall_cache_meta->addCacheContexts([
      'user',
      'url.path',
    ]);
    $overall_cache_meta->addCacheableDependency($course);

    // Build all lessons with appropriate cache settings.
    foreach ($structure as $lesson_id => $lesson_data) {
      $is_current_lesson = ($current_lesson_route_delta === $lesson_data['delta']);

      $lesson_cache_tags = ['lms_lesson:' . $lesson_id];

      // Adjust course progress to include this lesson.
      if (\array_key_exists('activities', $lesson_data) && \is_array($lesson_data['activities'])) {
        $lesson_total = \count($lesson_data['activities']);
        $total_activities += $lesson_total;

        // Count answered activities in this lesson.
        $lesson_status = $this->getLessonStatus((string) $lesson_id);
        if ($lesson_status !== NULL) {
          $overall_cache_meta->addCacheableDependency($lesson_status);
          $lesson_cache_tags[] = 'lms_lesson_status:' . $lesson_status->id();
          $activity_ids = \array_map('intval', \array_keys($lesson_data['activities']));
          $answers = $this->trainingManager->getAnswersByActivityId($lesson_status, $activity_ids);
          $answered_activities += \count($answers);
        }
      }

      // Add this lesson to the render array.
      $build['#slots']['lessons'][$lesson_id] = [
        '#lazy_builder' => [
          static::class . '::buildLesson',
          [$is_current_lesson, $course_id, $user_id, $lesson_data['delta']],
        ],
        '#create_placeholder' => TRUE,
        '#cache' => [
          'keys' => ['lms-lesson', $lesson_id, $course_id],
          'contexts' => ['user', 'url.query_args'],
          'tags' => $lesson_cache_tags,
        ],
      ];
    }

    // Inject the Reset Progress button for course editors if enabled.
    $show_reset = $this->configFactory->get('lms.settings')->get('show_editor_reset_button') !== FALSE;

    if ($show_reset && $course->access('update', $account)) {
      $reset_url = Url::fromRoute('lms.course.reset_test', ['group' => $course->id()]);
      $token = $this->csrfTokenGenerator->get($reset_url->getInternalPath());
      $reset_url->setOptions([
        'query' => [
          'token' => $token,
        ] + $this->redirectDestination->getAsArray(),
        'attributes' => [
          'title' => $this->t('Pressing this button resets your saved course progress, so you can see the latest revisions. This button is not visible to students. Warning! Any answers you have saved will be lost.'),
        ],
      ]);
      $build['#slots']['actions']['reset'] = [
        '#type' => 'link',
        '#title' => $this->t('Reset for updates'),
        '#url' => $reset_url,
        '#cache' => [
          'contexts' => ['session'],
        ],
      ];
    }

    $overall_cache_meta->applyTo($build);

    // Calculate overall course progress percentage.
    $progress_percentage = $total_activities > 0 ?
      \round(($answered_activities / $total_activities) * 100) : 0;

    // Update component props with course progress.
    $build['#props']['progress_percentage'] = $progress_percentage;
    $build['#props']['progress_text'] = $this->t('@answered of @total total answered.', [
      '@answered' => $answered_activities,
      '@total' => $total_activities,
    ]);

    return $build;
  }

  /**
   * Lesson lazy builder callback actual callback.
   */
  public function doBuildLesson(
    bool $is_on_route,
    string $course_id,
    string $user_id,
    int $lesson_route_delta,
  ): array {
    $course = $this->entityTypeManager->getStorage('group')->load($course_id);
    $account = $this->entityTypeManager->getStorage('user')->load($user_id);
    if (
      !$course instanceof Course ||
      !$account instanceof AccountInterface
    ) {
      return [];
    }
    $structure = $this->initialize($course, $account);
    if ($structure === NULL) {
      return [];
    }

    $lesson = $this->trainingManager->getLessonByDelta($this->courseStatus, $lesson_route_delta);
    if (
      $lesson === NULL ||
      !\array_key_exists($lesson->id(), $structure)) {
      return [];
    }

    $show_scores = $this->courseStatus->isFinished() &&
      $course->revisitMode();

    $lesson_structure_data = $structure[$lesson->id()];
    $current_activity_route_delta = $this->getRouteParameter('activity_delta');

    $cacheable_metadata = new CacheableMetadata();
    $cacheable_metadata->addCacheableDependency($this->courseStatus);
    $cacheable_metadata->addCacheableDependency($lesson);

    // Build lesson properties.
    $lesson_props = [
      'title' => $lesson->label(),
      'url' => Url::fromRoute('lms.group.answer_form', [
        'group' => $course->id(),
        'lesson_delta' => $lesson_route_delta,
      ])->toString(),
      'url_enabled' => FALSE,
      'is_current' => $is_on_route,
      'started' => FALSE,
      'finished' => FALSE,
      'evaluated' => FALSE,
      'passed' => FALSE,
    ];

    // Get current course position.
    $current_lesson_delta = NULL;
    $current_activity_delta = 0;

    $current_lesson_status = $this->courseStatus->getCurrentLessonStatus();
    if ($current_lesson_status instanceof LessonStatusInterface) {
      $current_lesson_delta = $current_lesson_status->getCurrentLessonDelta();
      $current_activity_delta = $current_lesson_status->getCurrentActivityDelta() ?? 0;
    }
    else {
      $current_lesson_delta = 0;
    }

    // Update lesson properties based on status.
    $lesson_status = $this->getLessonStatus($lesson->id());
    if ($lesson_status instanceof LessonStatusInterface) {
      $lesson_props['started'] = TRUE;
      $lesson_props['evaluated'] = $lesson_status->isEvaluated();
      if ($lesson_status->isFinished()) {
        $lesson_props['finished'] = TRUE;
        $lesson_props['passed'] = $lesson_status->getScore() >= $lesson_status->getRequiredScore();
      }
      $cacheable_metadata->addCacheableDependency($lesson_status);
    }

    // Build activities.
    $activities_from_source = [];
    $answers_for_lesson = [];

    // Get activities from lesson status or course structure.
    if ($lesson_status instanceof LessonStatusInterface) {
      foreach ($lesson_status->getActivities() as $delta => $activity) {
        $activities_from_source[$activity->id()] = [
          'entity' => $activity,
          'delta' => $delta,
        ];
      }
      $answers_for_lesson = \count($activities_from_source) > 0 ?
        $this->trainingManager->getAnswersByActivityId($lesson_status) : [];
    }

    // Get from course structure only if lesson randomization is off.
    elseif ($lesson->getRandomization() === 0) {
      if (\array_key_exists('activities', $lesson_structure_data) && \is_array($lesson_structure_data['activities'])) {
        foreach ($lesson_structure_data['activities'] as $activity_id_from_structure => $activity_data_from_structure) {
          if ($activity_data_from_structure['activity'] instanceof ActivityInterface) {
            $activities_from_source[(string) $activity_id_from_structure] = [
              'entity' => $activity_data_from_structure['activity'],
              'delta' => $activity_data_from_structure['delta'],
            ];
          }
        }
      }
    }

    $deltas_for_check = [
      'lesson' => $lesson_route_delta,
      'activity' => 0,
      'current_lesson' => $current_lesson_delta,
      'current_activity' => $current_activity_delta,
    ];

    // Process each activity.
    $activities_build_slot = [];
    foreach ($activities_from_source as $activity_id => $activity_info) {
      $activity = $activity_info['entity'];
      $is_current_activity = $is_on_route && $activity_info['delta'] === $current_activity_route_delta;
      $is_answered = \array_key_exists($activity_id, $answers_for_lesson);
      $answer = $is_answered ? $answers_for_lesson[$activity_id] : NULL;
      $is_evaluated = $is_answered && $answer->isEvaluated();
      $current_score = $is_answered ? $answer->getScore() : NULL;
      $max_score = $this->trainingManager->getActivityMaxScore($lesson, $activity);
      $max_score_achieved = ($max_score > 0 && $current_score !== NULL && $current_score >= $max_score);

      // Determine URL access.
      $url_enabled = FALSE;

      $deltas_for_check['activity'] = $activity_info['delta'];
      $access_code = $this->trainingManager->checkActivityAccess($this->courseStatus, $deltas_for_check);
      $url_enabled = ($access_code === NULL);
      if ($is_current_activity) {
        $url_enabled = FALSE;
      }

      $activities_build_slot[$activity_id] = [
        '#type' => 'component',
        '#component' => 'lms:activity_item',
        '#props' => [
          'title' => $activity->label(),
          'url' => $url_enabled ? Url::fromRoute('lms.group.answer_form', [
            'group' => $course->id(),
            'lesson_delta' => $lesson_route_delta,
            'activity_delta' => $activity_info['delta'],
          ])->toString() : '',
          'url_enabled' => $url_enabled,
          'is_current' => $is_current_activity,
          'answered' => $is_answered,
          'evaluated' => $is_evaluated,
          'max_score_achieved' => $show_scores ? $max_score_achieved : FALSE,
          'score' => $show_scores ? $current_score : NULL,
          'max_score' => $show_scores ? $max_score : NULL,
          'show_score' => $show_scores,
        ],
      ];

      if ($is_answered) {
        $cacheable_metadata->addCacheableDependency($answer);
      }
    }

    if (\count($activities_build_slot) !== 0) {
      $activities_build_slot['#type'] = 'container';
      $activities_build_slot['#attributes'] = ['class' => ['lms-activities-list']];
    }
    elseif (
      $this->trainingManager->checkActivityAccess($this->courseStatus, $deltas_for_check) === NULL &&
      !$is_on_route
    ) {
      $lesson_props['url_enabled'] = TRUE;
    }

    $build = [
      '#type' => 'component',
      '#component' => 'lms:lesson_item',
      '#props' => $lesson_props,
      '#slots' => [],
    ];
    if (\count($activities_build_slot) !== 0) {
      $build['#slots']['activities'] = $activities_build_slot;
    }

    $cacheable_metadata->applyTo($build);
    return ['lesson' => $build];
  }

  /**
   * Lazy builder callback to render a lesson, populated with its activities.
   *
   * @param bool $is_on_route
   *   Whether this is the current lesson.
   * @param string $course_id
   *   The course ID.
   * @param string $user_id
   *   The user ID.
   * @param int $lesson_route_delta
   *   The lesson route delta.
   *
   * @return array
   *   The render array.
   */
  #[TrustedCallback]
  public static function buildLesson(
    bool $is_on_route,
    string $course_id,
    string $user_id,
    int $lesson_route_delta,
  ): array {
    return \Drupal::service(self::class)->doBuildLesson($is_on_route, $course_id, $user_id, $lesson_route_delta);
  }

}
