<?php

declare(strict_types=1);

namespace Drupal\commerce_lms_entitlements\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Configures the protected VIP LMS hub and eligible event series. */
final class VipSettingsForm extends ConfigFormBase {

  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($config_factory);
    $this->entityTypeManager = $entity_type_manager;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('config.factory'), $container->get('entity_type.manager'));
  }

  public function getFormId(): string { return 'commerce_lms_entitlements_vip_settings'; }

  protected function getEditableConfigNames(): array { return ['commerce_lms_entitlements.vip']; }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('commerce_lms_entitlements.vip');
    $form['hub_course_id'] = ['#type' => 'number', '#title' => $this->t('VIP hub LMS Course ID'), '#min' => 1, '#default_value' => $config->get('hub_course_id'), '#required' => TRUE];
    $form['hub_class_id'] = ['#type' => 'number', '#title' => $this->t('VIP hub LMS Class ID'), '#min' => 1, '#default_value' => $config->get('hub_class_id'), '#required' => TRUE];
    $form['event_series_ids'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Eligible Recurring Events series IDs'),
      '#description' => $this->t('One numeric event series ID per line. Configure each series for instance registration, capacity, and no waitlist.'),
      '#default_value' => implode("\n", $config->get('event_series_ids') ?: []),
      '#required' => TRUE,
    ];
    $form['meeting_url'] = ['#type' => 'url', '#title' => $this->t('Protected shared meeting URL'), '#default_value' => $config->get('meeting_url'), '#required' => TRUE];
    $form['change_cutoff_hours'] = ['#type' => 'number', '#title' => $this->t('Booking/change cutoff (hours before session)'), '#min' => 0, '#default_value' => $config->get('change_cutoff_hours') ?? 24, '#required' => TRUE];
    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $course = $this->entityTypeManager->getStorage('group')->load((int) $form_state->getValue('hub_course_id'));
    $class = $this->entityTypeManager->getStorage('group')->load((int) $form_state->getValue('hub_class_id'));
    if (!$course || $course->bundle() !== 'lms_course' || !$class || $class->bundle() !== 'lms_class') {
      $form_state->setErrorByName('hub_course_id', $this->t('Select an existing LMS Course and LMS Class.'));
    }
    elseif (!$this->isChildClass($course, $class)) {
      $form_state->setErrorByName('hub_class_id', $this->t('The VIP Class must be a child of the VIP Course.'));
    }
    $ids = $this->parseIds((string) $form_state->getValue('event_series_ids'));
    if (!$ids) {
      $form_state->setErrorByName('event_series_ids', $this->t('Enter at least one event series ID.'));
    }
    foreach ($ids as $id) {
      if (!$this->entityTypeManager->getStorage('eventseries')->load($id)) {
        $form_state->setErrorByName('event_series_ids', $this->t('Event series @id does not exist.', ['@id' => $id]));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('commerce_lms_entitlements.vip')
      ->set('hub_course_id', (int) $form_state->getValue('hub_course_id'))
      ->set('hub_class_id', (int) $form_state->getValue('hub_class_id'))
      ->set('event_series_ids', $this->parseIds((string) $form_state->getValue('event_series_ids')))
      ->set('meeting_url', trim((string) $form_state->getValue('meeting_url')))
      ->set('change_cutoff_hours', (int) $form_state->getValue('change_cutoff_hours'))
      ->save();
    parent::submitForm($form, $form_state);
  }

  private function parseIds(string $input): array {
    return array_values(array_unique(array_map('intval', preg_grep('/^\s*\d+\s*$/', preg_split('/\R/', trim($input)) ?: []))));
  }

  private function isChildClass(object $course, object $class): bool {
    foreach ($course->getRelationships('lms_classes') as $relationship) {
      if ((int) $relationship->getEntity()->id() === (int) $class->id()) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
