<?php

declare(strict_types=1);

namespace Drupal\lms\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AlertCommand;
use Drupal\Core\Ajax\CloseDialogCommand;
use Drupal\Core\Ajax\CommandInterface;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\OpenDialogCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\UpdateBuildIdCommand;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormAjaxResponseBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\lms\ModalSubformManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Subform AJAX endpoint.
 */
final class ModalSubformController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly ModalSubformManager $modalSubformManager,
    private readonly AccountInterface $currentUser,
    private readonly FormAjaxResponseBuilderInterface $formAjaxResponseBuilder,
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirableFactory,
    private readonly RequestStack $requestStack,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('plugin.manager.modal_subform'),
      $container->get('current_user'),
      $container->get('form_ajax_response_builder'),
      $container->get('keyvalue.expirable'),
      $container->get('request_stack'),
      $container->get('logger.channel.modal_form')
    );
  }

  /**
   * Endpoint callback.
   */
  public function endpoint(Request $request): AjaxResponse {
    $query = $request->query->all();
    $input = $request->request->all();

    // Form plugin ID must be included in the query.
    if (!\array_key_exists('plugin_id', $query)) {
      return $this->errorOutput('Query is missing the plugin_id parameter.');
    }
    $plugin_id = $query['plugin_id'];
    unset($query['plugin_id']);

    // Set values of one-time query parameters.
    $dialog_operation = '';
    if (\array_key_exists('dialog_operation', $query)) {
      if (!\in_array($query['dialog_operation'], [
        'open',
        'replace',
        'close',
      ], TRUE)) {
        return $this->errorOutput('Invalid dialog_operation parameter: %value.', [
          '%value' => Json::encode($query['dialog_operation']),
        ]);
      }
      $dialog_operation = $query['dialog_operation'];
      // Cache parent form input if this is a parent form open dialog
      // request and parent form parameters are included.
      if ($dialog_operation === 'open' && \array_key_exists('parent', $query)) {
        if (!\array_key_exists('form_build_id', $input)) {
          return $this->errorOutput('Parent form build ID missing from input when opening dialog.');
        }

        $this->cacheInput($input);
        $query['parent']['form_build_id'] = $input['form_build_id'];
      }
      unset($query['dialog_operation']);
    }
    $rebuild_parent = FALSE;
    if (\array_key_exists('rebuild_parent', $query)) {
      $rebuild_parent = (bool) $query['rebuild_parent'];
      unset($query['rebuild_parent']);
    }

    // Build / submit modal subform.
    if (!$this->modalSubformManager->hasDefinition($plugin_id)) {
      return $this->errorOutput('Invalid subform plugin ID: %id. Available plugins: %plugins', [
        '%id' => $plugin_id,
        '%plugins' => \implode(', ', \array_keys($this->modalSubformManager->getDefinitions())),
      ]);
    }

    try {
      $configuration = $query;
      $configuration['input'] = $input;
      $plugin = $this->modalSubformManager->createInstance($plugin_id, $configuration);
      if (!$plugin->access($this->currentUser)) {
        return $this->errorOutput('Subform access denied.');
      }
      $form = $plugin->buildForm([
        'build_info' => [
          'query' => $query,
          'input' => $input,
        ],
      ]);
      $form['cache']['#max-age'] = 0;
      $form['#build_id_old'] = $input['form_build_id'];
    }
    catch (\InvalidArgumentException $e) {
      return $this->errorOutput($e->getMessage());
    }

    $form_state = $plugin->getFormState();
    if (
      $form_state !== NULL &&
      $form_state->isProcessingInput()
    ) {
      $response = $this->formAjaxResponseBuilder->buildResponse($request, $form, $form_state, []);
      if ($form_state->hasAnyErrors()) {
        $form['status_messages'] = [
          '#type' => 'status_messages',
          '#weight' => -1000,
        ];
        $rebuild_parent = FALSE;
        $dialog_operation = 'replace';
      }
    }
    else {
      $response = new AjaxResponse();
    }

    // Rebuild parent form if needed.
    if ($rebuild_parent) {
      if (!\array_key_exists('plugin_id', $query['parent'])) {
        return $this->errorOutput('Query is missing the parent plugin_id parameter.');
      }
      try {
        // User input of the parent form was cached when the dialog was
        // opened. Now we need to retrieve that input to rebuild the parent
        // form properly.
        $parent_input = $this->getCachedInput($query['parent']['form_build_id']);
        if ($parent_input === NULL) {
          return $this->errorOutput('Form cache not found.', [], $this->t('The form you are working on became stale, please reload the page and try again'));
        }
        $this->requestStack->push($request->duplicate($query, $parent_input));
        $configuration = $query['parent'];
        $configuration['input'] = $parent_input;
        $parent_plugin = $this->modalSubformManager->createInstance($query['parent']['plugin_id'], $configuration);
        if (!$parent_plugin->access($this->currentUser)) {
          return $this->errorOutput('Parent form access denied: query: %query.', [
            '%query' => Json::encode($query['parent']),
          ]);
        }
        $parent_form = $parent_plugin->buildForm([
          'rebuild_info' => [
            'modal_form_data' => $plugin->getSubmissionData(),
          ],
          'skip_validation' => TRUE,
        ]);
      }
      catch (\InvalidArgumentException $e) {
        return $this->errorOutput($e->getMessage());
      }

      $parent_form['#build_id_old'] = $query['parent']['form_build_id'];
      $response = $this->getAjaxResponse($parent_form, $parent_plugin->getFormState(), $response);
    }

    if ($dialog_operation === '') {
      return $response;
    }

    $dialog_id = $plugin->getDialogId();
    $title = $plugin->getTitle();
    $dialog_class = \substr($dialog_id, 1);
    $title_class = $dialog_class . '-title';
    if ($dialog_operation === 'open') {
      $response->addCommand(new OpenDialogCommand(
        $dialog_id,
        $title,
        $form,
        [
          'modal' => TRUE,
          'width' => '80%',
          'classes' => [
            // Add class to the container as well for selective styling.
            'ui-dialog' => $dialog_class,
            // The only way we can modify dialog title (titlebar is not
            // a child of the element with the modal ID).
            'ui-dialog-titlebar' => $title_class,
          ],
        ]
      ));
    }
    elseif ($dialog_operation === 'replace') {
      $response->addCommand(new HtmlCommand('.' . $title_class . ' > .ui-dialog-title', $title));
      $response->addCommand(new ReplaceCommand($dialog_id . ' > form', $form));
    }
    elseif ($dialog_operation === 'close') {
      $response->addCommand(new CloseDialogCommand($dialog_id));
    }

    return $response;
  }

  /**
   * Act on error.
   *
   * @todo Log and return a more general message.
   */
  private function errorOutput(string $log_message, array $arguments = [], ?TranslatableMarkup $message = NULL): AjaxResponse {
    $this->logger->error($log_message, $arguments);
    if ($message === NULL) {
      $message = $this->t('Unexpected error occurred, please contact the site administrator.');
    }
    $response = new AjaxResponse();
    $response->addCommand(new AlertCommand((string) $message));
    return $response;
  }

  /**
   * Get AJAX response.
   */
  private function getAjaxResponse(array $form, FormState $form_state, ?AjaxResponse $response = NULL): AjaxResponse {
    if ($response === NULL) {
      $response = new AjaxResponse();
    }

    if (
      \array_key_exists('#build_id_old', $form) &&
      $form['#build_id_old'] !== $form['#build_id']
    ) {
      $response->addCommand(new UpdateBuildIdCommand($form['#build_id_old'], $form['#build_id']));
    }

    $trigger = $form_state->getTriggeringElement();
    if (
      \is_array($trigger) &&
      \array_key_exists('#ajax', $trigger) &&
      \array_key_exists('callback', $trigger['#ajax'])
    ) {
      $callback = $form_state->prepareCallback($trigger['#ajax']['callback']);
      if (!\is_callable($callback)) {
        return $this->errorOutput('Invalid form callback.');
      }
      $form_response = \call_user_func_array($callback, [$form, $form_state]);
      if (!\is_array($form_response)) {
        return $this->errorOutput('Invalid form callback.');
      }
      // @todo track https://www.drupal.org/project/drupal/issues/3343670
      // so we can be consistent and make this parent form callback be
      // consistent with standard callbacks and return an AjaxResponse
      // to merge with the existing one instead of an array of commands.
      foreach ($form_response as $command) {
        if (!$command instanceof CommandInterface) {
          return $this->errorOutput('Invalid form callback.');
        }
        $response->addCommand($command);
      }
    }

    return $response;
  }

  /**
   * Cache parent form input.
   *
   * Similar to caching a form but in this case we're caching input only.
   *
   * @see \Drupal\Core\Form\FormCache::setCache()
   */
  private function cacheInput(array $input): void {
    $cache = $this->keyValueExpirableFactory->get('lms_parent_form');
    if ($cache->has($input['form_build_id'])) {
      return;
    }

    $this->keyValueExpirableFactory->get('lms_parent_form')->setWithExpire(
      $input['form_build_id'],
      $input,
      Settings::get('form_cache_expiration', 21600)
    );
  }

  /**
   * Get parent form input.
   */
  private function getCachedInput(string $form_build_id): ?array {
    $cache = $this->keyValueExpirableFactory->get('lms_parent_form');
    $input = $cache->get($form_build_id);
    if (!\is_array($input)) {
      return NULL;
    }

    // The cached input is used only once to rebuild the parent form so it
    // can be deleted from the database. Form build ID will get regenerated
    // at the same time making it more obsolete.
    $cache->delete($form_build_id);

    return $input;
  }

}
