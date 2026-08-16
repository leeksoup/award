<?php

declare(strict_types=1);

namespace Drupal\lms\Hook;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\lms\Entity\CourseStatusInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Other hooks.
 *
 * Delegate to different classes when this gets too crowded.
 */
final class LmsOtherHooks {

  use StringTranslationTrait;

  public function __construct(
    #[Autowire(service: 'module_handler', lazy: TRUE)]
    protected readonly ModuleHandlerInterface $moduleHandler,
    #[Autowire(service: 'current_route_match', lazy: TRUE)]
    protected readonly RouteMatchInterface $routeMatch,
    #[Autowire(service: 'request_stack')]
    protected readonly RequestStack $requestStack,
    #[Autowire(service: 'path.validator')]
    protected readonly PathValidatorInterface $pathValidator,
    #[Autowire(service: 'current_user')]
    protected readonly AccountInterface $currentUser,
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

    $data['group_relationship_field_data']['classes_filter'] = [
      'title' => t('Filter relationships by child class'),
      'filter' => [
        'title' => t('Is content of selected classes'),
        'real field' => CourseStatusInterface::COURSE_FIELD,
        'id' => 'lms_parent_class',
      ],
      'argument' => [
        'title' => t('Group relationships of Course classes'),
        'id' => 'course_classes_relationships',
        'real field' => CourseStatusInterface::COURSE_FIELD,
      ],
    ];

    $data['group_relationship_field_data']['course_status'] = [
      'title' => t('Course status'),
      'help' => t('Relates memberships to the current Course Status entity. Requires Course (lms_course group bundle) context.'),
      'relationship' => [
        'label' => t('Course status'),
        'group' => t('LMS'),
        'real field' => CourseStatusInterface::COURSE_FIELD,
        'base' => 'lms_course_status',
        'id' => 'class_member_to_course_status',
      ],
    ];
  }

  #[Hook('page_top')]
  public function pageTop(array &$page_top): void {
    if (!$this->moduleHandler->moduleExists('ckeditor5')) {
      return;
    }

    $route_name = $this->routeMatch->getRouteName();
    $is_relevant = match ($route_name) {
      'entity.group.add_form' => $this->routeMatch->getParameter('group_type')?->id() === 'lms_course',
      'entity.group.edit_form' => $this->routeMatch->getParameter('group')?->bundle() === 'lms_course',
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

}
