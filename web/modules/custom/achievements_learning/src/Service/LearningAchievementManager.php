<?php

namespace Drupal\achievements_learning\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Coordinates lesson, course, forum, and milestone achievement evaluation.
 */
class LearningAchievementManager {

  /**
   * Constructs the service.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Records a lesson completion event.
   */
  public function recordLessonCompleted(int $uid, int $lessonId): void {
    $this->logger->info('Scaffold lesson completion recorded for uid @uid and lesson @lesson.', ['@uid' => $uid, '@lesson' => $lessonId]);
    $this->evaluateConfiguredMilestones($uid, [
      'trigger_type' => 'lesson_complete',
      'target_id' => (string) $lessonId,
      'lesson_id' => $lessonId,
    ]);
  }

  /**
   * Records a section completion event.
   */
  public function recordSectionCompleted(int $uid, int $sectionId): void {
    $this->evaluateConfiguredMilestones($uid, [
      'trigger_type' => 'section_complete',
      'target_id' => (string) $sectionId,
      'section_id' => $sectionId,
    ]);
  }

  /**
   * Records a course completion event.
   */
  public function recordCourseCompleted(int $uid, int $courseId): void {
    $this->evaluateConfiguredMilestones($uid, [
      'trigger_type' => 'course_complete',
      'target_id' => (string) $courseId,
      'course_id' => $courseId,
    ]);
  }

  /**
   * Records a forum topic event.
   */
  public function recordForumTopic(int $uid, int $topicId): void {
    $this->evaluateConfiguredMilestones($uid, [
      'trigger_type' => 'forum_topic_count',
      'target_id' => (string) $topicId,
      'topic_id' => $topicId,
    ]);
  }

  /**
   * Records a forum reply event.
   */
  public function recordForumReply(int $uid, int $commentId): void {
    $this->evaluateConfiguredMilestones($uid, [
      'trigger_type' => 'forum_reply_count',
      'target_id' => (string) $commentId,
      'comment_id' => $commentId,
    ]);
  }

  /**
   * Evaluates configured milestone rules for a context.
   */
  public function evaluateConfiguredMilestones(int $uid, array $context): void {
    $rules = $this->configFactory->get('achievements_learning.settings')->get('milestone_rules') ?? [];
    foreach ($rules as $rule) {
      if (empty($rule['enabled']) || empty($rule['achievement_id']) || empty($rule['trigger_type'])) {
        continue;
      }
      if ($rule['trigger_type'] !== ($context['trigger_type'] ?? NULL)) {
        continue;
      }
      if (!empty($rule['target_id']) && (string) $rule['target_id'] !== (string) ($context['target_id'] ?? '')) {
        continue;
      }
      if (!empty($rule['threshold'])) {
        continue;
      }
      achievements_unlocked($rule['achievement_id'], $uid);
    }
  }

}
