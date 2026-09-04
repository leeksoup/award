<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/** Administrative editor for an offer's fixed gateway and Course/Class targets. */
final class OfferForm extends EntityForm {
  public function form(array $form, FormStateInterface $form_state): array {
    $offer = $this->entity;
    $lines = array_map(static fn (array $item): string => $item['course_id'] . ':' . $item['class_id'], $offer->getCourseClassMap());
    $form['label'] = ['#type' => 'textfield', '#title' => $this->t('Name'), '#default_value' => $offer->label(), '#required' => TRUE];
    $form['id'] = ['#type' => 'machine_name', '#default_value' => $offer->id(), '#machine_name' => ['exists' => '\\Drupal::entityTypeManager()->getStorage("commerce_lms_offer")->load'], '#disabled' => !$offer->isNew()];
    $form['variation_id'] = ['#type' => 'number', '#title' => $this->t('Commerce variation ID'), '#default_value' => $offer->get('variation_id'), '#min' => 1, '#required' => TRUE];
    $form['purchase_type'] = ['#type' => 'radios', '#title' => $this->t('Purchase type'), '#options' => ['recurring' => $this->t('Recurring'), 'lifetime' => $this->t('Lifetime one-time purchase')], '#default_value' => $offer->getPurchaseType(), '#required' => TRUE];
    $form['payment_gateway_id'] = ['#type' => 'textfield', '#title' => $this->t('Payment gateway ID'), '#description' => $this->t('The only Commerce gateway allowed for this offer.'), '#default_value' => $offer->getPaymentGatewayId(), '#required' => TRUE];
    $form['paypal_plan_id'] = ['#type' => 'textfield', '#title' => $this->t('PayPal plan ID'), '#description' => $this->t('Required for recurring offers; ignored for lifetime purchases.'), '#default_value' => $offer->getPayPalPlanId()];
    $form['course_class_map'] = ['#type' => 'textarea', '#title' => $this->t('Course to Class mapping'), '#description' => $this->t('One administrator-selected target per line as COURSE_ID:CLASS_ID. The Class must be a child of the Course.'), '#default_value' => implode("\n", $lines), '#required' => TRUE];
    return parent::form($form, $form_state);
  }
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $map = [];
    foreach (preg_split('/\R/', trim($form_state->getValue('course_class_map'))) as $line) {
      if (!preg_match('/^\s*(\d+)\s*:\s*(\d+)\s*$/', $line, $matches)) { $form_state->setErrorByName('course_class_map', $this->t('Each target must use COURSE_ID:CLASS_ID.')); return; }
      $map[] = ['course_id' => (int) $matches[1], 'class_id' => (int) $matches[2]];
    }
    if ($form_state->getValue('purchase_type') === 'recurring' && trim($form_state->getValue('paypal_plan_id')) === '') { $form_state->setErrorByName('paypal_plan_id', $this->t('A recurring offer needs a PayPal plan ID.')); }
    $form_state->set('course_class_map', $map);
  }
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    foreach (['label', 'id', 'payment_gateway_id'] as $key) { $this->entity->set($key, trim((string) $form_state->getValue($key))); }
    $this->entity->set('variation_id', (int) $form_state->getValue('variation_id'));
    $this->entity->set('purchase_type', $form_state->getValue('purchase_type'));
    $this->entity->set('paypal_plan_id', trim((string) $form_state->getValue('paypal_plan_id')));
    $this->entity->set('course_class_map', $form_state->get('course_class_map'));
    parent::submitForm($form, $form_state);
  }
}
