<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Form;

use Drupal\commerce_lms_entitlements\VipBookingManager;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Lets an entitled learner book or change one session in a month. */
final class VipBookingForm extends FormBase {

  protected VipBookingManager $bookings;
  protected DateFormatterInterface $dateFormatter;

  public function __construct(VipBookingManager $bookings, DateFormatterInterface $date_formatter) {
    $this->bookings = $bookings;
    $this->dateFormatter = $date_formatter;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('commerce_lms_entitlements.vip_booking_manager'), $container->get('date.formatter'));
  }

  public function getFormId(): string { return 'commerce_lms_entitlements_vip_booking'; }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $options = [];
    foreach ($this->bookings->availableInstances((int) $this->currentUser()->id()) as $id => $item) {
      $spaces = $item['capacity'] < 0 ? $this->t('unlimited spaces') : $this->formatPlural($item['capacity'], '1 space', '@count spaces');
      $options[$id] = $this->t('@session — @date — @spaces', [
        '@session' => $item['entity']->label(),
        '@date' => $this->dateFormatter->format($item['start'], 'long'),
        '@spaces' => $spaces,
      ]);
    }
    $form['eventinstance_id'] = ['#type' => 'select', '#title' => $this->t('Choose a VIP session'), '#options' => $options, '#empty_option' => $this->t('- Select -'), '#required' => TRUE];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Book session')];
    if (!$options) {
      $form['eventinstance_id']['#description'] = $this->t('No future sessions currently have availability before their cutoff.');
      $form['actions']['submit']['#disabled'] = TRUE;
    }
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->bookings->book((int) $this->currentUser()->id(), (int) $form_state->getValue('eventinstance_id'));
      $this->messenger()->addStatus($this->t('Your VIP session booking has been saved.'));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($e->getMessage());
    }
    $form_state->setRedirect('commerce_lms_entitlements.vip_sessions');
  }

}
