<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\views\field;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\Entity\LessonStatusInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays student course progress as finished lessons out of the total.
 */
#[ViewsField(id: 'lms_course_progress')]
final class CourseProgress extends FieldPluginBase implements CacheableDependencyInterface {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Progress data keyed by course status id.
   *
   * @var array<string, array{completed: int, total: int}>
   */
  protected array $progress = [];

  /**
   * Injects services.
   */
  public function injectServices(ContainerInterface $container): void {
    $this->entityTypeManager = $container->get(EntityTypeManagerInterface::class);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->injectServices($container);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions(): array {
    $options = parent::defineOptions();
    $options['display_mode'] = ['default' => 'full'];

    return $options;
  }

  /**
   * {@inheritdoc}
   *
   * @todo Add first parameter type hint when parent class does.
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    $form['display_mode'] = [
      '#type' => 'radios',
      '#options' => [
        'full' => $this->t('Full'),
        'compact' => $this->t('Compact (no progress bar)'),
      ],
      '#title' => $this->t('Display mode'),
      '#default_value' => $this->options['display_mode'],
    ];

    parent::buildOptionsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function preRender(&$values): void {
    $ids = [];
    foreach ($values as $row) {
      $id = $this->getValue($row);
      if ($id !== NULL) {
        $ids[$id] = $id;
      }
    }
    if (\count($ids) === 0) {
      return;
    }

    // Finished lessons per course status. Grouping by the lesson as well counts
    // a lesson once even when it has more than one finished status.
    /** @var array<int, array<string, string>> $finished */
    $finished = $this->entityTypeManager->getStorage('lms_lesson_status')->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('course_status', $ids, 'IN')
      ->condition('finished', 0, '<>')
      ->groupBy('course_status')
      ->groupBy(LessonStatusInterface::LESSON_FIELD)
      ->execute();
    $completed = [];
    foreach ($finished as $item) {
      $id = $item['course_status'];
      $completed[$id] = ($completed[$id] ?? 0) + 1;
    }

    // Total lessons of each status' course, counted from the current course
    // revision.
    $totals_alias = 'total';
    /** @var array<int, array<string, string>> $totals */
    $totals = $this->entityTypeManager->getStorage('lms_course_status')->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('id', $ids, 'IN')
      ->groupBy('id')
      ->aggregate('course.target_id.entity:group.' . Course::LESSONS, 'COUNT', NULL, $totals_alias)
      ->execute();
    $lesson_counts = [];
    foreach ($totals as $item) {
      $lesson_counts[$item['id']] = (int) $item[$totals_alias];
    }

    foreach ($ids as $id) {
      $this->progress[$id] = [
        'completed' => $completed[$id] ?? 0,
        'total' => $lesson_counts[$id] ?? 0,
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): MarkupInterface {
    $id = $this->getValue($values);
    $data = $this->progress[$id] ?? ['completed' => 0, 'total' => 0];

    $label = $this->t('@completed / @total Lessons', [
      '@completed' => $data['completed'],
      '@total' => $data['total'],
    ]);

    $build = [];
    if ($this->options['display_mode'] === 'compact') {
      $build['#markup'] = $label;
      $output = (string) $this->getRenderer()->render($build);
      return Markup::create($output);
    }

    $build['#theme'] = 'progress_bar';
    $build['#label'] = $label;
    $build['#percent'] = $data['total'] > 0
      ? \min(100, (int) \round($data['completed'] / $data['total'] * 100))
      : 0;

    $output = (string) $this->getRenderer()->render($build);
    return Markup::create($output);
  }

  /**
   * {@inheritdoc}
   */
  public function clickSortable(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return [
      'lms_lesson_status_list',
      'lms_course_status_list',
      'group_list:course',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return Cache::PERMANENT;
  }

}
