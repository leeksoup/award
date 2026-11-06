<?php

declare(strict_types=1);

namespace Drupal\lms\Hook;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\lms\Entity\Bundle\CourseInterface;
use Drupal\lms\LmsCourseStorage;
use Drupal\lms\TrainingManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Other hooks.
 *
 * Delegate to different classes when this gets too crowded.
 */
final class LmsOtherHooks {

  use StringTranslationTrait;

  public function __construct(
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly RouteMatchInterface $routeMatch,
    #[Autowire(service: RequestStack::class)]
    protected readonly RequestStack $requestStack,
    #[Autowire(service: PathValidatorInterface::class)]
    protected readonly PathValidatorInterface $pathValidator,
    #[Autowire(service: AccountInterface::class)]
    protected readonly AccountInterface $currentUser,
    #[AutowireServiceClosure(TrainingManager::class)]
    protected readonly \Closure $trainingManager,
    #[AutowireServiceClosure(LmsCourseStorage::class)]
    protected readonly \Closure $lmsCourseStorage,
    #[AutowireServiceClosure(EntityFieldManagerInterface::class)]
    protected readonly \Closure $entityFieldManager,
    #[AutowireServiceClosure(EntityTypeManagerInterface::class)]
    protected readonly \Closure $entityTypeManager,
  ) {}

  #[Hook('theme_suggestions_container_alter')]
  public function themeSuggestionsContainerAlter(array &$suggestions, array $variables): void {
    // Provide a theme suggestion for the container in the course navigation.
    if (
      \array_key_exists('element', $variables) &&
      \array_key_exists('activities', $variables['element'])
    ) {
      $suggestions[] = 'container__lms_activities_navigation';
    }
  }

  #[Hook('element_info_alter')]
  public function elementInfoAlter(array &$types): void {
    // Attach our extra CSS for toolbar icons.
    if (\array_key_exists('toolbar', $types)) {
      $types['toolbar']['#attached']['library'][] = 'lms/toolbar';
    }
  }

  #[Hook('views_data_alter')]
  public function viewsDataAlter(array &$data): void {
    $data['groups_field_data']['course_card_row'] = [
      'title' => t('Course Card'),
      'help' => t('Display courses as SDC card components.'),
      'row' => [
        'id' => 'course_card_row',
      ],
    ];

    $data['groups_field_data']['lms_course_take_link'] = [
      'title' => t('Take course'),
      'field' => [
        'title' => t('Course take link'),
        'help' => t('Course take link'),
        'id' => 'lms_course_take_link',
      ],
    ];

    $this->useEntityReferenceFilters($data);
  }

  /**
   * Use the entity_reference filter for LMS's custom reference field types.
   *
   * Both 'lms_reference' and 'lms_revision_reference' extend
   * EntityReferenceItem but, since Views only special-cases the core
   * 'entity_reference' field type, their target_id columns otherwise default
   * to a plain numeric filter.
   */
  private function useEntityReferenceFilters(array &$data): void {
    $entity_field_manager = ($this->entityFieldManager)();
    $entity_type_manager = ($this->entityTypeManager)();

    foreach (['lms_reference', 'lms_revision_reference'] as $field_type) {
      foreach ($entity_field_manager->getFieldMapByFieldType($field_type) as $entity_type_id => $fields) {
        $storage = $entity_type_manager->getStorage($entity_type_id);
        if (!$storage instanceof SqlEntityStorageInterface) {
          continue;
        }
        $table_mapping = $storage->getTableMapping();

        foreach (\array_keys($fields) as $field_name) {
          $table = $table_mapping->getFieldTableName($field_name);
          $column = $table_mapping->getColumnNames($field_name)['target_id'] ?? NULL;
          if (
            $column === NULL ||
            !\array_key_exists($table, $data) ||
            !\array_key_exists($column, $data[$table])
          ) {
            continue;
          }
          $data[$table][$column]['filter']['id'] = 'entity_reference';
        }
      }
    }
  }

