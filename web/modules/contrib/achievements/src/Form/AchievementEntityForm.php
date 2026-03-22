<?php

namespace Drupal\achievements\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StreamWrapper\PublicStream;

/**
 * Class AchievementEntityForm.
 */
class AchievementEntityForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\achievements\Entity\AchievementEntity $achievement_entity */
    $achievement_entity = $this->entity;
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $achievement_entity->label(),
      '#description' => $this->t("Label for the Achievement entity."),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $achievement_entity->id(),
      '#machine_name' => [
        'exists' => '\Drupal\achievements\Entity\AchievementEntity::load',
      ],
      '#disabled' => !$achievement_entity->isNew(),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#required' => TRUE,
      '#default_value' => $achievement_entity->getDescription(),
      '#description' => $this->t('The description of the achievement.'),
    ];

    $form['storage'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Storage'),
      '#size' => 60,
      '#maxlength' => 128,
      '#required' => FALSE,
      '#default_value' => $achievement_entity->getStorage(),
      '#description' => $this->t('The storage of the achievement.'),
    ];

    $form['secret'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Secret'),
      '#description' => $this->t('The achievement is only visible once a user has unlocked it.'),
      '#default_value' => $achievement_entity->isSecret(),
    ];

    $form['invisible'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Invisible'),
      '#description' => $this->t('The achievement does <em>not</em> display. Ever.'),
      '#default_value' => $achievement_entity->isInvisible(),
    ];

    $form['points'] = [
      '#type' => 'number',
      '#title' => $this->t('Points'),
      '#description' => $this->t('The point value of the achievement.'),
      '#default_value' => $achievement_entity->getPoints(),
    ];

    $form['image'] = [
      '#type' => 'details',
      '#title' => $this->t('Images'),
      '#open' => TRUE,
    ];
    $form['image']['use_default_image'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use the default images supplied by the module'),
      '#default_value' => $achievement_entity->useDefaultImage(),
      '#tree' => FALSE,
    ];
    $form['image']['settings'] = [
      '#type' => 'container',
      '#states' => [
        // Hide the image settings when using the default image.
        'invisible' => [
          'input[name="use_default_image"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $form['image']['settings']['unlocked_image_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path to custom image for unlocked achievements'),
      '#default_value' => $achievement_entity->getImagePath('unlocked', FALSE),
    ];
    $form['image']['settings']['unlocked_image_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload custom image for unlocked achievement'),
      '#description' => $this->t("If you don't have direct file access to the server, use this field to upload your image."),
      '#required' => FALSE,
      '#upload_location' => 'public://achievements/',
      '#upload_validators' => [
        'FileExtension' => [
          'extensions' => 'png gif jpg jpeg svg webp',
        ],
      ],
    ];
    $form['image']['settings']['locked_image_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path to custom image for locked achievement'),
      '#default_value' => $achievement_entity->getImagePath('locked', FALSE),
    ];
    $form['image']['settings']['locked_image_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload custom image for locked achievement'),
      '#description' => $this->t("If you don't have direct file access to the server, use this field to upload your image."),
      '#required' => FALSE,
      '#upload_location' => 'public://achievements/',
      '#upload_validators' => [
        'FileExtension' => [
          'extensions' => 'png gif jpg jpeg svg webp',
        ],
      ],
    ];
    $default = '.svg';
    // Inject human-friendly values and form element descriptions for image.
    foreach (['locked_image', 'unlocked_image'] as $type) {
      if (isset($form[$type]['settings'][$type . '_path'])) {
        $element = &$form[$type]['settings'][$type . '_path'];

        // If path is a public:// URI, display the path relative to the files
        // directory; stream wrappers are not end-user friendly.
        $original_path = $element['#default_value'];
        $friendly_path = NULL;
        if (\Drupal::service('stream_wrapper_manager')->getScheme($original_path) == 'public') {
          $friendly_path = \Drupal::service('stream_wrapper_manager')->getTarget($original_path);
          $element['#default_value'] = $friendly_path;
        }

        // Prepare local file path for description.
        if ($original_path && isset($friendly_path)) {
          $local_file = strtr($original_path, ['public:/' => PublicStream::basePath()]);
        }
        else {
          $local_file = $this->moduleHandler->getModule('achievements')->getPath() . '/' . $default;
        }

        $element['#description'] = $this->t('Examples: <code>@implicit-public-file</code> (for a file in the public filesystem), <code>@explicit-file</code>, or <code>@local-file</code>.', [
          '@implicit-public-file' => isset($friendly_path) ? $friendly_path : $default,
          '@explicit-file' => \Drupal::service('stream_wrapper_manager')->getScheme($original_path) !== FALSE ? $original_path : 'public://' . $default,
          '@local-file' => $local_file,
        ]);
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    foreach (['locked', 'unlocked'] as $type) {
      // When intending to use the default image, unset the image_path.
      if ($form_state->getValue('use_default_image')) {
        $form_state->unsetValue($type . '_image_path');
      }

      // If the user provided a path for an image file, make sure a file
      // exists at that path.
      if ($form_state->getValue($type . '_image_path')) {
        $path = $this->validatePath($form_state->getValue($type . '_image_path'));
        if (!$path) {
          $form_state->setErrorByName($type . '_image_path', $this->t("The custom image path for $type is invalid."));
        }
      }
    }
  }

  /**
   * Helper function for the system_theme_settings form.
   *
   * Attempts to validate normal system paths, paths relative to the public files
   * directory, or stream wrapper URIs. If the given path is any of the above,
   * returns a valid path or URI that the theme system can display.
   *
   * @param string $path
   *   A path relative to the Drupal root or to the public files directory, or
   *   a stream wrapper URI.
   *
   * @return mixed
   *   A valid path that can be displayed through the theme system, or FALSE if
   *   the path could not be validated.
   */
  protected function validatePath($path) {
    // Absolute local file paths are invalid.
    if (\Drupal::service('file_system')->realpath($path) == $path) {
      return FALSE;
    }
    // A path relative to the Drupal root or a fully qualified URI is valid.
    if (is_file($path)) {
      return $path;
    }
    // Prepend 'public://' for relative file paths within public filesystem.
    if (\Drupal::service('stream_wrapper_manager')->getScheme($path) === FALSE) {
      $path = 'public://' . $path;
    }
    if (is_file($path)) {
      return $path;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    foreach (['locked', 'unlocked'] as $type) {
      // If the user uploaded a new image via managed_file, get the file URI.
      if (!empty($values[$type . '_image_upload'])) {
        $fid = is_array($values[$type . '_image_upload']) ? reset($values[$type . '_image_upload']) : $values[$type . '_image_upload'];
        if ($fid) {
          /** @var \Drupal\file\FileInterface $file */
          $file = \Drupal\file\Entity\File::load($fid);
          if ($file) {
            // Make the file permanent.
            $file->setPermanent();
            $file->save();
            $values['use_default_image'] = 0;
            // Store as stream wrapper URI (public://...) - the entity/rendering will handle conversion
            $values[$type . '_image_path'] = $file->getFileUri();
          }
        }
      }
      unset($values[$type . '_image_upload']);

      // If the user entered a path relative to the system files directory for
      // an image, store a public:// URI so the theme system can handle it.
      if (!empty($values[$type . '_image_path'])) {
        $values[$type . '_image_path'] = $this->validatePath($values[$type . '_image_path']);
      }
    }

    $form_state->setValues($values);

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $achievement_entity = $this->entity;
    $status = $achievement_entity->save();

    switch ($status) {
      case SAVED_NEW:
        \Drupal::messenger()->addMessage($this->t('Created the %label Achievement entity.', [
          '%label' => $achievement_entity->label(),
        ]));
        break;

      default:
        \Drupal::messenger()->addMessage($this->t('Saved the %label Achievement entity.', [
          '%label' => $achievement_entity->label(),
        ]));
    }
    $form_state->setRedirectUrl($achievement_entity->toUrl('collection'));
  }

}
