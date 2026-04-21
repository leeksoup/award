<?php

namespace Drupal\achievements_learning\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
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
    protected LearningSectionManager $sectionManager,
    protected LearningCourseManager $courseManager,
    protected LearningRewardManager $rewardManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Records a lesson completion event.
   */
  public function recordLessonCompleted(int $uid, int $lessonId): void {
    $this->markCompletedItem($uid, $lessonId);
    $lesson_count = $this->incrementCounter('lesson_count', $uid);
    $this->processThresholdMilestones($uid, 'lesson_count', $lesson_count, 'lesson_count_milestones');
    $this->evaluateConfiguredMilestones($uid, [
      'trigger_type' => 'lesson_count',
      'count' => $lesson_count,
    ]);
    $this->evaluateConfiguredMilestones($uid, [
      'trigger_type' => 'lesson_complete',
      'target_id' => (string) $lessonId,
      'lesson_id' => $lessonId,
    ]);

    foreach ($this->sectionManager->getLessonSectionIds($lessonId) as $section_id) {
      if (!$this->sectionManager->isSectionComplete($uid, $section_id)) {
        continue;
      }

      if ($this->markUniqueCompletion('section_complete', $uid, $section_id)) {
        $this->recordSectionCompleted($uid, $section_id);
      }
    }

    $course_id = $this->courseManager->getLessonCourseId($lessonId);
    if ($course_id && $this->courseManager->isCourseComplete($uid, $course_id) && $this->markUniqueCompletion('course_complete', $uid, $course_id)) {
      $course_count = $this->incrementCounter('course_count', $uid);
      $this->processThresholdMilestones($uid, 'course_count', $course_count, 'course_count_milestones');
      $this->evaluateConfiguredMilestones($uid, [
        'trigger_type' => 'course_count',
        'count' => $course_count,
      ]);
      $this->recordCourseCompleted($uid, $course_id);
      $this->rewardManager->markRewardEligibility($uid, $course_id);
    }
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
    $topic_count = $this->incrementCounter('forum_topic_count', $uid);
    $this->processThresholdMilestones($uid, 'forum_topic_count', $topic_count, 'forum_topic_milestones');
    $this->evaluateConfiguredMilestones($uid, [
      'trigger_type' => 'forum_topic_count',
      'target_id' => (string) $topicId,
      'topic_id' => $topicId,
      'count' => $topic_count,
    ]);
  }

  /**
   * Records a forum reply event.
   */
  public function recordForumReply(int $uid, int $commentId): void {
    $reply_count = $this->incrementCounter('forum_reply_count', $uid);
    $this->processThresholdMilestones($uid, 'forum_reply_count', $reply_count, 'forum_reply_milestones');
    $this->evaluateConfiguredMilestones($uid, [
      'trigger_type' => 'forum_reply_count',
      'target_id' => (string) $commentId,
      'comment_id' => $commentId,
      'count' => $reply_count,
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
      if (($rule['trigger_type'] ?? NULL) !== ($context['trigger_type'] ?? NULL)) {
        continue;
      }
      if ($this->isCountTrigger($rule['trigger_type'])) {
        if (empty($rule['threshold']) || (int) ($context['count'] ?? 0) !== (int) $rule['threshold']) {
          continue;
        }
      }
      elseif (!empty($rule['threshold'])) {
        $this->logger->warning('Ignoring non-count milestone rule @id with a threshold configured.', [
          '@id' => $rule['id'] ?? $rule['achievement_id'],
        ]);
        continue;
      }

      if (!empty($rule['target_id']) && (string) $rule['target_id'] !== (string) ($context['target_id'] ?? '')) {
        continue;
      }

      achievements_unlocked($rule['achievement_id'], $uid);
    }
  }

  /**
   * Increments and returns a named counter for a user.
   */
  protected function incrementCounter(string $counter, int $uid): int {
    $storage_key = $this->getStorageKey($counter);
    $current = achievements_storage_get($storage_key, $uid);
    if ($current === FALSE) {
      $legacy_key = 'achievements_learning:' . $counter;
      $legacy_value = achievements_storage_get($legacy_key, $uid);
      $current = $legacy_value === FALSE ? 0 : (int) $legacy_value;
      achievements_storage_set($storage_key, $current, $uid);
    }
    $current = (int) $current;
    $current++;
    achievements_storage_set($storage_key, $current, $uid);
    return $current;
  }

  /**
   * Processes configured threshold milestones.
   */
  protected function processThresholdMilestones(int $uid, string $triggerType, int $count, string $configKey): void {
    $milestones = $this->configFactory->get('achievements_learning.settings')->get($configKey) ?? [];
    foreach ($milestones as $milestone) {
      if ((int) ($milestone['threshold'] ?? 0) !== $count || empty($milestone['achievement_id'])) {
        continue;
      }

      achievements_unlocked($milestone['achievement_id'], $uid);
    }
  }

  /**
   * Returns whether a trigger type is count-based.
   */
  protected function isCountTrigger(string $triggerType): bool {
    return in_array($triggerType, [
      'lesson_count',
      'course_count',
      'forum_topic_count',
      'forum_reply_count',
    ], TRUE);
  }

  /**
   * Marks a unique completion for a user and returns whether it was new.
   */
  protected function markUniqueCompletion(string $type, int $uid, int $targetId): bool {
    $storage_key = $this->getStorageKey($type . '_ids');
    $completed = achievements_storage_get($storage_key, $uid);
    if ($completed === FALSE) {
      $legacy_key = sprintf('achievements_learning:%s_ids', $type);
      $legacy_value = achievements_storage_get($legacy_key, $uid);
      $completed = is_array($legacy_value) ? $legacy_value : [];
      achievements_storage_set($storage_key, $completed, $uid);
    }
    $completed = is_array($completed) ? $completed : [];
    if (in_array($targetId, $completed, TRUE)) {
      return FALSE;
    }

    $completed[] = $targetId;
    achievements_storage_set($storage_key, $completed, $uid);
    return TRUE;
  }

  /**
   * Returns a short key compatible with achievements_storage.achievement_id.
   */
  protected function getStorageKey(string $name): string {
    return match ($name) {
      'lesson_count' => 'al:lesson_count',
      'course_count' => 'al:course_count',
      'forum_topic_count' => 'al:topic_count',
      'forum_reply_count' => 'al:reply_count',
      'section_complete_ids' => 'al:section_ids',
      'course_complete_ids' => 'al:course_ids',
      'completed_items' => 'al:completed_items',
      default => 'al:' . $name,
    };
  }

  /**
   * Records a completed lesson/activity ID for a user.
   */
  protected function markCompletedItem(int $uid, int $itemId): void {
    $storage_key = $this->getStorageKey('completed_items');
    $completed = achievements_storage_get($storage_key, $uid);
    $completed = is_array($completed) ? $completed : [];
    if (in_array($itemId, $completed, TRUE)) {
      return;
    }

    $completed[] = $itemId;
    achievements_storage_set($storage_key, $completed, $uid);
  }

}
