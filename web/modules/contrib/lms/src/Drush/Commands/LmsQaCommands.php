<?php

declare(strict_types=1);

namespace Drupal\lms\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\Hooks\HookManager;
use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\lms\TrainingManager;
use Drupal\lms\LmsContentImporter;
use Drupal\user\UserInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Drush\Commands\core\QueueCommands;

/**
 * Provides document fetching commands.
 */
final class LmsQaCommands extends DrushCommands implements SiteAliasManagerAwareInterface {

  use AutowireTrait;
  use SiteAliasManagerAwareTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TrainingManager $trainingManager,
    private readonly LmsContentImporter $lmsContentImporter,
  ) {
    parent::__construct();
  }

  /**
   * Create test content.
   */
  #[CLI\Command(name: 'lms:create-test-content', aliases: ['lms-ctt'])]
  #[CLI\Option(name: 'module', description: 'The module to be tested.')]
  #[CLI\Option(name: 'simple-passwords', description: 'Should we use "123456" for all passwords or generate them?')]
  #[CLI\Usage(name: 'drush lms-ctt', description: 'Create content used in tests.')]
  public function createTestContent(
    array $options = [
      'module' => 'lms',
      'simple-passwords' => FALSE,
    ],
  ): void {
    // Install activity types.
    $this->lmsContentImporter->installActivityTypes($options['module']);

    $entity_data = $this->lmsContentImporter->getData($options['module']);
    $this->lmsContentImporter->import($entity_data, $options);

    // Add LMS Admin role for user 1 to ensure all group permissions
    // are granted.
    $role = $this->entityTypeManager->getStorage('user_role')->load('lms_admin');
    if ($role !== NULL) {
      $superuser = $this->entityTypeManager->getStorage('user')->load('1');
      $superuser->addRole('lms_admin')->save();
    }

    if (
      \array_key_exists('user', $entity_data) &&
      \array_key_exists('admin', $entity_data['user'])
    ) {
      $admin_uuid = $entity_data['user']['admin']['uuid'];
      $ids = $this->entityTypeManager->getStorage('user')->getQuery()
        ->accessCheck(FALSE)
        ->condition('uuid', $admin_uuid)
        ->execute();
      $process = $this->processManager()->drush($this->siteAliasManager()->getSelf(), 'user:login', [], ['uid' => \reset($ids)]);
      $process->mustRun();
      $this->logger()->success(\dt('QA content has been created. LMS Admin login link: @link', [
        '@link' => $process->getOutput(),
      ]));
    }
    else {
      $this->logger()->success(\dt('QA content from the @module has been created.', [
        '@module' => $options['module'],
      ]));
    }
  }

  /**
   * Reset course.
   */
  #[CLI\Command(name: 'lms:reset-course', aliases: ['lms-rc'])]
  #[CLI\Argument(name: 'course_id', description: 'The ID of the course to reset.')]
  #[CLI\Argument(name: 'user_id', description: 'The ID of the student to reset. If not provided, the entire course will be reset.')]
  #[CLI\Usage(name: 'drush lms-rc 1 3', description: 'Reset course 1 for user 3.')]
  public function resetCourse(
    string $course_id,
    ?string $user_id = NULL,
  ): void {
    $this->trainingManager->resetTraining($course_id, $user_id);

    $site_alias = $this->siteAliasManager()->getSelf();
    /** @var \Symfony\Component\Process\Process $process */
    $process = $this->processManager()->drush($site_alias, QueueCommands::RUN, ['lms_delete_entities']);
    $process->run($process->showRealtime()->hideStdout());

    if ($user_id === NULL) {
      $this->logger()->success(\dt('Course has been reset for all users.'));
    }
    else {
      $this->logger()->success(\dt('Course has been reset for the given user ID.'));
    }
  }

  /**
   * Validates lms:reset-course command arguments.
   */
  #[CLI\Hook(type: HookManager::ARGUMENT_VALIDATOR, target: 'lms:reset-course')]
  public function validateResetCourseArguments(CommandData $commandData): void {
    $args = $commandData->input()->getArguments();
    $course = $this->entityTypeManager->getStorage('group')->load($args['course_id']);
    if (!$course instanceof Course) {
      throw new \InvalidArgumentException("A course with ID {$args['course_id']} does not exist.");
    }
    if ($args['user_id'] !== NULL) {
      $account = $this->entityTypeManager->getStorage('user')->load($args['user_id']);
      if (!$account instanceof UserInterface) {
        throw new \InvalidArgumentException("A user with ID {$args['user_id']} does not exist.");
      }
    }
  }

}
