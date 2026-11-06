<?php

declare(strict_types=1);

namespace Drupal\Tests\lms\Kernel;

use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\lms\LmsKernelTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group as AttributesGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the course start link block and the lms_course_link hook.
 */
#[AttributesGroup('lms')]
#[RunTestsInSeparateProcesses]
final class CourseStartLinkTest extends KernelTestBase {

  use LmsKernelTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'filter',
    'field',
    'flexible_permissions',
    'group',
    'lms',
    'lms_hooks_test',
    'options',
    'text',
    'user',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('action');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installConfig(['group']);
    $this->installConfig(['lms']);
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('view');
    $this->installEntitySchema('lms_course_status');
  }

  /**
   * Tests CourseStartLink build with lms_course_link hook implementations.
   */
  public function testCourseLinkHook(): void {
    $student = $this->createUser();
    $this->setCurrentUser($student);

    // An unpublished course: without any hook output the block build
    // deterministically returns the "Coming soon" action info.
    [$course] = $this->createCourse('Test course', 0, (int) $this->createUser()->id(), ['status' => 0]);

    $block = $this->container->get('plugin.manager.block')->createInstance('lms_start_link', []);
    $block->setContext('group', new Context(new EntityContextDefinition('entity:group'), $course));
    $block->setContext('user', new Context(new EntityContextDefinition('entity:user'), $student));
    self::assertTrue($block->access($student), 'The block is accessible.');

    $key_value = $this->container->get('keyvalue.database')->get('lms_test');
    $renderer = $this->container->get('renderer');

    // Case 1: all hook implementations return NULL, the block falls back to
    // its default logic and all implementations are executed.
    $build = $block->build();
    self::assertIsArray($build);
    self::assertSame('lms:course_action_info', $build['#component']);
    self::assertSame('coming-soon', $build['#props']['class']);
    self::assertTrue($key_value->get('lms_course_link_last_executed', FALSE), 'The last hook implementation was executed.');
    $output = (string) $renderer->renderInIsolation($build);
    self::assertStringContainsString('Coming soon!', $output);

    // Case 2: the first hook implementation returns a build, it is used as
    // the block build with cacheability applied and later implementations
    // are skipped entirely.
    $key_value->set('lms_course_link', TRUE);
    $key_value->delete('lms_course_link_last_executed');
    $build = $block->build();
    self::assertIsArray($build);
    self::assertSame('Test course link', $build['#markup']);
    self::assertContains('user', $build['#cache']['contexts']);
    self::assertContains('lms_test:course_link', $build['#cache']['tags']);
    self::assertFalse($key_value->get('lms_course_link_last_executed', FALSE), 'The last hook implementation was skipped.');
    $output = (string) $renderer->renderInIsolation($build);
    self::assertStringContainsString('Test course link', $output);

    // The computed start_link field renders the same block build.
    $build = $course->get('start_link')->view();
    self::assertSame('Test course link', $build['#markup']);
  }

}
