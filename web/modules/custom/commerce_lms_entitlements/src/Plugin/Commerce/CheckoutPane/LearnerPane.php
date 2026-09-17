<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Annotation\CommerceCheckoutPane;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Records the purchaser-designated learner before payment approval.
 *
 * Existing Drupal users are stored directly by user ID. A new email address
 * remains order-scoped until successful payment activates the entitlement;
 * only then is its signed invitation created and sent. The learner selection
 * is order data so later subscription creation and webhook processing can
 * create the correct entitlement.
 *
 * @CommerceCheckoutPane(
 *   id = "commerce_lms_learner",
 *   label = @Translation("Learner"),
 *   default_step = "order_information",
 *   weight = 20
 * )
 */
final class LearnerPane extends CheckoutPaneBase implements ContainerFactoryPluginInterface {

  /** Constructs the learner checkout pane. */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    CheckoutFlowInterface $checkout_flow,
    EntityTypeManagerInterface $entity_type_manager,
    private EntitlementManager $manager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $checkout_flow, $entity_type_manager);
  }

  /** {@inheritdoc} */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?CheckoutFlowInterface $checkout_flow = NULL): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $checkout_flow,
      $container->get('entity_type.manager'),
      $container->get('commerce_lms_entitlements.manager'),
    );
  }

  /** {@inheritdoc} */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form): array {
    $choice = $this->order->getData('commerce_lms_learner') ?: [];
    $pane_form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Learner email'),
      '#description' => $this->t('Course access is assigned to this person; class placement is set by the offer.'),
      '#default_value' => $choice['email'] ?? '',
      '#required' => TRUE,
    ];
    return $pane_form;
  }

  /** {@inheritdoc} */
  public function validatePaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    try {
      $this->manager->offerForOrder($this->order);
    }
    catch (\DomainException $e) {
      $form_state->setError($pane_form, $e->getMessage());
    }

    $values = $form_state->getValue($pane_form['#parents']);
    if (trim((string) ($values['email'] ?? '')) === '') {
      $form_state->setError($pane_form['email'], $this->t('Enter the learner email address.'));
    }
  }

  /** {@inheritdoc} */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form): void {
    // Checkout-pane values are nested below the pane's #parents. Reading a
    // top-level "email" returns NULL and previously sent invitations with an
    // empty recipient.
    $values = $form_state->getValue($pane_form['#parents']);
    $email = mb_strtolower(trim((string) ($values['email'] ?? '')));
    if ($email === '') {
      // Required-element validation should make this unreachable. Keep the
      // guard so a future form-layout change cannot send blank-address mail.
      throw new \UnexpectedValueException('The learner email is missing from checkout submission values.');
    }

    $users = $this->entityTypeManager->getStorage('user')->loadByProperties([
      'mail' => $email,
    ]);
    if ($users) {
      $account = reset($users);
      $choice = [
        'email' => $email,
        'uid' => (int) $account->id(),
      ];
    }
    else {
      $existing_choice = $this->order->getData('commerce_lms_learner') ?: [];
      if (($existing_choice['email'] ?? '') === $email && !empty($existing_choice['invitation_id'])) {
        // Preserve invitations issued by releases that sent them before
        // payment. Activation recognizes these as already delivered and does
        // not send a duplicate.
        $choice = $existing_choice;
      }
      else {
        // Do not create or send an invitation for an abandoned checkout.
        $choice = ['email' => $email];
      }
    }

    $this->order->setData('commerce_lms_learner', $choice);
    $this->order->save();
  }

}
