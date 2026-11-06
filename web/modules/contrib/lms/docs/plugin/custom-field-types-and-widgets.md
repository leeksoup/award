# Custom Field Types and Widgets

For more advanced plugins, you may need to create custom field types and widgets to handle specialized data structures and user interfaces.

Custom field types are only necessary when standard Drupal fields can’t handle your data structure.

## When to Create Custom Field Types

Consider creating custom field types when:

1.  You need to store complex structured data (beyond default Drupal field types)
2.  You need to store multiple related values in a single field
3.  You need special validation or processing logic for field data

For example, the LMS module includes:

-   `LmsAnswer` field type for storing answer options that each have correct/incorrect flags  
     
-   `LmsPhraseFeedback` field type for storing phrases and corresponding feedback

## Creating a Custom Field Type

Custom field types are implemented as classes that extend `FieldItemBase` and are annotated with the `FieldType` attribute:

    <?php
    
    declare(strict_types=1);
    
    namespace Drupal\my_module\Plugin\Field\FieldType;
    
    use Drupal\Core\Field\Attribute\FieldType;
    use Drupal\Core\Field\FieldItemBase;
    use Drupal\Core\Field\FieldStorageDefinitionInterface;
    use Drupal\Core\StringTranslation\TranslatableMarkup;
    use Drupal\Core\TypedData\DataDefinition;
    
    /**
     * Defines a custom field type for an answer and its correctness.
     */
    #[FieldType(
      id: "my_custom_field",
      label: new TranslatableMarkup("My Custom Field"),
      description: new TranslatableMarkup("Stores a text answer and a boolean for correctness."),
      category: "Custom",
      default_widget: "my_custom_field_widget",
    )]
    final class MyCustomField extends FieldItemBase {
    
      /**
       * {@inheritdoc}
       */
      public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
        return [
          'answer' => DataDefinition::create('string')
            ->setLabel(new TranslatableMarkup('Answer Text'))
            ->setRequired(TRUE),
          'correct' => DataDefinition::create('boolean')
            ->setLabel(new TranslatableMarkup('Is Correct?'))
            ->setRequired(TRUE),
        ];
      }
    
      /**
       * {@inheritdoc}
       */
      public static function schema(FieldStorageDefinitionInterface $field_definition): array {
        return [
          'columns' => [
            'answer' => ['type' => 'text', 'size' => 'big'],
            'correct' => ['type' => 'int', 'size' => 'tiny'],
          ],
        ];
      }
    
      /**
       * {@inheritdoc}
       */
      public function isEmpty(): bool {
        return $this->get('answer')->getString() === '';
      }
    
    }

Place this class in `src/Plugin/Field/FieldType/MyCustomField.php` within your module.

For reference examples, see:

-   `LmsAnswer` in `modules/lms_answer_plugins/src/Plugin/Field/FieldType/LmsAnswer.php`  
     
-   `LmsPhraseFeedback` in `modules/lms_answer_plugins/src/Plugin/Field/FieldType/LmsPhraseFeedback.php`  
     

