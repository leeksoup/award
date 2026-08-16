<?php

declare(strict_types=1);

namespace Drupal\lms_classes\Plugin\Group\Relation;

use Drupal\Core\Form\FormStateInterface;
use Drupal\group\Plugin\Group\Relation\GroupRelationBase;

/**
 * Provides a group relation for users as members.
 *
 * @todo Convert to Attributes when dropping Group 3.2 support.
 *
 * @GroupRelationType(
 *   id = "lms_classes",
 *   entity_type_id = "group",
 *   label = @Translation("LMS Class"),
 *   description = @Translation("Adds classes to groups."),
 *   reference_label = @Translation("Class"),
 *   entity_bundle = "lms_class",
 *   reference_description = @Translation("Class you want to make a part of the Course."),
 *   admin_permission = "administer lms",
 *   pretty_path_key = "class",
 *   enforced = FALSE,
 * )
 */
final class LmsClasses extends GroupRelationBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $config = parent::defaultConfiguration();
    $config['group_cardinality'] = 0;
    $config['entity_cardinality'] = 1;
    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Disable the entity cardinality field as the functionality of this module
    // relies on a cardinality of 1. We don't just hide it, though, to keep a UI
    // that's consistent with other group relations.
    $info = $this->t("This field has been disabled by the plugin to guarantee the functionality that's expected of it.");
    $form['entity_cardinality']['#disabled'] = TRUE;
    $form['group_cardinality']['#disabled'] = TRUE;
    $form['entity_cardinality']['#description'] .= '<br /><em>' . $info . '</em>';
    $form['group_cardinality']['#description'] .= '<br /><em>' . $info . '</em>';

    return $form;
  }

}
