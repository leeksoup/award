<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\views\field;

use Drupal\lms\Entity\CourseStatus;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\Date;
use Drupal\views\ResultRow;

/**
 * Displays LMS user Course status last activity date / time.
 */
#[ViewsField(id: 'lms_course_student_latest_activity')]
final class CourseStudentLatestActivity extends Date {

  /**
   * {@inheritdoc}
   */
  public function getValue(ResultRow $values, $field = NULL) {
    $course_status = $this->getEntity($values);
    if (!$course_status instanceof CourseStatus) {
      return NULL;
    }

    return $course_status->getLastActivity();
  }

}
