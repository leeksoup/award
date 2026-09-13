<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Form;

use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\user\LoginFinalizer;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets an invited learner create or claim the Drupal account for access.
 */
final class ClaimInvitationForm extends FormBase {

  /**
   * Constructs an invitation claim form.
   */
  public function __construct(
    private EntitlementManager $manager,
    private EntityTypeManagerInterface $entityTypeManager,
    private ?LoginFinalizer $loginFinalizer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('commerce_lms_entitlements.manager'),
      $container->get('entity_type.manager'),
      $container->has(LoginFinalizer::class)
        ? $container->get(LoginFinalizer::class)
        : NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_lms_entitlements_claim';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $token = NULL): array {
    $token = (string) $token;
    $invitation = $this->manager->invitation($token);
    if (!$invitation) {
      return [
        'message' => [
          '#markup' => $this->t('This invitation is invalid, expired, or already claimed.'),
        ],
      ];
    }

    $current_user = $this->currentUser();
    if (!$current_user->isAnonymous()
      && $this->normalizedEmail($current_user->getEmail()) !== $invitation['email']) {
      $claim_url = Url::fromRoute(
        'commerce_lms_entitlements.claim',
        ['token' => $token],
      );
      return [
        'message' => [
          '#markup' => $this->t(
            'This invitation is for %email, but you are currently signed in as %name. Log out to create or claim the learner account.',
            [
              '%email' => $invitation['email'],
              '%name' => $current_user->getAccountName(),
            ],
          ),
        ],
        'logout' => [
          '#type' => 'link',
          '#title' => $this->t('Log out and continue'),
          '#url' => Url::fromRoute('user.logout', [], [
            'query' => ['destination' => $claim_url->toString()],
          ]),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
      ];
    }

    $form['token'] = [
      '#type' => 'value',
      '#value' => $token,
    ];
    $form['email'] = [
      '#type' => 'item',
      '#title' => $this->t('Invitation email'),
      '#plain_text' => $invitation['email'],
    ];

    if ($current_user->isAnonymous()) {
      if ($this->loadAccountByEmail($invitation['email'])) {
        $claim_url = Url::fromRoute(
          'commerce_lms_entitlements.claim',
          ['token' => $token],
        );
        $form['message'] = [
          '#markup' => $this->t(
            'An account already exists for this invitation. Sign in normally to continue.',
          ),
        ];
        $form['login'] = [
          '#type' => 'link',
          '#title' => $this->t('Sign in to claim invitation'),
          '#url' => Url::fromRoute('user.login', [], [
            'query' => ['destination' => $claim_url->toString()],
          ]),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ];
        return $form;
      }

      $form['name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Username'),
        '#required' => TRUE,
      ];
      $form['password'] = [
        '#type' => 'password_confirm',
        '#required' => TRUE,
      ];
    }

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Claim invitation'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $invitation = $this->manager->invitation((string) $form_state->getValue('token'));
    if (!$invitation) {
      $form_state->setErrorByName(
        'token',
        $this->t('This invitation is invalid, expired, or already claimed.'),
      );
      return;
    }

    $current_user = $this->currentUser();
    if (!$current_user->isAnonymous()) {
      if ($this->normalizedEmail($current_user->getEmail()) !== $invitation['email']) {
        $form_state->setErrorByName(
          'token',
          $this->t('Sign in using the email address that received this invitation.'),
        );
      }
      return;
    }

    // Account existence is checked again during submission validation so an
    // account created after the form was built cannot use the invitation as a
    // passwordless login token.
    if ($this->loadAccountByEmail($invitation['email'])) {
      $form_state->setErrorByName(
        'token',
        $this->t('An account already exists for this invitation. Sign in normally to continue.'),
      );
      return;
    }

    $password_values = $form_state->getValue('password');
    $password = is_array($password_values)
      ? (string) ($password_values['pass1'] ?? '')
      : (string) $password_values;
    $account = $this->entityTypeManager->getStorage('user')->create([
      'name' => trim((string) $form_state->getValue('name')),
      'mail' => $invitation['email'],
      'pass' => $password,
      'status' => 1,
    ]);
    if (!$account instanceof UserInterface) {
      throw new \UnexpectedValueException('User storage did not create a user entity.');
    }

    foreach ($account->validate() as $violation) {
      $property_path = (string) $violation->getPropertyPath();
      if (str_starts_with($property_path, 'name')) {
        $element = 'name';
      }
      elseif (str_starts_with($property_path, 'pass')) {
        $element = 'password';
      }
      else {
        $element = 'token';
      }
      $form_state->setErrorByName($element, $violation->getMessage());
    }
    if (!$form_state->hasAnyErrors()) {
      $form_state->set('commerce_lms_entitlements_new_account', $account);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $token = (string) $form_state->getValue('token');
    $invitation = $this->manager->invitation($token);
    if (!$invitation) {
      $this->messenger()->addError(
        $this->t('This invitation is invalid, expired, or already claimed.'),
      );
      return;
    }

    $account = $this->currentUser();
    $created_account = FALSE;
    if ($account->isAnonymous()) {
      $account = $form_state->get('commerce_lms_entitlements_new_account');
      if (!$account instanceof UserInterface) {
        $this->messenger()->addError(
          $this->t('The learner account could not be validated. Please try again.'),
        );
        return;
      }
      $account->save();
      $created_account = TRUE;
    }

    if ($this->normalizedEmail($account->getEmail()) !== $invitation['email']) {
      $this->messenger()->addError(
        $this->t('Sign in using the email address that received this invitation.'),
      );
      return;
    }
    if (!$this->manager->claimInvitation($token, (int) $account->id(), $account->getEmail())) {
      $this->messenger()->addError(
        $this->t('This invitation is invalid, expired, or already claimed.'),
      );
      return;
    }

    if ($created_account) {
      if ($this->loginFinalizer) {
        $this->loginFinalizer->finalizeLogin($account);
      }
      else {
        user_login_finalize($account);
      }
    }
    if ($this->manager->invitationHasActiveAccess($invitation['id'])) {
      $this->messenger()->addStatus($this->t('Your course access has been claimed.'));
      $form_state->setRedirectUrl(Url::fromUserInput('/courses'));
    }
    else {
      $this->messenger()->addStatus($this->t(
        'Your learner account has been claimed. Course access will become available after the subscription payment is activated.',
      ));
      $form_state->setRedirectUrl(Url::fromRoute('user.page'));
    }
  }

  /**
   * Loads the account for a normalized invitation email, when one exists.
   */
  private function loadAccountByEmail(string $email): ?UserInterface {
    $accounts = $this->entityTypeManager
      ->getStorage('user')
      ->loadByProperties(['mail' => $this->normalizedEmail($email)]);
    $account = $accounts ? reset($accounts) : NULL;
    return $account instanceof UserInterface ? $account : NULL;
  }

  /**
   * Normalizes an email address for invitation ownership comparisons.
   */
  private function normalizedEmail(string $email): string {
    return mb_strtolower(trim($email));
  }

}
