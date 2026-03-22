<?php

namespace Drupal\anu_lms;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * Methods related to settings.
 */
class Settings {

  /**
   * Courses page entity.
   *
   * @var string
   */
  public const ENTITY_TYPE_COURSES_PAGE = 'Courses page';

  /**
   * Course entity.
   *
   * @var string
   */
  public const ENTITY_TYPE_COURSE = 'Course';

  /**
   * Lesson entity.
   *
   * @var string
   */
  public const ENTITY_TYPE_LESSON = 'Lesson';

  /**
   * Quiz entity.
   *
   * @var string
   */
  public const ENTITY_TYPE_QUIZ = 'Quiz';

  /**
   * Module entity.
   *
   * @var string
   */
  public const ENTITY_TYPE_MODULE = 'Module';

  /**
   * All entities system names.
   *
   * @var string
   */
  public const ALL_ENTITIES = [
    'course',
    'courses_page',
    'courses_landing_page',
    'module_lesson',
  ];

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected ModuleExtensionList $moduleExtensionList;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Creates Settings object.
   *
   * @param \Drupal\Core\Extension\ModuleExtensionList $extension_list_module
   *   The module extension list.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(ModuleExtensionList $extension_list_module, ModuleHandlerInterface $module_handler, ConfigFactoryInterface $config_factory) {
    $this->moduleExtensionList = $extension_list_module;
    $this->moduleHandler = $module_handler;
    $this->configFactory = $config_factory;
  }

  /**
   * Returns normalized settings entity.
   */
  public function getSettings(): array {
    // At the moment there are no settings for ANU LMS.
    return [];
  }

  /**
   * Returns normalized permissions entity.
   */
  public function getPermissions(): array {
    return [
      'view published question entities' => AccessResult::allowedIfHasPermission(\Drupal::currentUser(), 'view published question entities')->isAllowed(),
      'restful post anu_lms_lesson_checklist' => AccessResult::allowedIfHasPermission(\Drupal::currentUser(), 'restful post anu_lms_lesson_checklist')->isAllowed(),
    ];
  }

  /**
   * Returns boolean whether the offline mode can be supported.
   *
   * @return bool
   *   Boolean indicating whether the offline mode is supported.
   */
  public function isOfflineSupported(): bool {
    return $this->moduleHandler->moduleExists('pwa');
  }

  /**
   * Returns Pwa settings.
   *
   * Code from /pwa/src/Controller/PWAController.php:151
   */
  public function getPwaSettings(): ?array {
    if (!$this->isOfflineSupported()) {
      return NULL;
    }

    // Get module configs for pwa v1.
    $config_v1 = $this->configFactory->get('pwa.config');

    // Get module configs for pwa v2.
    $config_v2 = $this->configFactory->get('pwa_service_worker.config');

    // Look up module release from package info.
    $pwa_module_info = $this->moduleExtensionList->getExtensionInfo('pwa');
    // Packaging script will always provide the published module version.
    // Checking for NULL is only so maintainers have something
    // predictable to test against.
    $pwa_module_version = $pwa_module_info['version'] ?? '8.x-1.x-dev';

    $version = $config_v1->get('cache_version') ?? $config_v2->get('cache_version') ?? 1;

    return [
      'current_cache' => 'pwa-main-' . $pwa_module_version . '-v' . $version,
    ];
  }

  /**
   * Returns the customized name of entity.
   *
   * @param string $entity
   *   Name of entity.
   * @param bool $plural
   *   Indicator of plural form.
   * @param int $count
   *   Amount of entities.
   *
   * @return string
   *   Rendered string.
   */
  public function getEntityLabel($entity, $plural = FALSE, $count = 1): string {
    $trans = new IdentityTranslator();
    $locale = \Drupal::languageManager()->getCurrentLanguage()->getId();

    $deprecated = FALSE;
    $dynamicEntityLabel = $entity;

    if (str_contains($entity, '[DEPRECATED]')) {
      $deprecated = TRUE;
      $entity = trim(str_replace('[DEPRECATED]', '', $entity));
    }

    $entity = ucfirst($entity);

    $labels = $this->configFactory->get('anu_lms.entity_labels');

    if ($entity === static::ENTITY_TYPE_COURSES_PAGE) {
      $dynamicEntityLabel = $plural ? $labels->get('courses_page_label_plural') : $trans->trans($labels->get('courses_page_labels'), ['%count%' => $count], NULL, $locale);
    }
    elseif ($entity === static::ENTITY_TYPE_COURSE) {
      $dynamicEntityLabel = $plural ? $labels->get('course_label_plural') : $trans->trans($labels->get('course_labels'), ['%count%' => $count], NULL, $locale);
    }
    elseif ($entity === static::ENTITY_TYPE_LESSON) {
      $dynamicEntityLabel = $plural ? $labels->get('lesson_label_plural') : $trans->trans($labels->get('lesson_labels'), ['%count%' => $count], NULL, $locale);
    }
    elseif ($entity === static::ENTITY_TYPE_QUIZ) {
      $dynamicEntityLabel = $plural ? $labels->get('assessment_label_plural') : $trans->trans($labels->get('assessment_labels'), ['%count%' => $count], NULL, $locale);
    }
    elseif ($entity === static::ENTITY_TYPE_MODULE) {
      $dynamicEntityLabel = $plural ? $labels->get('module_label_plural') : $trans->trans($labels->get('module_labels'), ['%count%' => $count], NULL, $locale);
    }

    if ($deprecated) {
      $dynamicEntityLabel .= ' [DEPRECATED]';
    }

    return $dynamicEntityLabel;
  }

  /**
   * Returns the phrases with dynamic labels.
   *
   * @param string $phrase
   *   The original phrase.
   *
   * @return string
   *   The dynamic phrase.
   */
  public function getPhrase($phrase): string {
    $labels = $this->configFactory->get('anu_lms.entity_labels');

    $mapping = [
      'Courses page' => $labels->get('courses_page_labels'),
      'Course' => $labels->get('course_labels'),
      'Lesson' => $labels->get('lesson_labels'),
      'Quiz' => $labels->get('assessment_labels'),
      'Module' => $labels->get('module_labels'),
    ];

    foreach ($mapping as $from => $to) {
      $to = explode('|', $to)[0] ?? '';

      $phrase = str_replace($from, $to, $phrase);
      $phrase = str_replace(strtolower($from), strtolower($to), $phrase);
    }

    return $phrase;
  }

}
