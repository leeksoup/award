<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Annotation\CommerceCheckoutPane;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Records the purchaser-designated learner before payment approval.
 *
 * @CommerceCheckoutPane(
 *   id = "commerce_lms_learner",
 *   label = @Translation("Learner"),
 *   default_step = "order_information",
 *   weight = 20
 * )
 */
final class LearnerPane extends CheckoutPaneBase {
  public function __construct(array $configuration, $plugin_id, $plugin_definition, private EntitlementManager $manager) { parent::__construct($configuration, $plugin_id, $plugin_definition); }
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static { return new static($configuration, $plugin_id, $plugin_definition, $container->get('commerce_lms_entitlements.manager')); }
  public function buildPaneForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $choice = $this->order->getData('commerce_lms_learner') ?: [];
    $form['email'] = ['#type' => 'email', '#title' => $this->t('Learner email'), '#description' => $this->t('Course access is assigned to this person; class placement is set by the offer.'), '#default_value' => $choice['email'] ?? '', '#required' => TRUE];
    return $form;
  }
  public function validatePaneForm(array $form, FormStateInterface $form_state, array &$complete_form): void { try { $this->manager->offerForOrder($this->order); } catch (\DomainException $e) { $form_state->setError($form, $e->getMessage()); } }
  public function submitPaneForm(array $form, FormStateInterface $form_state, array &$complete_form): void {
    $email = mb_strtolower(trim((string) $form_state->getValue('email'))); $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['mail' => $email]);
    if ($users) { $account = reset($users); $choice = ['email' => $email, 'uid' => (int) $account->id()]; }
    else { $invitation = $this->manager->createInvitation($email); $choice = ['email' => $email, 'invitation_id' => $invitation['id']]; $url = Url::fromRoute('commerce_lms_entitlements.claim', ['token' => $invitation['token']], ['absolute' => TRUE])->toString(); \Drupal::service('plugin.manager.mail')->mail('commerce_lms_entitlements', 'invitation', $email, \Drupal::languageManager()->getDefaultLanguage()->getId(), ['url' => $url]); }
    $this->order->setData('commerce_lms_learner', $choice); $this->order->save();
  }
}
