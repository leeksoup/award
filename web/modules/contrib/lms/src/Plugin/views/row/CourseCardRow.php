<?php

declare(strict_types=1);

namespace Drupal\lms\Plugin\views\row;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\image\ImageStyleStorageInterface;
use Drupal\image\Plugin\Field\FieldType\ImageItem;
use Drupal\Core\Security\Attribute\TrustedCallback;
use Drupal\lms\Entity\Bundle\Course;
use Drupal\media\MediaInterface;
use Drupal\text\Plugin\Field\FieldType\TextWithSummaryItem;
use Drupal\views\Attribute\ViewsRow;
use Drupal\views\Plugin\views\row\RowPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a course entity as a fully themed card component.
 */
#[ViewsRow(
  id: "course_card_row",
  title: new TranslatableMarkup("Course Card"),
  help: new TranslatableMarkup("Display courses as themed card components."),
  base: ["groups_field_data"],
  display_types: ["normal"],
)]
final class CourseCardRow extends RowPluginBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * The image style storage.
   *
   * This service is optional and only loaded if the Image module is enabled.
   */
  protected ?ImageStyleStorageInterface $imageStyleStorage;

  /**
   * The constructor.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly FileUrlGeneratorInterface $fileUrlGenerator,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly EntityDisplayRepositoryInterface $entityDisplayRepository,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->imageStyleStorage = $this->entityTypeManager->hasDefinition('image_style') ? $this->entityTypeManager->getStorage('image_style') : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(EntityTypeManagerInterface::class),
      $container->get(EntityFieldManagerInterface::class),
      $container->get(FileUrlGeneratorInterface::class),
      $container->get(ModuleHandlerInterface::class),
      $container->get(EntityDisplayRepositoryInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions(): array {
    $options = parent::defineOptions();
    $options['background_image_field'] = ['default' => ''];
    $options['image_style'] = ['default' => ''];
    $options['media_source_field'] = ['default' => 'field_media_image'];
    $options['description_field'] = ['default' => ''];
    $options['description_display_type'] = ['default' => 'full'];
    return $options;
  }

  /**
   * {@inheritdoc}
   *
   * Method buildOptionsForm has required terms that phpstan doesn't like.
   *
   * @phpstan-ignore-next-line
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $field_definitions = $this->entityFieldManager->getFieldDefinitions('group', 'lms_course');
    $image_or_media_field_options = ['' => $this->t('- None (Use default image) -')];
    $text_field_options = ['' => $this->t('- None -')];
    $display_field_options = ['' => $this->t('- None -')];
    $media_field_names = [];
    $text_with_summary_field_names = [];

    // Populate field options based on their type.
    foreach ($field_definitions as $field_name => $field_definition) {
      if ($field_definition->isComputed()) {
        continue;
      }

      $field_type = $field_definition->getType();
      $field_label = (string) $field_definition->getLabel();

      if ($field_type === 'image') {
        $image_or_media_field_options[$field_name] = $this->t('@label (Image Field)', ['@label' => $field_label]);
      }
      elseif (
        $field_type === 'entity_reference' &&
        $this->moduleHandler->moduleExists('media')
      ) {
        if ($field_definition->getSetting('target_type') === 'media') {
          $image_or_media_field_options[$field_name] = $this->t('@label (Media Reference)', ['@label' => $field_label]);
          $media_field_names[] = $field_name;
        }
      }

      if (\in_array($field_type, ['string', 'string_long', 'text', 'text_long', 'text_with_summary'], TRUE) &&
        $field_name !== 'revision_log_message') {
        $text_field_options[$field_name] = $this->t('@label (@type)', [
          '@label' => $field_label,
          '@type' => \ucfirst(\str_replace('_', ' ', $field_type)),
        ]);

        if ($field_type === 'text_with_summary') {
          $text_with_summary_field_names[] = $field_name;
        }
      }

      if (!\in_array($field_type, ['uuid', 'boolean', 'revision_log_message'], TRUE) &&
        !\in_array($field_name, ['id', 'uuid', 'langcode', 'type', 'revision_id', 'label', 'uid', 'created', 'changed'], TRUE)) {
        $display_field_options[$field_name] = $this->t('@label (@type)', [
          '@label' => $field_label,
          '@type' => \ucfirst(\str_replace('_', ' ', $field_type)),
        ]);
      }
    }

    // Group description settings in a fieldset.
    $form['description_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Description Field Settings'),
      '#open' => TRUE,
    ];

    $form['description_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Description Field'),
      '#description' => $this->t('Select a text field to display as the course description in the course cards. You may first have to add a text field to your Course group type.'),
      '#options' => $text_field_options,
      '#default_value' => $this->options['description_field'] ?? '',
      '#fieldset' => 'description_settings',
    ];

    $summary_states = \array_map(static fn($name) => ['[data-drupal-selector="edit-row-options-description-field"]' => ['value' => $name]], $text_with_summary_field_names);

    if (\count($summary_states) > 0) {
      $form['description_display_type'] = [
        '#type' => 'select',
        '#title' => $this->t('Description Display Type'),
        '#description' => $this->t('For fields with a summary, choose whether to display the full text or the summary. Summary recommended.'),
        '#options' => [
          'full' => $this->t('Full text'),
          'summary' => $this->t('Summary only'),
        ],
        '#default_value' => $this->options['description_display_type'] ?? 'summary',
        '#fieldset' => 'description_settings',
        '#states' => ['visible' => $summary_states],
      ];
    }

    // Group background image settings in a fieldset.
    $form['background_image_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Background Image Settings'),
      '#open' => TRUE,
    ];

    $form['background_image_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Background Image Field'),
      '#description' => $this->t('Select an Image field or a Media reference field for the course card background. You may first have to add an image field to your Course group type.'),
      '#options' => $image_or_media_field_options,
      '#default_value' => $this->options['background_image_field'] ?? '',
      '#fieldset' => 'background_image_settings',
    ];

    if ($this->imageStyleStorage !== NULL) {
      $image_styles = $this->imageStyleStorage->loadMultiple();
      $style_options = ['' => $this->t('- Original image -')];
      foreach ($image_styles as $name => $style) {
        $style_options[$name] = $style->label();
      }
      $image_field_names = array_diff(array_keys($image_or_media_field_options), $media_field_names, ['']);

      $form['image_style'] = [
        '#type' => 'select',
        '#title' => $this->t('Image Style'),
        '#options' => $style_options,
        '#default_value' => $this->options['image_style'] ?? '',
        '#states' => [
          'visible' => \array_map(static fn ($name) => ['[data-drupal-selector="edit-row-options-background-image-field"]' => ['value' => $name]], $image_field_names),
        ],
        '#fieldset' => 'background_image_settings',
      ];
    }

    if ($this->moduleHandler->moduleExists('media')) {
      $media_view_modes = $this->entityDisplayRepository->getViewModeOptions('media');
      $form['media_view_mode'] = [
        '#type' => 'select',
        '#title' => $this->t('Media View Mode'),
        '#description' => $this->t("If using a Media reference, choose a view mode. To display an image, ensure this view mode is configured to render an image field from your media type. You can configure this under Structure > Display modes."),
        '#options' => $media_view_modes,
        '#default_value' => $this->options['media_view_mode'],
        '#states' => [
          'visible' => \array_map(static fn ($name) => ['[data-drupal-selector="edit-row-options-background-image-field"]' => ['value' => $name]], $media_field_names),
        ],
        '#fieldset' => 'background_image_settings',
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function render($row) {
    $entity = $row->_entity;
    if ($entity instanceof Course) {
      // @phpstan-ignore-next-line - wants a string but array return required.
      return $this->buildCard($entity);
    }
    // @phpstan-ignore-next-line - wants a string but array return required.
    return [];
  }

  /**
   * Builds a single course card render array.
   */
  private function buildCard(Course $entity): array {
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($entity);

    $props = [
      'title' => $entity->label(),
      'url' => $entity->toUrl()->toString(),
      'background_image_url' => $this->getBackgroundImageUrl($entity),
      'description' => $this->getDescriptionText($entity),
      'extra_fields' => [],
    ];

    // Invoke a hook allowing other modules to add or alter props.
    $this->moduleHandler->alter('views_row_lms_course_card', $props, $entity, $cacheability);

    $build = [
      '#type' => 'component',
      '#component' => 'lms:course_card',
      '#props' => $props,
      '#slots' => [
        'start_link' => [
          'content' => [
            '#lazy_builder' => [
              static::class . '::buildStartLink',
              [(string) $entity->id()],
            ],
            '#create_placeholder' => TRUE,
            '#cache' => [
              'keys' => ['lms-start-link', (string) $entity->id()],
              'contexts' => ['session.exists'],
              'tags' => $entity->getCacheTags(),
            ],
          ],
        ],
      ],
    ];

    $cacheability->applyTo($build);
    return $build;
  }

  #[TrustedCallback]
  public static function buildStartLink(string $course_id): array {
    $course = \Drupal::entityTypeManager()
      ->getStorage('group')
      ->load($course_id);
    if (!$course instanceof Course) {
      return [];
    }
    return $course->get('start_link')->view();
  }

  /**
   * Gets the background image render array for the course card.
   */
  private function getBackgroundImageUrl(Course $entity): ?string {
    $source_field_name = $this->options['background_image_field'] ?? '';

    if ($source_field_name === '' || !$entity->hasField($source_field_name) || $entity->get($source_field_name)->isEmpty()) {
      return NULL;
    }

    $field_list = $entity->get($source_field_name);
    $field_item = $field_list->first();
    // @phpstan-ignore-next-line
    if ($field_item === NULL) {
      return NULL;
    }

    $image_uri = NULL;

    // Handle direct image field by rendering it with the chosen image style.
    if ($field_item instanceof ImageItem && $field_item->entity !== NULL) {
      $image_uri = $field_item->entity->getFileUri();
    }
    elseif ($this->moduleHandler->moduleExists('media') && $field_item instanceof EntityReferenceItem && $field_item->entity instanceof MediaInterface) {
      // Handle media reference field by rendering entity in chosen view mode.
      $media_entity = $field_item->entity;
      $view_mode = $this->options['media_view_mode'] ?? 'default';
      $display = $this->entityTypeManager->getStorage('entity_view_display')->load('media.' . $media_entity->bundle() . '.' . $view_mode);
      if ($display !== NULL) {
        $components = $display->getComponents();
        foreach ($components as $field_name => $component) {
          if ($media_entity->hasField($field_name) && $media_entity->get($field_name)->getFieldDefinition()->getType() === 'image') {
            $image_field_item = $media_entity->get($field_name)->first();
            if ($image_field_item instanceof ImageItem && $image_field_item->entity !== NULL) {
              $image_uri = $image_field_item->entity->getFileUri();
              break;
            }
          }
        }
      }
    }
    if ($image_uri === NULL) {
      return NULL;
    }
    if ($this->imageStyleStorage !== NULL) {
      $style_name = $this->options['image_style'] ?? '';
      $style = $this->imageStyleStorage->load($style_name) ?? '';
      if ($style_name !== '' && $style !== '') {
        return $style->buildUrl($image_uri);
      }
    }
    return $this->fileUrlGenerator->generateString($image_uri);
  }

  /**
   * Gets the description text for the course card.
   */
  private function getDescriptionText(Course $entity): ?string {
    $description_field_name = $this->options['description_field'] ?? '';

    if ($description_field_name === '' || !$entity->hasField($description_field_name) || $entity->get($description_field_name)->isEmpty()) {
      return NULL;
    }

    $field_list = $entity->get($description_field_name);
    /** @var \Drupal\text\Plugin\Field\FieldType\TextItemBase $field_item */
    $field_item = $field_list->first();
    // @phpstan-ignore-next-line
    if ($field_item === NULL) {
      return NULL;
    }

    if ($field_item instanceof TextWithSummaryItem) {
      $display_type = $this->options['description_display_type'] ?? 'full';
      $value = ($display_type === 'summary' && !\is_null($field_item->summary)) ? $field_item->summary : $field_item->value;
    }
    else {
      $value = $field_item->value;
    }

    $format = $field_item->format;
    if (!\is_null($format)) {
      $processed = \check_markup((string) $value, $format);
      return \trim((string) $processed);
    }

    return \trim((string) $value);
  }

}
