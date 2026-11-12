<?php

declare(strict_types=1);

namespace Drupal\webform_brevo\Plugin\WebformHandler;

use Brevo\Client\Model\CreateContact;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Element\WebformMessage;
use Drupal\webform\Plugin\WebformHandler\RemotePostWebformHandler;
use Drupal\webform\WebformMessageManagerInterface;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform_brevo\BrevoContactClient;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends selected submission states to a Brevo contact list.
 *
 * @WebformHandler(
 *   id = "brevo",
 *   label = @Translation("Brevo"),
 *   category = @Translation("External"),
 *   description = @Translation("Adds or updates a contact in a Brevo list."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 *   tokens = TRUE
 * )
 */
class WebformBrevoHandler extends RemotePostWebformHandler {

  /**
   * The contact API adapter.
   */
  protected BrevoContactClient $contactClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->contactClient = $container->get('webform_brevo.contact_client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'api_key' => '',
      'list_id' => -1,
      'completed_do' => TRUE,
      'updated_do' => FALSE,
      'deleted_do' => FALSE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary() {
    // Summaries must remain available even when the key is disabled or Brevo
    // is unavailable. Keep credentials out of the summary render array.
    return [
      'list' => ['#plain_text' => $this->t('Brevo list ID: @id', ['@id' => (string) $this->configuration['list_id']])],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $webform = $this->getWebform();

    // Brevo settings.
    $form['brevo'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Brevo Settings'),
    ];
    // User inputs API key.
    $form['brevo']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Your Brevo API key'),
      '#description' => $this->t('The version 3 API key from Brevo developer site.'),
      '#default_value' => $this->configuration['api_key'],
      '#pattern' => '(xkeysib\-).+',
      '#required' => TRUE,
    ];
    $form['brevo']['list_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Brevo list ID'),
      '#description' => $this->t('Enter the ID# of the Brevo list you want to add contacts from this sign-up form to.'),
      '#default_value' => $this->configuration['list_id'],
      '#pattern' => '^\d+$',
      '#required' => TRUE,
    ];

    // States.
    $states = [
      WebformSubmissionInterface::STATE_COMPLETED => [
        'state' => $this->t('completed'),
        'label' => $this->t('Completed'),
        'description' => $this->t('Call Brevo API when <b>submission is completed</b>.'),
        'access' => TRUE,
      ],
      WebformSubmissionInterface::STATE_UPDATED => [
        'state' => $this->t('updated'),
        'label' => $this->t('Updated'),
        'description' => $this->t('Call Brevo API when <b>submission is updated</b>.'),
        'access' => $this->isResultsEnabled(),
      ],
      WebformSubmissionInterface::STATE_DELETED => [
        'state' => $this->t('deleted'),
        'label' => $this->t('Deleted'),
        'description' => $this->t('Call Brevo API when <b>submission is deleted</b>.'),
        'access' => $this->isResultsEnabled(),
      ],
    ];
    foreach ($states as $state => $state_item) {
      $state_do = $state . '_do';
      $state_custom_data = $state . '_custom_data';
      $t_args = [
        '@state' => $state_item['state'],
        '@title' => $state_item['label'],
      ];
      $form[$state] = [
        '#type' => 'details',
        '#open' => ($state === WebformSubmissionInterface::STATE_COMPLETED),
        '#title' => $state_item['label'],
        '#description' => $state_item['description'],
        '#access' => $state_item['access'],
      ];
      $form[$state][$state_do] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Send to Brevo if @title', $t_args),
        '#description' => $this->t('Add / update the subscriber to Brevo list when the webform submission is @state.', $t_args),
        '#required' => FALSE,
        '#default_value' => $this->configuration[$state_do],
      ];
      $form[$state][$state_custom_data] = [
        '#type' => 'webform_codemirror',
        '#mode' => 'yaml',
        '#title' => $this->t('@title custom data', $t_args),
        '#description' => $this->t('Enter custom data that will be included when a webform submission is @state.', $t_args),
        '#states' => ['visible' => [':input[name="settings[' . $state_do . ']"]' => 1]],
        '#default_value' => $this->configuration[$state_custom_data],
      ];
      if ($state === WebformSubmissionInterface::STATE_COMPLETED) {
        $form[$state]['token'] = [
          '#type' => 'webform_message',
          '#message_message' => $this->t('Response data can be passed to the submission data using [webform:handler:{machine_name}:{state}:{key}] tokens. (i.e. [webform:handler:brevo:completed:id])'),
          '#message_type' => 'info',
        ];
      }
    }

    // Additional.
    $form['additional'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Additional settings'),
    ];
    $form['additional']['custom_data'] = [
      '#type' => 'webform_codemirror',
      '#mode' => 'yaml',
      '#title' => $this->t('Custom data'),
      '#description' => $this->t('Enter custom data used to map submission fields to the Brevo contact.'),
      '#default_value' => $this->configuration['custom_data'],
    ];
    $form['additional']['message'] = [
      '#type' => 'webform_html_editor',
      '#title' => $this->t('Custom error response message'),
      '#description' => $this->t('This message is displayed when the response status code is not 2xx.') . '<br/><br/>' . $this->t('Defaults to: %value', ['%value' => $this->messageManager->render(WebformMessageManagerInterface::SUBMISSION_EXCEPTION_MESSAGE)]),
      '#default_value' => $this->configuration['message'],
    ];
    $form['additional']['messages_token'] = [
      '#type' => 'webform_message',
      '#message_message' => $this->t('Response data can be passed to response messages using [webform:handler:{machine_name}:{key}] tokens. (i.e. [webform:handler:brevo:message])'),
      '#message_type' => 'info',
    ];
    $form['additional']['messages'] = [
      '#type' => 'webform_multiple',
      '#title' => $this->t('Custom response messages'),
      '#description' => $this->t('Enter custom response messages for specific status codes.'),
      '#empty_items' => 0,
      '#no_items_message' => $this->t('No error response messages entered. Please add messages below.'),
      '#add' => FALSE,
      '#element' => [
        'code' => [
          '#type' => 'webform_select_other',
          '#title' => $this->t('Response status code'),
          '#options' => [
            '200' => $this->t('200 OK'),
            '201' => $this->t('201 Created'),
            '204' => $this->t('204 No Content'),
            '400' => $this->t('400 Bad Request'),
            '401' => $this->t('401 Unauthorized'),
            '403' => $this->t('403 Forbidden'),
            '404' => $this->t('404 Not Found'),
            '500' => $this->t('500 Internal Server Error'),
            '502' => $this->t('502 Bad Gateway'),
            '503' => $this->t('503 Service Unavailable'),
            '504' => $this->t('504 Gateway Timeout'),
          ],
          '#other__type' => 'number',
          '#other__description' => $this->t('<a href="https://en.wikipedia.org/wiki/List_of_HTTP_status_codes">List of HTTP status codes</a>.'),
        ],
        'message' => [
          '#type' => 'webform_html_editor',
          '#title' => $this->t('Response message'),
        ],
      ],
      '#default_value' => $this->configuration['messages'],
    ];
    $form['additional']['error_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom error response redirect URL'),
      '#description' => $this->t('The URL or path to redirect to when synchronization fails.'),
      '#default_value' => $this->configuration['error_url'],
      '#pattern' => '(https?:\/\/|\/).+',
    ];

    // Development.
    $form['development'] = [
      '#type' => 'details',
      '#title' => $this->t('Development settings'),
    ];
    $form['development']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debugging'),
      '#description' => $this->t('Show Brevo response details to users who can administer webforms.'),
      '#return_value' => TRUE,
      '#default_value' => $this->configuration['debug'],
    ];

    // Submission data.
    $form['submission_data'] = [
      '#type' => 'details',
      '#title' => $this->t('Submission data'),
    ];
    // Display warning about file uploads.
    if ($this->getWebform()->hasManagedFile()) {
      $form['submission_data']['managed_file_message'] = [
        '#type' => 'webform_message',
        '#message_message' => $this->t('Upload files will include the file\'s id, name, uri, and data (<a href=":href">Base64</a> encode).', [':href' => 'https://en.wikipedia.org/wiki/Base64']),
        '#message_type' => 'warning',
        '#message_close' => TRUE,
        '#message_id' => 'webform_node.references',
        '#message_storage' => WebformMessage::STORAGE_SESSION,
      ];
    }
    $form['submission_data']['excluded_data'] = [
      '#type' => 'webform_excluded_columns',
      '#title' => $this->t('Posted data'),
      '#title_display' => 'invisible',
      '#webform_id' => $webform->id(),
      '#required' => TRUE,
      '#default_value' => $this->configuration['excluded_data'],
    ];

    $this->elementTokenValidate($form);

    return $this->setSettingsParents($form);
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $list_id = (string) $form_state->getValue('list_id');
    if (!ctype_digit($list_id) || (int) $list_id < 1) {
      $form_state->setErrorByName('list_id', $this->t('Enter a positive Brevo list ID.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->configuration['list_id'] = (int) $this->configuration['list_id'];
    foreach (['completed_do', 'updated_do', 'deleted_do'] as $key) {
      $this->configuration[$key] = (bool) $this->configuration[$key];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $state = $webform_submission->getWebform()->getSetting('results_disabled')
      ? WebformSubmissionInterface::STATE_COMPLETED
      : $webform_submission->getState();
    $this->manageContact($state, $webform_submission);
  }

  /**
   * {@inheritdoc}
   */
  public function postDelete(WebformSubmissionInterface $webform_submission) {
    $this->manageContact(WebformSubmissionInterface::STATE_DELETED, $webform_submission);
  }

  /**
   * Sends only states enabled in this handler's existing configuration.
   */
  protected function manageContact(string $state, WebformSubmissionInterface $submission): void {
    if (empty($this->configuration[$state . '_do'])) {
      return;
    }
    $this->setWebformSubmission($submission);
    $this->messageManager->setWebformSubmission($submission);
    $data = $this->getRequestData($state, $submission);
    if (empty($data['email']) || !is_string($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)
      || (int) $this->configuration['list_id'] < 1) {
      $this->reportFailure($state, new Response(400, [], '{"code":"invalid_contact_configuration"}'));
      return;
    }
    $attributes = [
      'FIRSTNAME' => $data['firstname'] ?? $data['first_name'] ?? '',
      'LASTNAME' => $data['lastname'] ?? $data['last_name'] ?? '',
    ];
    // Excluded metadata is optional. Do not read missing keys or invent
    // values for fields the administrator chose to omit.
    foreach (['sid' => 'SID', 'uri' => 'FROM_FORM', 'remote_addr' => 'USER_IP'] as $key => $attribute) {
      if (isset($data[$key])) {
        $attributes[$attribute] = $data[$key];
      }
    }
    if (!empty($data['completed'])) {
      $attributes['SUBMIT_DATE'] = date('Y-m-d', (int) $data['completed']);
    }
    $contact = new CreateContact([
      'email' => $data['email'],
      'updateEnabled' => TRUE,
      'attributes' => $attributes,
      'listIds' => [(int) $this->configuration['list_id']],
    ]);
    $response = $this->contactClient->upsert((string) $this->configuration['api_key'], $contact);
    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
      $this->reportFailure($state, $response);
      return;
    }
    $this->displayCustomResponseMessage($response, FALSE);
    $this->debug($this->t('Brevo contact synchronized.'), $state, 'https://api.brevo.com/v3/contacts', 'POST', 'json', [], $response, 'status');
    $submission_data = $submission->getData();
    if (str_contains(print_r($submission_data, TRUE), '[webform:handler:' . $this->getHandlerId() . ':')) {
      $token_data = ['webform_handler' => [$this->getHandlerId() => [$state => $this->getResponseData($response)]]];
      $submission->setData($this->replaceTokens($submission_data, $submission, $token_data));
      if ($this->isResultsEnabled()) {
        $submission->resave();
      }
    }
  }

  /**
   * Delegates messages and redirects to Webform's maintained error path.
   */
  protected function reportFailure(string $state, ResponseInterface $response): void {
    $message = (string) $this->t('Brevo could not synchronize this submission (HTTP @status).', ['@status' => $response->getStatusCode()]);
    // No request headers, API key, contact body, or SDK exception message is
    // passed to the logger or generic user-facing message.
    $this->handleError($state, $message, 'https://api.brevo.com/v3/contacts', 'POST', 'json', [], $response);
  }

  /**
   * {@inheritdoc}
   */
  protected function debug($message, $state, $request_url, $request_method, $request_type, $request_options, ?ResponseInterface $response = NULL, $type = 'warning') {
    if (!$this->currentUser->hasPermission('administer webform')) {
      return;
    }
    parent::debug($message, $state, $request_url, $request_method, $request_type, $request_options, $response, $type);
  }

}
