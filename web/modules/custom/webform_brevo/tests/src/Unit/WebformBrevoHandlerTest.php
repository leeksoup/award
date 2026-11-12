<?php

declare(strict_types=1);

namespace Drupal\Tests\webform_brevo\Unit;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformMessageManagerInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform_brevo\BrevoContactClient;
use Drupal\webform_brevo\Plugin\WebformHandler\WebformBrevoHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests settings-page safety and submission handling using the real SDK.
 *
 * @group webform_brevo
 */
final class WebformBrevoHandlerTest extends TestCase {

  /**
   * Summary rendering must neither query Brevo nor expose the API key.
   */
  public function testSummaryIsOfflineAndOmitsCredentials(): void {
    $handler = $this->handler([], ['api_key' => 'private-test-key']);
    ob_start();
    try {
      $summary = $handler->getSummary();
      self::assertSame('', ob_get_contents());
    }
    finally {
      ob_end_clean();
    }
    self::assertSame('Brevo list ID: 7', $summary['list']['#plain_text']);
    self::assertStringNotContainsString('private-test-key', json_encode($summary, JSON_THROW_ON_ERROR));
    self::assertNull($handler->failure);
  }

  /**
   * Handler settings remain editable without contacting Brevo.
   */
  public function testConfigurationFormIsOffline(): void {
    $previous = \Drupal::hasContainer() ? \Drupal::getContainer() : NULL;
    $container = new ContainerBuilder();
    $fields = $this->createMock(EntityFieldManagerInterface::class);
    $fields->method('getBaseFieldDefinitions')->willReturn([]);
    $container->set('entity_field.manager', $fields);
    \Drupal::setContainer($container);
    try {
      $handler = $this->handler([], ['api_key' => 'disabled-test-key']);
      $handler->getWebform()->method('hasManagedFile')->willReturn(TRUE);
      $form = $handler->buildConfigurationForm([], new FormState());
      self::assertSame('disabled-test-key', $form['brevo']['api_key']['#default_value']);
      self::assertSame(7, $form['brevo']['list_id']['#default_value']);
      self::assertArrayNotHasKey('list_msg', $form['brevo']);
      self::assertArrayHasKey('managed_file_message', $form['submission_data']);
    }
    finally {
      if ($previous !== NULL) {
        \Drupal::setContainer($previous);
      }
      else {
        \Drupal::unsetContainer();
      }
    }
  }

  /**
   * Disabled submission states must not make any remote requests.
   */
  public function testDisabledStatesDoNotCallBrevo(): void {
    $handler = $this->handler([], ['completed_do' => FALSE]);
    $submission = $this->createMock(WebformSubmissionInterface::class);
    foreach (['completed', 'updated', 'deleted', 'draft_created'] as $state) {
      $handler->deliver($state, $submission);
    }
    self::assertNull($handler->failure);
  }

  /**
   * Optional metadata can be absent without warnings or notices.
   */
  public function testContactMappingAndQuietSuccess(): void {
    $history = [];
    $handler = $this->handler([new Response(204)], ['debug' => TRUE], $history);
    $handler->data = ['email' => 'learner@example.com', 'first_name' => 'Test', 'last_name' => 'Learner'];
    $submission = $this->createMock(WebformSubmissionInterface::class);
    $submission->method('getData')->willReturn([]);
    ob_start();
    try {
      $handler->deliver('completed', $submission);
      self::assertSame('', ob_get_contents());
    }
    finally {
      ob_end_clean();
    }
    $payload = json_decode((string) $history[0]['request']->getBody(), TRUE);
    self::assertSame('learner@example.com', $payload['email']);
    self::assertSame([7], $payload['listIds']);
    self::assertTrue($payload['updateEnabled']);
    self::assertSame(['FIRSTNAME' => 'Test', 'LASTNAME' => 'Learner'], $payload['attributes']);
    self::assertNull($handler->failure);
  }

  /**
   * A disabled key follows Webform's controlled error path.
   */
  public function testUnauthorizedResponseIsHandled(): void {
    $handler = $this->handler([new Response(401, [], '{"message":"API Key is not enabled","code":"unauthorized"}')]);
    $submission = $this->createMock(WebformSubmissionInterface::class);
    $handler->deliver('completed', $submission);
    self::assertSame(401, $handler->failure->getStatusCode());
    self::assertSame('completed', $handler->failureState);
    self::assertStringContainsString('HTTP 401', $handler->failureMessage);
    self::assertStringNotContainsString('private-test-key', $handler->failureMessage);
    self::assertStringNotContainsString('learner@example.com', $handler->failureMessage);
  }

  /**
   * Missing contact email is reported before attempting an API call.
   */
  public function testMissingEmailIsHandled(): void {
    $handler = $this->handler([]);
    $handler->data = [];
    $handler->deliver('completed', $this->createMock(WebformSubmissionInterface::class));
    self::assertSame(400, $handler->failure->getStatusCode());
  }

