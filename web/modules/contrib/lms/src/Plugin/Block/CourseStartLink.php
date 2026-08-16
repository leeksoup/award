<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Entity\CourseStatusInterface;
use Drupal\lms\TrainingManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a course start link block.
 */
#[Block(
  id: 'lms_start_link',
  admin_label: new TranslatableMarkup('Course start link'),
  context_definitions: [
    'user' => new EntityContextDefinition('entity:user', new TranslatableMarkup('User')),
    'group' => new EntityContextDefinition('entity:group', new TranslatableMarkup('Group')),
  ]
)]
final class CourseStartLink extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly TrainingManager $trainingManager,
    protected readonly SessionConfigurationInterface $sessionConfiguration,
    protected readonly RequestStack $requestStack,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(ModuleHandlerInterface::class),
      $container->get(TrainingManager::class),
      $container->get(SessionConfigurationInterface::class),
      $container->get(RequestStack::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResult {
    $group = $this->getContextValue('group');
    if (!$group instanceof Course) {
      return AccessResult::forbidden();
    }
    return AccessResult::allowed();
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $course = $this->getContextValue('group');
    $currentUser = $this->getContextValue('user');

    // The build() method is called when adding lessons to courses.
    // Return an empty uncacheable array to prevent AJAX errors in new courses.
    // @todo Check if course edit form can avoid calling CourseStartLink::build.
    if ($course->isNew()) {
      return ['#cache' => ['max-age' => 0]];
    }

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($course);
    $cacheability->addCacheContexts(['session.exists']);
    if (
      $currentUser->isAnonymous() &&
      $this->sessionConfiguration->hasSession($this->requestStack->getCurrentRequest())
    ) {
      $cacheability->setCacheMaxAge(0);
    }

    $cacheability
      ->addCacheTags([
        TrainingManager::trainingStatusTag($course->id(), (string) $currentUser->id()),
      ])
      ->addCacheContexts([
        // Output of this block is unique per user.
        'user',
        // Rendered link (e.g. Enroll vs Start) depends on group permissions.
        'user.group_permissions',
      ]);

    // Allow other modules to conditionally return a link.
    $build = $this->moduleHandler->invokeAll('lms_course_link', [$course, $currentUser, $cacheability]);
    if (\count($build) !== 0) {
      if (
        !\array_key_exists('#type', $build) &&
        !\array_key_exists('#theme', $build)
      ) {
        $build = \reset($build);
      }
      if (
        \array_key_exists('#type', $build) ||
        \array_key_exists('#theme', $build)
      ) {
        $cacheability->applyTo($build);
        return $build;
      }
    }

    // @todo This array is inherited while moving the code around but there's no
    //   way to be set as the field is not configurable. Needs a decision.
    $htmlClasses = [];

    $link = $actionInfo = NULL;

    // Handle unpublished courses if set to be displayed in view settings.
    if (!$course->isPublished()) {
      $build = self::buildActionInfo('coming-soon', $this->t('Coming soon!'));
      $cacheability->applyTo($build);
      return $build;
    }

    $takeAccess = $course->access('take', $currentUser, TRUE);
    $cacheability->addCacheableDependency($takeAccess);
    if (!$takeAccess->isAllowed()) {
      if ($currentUser->isAnonymous()) {
        $url = Url::fromRoute(
          'user.login',
          ['destination' => $course->toUrl()->toString()],
          ['attributes' => ['class' => $htmlClasses + ['join-link']]],
        );
        $link = Link::fromTextAndUrl($this->t('Login / Register'), $url)->toRenderable();
        $link['#attributes']['aria-label'] = $this->t('Login or register to access @title', ['@title' => $course->label()]);
        $build = $this->getBuild(link: $link);
        $cacheability->applyTo($build);
        return $build;
      }

      $url = Url::fromRoute(
        'entity.group.join',
        ['group' => $course->id()],
        [
          'query' => ['destination' => $course->toUrl()->toString()],
          'attributes' => [
            'class' => $htmlClasses + ['join-link'],
          ],
        ],
      );
      if (!$url->access()) {
        $build = self::buildActionInfo('not-accessible', $this->t('Course not accessible.'));
        $cacheability->applyTo($build);
        return $build;
      }
      $link = Link::fromTextAndUrl($this->t('Enroll'), $url)->toRenderable();
      $link['#attributes']['aria-label'] = $this->t('Enroll in @title', ['@title' => $course->label()]);
    }
    else {
      $courseStatus = $this->trainingManager->loadCourseStatus($course, $currentUser, [
        'current' => TRUE,
      ]);
      if ($courseStatus !== NULL) {
        $cacheability->addCacheableDependency($courseStatus);
      }
      $status = $courseStatus?->getStatus();

      $route_parameters = ['group' => $course->id()];
      $link_options = ['attributes' => ['class' => $htmlClasses + ['start-link']]];
      $link_text = '';
      $aria_label = NULL;

      if ($status === NULL) {
        $link_text = $this->t('Start');
        $aria_label = $this->t('Start @title', ['@title' => $course->label()]);
        $link_options['attributes']['class'][] = 'start-link--start';
      }
      elseif ($status === CourseStatusInterface::STATUS_PROGRESS) {
        $link_text = $this->t('Continue');
        $aria_label = $this->t('Continue @title', ['@title' => $course->label()]);
        $link_options['attributes']['class'][] = 'start-link--continue';
      }
      elseif (
        $status === CourseStatusInterface::STATUS_FAILED ||
        $status === CourseStatusInterface::STATUS_PASSED
      ) {
        if ($course->revisitMode()) {
          $link_text = $this->t('Revisit');
          $aria_label = $this->t('Revisit @title', ['@title' => $course->label()]);
          $link_options['attributes']['class'][] = 'start-link--revisit';
        }
        else {
          // @todo Add a confirmation step when restarting.
          $link_text = $this->t('Restart');
          $aria_label = $this->t('Restart @title', ['@title' => $course->label()]);
          $link_options['attributes']['class'][] = 'start-link--restart';
        }
      }
      elseif ($status === CourseStatusInterface::STATUS_NEEDS_EVALUATION) {
        if ($course->ungradedAccess()) {
          $link_text = $this->t('Continue (ungraded)');
          $aria_label = $this->t('Continue @title (ungraded)', ['@title' => $course->label()]);
          $link_options['attributes']['class'][] = 'start-link--continue';
        }
        else {
          $actionInfo = self::buildActionInfo('needs-evaluation', $this->t('Awaits grading'));
        }
      }

      if ($link_text !== '') {
        $url = Url::fromRoute('lms.course.start', $route_parameters, $link_options);
        $link = Link::fromTextAndUrl($link_text, $url)->toRenderable();
        $link['#attributes']['aria-label'] = $aria_label;
      }
    }

    $build = $this->getBuild(link: $link, actionInfo: $actionInfo);
    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * Returns the final theme render array.
   *
   * @param array|null $link
   *   The link as render array, if any.
   * @param array|null $actionInfo
   *   The action info as render array, if any.
   *
   * @return array
   *   Render array.
   */
  private function getBuild(?array $link = NULL, ?array $actionInfo = NULL): array {
    return [
      '#type' => 'component',
      '#component' => 'lms:start_link',
      '#props' => [
        'link' => $link,
        'action_info' => $actionInfo,
      ],
    ];
  }

  /**
   * Builds the themed action info.
   *
   * @param string $class
   *   The CSS class.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $info
   *   Message to be displayed to end-user.
   *
   * @return array
   *   Render array.
   */
  public static function buildActionInfo(string $class, TranslatableMarkup $info): array {
    return [
      '#type' => 'component',
      '#component' => 'lms:course_action_info',
      '#props' => [
        'class' => $class,
        'info_text' => $info,
      ],
    ];
  }

}
