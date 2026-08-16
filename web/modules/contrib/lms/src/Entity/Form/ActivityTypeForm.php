<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Url;
use Drupal\lms\ActivityAnswerManager;
use Drupal\lms\Entity\ActivityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Activity Type Form.
 */
final class ActivityTypeForm extends EntityForm {

  private const PLUGIN_CONFIGURATION_WRAPPER = 'plugin-configuration-wrapper';

  public function __construct(
    protected ActivityAnswerManager $answerManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('plugin.manager.activity_answer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    \assert($this->entity instanceof ActivityTypeInterface);

    $form = parent::form($form, $form_state);

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#maxlength' => 255,
      '#default_value' => $this->entity->label(),
      '#description' => $this->t("Name of the Activity type."),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $this->entity->id(),
      '#machine_name' => [
        'exists' => '\Drupal\lms\Entity\ActivityType::load',
        'replace_pattern' => '[^a-z0-9_.]+',
        'source' => ['name'],
      ],
      '#maxlength' => 32,
      '#disabled' => !$this->entity->isNew(),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#rows' => 5,
      '#default_value' => $this->entity->getDescription(),
    ];

    $form['pluginId'] = [
      '#type' => 'select',
      '#title' => $this->t('Activity - answer plugin'),
      '#options' => [
        '' => $this->t('-- Select plugin --'),
      ],
      '#required' => TRUE,
      '#default_value' => $this->entity->getPluginId(),
      '#ajax' => [
        'wrapper' => self::PLUGIN_CONFIGURATION_WRAPPER,
        'callback' => '::ajaxUpdate',
      ],
    ];
    foreach ($this->answerManager->getDefinitions() as $definition) {
      $form['pluginId']['#options'][$definition['id']] = $definition['name'];
    }

    // Plugin configuration.
    $form['pluginConfiguration'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => self::PLUGIN_CONFIGURATION_WRAPPER,
      ],
      '#tree' => TRUE,
    ];
    $plugin = $this->getConfigurablePlugin($form_state);
    if ($plugin !== NULL) {
      $subform_state = SubformState::createForSubform($form['pluginConfiguration'], $form, $form_state);
      $form['pluginConfiguration'] = $plugin->buildConfigurationForm($form['pluginConfiguration'], $subform_state);
    }

    // Don't allow changing the plugin ID for existing types.
    if (!$this->entity->isNew()) {
      $form['pluginId']['#disabled'] = TRUE;
      $form['pluginId']['#description'] = $this->t('Changing plugin ID on existing activity types is not possible due to existing config and data. Please create a new activity type if needed.');
    }

    $form['defaultMaxScore'] = [
      '#title' => $this->t('Default maximum score'),
      '#description' => $this->t('Applied when assigning activities to a lesson.'),
      '#type' => 'number',
      '#min' => 0,
      '#step' => 1,
      '#default_value' => $this->entity->getDefaultMaxScore(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $plugin = $this->getConfigurablePlugin($form_state);
    if ($plugin !== NULL) {
      $plugin->validateConfigurationForm($form['pluginConfiguration'], SubformState::createForSubform($form['pluginConfiguration'], $form, $form_state));
    }
  }

  /**
   * Get Plugin if it is configurable.
   */
  private function getConfigurablePlugin(FormStateInterface $form_state): ?PluginFormInterface {
    \assert($this->entity instanceof ActivityTypeInterface);
    $plugin_id = $form_state->getValue('pluginId') ?? $this->entity->getPluginId();
    if ($plugin_id === '' || !$this->answerManager->hasDefinition($plugin_id)) {
      return NULL;
    }
    $plugin_configuration = $form_state->getValue('pluginConfiguration') ?? $this->entity->getPluginConfiguration();
    $plugin = $this->answerManager->createInstance($plugin_id, $plugin_configuration);
    if (!$plugin instanceof PluginFormInterface) {
      return NULL;
    }
    return $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);
    $plugin = $this->getConfigurablePlugin($form_state);
    if ($plugin !== NULL) {
      $plugin->submitConfigurationForm($form['pluginConfiguration'], SubformState::createForSubform($form['pluginConfiguration'], $form, $form_state));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    \assert($this->entity instanceof ActivityTypeInterface);
    $status = $this->entity->save();

    if ($status === \SAVED_NEW) {
      $plugin = $this->answerManager->createInstance($this->entity->getPluginId());
      $plugin->install($this->entity);
      $this->messenger()->addMessage($this->t('Created the %label Activity type.', [
        '%label' => $this->entity->label(),
      ]));
    }
    else {
      $this->messenger()->addMessage($this->t('Saved the %label Activity type.', [
        '%label' => $this->entity->label(),
      ]));
    }

    $form_state->setRedirectUrl(Url::fromRoute('entity.lms_activity_type.collection'));

    return $status;
  }

  /**
   * Ajax callback.
   */
  public function ajaxUpdate(array $form, FormStateInterface $form_state): array {
    return $form['pluginConfiguration'];
  }

}
