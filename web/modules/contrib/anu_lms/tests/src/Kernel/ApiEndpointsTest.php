<?php

namespace Drupal\Tests\anu_lms\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\KernelTests\KernelTestBase;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Symfony\Component\HttpFoundation\Request;

/**
 * Test API endpoints.
 *
 * @group anu_lms
 */
class ApiEndpointsTest extends KernelTestBase {

  use NodeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'anu_lms',
    'system',
    'eck',
    'image',
    'file',
    'media',
    'entity_reference_revisions',
    'link',
    'rest',
    'field',
    'field_group',
    'rest_paragraphs_recursive',
    'weight',
    'text',
    'node',
    'paragraphs',
    'paragraphs_selection',
    'rest_paragraphs_recursive',
    'paragraphs_browser',
    'user',
    'serialization',
    'filter',
    'taxonomy',
    'options',
    'path',
    'path_alias',
  ];

  /**
   * Set to TRUE to strict check all configuration saved.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  /**
   * The Lesson service.
   *
   * @var \Drupal\anu_lms\Lesson
   */
  private $lesson;

  /**
   * The Course progress service.
   *
   * @var \Drupal\anu_lms\CourseProgress
   */
  private $courseProgress;

  /**
   * HTTP Kernel.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  private $httpClient;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('path_alias');

    EntityViewMode::create(['id' => 'node.teaser', 'targetEntityType' => 'node'])->save();

    $this->installConfig(static::$modules);

    $this->installSchema('system', ['sequences']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('anu_lms', ['anu_lms_progress']);

    $this->lesson = $this->container->get('anu_lms.lesson');
    $this->courseProgress = $this->container->get('anu_lms.course_progress');
    $this->httpClient = $this->container->get('http_kernel');
  }

  /**
   * Test progress in lessons.
   */
  public function testLessonsProgress() {
    // 0. Create a user and set it as the current user.
    $user = $this->createUser([
      'restful post anu_lms_progress',
    ]);
    $this->setCurrentUser($user);

    // 1. Create 4 lessons.
    $lessons = [];

    for ($num = 1; $num <= 4; $num++) {
      $lessons[] = $this->createNode([
        'type' => 'module_lesson',
        'title' => 'Lesson ' . $num,
      ]);
    }

    // 2. Create a course.
    $course = $this->createNode([
      'type' => 'course',
      'title' => 'Check API entrypoints',
      'field_course_linear_progress' => '1',
      'field_course_module' => [
        Paragraph::create([
          'type' => 'course_modules',
          'field_module_title' => 'Module 1',
          'field_module_lessons' => [
            [
              'target_id' => $lessons[0]->id(),
            ],
            [
              'target_id' => $lessons[1]->id(),
            ],
          ],
        ]),
        Paragraph::create([
          'type' => 'course_modules',
          'field_module_title' => 'Module 2',
          'field_module_lessons' => [
            [
              'target_id' => $lessons[2]->id(),
            ],
            [
              'target_id' => $lessons[3]->id(),
            ],
          ],
        ]),
      ],
    ]);

    // 3. Make sure all lessons are not completed.
    $this->assertEmpty($this->courseProgress->getCompletedLessons($course));

    // 4. Set 1st and 2nd lessons as completed and make sure it was reflected.
    $twoLessons = array_slice($lessons, 0, 2);
    foreach ($twoLessons as $lesson) {
      $request = Request::create('/anu_lms/progress?_format=json', 'POST', [], [], [], [], Json::encode([$lesson->id()]));
      $request->headers->set('Content-Type', 'application/json');
      $response = $this->httpClient->handle($request);

      $this->assertSame('{"lessons":["' . $lesson->id() . '"]}', $response->getContent());
      $this->assertSame(200, $response->getStatusCode());
    }

    // Make sure that 1st and 2nd lesson were passed.
    $this->assertSame([$twoLessons[0]->id(), $twoLessons[1]->id()], $this->courseProgress->getCompletedLessons($course));

    // 5. Make sure that /anu_lms/progress correctly handles requests with
    // non-existing lessons.
    $request = Request::create('/anu_lms/progress?_format=json', 'POST', [], [], [], [], Json::encode([
      8888,
      9999,
    ]));
    $request->headers->set('Content-Type', 'application/json');
    $response = $this->httpClient->handle($request);

    $this->assertSame('{"lessons":[]}', $response->getContent());
    $this->assertSame(200, $response->getStatusCode());

    // 5. Make sure that /anu_lms/progress correctly handles requests
    // containing incorrect data.
    $request = Request::create('/anu_lms/progress?_format=json', 'POST', [], [], [], [], 'just a string');
    $request->headers->set('Content-Type', 'application/json');
    $response = $this->httpClient->handle($request);

    $this->assertSame('{"message":"Syntax error"}', $response->getContent());
    $this->assertSame(400, $response->getStatusCode());

    // 6. Make sure that /anu_lms/progress rejects requests with empty lessons
    // list.
    $request = Request::create('/anu_lms/progress?_format=json', 'POST', [], [], [], [], Json::encode([]));
    $request->headers->set('Content-Type', 'application/json');
    $response = $this->httpClient->handle($request);

    $this->assertSame('{"message":"Incorrect request data."}', $response->getContent());
    $this->assertSame(406, $response->getStatusCode());

    // 7. Make sure that /anu_lms/progress rejects requests from users without
    // necessary permissions.
    $this->setUpCurrentUser(['uid' => 0]);
    $request = Request::create('/anu_lms/progress?_format=json', 'POST', [], [], [], [], Json::encode([$lessons[0]]));
    $request->headers->set('Content-Type', 'application/json');
    $response = $this->httpClient->handle($request);

    $this->assertSame('{"message":"The \u0027restful post anu_lms_progress\u0027 permission is required."}', $response->getContent());
    $this->assertSame(403, $response->getStatusCode());

    // 8. Make sure that /anu_lms/progress rejects requests for anon users
    // anyway even if they have proper permissions.
    $role = Role::load(Role::ANONYMOUS_ID);
    $this->grantPermissions($role, ['restful post anu_lms_progress']);
    $request = Request::create('/anu_lms/progress?_format=json', 'POST', [], [], [], [], Json::encode([$lessons[0]]));
    $request->headers->set('Content-Type', 'application/json');
    $response = $this->httpClient->handle($request);

    $this->assertSame('{"message":"Progress for anonymous users is not supported."}', $response->getContent());
    $this->assertSame(406, $response->getStatusCode());
  }

  /**
   * Test checklists in lessons.
   */
  public function testCheckboxesInLessons() {
    // 0. Create a user and set it as the current user.
    $user = $this->createUser([
      'restful get anu_lms_lesson_checklist',
      'restful post anu_lms_lesson_checklist',
    ]);
    $this->setCurrentUser($user);

    // 1. Create a lesson with a checklist.
    $lesson = $this->createNode([
      'type' => 'module_lesson',
      'title' => 'Lesson',
      'field_module_lesson_content' => [
        Paragraph::create([
          'type' => 'lesson_section',
          'field_lesson_section_content' => [
            Paragraph::create([
              'type' => 'lesson_checklist',
              'field_checklist_items' => [
                Paragraph::create([
                  'type' => 'checklist_item',
                  'field_checkbox_option' => '1st option',
                ]),
                Paragraph::create([
                  'type' => 'checklist_item',
                  'field_checkbox_option' => '2nd option',
                ]),
                Paragraph::create([
                  'type' => 'checklist_item',
                  'field_checkbox_option' => '3rd option',
                ]),
              ],
            ]),
          ],
        ]),
      ],
    ]);

    $checklistParagraph = $lesson
      ->get('field_module_lesson_content')
      ->referencedEntities()[0]
      ->get('field_lesson_section_content')
      ->referencedEntities()[0];
    $optionsParagraphs = $checklistParagraph->get('field_checklist_items')->referencedEntities();

    // 2. Tick 1st and 2nd options in that checklist and make sure it was
    // reflected.
    $selectedOptionIds = array_slice(array_map(function ($option) {
      return (int) $option->id();
    }, $optionsParagraphs), 0, 2);
    $params = [
      'checklist_paragraph_id' => $checklistParagraph->id(),
      'selected_option_ids' => $selectedOptionIds,
    ];
    $request = Request::create('/anu_lms/lesson/checklist?_format=json', 'POST', [], [], [], [], Json::encode($params));
    $request->headers->set('Content-Type', 'application/json');
    $response = $this->httpClient->handle($request);

    $this->assertSame('{"lesson_checklist_id":"1"}', $response->getContent());
    $this->assertSame(200, $response->getStatusCode());

    $request = Request::create('/anu_lms/lesson/checklist?_format=json&checklist_paragraph_id=' . $checklistParagraph->id());
    $request->headers->set('Content-Type', 'application/json');
    $response = $this->httpClient->handle($request);

    $jsonResponse = Json::decode($response->getContent());

    $this->assertSame($selectedOptionIds, array_map(function ($item) {
      return $item['target_id'];
    }, $jsonResponse['field_checklist_selected_options']));

    $this->assertSame(200, $response->getStatusCode());

    // 3. Make sure that /anu_lms/lesson/checklist correctly handles requests
    // with non-existing paragraphs.
    $params = [
      'checklist_paragraph_id' => 8888,
      'selected_option_ids' => [1111, 2222],
    ];
    $request = Request::create('/anu_lms/lesson/checklist?_format=json', 'POST', [], [], [], [], Json::encode($params));
    $request->headers->set('Content-Type', 'application/json');
    $response = $this->httpClient->handle($request);

    $this->assertSame('{"message":"Can\u0027t submit or update checklist results. Error: Paragraph with given id doesn\u0027t exist"}', $response->getContent());
    $this->assertSame(406, $response->getStatusCode());

    // 4. Make sure that /anu_lms/lesson/checklist correctly handles requests
    // containing incorrect data.
    $request = Request::create('/anu_lms/lesson/checklist?_format=json', 'POST', [], [], [], [], Json::encode([]));
    $request->headers->set('Content-Type', 'application/json');
    $response = $this->httpClient->handle($request);

    $this->assertSame('{"message":"Incorrect request data."}', $response->getContent());
    $this->assertSame(406, $response->getStatusCode());

    // 5. Make sure that /anu_lms/lesson/checklist rejects requests from users
    // without necessary permissions.
    $this->setUpCurrentUser(['uid' => 0]);
    $request = Request::create('/anu_lms/lesson/checklist?_format=json', 'POST', [], [], [], [], Json::encode([]));
    $request->headers->set('Content-Type', 'application/json');
    $response = $this->httpClient->handle($request);

    $this->assertSame('{"message":"The \u0027restful post anu_lms_lesson_checklist\u0027 permission is required."}', $response->getContent());
    $this->assertSame(403, $response->getStatusCode());
  }

}
