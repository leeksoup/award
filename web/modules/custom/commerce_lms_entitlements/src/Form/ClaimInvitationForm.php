<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Form;

use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Lets an invited learner create or claim the Drupal account for access. */
final class ClaimInvitationForm extends FormBase {
  public function __construct(private EntitlementManager $manager) {}
  public static function create(ContainerInterface $container): static { return new static($container->get('commerce_lms_entitlements.manager')); }
  public function getFormId(): string { return 'commerce_lms_entitlements_claim'; }
  public function buildForm(array $form, FormStateInterface $form_state, $token = NULL): array {
    $invitation = $this->manager->invitation((string) $token);
    if (!$invitation) {
      return ['message' => ['#markup' => $this->t('This invitation is invalid, expired, or already claimed.')]];
    }
    $form['token'] = ['#type' => 'value', '#value' => (string) $token];
    $form['email'] = ['#type' => 'item', '#title' => $this->t('Invitation email'), '#markup' => $invitation['email']];
    if ($this->currentUser()->isAnonymous()) { $form['name'] = ['#type' => 'textfield', '#title' => $this->t('Username'), '#required' => TRUE]; $form['mail'] = ['#type' => 'value', '#value' => $invitation['email']]; $form['password'] = ['#type' => 'password_confirm', '#required' => TRUE]; }
    $form['submit'] = ['#type' => 'submit', '#value' => $this->t('Claim invitation')]; return $form;
  }
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $account = $this->currentUser();
    $invitation = $this->manager->invitation((string) $form_state->getValue('token'));
    if (!$invitation) { $this->messenger()->addError($this->t('This invitation is invalid, expired, or already claimed.')); return; }
    if ($account->isAnonymous()) { $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['mail' => $invitation['email']]); $account = $users ? reset($users) : \Drupal::entityTypeManager()->getStorage('user')->create(['name' => $form_state->getValue('name'), 'mail' => $invitation['email'], 'pass' => $form_state->getValue('password'), 'status' => 1]); if ($account->isNew()) { $account->save(); } }
    if (mb_strtolower($account->getEmail()) !== $invitation['email']) { $this->messenger()->addError($this->t('Sign in using the email address that received this invitation.')); return; }
    if ($this->manager->claimInvitation($form_state->getValue('token'), (int) $account->id(), $account->getEmail())) { $this->messenger()->addStatus($this->t('Your course access has been claimed.')); }
    else { $this->messenger()->addError($this->t('This invitation is invalid, expired, or already claimed.')); }
  }
}
