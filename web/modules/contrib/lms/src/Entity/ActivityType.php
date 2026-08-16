<?php

declare(strict_types=1);

namespace Drupal\lms\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;

/**
 * Defines the Activity type entity.
 *
 * @ConfigEntityType(
 *   id = "lms_activity_type",
 *   label = @Translation("Activity type"),
 *   handlers = {
 *     "list_builder" = "Drupal\lms\Entity\Handlers\ActivityTypeListBuilder",
 *     "form" = {
 *       "add" = "Drupal\lms\Entity\Form\ActivityTypeForm",
 *       "edit" = "Drupal\lms\Entity\Form\ActivityTypeForm",
 *       "delete" = "Drupal\lms\Entity\Form\ActivityTypeDeleteForm"
 *     },
 *     "access" = "Drupal\lms\Entity\Handlers\ActivityTypeAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   config_prefix = "lms_activity_type",
 *   admin_permission = "administer lms",
 *   bundle_of = "lms_activity",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "name",
 *     "uuid" = "uuid",
 *   },
 *   links = {
 *     "edit-form" = "/admin/lms/activity_type/{lms_activity_type}/edit",
 *     "delete-form" = "/admin/lms/activity_type/{lms_activity_type}/delete",
 *     "add-form" = "/admin/lms/activity_type/add",
 *     "collection" = "/admin/lms/activity_type",
 *   },
 *   config_export = {
 *     "id",
 *     "name",
 *     "description",
 *     "pluginId",
 *     "pluginConfiguration",
 *     "defaultMaxScore",
 *   }
 * )
 */
class ActivityType extends ConfigEntityBundleBase implements ActivityTypeInterface {

  /**
   * The Activity type ID.
   */
  protected string $id;

  /**
   * The Activity type name.
   */
  protected string $name = '';

  /**
   * The Activity type description.
   */
  protected string $description = '';

  /**
   * The activity - answer plugin ID.
   */
  protected string $pluginId = '';

  /**
   * The activity - answer plugin configuration.
   */
  protected array $pluginConfiguration = [];

  /**
   * Default max score when assigning lesson activities.
   */
  protected int $defaultMaxScore = 5;

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return $this->name;
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->description;
  }

  /**
   * {@inheritdoc}
   */
  public function setDescription(string $description): void {
    $this->description = $description;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginId(): string {
    return $this->pluginId;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginConfiguration(): array {
    return $this->pluginConfiguration;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultMaxScore(): int {
    return $this->defaultMaxScore;
  }

}
