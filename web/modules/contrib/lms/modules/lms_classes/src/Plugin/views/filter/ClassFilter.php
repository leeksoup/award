<?php

declare(strict_types=1);

namespace Drupal\lms_classes\Plugin\views\filter;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms_classes\ClassHelper;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\views\Plugin\views\query\Sql;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filters group content by parent class.
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter(id: 'lms_parent_class')]
final class ClassFilter extends FilterPluginBase {

  /**
   * Static cache.
   */
  private ?Course $course = NULL;

  /**
   * The constructor.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function operatorOptions(): array {
    return [
      'IN' => $this->t('Is one of'),
    ];
  }

  /**
   * Value form.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state object.
   */
  protected function valueForm(&$form, FormStateInterface $form_state): void {
    // We need to get classes that are sub classes of the current course.
    $form['value'] = [
      '#title' => $this->t('Classes'),
      '#type' => 'checkboxes',
      '#options' => [],
      '#description' => $this->t('This filter needs Course ID as an argument to be able to display class options. It works on /group/%group/* URIs as an exposed filter.'),
    ];

    $course = $this->getCourseFromArgs();
    if ($course === NULL) {
      return;
    }

    unset($form['value']['#description']);

    foreach (ClassHelper::getClasses($course) as $class) {
      $form['value']['#options'][$class->id()] = $class->label();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function acceptExposedInput($input) {
    $value = $input[$this->options['expose']['identifier']];
    if (!\is_array($value)) {
      if (!\is_numeric($value)) {
        $value = [];
      }
      else {
        $value = [$value];
      }
    }

    if (\count($value) === 0) {
      $course = $this->getCourseFromArgs();
      if ($course === NULL) {
        return FALSE;
      }
      foreach (ClassHelper::getClasses($course) as $class) {
        $value[$class->id()] = $class->id();
      }
    }

    $this->value = $value;

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    if (\count($this->value) === 0) {
      return;
    }
    $table = $this->ensureMyTable();
    \assert($this->query instanceof Sql);
    $this->query->addWhere($this->options['group'], "$table.gid", $this->value, 'IN');
  }

  /**
   * Get Course entity from view args.
   *
   * @todo Go one step further and get classes here.
   */
  private function getCourseFromArgs(): ?Course {
    if ($this->course !== NULL) {
      return $this->course;
    }

    if (
      !\array_key_exists(0, $this->view->args) ||
      !\is_numeric($this->view->args[0])
    ) {
      return NULL;
    }

    $course = $this->entityTypeManager->getStorage('group')->load($this->view->args[0]);
    if (!$course instanceof Course) {
      return NULL;
    }
    $this->course = $course;
    return $course;
  }

  /**
   * {@inheritdoc}
   */
  public function adminSummary(): string {
    return '';
  }

}
