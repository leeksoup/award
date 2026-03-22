<?php

namespace Drupal\achievements_learning\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Settings form for Achievements Learning.
 */
class LearningSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['achievements_learning.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'achievements_learning_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('achievements_learning.settings');

    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General settings'),
      '#open' => TRUE,
    ];
    $form['general']['parent_email_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Parent email field'),
      '#default_value' => $config->get('parent_email_field') ?: 'field_parent_email',
      '#required' => TRUE,
    ];
    $form['general']['current_title_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Current title field'),
      '#default_value' => $config->get('current_title_field') ?: 'field_learning_current_title',
      '#required' => TRUE,
    ];
    $form['general']['forum_excluded_roles'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Excluded forum roles'),
      '#description' => $this->t('Enter one role machine name per line.'),
      '#default_value' => implode("\n", $config->get('forum_excluded_roles') ?? []),
    ];

    $form['milestones'] = [
      '#type' => 'details',
      '#title' => $this->t('Milestone configuration'),
      '#open' => TRUE,
    ];
    $form['milestones']['title_priority'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Title priority order'),
      '#description' => $this->t('Enter one title per line, highest priority first.'),
      '#default_value' => implode("\n", $config->get('title_priority') ?? []),
    ];
    $form['milestones']['milestone_rules'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Milestone rules (YAML)'),
      '#description' => $this->t('Provide an array of rule definitions keyed by machine name.'),
      '#default_value' => Yaml::dump($config->get('milestone_rules') ?? [], 6, 2),
    ];

    $form['rewards'] = [
      '#type' => 'details',
      '#title' => $this->t('Reward configuration'),
      '#open' => FALSE,
    ];
    $form['rewards']['reward_rules'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reward rules (YAML)'),
      '#default_value' => Yaml::dump($config->get('reward_rules') ?? [], 6, 2),
    ];
    $form['rewards']['reward_items'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reward items (YAML)'),
      '#default_value' => Yaml::dump($config->get('reward_items') ?? [], 6, 2),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    foreach (['milestone_rules', 'reward_rules', 'reward_items'] as $yaml_field) {
      try {
        $decoded = Yaml::parse($form_state->getValue($yaml_field));
        if (!is_array($decoded) && $decoded !== NULL) {
          $form_state->setErrorByName($yaml_field, $this->t('The %field value must decode to a YAML array.', ['%field' => $yaml_field]));
        }
      }
      catch (\Throwable $exception) {
        $form_state->setErrorByName($yaml_field, $this->t('Invalid YAML in %field: %message', [
          '%field' => $yaml_field,
          '%message' => $exception->getMessage(),
        ]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);

    $this->configFactory->getEditable('achievements_learning.settings')
      ->set('parent_email_field', trim($form_state->getValue('parent_email_field')))
      ->set('current_title_field', trim($form_state->getValue('current_title_field')))
      ->set('forum_excluded_roles', array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $form_state->getValue('forum_excluded_roles') ?? '')))))
      ->set('title_priority', array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $form_state->getValue('title_priority') ?? '')))))
      ->set('milestone_rules', Yaml::parse($form_state->getValue('milestone_rules')) ?? [])
      ->set('reward_rules', Yaml::parse($form_state->getValue('reward_rules')) ?? [])
      ->set('reward_items', Yaml::parse($form_state->getValue('reward_items')) ?? [])
      ->save();
  }

}
