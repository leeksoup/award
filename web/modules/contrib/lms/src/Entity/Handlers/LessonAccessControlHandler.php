<?php

declare(strict_types=1);

namespace Drupal\lms\Entity\Handlers;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lms\Entity\LessonInterface;

/**
 * Access controller for the Lesson entity.
 *
 * @see \Drupal\lms\Entity\Lesson.
 */
final class LessonAccessControlHandler extends LmsEntityAccessControlHandlerBase {

  /**
   * {@inheritdoc}
   */
  protected function checkFieldAccess($operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    if (
      $operation === 'edit' &&
      $field_definition->getName() === LessonInterface::ACTIVITIES
    ) {
      return LmsEntityAccessControlHandlerBase::lmsReferenceFieldEditAccess('lms_activity', $account);
    }
    return parent::checkFieldAccess($operation, $field_definition, $account, $items);
  }

}