  /**
   * Missing keys still permit settings access and produce a controlled error.
   */
  public function testMissingKeyIsHandled(): void {
    $handler = $this->handler([], ['api_key' => '']);
    self::assertIsArray($handler->getSummary());
    $handler->deliver('completed', $this->createMock(WebformSubmissionInterface::class));
    self::assertSame(503, $handler->failure->getStatusCode());
  }

  /**
   * The migration hook preserves the existing plugin ID and handler options.
   */
  public function testLegacyDefinitionHandover(): void {
    require_once dirname(__DIR__, 3) . '/webform_brevo.module';
    $definitions = [
      'brevo' => ['class' => 'LegacyHandler', 'provider' => 'webform', 'id' => 'brevo', 'cardinality' => -1],
      'email' => ['class' => 'EmailHandler'],
    ];
    webform_brevo_webform_handler_info_alter($definitions);
    self::assertSame(WebformBrevoHandler::class, $definitions['brevo']['class']);
    self::assertSame('webform_brevo', $definitions['brevo']['provider']);
    self::assertSame('brevo', $definitions['brevo']['id']);
    self::assertSame(-1, $definitions['brevo']['cardinality']);
    self::assertSame(['class' => 'EmailHandler'], $definitions['email']);
  }

  /**
   * Only Webforms using the existing Brevo plugin are resaved on install.
   */
  public function testInstallationRefreshesAffectedFormsOnly(): void {
    require_once dirname(__DIR__, 3) . '/webform_brevo.install';
    $previous = \Drupal::hasContainer() ? \Drupal::getContainer() : NULL;
    $affected = $this->createMock(WebformInterface::class);
    $affected->method('get')->with('handlers')->willReturn([
      'contact_one' => ['id' => 'brevo', 'settings' => ['list_id' => 7]],
      'contact_two' => ['id' => 'brevo', 'settings' => ['list_id' => 8]],
    ]);
    $affected->expects(self::once())->method('save');
    $other = $this->createMock(WebformInterface::class);
    $other->method('get')->with('handlers')->willReturn(['email' => ['id' => 'email']]);
    $other->expects(self::never())->method('save');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn([$affected, $other]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('webform')->willReturn($storage);
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $manager);
    \Drupal::setContainer($container);
    try {
      webform_brevo_install();
    }
    finally {
      if ($previous !== NULL) {
        \Drupal::setContainer($previous);
      }
      else {
        \Drupal::unsetContainer();
      }
    }
  }

  private function handler(array $responses, array $settings = [], ?array &$history = NULL): BrevoHandlerHarness {
    $stack = HandlerStack::create(new MockHandler($responses));
    if ($history !== NULL) {
      $stack->push(Middleware::history($history));
    }
    $messages = $this->createMock(WebformMessageManagerInterface::class);
    $messages->method('render')->willReturn('Provider synchronization failed.');
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);
    $handler = new BrevoHandlerHarness(
      new BrevoContactClient(new Client(['handler' => $stack])),
      $messages,
      $account,
      $settings,
    );
    $webform = $this->createMock(WebformInterface::class);
    $webform->method('id')->willReturn('test_form');
    $handler->setWebform($webform);
    return $handler;
  }

}

/**
 * Isolates response handling from Webform rendering and token services.
 */
final class BrevoHandlerHarness extends WebformBrevoHandler {

  public array $data = ['email' => 'learner@example.com'];
  public ?ResponseInterface $failure = NULL;
  public string $failureMessage = '';
  public string $failureState = '';

  public function __construct(BrevoContactClient $client, WebformMessageManagerInterface $messages, AccountProxyInterface $account, array $settings) {
    $this->configuration = $settings + [
      'api_key' => 'private-test-key', 'list_id' => 7,
      'completed_do' => TRUE, 'updated_do' => FALSE, 'deleted_do' => FALSE,
      'debug' => FALSE, 'completed_custom_data' => '', 'updated_custom_data' => '',
      'deleted_custom_data' => '', 'custom_data' => '', 'message' => '',
      'messages' => [], 'error_url' => '', 'excluded_data' => [],
    ];
    $this->contactClient = $client;
    $this->messageManager = $messages;
    $this->currentUser = $account;
    $this->handler_id = 'brevo';
  }

  public function deliver(string $state, WebformSubmissionInterface $submission): void {
    $this->manageContact($state, $submission);
  }

  protected function getRequestData($state, WebformSubmissionInterface $submission) {
    return $this->data;
  }

  protected function handleError($state, $message, $request_url, $request_method, $request_type, $request_options, $response) {
    $this->failure = $response;
    $this->failureMessage = $message;
    $this->failureState = $state;
  }

  protected function elementTokenValidate(array &$form, array $token_types = ['webform', 'webform_submission', 'webform_handler']) {
    // Token rendering is owned and tested by Webform.
  }

  protected function t($string, array $args = [], array $options = []) {
    return strtr($string, $args);
  }

}
