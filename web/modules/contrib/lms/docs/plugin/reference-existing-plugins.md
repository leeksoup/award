# Reference: Existing Plugins

The Drupal LMS module includes a number of core Activity-Answer plugins in the `lms/modules/lms_answer_plugins` directory, which serve as excellent, practical examples for you to write your own custom plugins. This section explains some of the core plugins, explaining the purpose of their key methods and the logic they use.

The core plugins follow consistent patterns, which you can use as a reference for your own work.  The simplest plugins to understand are the "No Answer" and "True/False" plugins, building in complexity up to the "Free Text with Feedback" and "Select with Feedback" plugins.

## No Answer (Built into LMS Core)

The “No Answer” plugin is the simplest one, used for content-only activities (e.g., reading material, instructions), with no answer or evaluation. It’s part of the core LMS module and requires only a minimal plugin class that adds no methods beyond the defaults inherited from its base class, `ActivityAnswerBase`.

**File**: `src/Plugin/ActivityAnswer/NoAnswer.php`

Implementation highlights:

-   **`evaluatedOnSave()`**: Inherits the default `TRUE` value.  The activity is marked as "complete" immediately.  
      
-   **`getScore()`**: Inherits the default method, which returns `1.0`.  This means the activity is always considered 100% correct upon submission.  
      
-   **`answeringForm()`**: The method is not implemented, so no form elements are added for the student to interact with, other than the "Next" button.

## Free Text

**File**: `modules/lms_answer_plugins/src/Plugin/ActivityAnswer/FreeText.php`

The Free Text plugin provides a open-ended text response fields for essays or long-form answers, and is designed for manual teacher evaluation. Key implementations:

-   **`answeringForm()`**: This method builds the form elements for the student. It dynamically creates either a simple `textarea` or a rich-text `text_format` editor, depending on the Activity Type's configuration. It also attaches a custom validation callback:
    
        '#element_validate' => [[$this, 'validateAnswers']],
    
-   **`evaluatedOnSave()`**: Returns `FALSE` to trigger manual teacher evaluation.  
      
-   **`evaluationDisplay()`**: This method is crucial for manual grading. It returns a render array that displays both the original question and the student's formatted answer, providing the necessary context for a teacher to assign a score.  
      
-   `validateAnswers()`: A custom validation handler that checks if the student's input meets the "Minimum characters" requirement configured on the activity.  
      

## Free Text with Feedback

**Files**:  
`modules/lms_answer_plugins/src/Plugin/WithFeedbackPluginTrait.php`

`modules/lms_answer_plugins/src/Plugin/ActivityAnswer/FreeTextFeedback.php`

The Free Text with Feedback plugin extends the free text functionality with automatic phrase matching and immediate AJAX feedback. It uses the `WithFeedbackPluginTrait` to provide interactive responses. Key implementations:

-   `evaluatedOnSave()`: Returns `TRUE` because evaluation is automatic, based on phrase matching.  
      
-   `getScore()`: Calculates a score based on the percentage of phrases matched in the student’s answer.  
      
-   **`buildFeedbackRenderArray()`:** This method, required by the feedback trait, builds the rich feedback for the student. It iterates through the configured phrases and displays the "feedback if present" or "feedback if absent" text for each, giving the student a detailed breakdown of what they included or missed.  
      
-   `isPhraseFound()`: A helper method that checks if a phrase or any of its variants is found in the student’s answer.  
      

## Select

**Files**:  
`modules/lms_answer_plugins/src/Plugin/SelectBase.php`: base class  
    
`modules/lms_answer_plugins/src/Plugin/ActivityAnswer/Select.php`: activity type plugin

The base class contains the core logic for handling single and multiple-choice questions, which are used in both the Select and Select Feedback plugins. Key implementations:

-   **`answeringForm()`:** Dynamically sets the form element's `#type` to either `'radios'` (for single selection) or `'checkboxes'` (for multiple selection) based on the Activity Type's configuration.  
      
-   **`buildConfigurationForm()`**: Adds the "Selection type" (single/multiple) option to the Activity Type configuration form.  
      
-   **`getElementType()`**: Returns either `'radios'` or `'checkboxes'` based on the Activity Type's configuration.  
      
-   **`getScore()`**: Contains the logic for calculating a score based on correct and incorrect selections for both single and multiple-answer questions. It awards points for correctly selected options, then calculates the final score as a proportion of the total possible correct points.  
      

## Select with Feedback

**File:** `lms/modules/lms_answer_plugins/src/Plugin/ActivityAnswer/SelectFeedback.php`

This plugin extends `SelectBase` and uses the `WithFeedbackPluginTrait` to add immediate, interactive feedback.

-   **`answeringForm()`:** Calls the parent method to get the radio/checkbox elements, then calls the trait's helper method to add the AJAX "Check Answer" button.  
      
-   **`buildFeedbackRenderArray()`:** Implements logic to return the render array for a "Correct" or "Wrong" feedback message.  
      
-   **`addAnswerClassesToForm()`:** A method required by the feedback trait. This is where the plugin adds the `correct-answer` or `wrong-answer` CSS classes to the individual radio buttons or checkboxes, providing immediate visual feedback to the student after they click "Check Answer".  
      

By studying these and the other core plugins, and using the guidance in this document, you have a powerful toolkit for creating almost any learning interaction you can imagine.

Please also consider contributing your custom plugins back to the Drupal LMS community!

[**Previous page: Integration and Deployment**](integration-and-deployment.md)

[**Back to main Drupal LMS documentation page**](../index.md)
