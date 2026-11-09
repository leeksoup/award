<?php

declare(strict_types=1);

namespace Drupal\Tests\lms_free_text_model\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\lms\Entity\Activity;
use Drupal\lms\Entity\ActivityType;
use Drupal\lms\Entity\Answer;
use Drupal\lms_free_text_model\Plugin\ActivityAnswer\FreeTextModelResponse;

/**
 * Tests persisted rubric fields and model visibility across answer saving.
 *
 * @group lms_free_text_model
 */
final class RubricActivityTest extends KernelTestBase {

  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'options', 'views',
    'entity', 'flexible_permissions', 'variationcache', 'group',
    'lms', 'lms_answer_plugins', 'lms_free_text_model',
  ];

  private FreeTextModelResponse $plugin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('lms_activity');
    $this->installEntitySchema('lms_answer');
    $this->installConfig(['system', 'filter', 'lms_free_text_model']);
    $type = ActivityType::create([
      'id' => 'rubric_question', 'name' => 'Rubric question',
      'pluginId' => 'free_text_model_response',
    ]);
    $type->save();
    $this->plugin = $this->container->get('plugin.manager.activity_answer')->createInstance('free_text_model_response');
    $this->plugin->install($type);
  }

  /**
   * A new activity has three defaults; an empty saved rubric retains full credit.
   */
  public function testDefaultsPartialScoreAndLegacyCompatibility(): void {
    $activity = Activity::create(['type' => 'rubric_question', 'name' => 'Question']);
    self::assertCount(3, $activity->get('rubric_checks'));
    $activity->set('minimum_characters', 5);
    $activity->set('rubric_checks', [
      ['label' => 'Private label', 'pattern' => 'Church|tradition', 'mode' => 'phrases', 'weight' => 1, 'nudge' => 'Address the objection.'],
      ['label' => 'Question', 'pattern' => '\\?', 'mode' => 'regex', 'weight' => 3, 'nudge' => 'Ask a question.'],
      ['label' => 'Skipped', 'pattern' => '', 'weight' => 100],
    ]);
    self::assertCount(0, $activity->get('rubric_checks')->validate());
    $activity->save();
    $answer = Answer::create([Answer::ACTIVITY_FIELD => $activity->id()]);
    $answer->setData(['answer' => 'The CHURCH teaches this.']);
    self::assertSame(0.25, $this->plugin->getScore($answer));
    self::assertTrue($this->plugin->evaluatedOnSave($answer));
    $answer->setScore($this->plugin->getScore($answer) * 10);
    self::assertSame(3, $answer->getScore());
    $activity->set('require_teacher_review', TRUE);
    $activity->save();
    $this->container->get('entity_type.manager')->getStorage('lms_activity')->resetCache();
    $answer = Answer::create([Answer::ACTIVITY_FIELD => $activity->id()]);
    $answer->setData(['answer' => 'The CHURCH teaches this.']);
    self::assertFalse($this->plugin->evaluatedOnSave($answer));
    $activity->set('rubric_checks', []);
    $activity->save();
    $this->container->get('entity_type.manager')->getStorage('lms_activity')->resetCache();
    $answer = Answer::create([Answer::ACTIVITY_FIELD => $activity->id()]);
    $answer->setData(['answer' => 'A legacy response']);
    self::assertSame(1.0, $this->plugin->getScore($answer));
    $answer->setData(['answer' => 'tiny']);
    self::assertSame(0.0, $this->plugin->getScore($answer));
  }

  /**
   * Entity validation rejects invalid regexes and a sixth check.
   */
  public function testAuthorValidation(): void {
    $activity = Activity::create(['type' => 'rubric_question']);
    $activity->set('rubric_checks', [['pattern' => '[', 'mode' => 'regex', 'weight' => 1]]);
    self::assertGreaterThan(0, $activity->get('rubric_checks')->validate()->count());
    $activity->set('rubric_checks', array_fill(0, 6, ['pattern' => 'word', 'mode' => 'phrases', 'weight' => 1]));
    self::assertGreaterThan(0, $activity->get('rubric_checks')->validate()->count());
  }

  /**
   * Installing the new fields never populates old activities with default checks.
   */
  public function testUpgradePreservesExistingActivities(): void {
    ActivityType::create([
      'id' => 'legacy_question', 'name' => 'Legacy questions',
      'pluginId' => 'free_text_model_response',
    ])->save();
    $ids = [];
    foreach ([467, 468, 469] as $id) {
      $activity = Activity::create(['id' => $id, 'type' => 'legacy_question', 'name' => 'Existing question']);
      $activity->save();
      $ids[] = $activity->id();
    }
    require_once DRUPAL_ROOT . '/modules/custom/lms_free_text_model/lms_free_text_model.install';
    lms_free_text_model_update_10002();
    lms_free_text_model_update_10002();
    foreach (Activity::loadMultiple($ids) as $activity) {
      self::assertTrue($activity->get('rubric_checks')->isEmpty());
      self::assertFalse((bool) $activity->get('require_teacher_review')->value);
      $answer = Answer::create([Answer::ACTIVITY_FIELD => $activity->id()]);
      $answer->setData(['answer' => 'Existing activity response']);
      self::assertSame(1.0, $this->plugin->getScore($answer));
    }
  }

  /**
   * Coaching never contains the model; a saved answer can render filtered HTML.
   */
  public function testModelOnlyAppearsAfterSaving(): void {
    $activity = Activity::create([
      'type' => 'rubric_question', 'name' => 'Question', 'minimum_characters' => 1,
      'model_response' => '<p><strong>Secret model response</strong></p>',
      'rubric_checks' => [
        ['label' => 'Private label', 'pattern' => 'Church', 'mode' => 'phrases', 'weight' => 1, 'nudge' => '<strong>Address this</strong>'],
      ],
    ]);
    $activity->save();
    $answer = Answer::create([Answer::ACTIVITY_FIELD => $activity->id()]);
    $state = new FormState();
    $state->setValue('answer', 'A different response');
    $trigger = ['#name' => 'model_response_check'];
    $state->setTriggeringElement($trigger);
    $form = ['actions' => ['submit' => ['#type' => 'submit']]];
    $this->plugin->answeringForm($form, $state, $answer);
    $serialized = json_encode($form);
    self::assertStringNotContainsString('Secret model response', $serialized);
    self::assertStringNotContainsString('Private label', $serialized);
    self::assertStringNotContainsString('Church', $serialized);
    self::assertSame([], $this->plugin->modelResponse($answer));
    $coaching = $this->plugin->coachingFeedback($answer, 'A different response');
    self::assertStringNotContainsString('Secret model response', json_encode($coaching));
    $markup = (string) $this->container->get('renderer')->renderRoot($coaching);
    self::assertStringContainsString('&lt;strong&gt;Address this&lt;/strong&gt;', $markup);
    // Run the plugin submit step, then persist just as LMS does before redirect.
    $this->plugin->submitAnsweringForm($form, $state, $answer);
    $answer->setScore($this->plugin->getScore($answer) * 10)->setEvaluated(TRUE)->save();
    self::assertTrue($state->get('lms_free_text_model_submitted'));
    $this->container->get('entity_type.manager')->getStorage('lms_answer')->resetCache();
    $saved = Answer::load($answer->id());
    $feedback = $this->plugin->modelResponse($saved);
    $markup = (string) $this->container->get('renderer')->renderRoot($feedback);
    self::assertStringContainsString('<strong>Secret model response</strong>', $markup);
    self::assertTrue($saved->isEvaluated());
    self::assertSame(0, $saved->getScore());
  }

}
