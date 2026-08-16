<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\views\field;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\Core\Session\AccountInterface;
use Drupal\lms\Entity\CourseStatus;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * LMS Course user status field.
 */
#[ViewsField(id: 'lms_course_results_link')]
final class CourseResultsLink extends FieldPluginBase {

  /**
   * The current user.
   */
  private AccountInterface $currentUser;

  /**
   * Injects services.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The dependency injection container.
   */
  public function injectServices(ContainerInterface $container): void {
    $this->currentUser = $container->get(AccountInterface::class);
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
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['output_url_as_text'] = ['default' => FALSE];
    $options['link_target'] = ['default' => '_self'];
    $options['link_text'] = ['default' => $this->t('Results')];
    $options['no_results_text'] = ['default' => $this->t('Not started')];
    return $options;
  }

  /**
   * {@inheritdoc}
   *
   * @todo Add first parameter type hint when parent class does.
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    $form['output_url_as_text'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Output the URL as text'),
      '#default_value' => $this->options['output_url_as_text'],
    ];
    $form['link_target'] = [
      '#type' => 'radios',
      '#title' => $this->t('Target'),
      '#options' => [
        '_self' => $this->t('Same tab (_self)'),
        '_blank' => $this->t('New tab (_blank)'),
      ],
      '#default_value' => $this->options['link_target'],
    ];
    $form['link_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Link text'),
      '#default_value' => $this->options['link_text'],
    ];
    $form['no_results_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('No results text'),
      '#default_value' => $this->options['no_results_text'],
    ];

    foreach (['link_target', 'link_text', 'no_results_text'] as $field) {
      $form[$field]['#states'] = [
        'visible' => [
          ':input[name="options[output_url_as_text]"]' => [
            'checked' => FALSE,
          ],
        ],
      ];
    }
    parent::buildOptionsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): MarkupInterface {
    // Get the course status entity if available.
    $course_status = $values->_relationship_entities['course_status'] ?? NULL;
    if ($course_status === NULL) {
      $course_status = $values->_entity;
    }

    // Generate URL to results if applicable.
    if (!$course_status instanceof CourseStatus) {
      if ($this->options['output_url_as_text']) {
        return Markup::create('');
      }
      return Markup::create($this->options['no_results_text'],);
    }

    $route_parameters = [
      'group' => $course_status->getCourseId(),
    ];

    if ($course_status->getUserId() === (string) $this->currentUser->id()) {
      $route_name = 'lms.group.self_results';
    }
    else {
      $route_name = 'lms.group.results';
      $route_parameters['user'] = $course_status->getUserId();
    }

    // Output URL only.
    if ($this->options['output_url_as_text']) {
      return Markup::create(Url::fromRoute($route_name, $route_parameters)->toString());
    }

    // Output link to results.
    return Markup::create(Link::createFromRoute(
      $this->options['link_text'],
      $route_name,
      $route_parameters,
      [
        'attributes' => [
          'target' => $this->options['link_target'],
        ],
      ]
    )->toString());
  }

}
