<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Form;

use Drupal\commerce_lms_entitlements\ClickBankManager;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/** Collects the learner email before redirecting to ClickBank checkout. */
final class ClickBankCheckoutForm extends FormBase {
  public function __construct(private ClickBankManager $clickBank) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('commerce_lms_entitlements.clickbank'));
  }

  public function getFormId(): string {
    return 'commerce_lms_clickbank_checkout';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    if (!$this->clickBank->isConfigured()) {
      return ['#markup' => $this->t('ClickBank checkout is not available.')];
    }
    $form['intro'] = [
      '#markup' => $this->t('Who should receive access to the course? The learner may be different from the purchaser. You will complete payment securely with ClickBank.'),
    ];
    $form['learner_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Learner email address'),
      '#required' => TRUE,
      '#maxlength' => 254,
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Continue to ClickBank checkout'),
    ];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $token = $this->clickBank->createIntent((string) $form_state->getValue('learner_email'));
    $form_state->setResponse(new RedirectResponse($this->clickBank->paymentUrl($token), 303));
  }
}
