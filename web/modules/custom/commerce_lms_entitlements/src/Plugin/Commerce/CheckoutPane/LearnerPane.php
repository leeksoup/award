<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Annotation\CommerceCheckoutPane;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_lms_entitlements\EntitlementManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Records the purchaser-designated learner before payment approval.
 *
 * Existing Drupal users are stored directly by user ID. A new email address
 * receives a signed invitation and is stored by invitation ID until it is
 * claimed. The learner selection is order data so later subscription creation
 * and webhook processing can create the correct entitlement.
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
    private MailManagerInterface $mailManager,
    private LanguageManagerInterface $languageManager,
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
      $container->get('plugin.manager.mail'),
      $container->get('language_manager'),
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
        // Rebuilding or resubmitting checkout must not generate a new token or
        // send another invitation for an unchanged learner selection.
        $choice = $existing_choice;
      }
      else {
        $invitation = $this->manager->createInvitation($email);
        $choice = [
          'email' => $email,
          'invitation_id' => $invitation['id'],
        ];
        $url = Url::fromRoute(
          'commerce_lms_entitlements.claim',
          ['token' => $invitation['token']],
          ['absolute' => TRUE],
        )->toString();
        $this->mailManager->mail(
          'commerce_lms_entitlements',
          'invitation',
          $email,
          $this->languageManager->getDefaultLanguage()->getId(),
          ['url' => $url],
        );
      }
    }

    $this->order->setData('commerce_lms_learner', $choice);
    $this->order->save();
  }

}
