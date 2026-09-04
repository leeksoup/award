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
    $form['token'] = ['#type' => 'value', '#value' => (string) $token];
    if ($this->currentUser()->isAnonymous()) { $form['name'] = ['#type' => 'textfield', '#title' => $this->t('Username'), '#required' => TRUE]; $form['mail'] = ['#type' => 'email', '#title' => $this->t('Email'), '#required' => TRUE]; $form['password'] = ['#type' => 'password_confirm', '#required' => TRUE]; }
    $form['submit'] = ['#type' => 'submit', '#value' => $this->t('Claim invitation')]; return $form;
  }
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $account = $this->currentUser();
    if ($account->isAnonymous()) { $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['mail' => mb_strtolower($form_state->getValue('mail'))]); $account = $users ? reset($users) : \Drupal::entityTypeManager()->getStorage('user')->create(['name' => $form_state->getValue('name'), 'mail' => $form_state->getValue('mail'), 'pass' => $form_state->getValue('password'), 'status' => 1]); if ($account->isNew()) { $account->save(); } }
    if ($this->manager->claimInvitation($form_state->getValue('token'), (int) $account->id())) { $this->messenger()->addStatus($this->t('Your course access has been claimed.')); }
    else { $this->messenger()->addError($this->t('This invitation is invalid, expired, or already claimed.')); }
  }
}
