<?php

namespace Drupal\anu_lms\Plugin\Condition;

use Drupal\Core\Condition\Attribute\Condition;
use Drupal\Core\Condition\ConditionPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides an 'Anu LMS pages' condition.
 */
#[Condition(
  id: "anu_lms_pages",
  label: new TranslatableMarkup("Anu LMS pages"),
  context_definitions: [
    "node" => new EntityContextDefinition(
      data_type: "entity:node",
      label: new TranslatableMarkup("Node"),
      required: FALSE,
    ),
  ],
)]
class AnuLmsPages extends ConditionPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['show' => 0] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['show'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show on Anu LMS pages'),
      '#default_value' => $this->configuration['show'],
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['show'] = $form_state->getValue('show');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): string {
    if ($this->configuration['show']) {
      if (!$this->isNegated()) {
        return $this->t('Show on Anu LMS pages');
      }
      else {
        return $this->t('Hide on Anu LMS pages');
      }
    }

    return $this->t('Not Restricted');
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(): bool {
    // To make the condition work on pages other than nodes, the node context
    // is set to not required. However, the right context is always provided
    // in hook_form_block_form_alter.
    $node = $this->getContextValue('node');
    $anu_lms_bundles = [
      'course',
      'courses_landing_page',
      'courses_page',
      'module_lesson',
      'module_assessment',
    ];

    return !empty($node) && in_array($node->bundle(), $anu_lms_bundles);
  }

}