See the Drupal **[Field API](https://api.drupal.org/api/drupal/core%21modules%21field%21field.module/group/field/10)** and **[Field Types API](https://api.drupal.org/api/drupal/core%21modules%21field%21field.api.php/group/field_types/11.x)** documentation for more information.

## Creating a Custom Field Widget

Custom field types typically need custom widgets for data entry. Field widgets control how the fields are displayed in the activity creation form, for example grouping a set of fields together and allowing course creators to add or remove sets of data. A simple example of this is a multiple choice question, where a teacher can add and remove as many selection options as they want, and each selection option needs a checkbox to indicate if it's the correct answer.

Field widgets are implemented as classes that extend `WidgetBase` and are annotated with the `FieldWidget` attribute:

    <?php
    
    declare(strict_types=1);
    
    namespace Drupal\my_module\Plugin\Field\FieldWidget;
    
    use Drupal\Core\Field\Attribute\FieldWidget;
    use Drupal\Core\Field\FieldItemListInterface;
    use Drupal\Core\Field\WidgetBase;
    use Drupal\Core\Form\FormStateInterface;
    use Drupal\Core\StringTranslation\TranslatableMarkup;
    
    /**
     * Plugin implementation of the 'my_custom_field_widget' widget.
     */
    #[FieldWidget(
      id: 'my_custom_field_widget',
      label: new TranslatableMarkup('My Custom Widget'),
      field_types: ['my_custom_field'],
    )]
    final class MyCustomFieldWidget extends WidgetBase {
    
      /**
       * {@inheritdoc}
       */
      public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
        $element['answer'] = [
          '#type' => 'textarea',
          '#title' => $this->t('Answer Text'),
          '#default_value' => $items[$delta]->answer ?? NULL,
          '#rows' => 2,
        ];
    
        $element['correct'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Is this answer correct?'),
          '#default_value' => $items[$delta]->correct ?? FALSE,
        ];
    
        return $element;
      }
    
    }

Place this class in `src/Plugin/Field/FieldWidget/MyCustomFieldWidget.php` within your module.

For reference examples, see:

-   `LmsAnswerWidget` in `modules/lms_answer_plugins/src/Plugin/Field/FieldWidget/LmsAnswerWidget.php`  
     
-   `LmsPhraseFeedbackWidget` in `modules/lms_answer_plugins/src/Plugin/Field/FieldWidget/LmsPhraseFeedbackWidget.php`  
     

See the Drupal **[Field Widget API](https://api.drupal.org/api/drupal/core%21modules%21field%21field.api.php/group/field_widget/11.x)** documentation for more info.

## Using Custom Field Types in Plugins

To use your custom field type in your Activity-Answer plugin:

-   Define the field storage in your plugin’s configuration:

    # field.storage.lms_activity.my_custom_field.yml
    status: true
    dependencies:
      module:
        - lms
        - my_module
    id: lms_activity.my_custom_field
    field_name: my_custom_field
    entity_type: lms_activity
    type: my_custom_field
    settings: {  }
    module: my_module
    locked: false
    cardinality: -1  # -1 for unlimited values
    translatable: true
    indexes: {  }
    persist_with_no_fields: false
    custom_storage: false

-   Attach the field to the activity bundle:

    # field.field.lms_activity._bundle_placeholder_.my_custom_field.yml
    status: true
    dependencies:
      config:
        - field.storage.lms_activity.my_custom_field
        - lms.lms_activity_type._bundle_placeholder_
      module:
        - my_module
    id: lms_activity._bundle_placeholder_.my_custom_field
    field_name: my_custom_field
    entity_type: lms_activity
    bundle: _bundle_placeholder_
    label: 'My Custom Field'
    description: ''
    required: true
    translatable: false
    default_value: {  }
    default_value_callback: ''
    settings: {  }
    field_type: my_custom_field

-   Add the field to the form display:

    # In core.entity_form_display.lms_activity._bundle_placeholder_.default.yml
    content:
      my_custom_field:
        type: my_custom_field_widget
        weight: 10
        region: content
        settings: {  }
        third_party_settings: {  }

-   Once configured, you can access the data from your custom fields within your plugin's methods, just like any other field. For example, in your `getScore()` method:

    public function getScore(Answer $answer): float {
      $activity = $answer->getActivity();
      $custom_field_items = $activity->get('my_custom_field');
      
      $score = 0.0;
      // Process custom field data
      foreach ($custom_field_items as $item) {
        $answer_text = $item->answer;
        $is_correct = $item->correct;
        // Use these values in scoring logic
      }
      
      return $score;
    }

If your plugin needs to store complex structured data, it's worth carefully considering your field type’s schema so that it's flexible enough for all future needs.

[**Previous page: Plugin Configuration Files**](plugin-configuration-files.md)

[**Next page: AJAX Implementation**](ajax-implementation.md)