  #[Hook('page_top')]
  public function pageTop(array &$page_top): void {
    if (!$this->moduleHandler->moduleExists('ckeditor5')) {
      return;
    }

    $route_name = $this->routeMatch->getRouteName();
    $is_relevant = match ($route_name) {
      'entity.group.add_form' => \in_array($this->routeMatch->getParameter('group_type')?->id(), ($this->lmsCourseStorage)()->getCourseBundleIds(), TRUE),
      'entity.group.edit_form' => $this->routeMatch->getParameter('group') instanceof CourseInterface,
      // Lessons have their canonical route set to the edit form one.
      'entity.lms_lesson.add_form', 'entity.lms_lesson.edit_form', 'entity.lms_lesson.canonical' => TRUE,
      default => FALSE,
    };

    if (!$is_relevant) {
      return;
    }

    // Fix CKEditor dialog windows when it is displayed in one of the
    // entity form modals.
    // See https://www.drupal.org/project/entity_browser/issues/3471034.
    $page_top['ckeditor_modal_fix'] = [
      '#type' => 'html_tag',
      '#tag' => 'style',
      '#value' => ":root {--ck-z-default:1260}!important",
    ];
  }

  #[Hook('menu_local_tasks_alter')]
  public function menuLocalTasksAlter(array &$data, string $route_name, RefinableCacheableDependencyInterface &$cacheability): void {
    if (!\str_starts_with($route_name, 'entity.lms_activity.')) {
      return;
    }

    // Set cache context by destination to prevent cache poisoning.
    $cacheability->addCacheContexts(['url.query_args:destination']);

    $request = $this->requestStack->getCurrentRequest();
    $destination = $request->query->get('destination');

    if ($destination !== NULL && \str_starts_with($destination, '/')) {
      $destination_url = $this->pathValidator->getUrlIfValidWithoutAccessCheck($destination);

      // Only alter tabs if the destination is the course activity view.
      if ($destination_url === FALSE || $destination_url->getRouteName() !== 'lms.group.answer_form') {
        return;
      }

      // Ensure the user actually has access to the destination route.
      $access = $destination_url->access($this->currentUser, TRUE);
      $cacheability->addCacheableDependency($access);

      if (!$access->isAllowed()) {
        return;
      }

      if (!\array_key_exists(0, $data['tabs'])) {
        $data['tabs'][0] = [];
      }

      // User is editing the activity from a lesson, so it can't be deleted.
      if (\array_key_exists('entity.lms_activity.delete_form', $data['tabs'][0])) {
        unset($data['tabs'][0]['entity.lms_activity.delete_form']);
      }

      // Inject a dynamic View tab to return to the activity that was edited.
      $data['tabs'][0]['lms_dynamic_view'] = [
        '#theme' => 'menu_local_task',
        '#active' => FALSE,
        '#weight' => -100,
        '#link' => [
          'title' => $this->t('View'),
          'url' => Url::fromUserInput($destination),
          'localized_options' => [],
        ],
        '#access' => $access,
      ];

      // Add destination parameter to all tabs except View to maintain context.
      foreach ($data['tabs'][0] as $tab_id => &$tab) {
        if ($tab_id === 'lms_dynamic_view') {
          continue;
        }

        if (\array_key_exists('#link', $tab) && $tab['#link']['url'] instanceof Url) {
          $query = $tab['#link']['url']->getOption('query') ?? [];
          $query['destination'] = $destination;
          $tab['#link']['url']->setOption('query', $query);
        }
      }
    }
  }

  #[Hook('preprocess_html')]
  public function preprocessHtml(array &$variables): void {
    // Add Course and Lesson context to the <title> element.
    if ($this->routeMatch->getRouteName() === 'lms.group.answer_form') {
      try {
        $group = $this->routeMatch->getParameter('group');
        $lesson_delta = (int) $this->routeMatch->getParameter('lesson_delta');
        $training_manager = ($this->trainingManager)();
        $course_status = $training_manager->getCurrentCourseStatus($group, $this->currentUser);
        $lesson = $training_manager->getLessonByDelta($course_status, $lesson_delta);

        if ($lesson !== NULL) {
          $current_title = $variables['head_title']['title'] ?? '';
          $variables['head_title']['title'] = $current_title . ' | ' . $lesson->label() . ' | ' . $group->label();
        }
      }
      catch (\Exception $e) {
        // Fail silently and fallback to default title.
      }
    }
  }

}
