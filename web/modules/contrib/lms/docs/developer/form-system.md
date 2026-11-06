# Form System

All forms in Drupal LMS implement Drupal’s `FormInterface` and use standard form building patterns, with additional AJAX capabilities and modal integration added.

The LMS module uses a modal form system; when adding a form, use the `ModalSubformTrait` to ensure consistent behavior with the rest of the module.  For reference, you can follow the pattern established in `AnswerForm` and `AnswerEvaluationForm`.

## The Modal Form System

To create a seamless experience for content creators and teachers, Drupal LMS uses a custom modal form system. This allows users to create, edit, and configure entities (like Activities and Lessons) from within another form, without losing their place.

### Modal Form Workflow

1.  A user clicks a button (e.g., "Create activity") on a parent form (like the Lesson edit form).  
      
2.  An AJAX request is sent to a central endpoint: `/lms/modal-subform`.  
      
3.  The **`ModalSubformController`** receives the request.  
      
4.  It uses the **`ModalSubformManager`** to find and instantiate the correct **Modal Subform Plugin** based on the request parameters.  
      
5.  The plugin's `buildForm()` method is called, which loads or creates the appropriate entity and returns a standard Drupal form array.  
      
6.  The controller wraps the form in an `OpenDialogCommand` and returns it in an `AjaxResponse`.  
      
7.  When the modal form is submitted, the controller processes the submission, and can optionally trigger a rebuild of the original parent form to reflect the changes (e.g., adding the new activity to the reference table, or updating the student's score after activity evaluation).  
      

### Key Modal Components

-   **Controller:** `src/Controller/ModalSubformController.php` - The single AJAX endpoint for all modal interactions.  
      
-   **Manager:** `src/ModalSubformManager.php` - Discovers and manages modal form plugins.  
      
-   **Plugin Interface:** `src/Plugin/ModalSubformInterface.php` - Defines the contract for all modal form plugins.  
      
-   **Core Modal Plugins:** (located in `src/Plugin/ModalSubform/`)
    -   **`EntityForm.php`:** A generic plugin for creating or editing any LMS entity in a modal.  
          
    -   **`LmsEntitySpecificForm.php`:** A plugin that handles forms for entity-specific parameters.  
          
    -   **`ViewForm.php`:** A plugin that displays a View and allows users to select entities to reference.  
          
-   **Modal Form Classes:** (located in `src/Form/Modal/`) These are the actual form builder classes used by the modal system:
    -   **`LmsBundleSelectionForm.php`:** A form for selecting an entity bundle (e.g., choosing an Activity Type).  
          
    -   **`LmsReferenceParametersForm.php`:** A form for editing the contextual parameters of an `LMSReferenceItem` field (e.g., setting the "Required Score" for a lesson).  
          

### Using the Modal System in Your Code

When building entity forms that need to function within this system, it's best to use the **`ModalEntitySubformTrait`** (`src/Form/Modal/ModalEntitySubformTrait.php`).

This trait automatically adapts your form's buttons and AJAX settings to work correctly when opened in a modal.

### AJAX Commands

The modal system relies on Drupal Core's AJAX framework. Key commands used in the module include:

-   **`OpenDialogCommand`:** Opens a form in a modal dialog.  
      
-   **`CloseDialogCommand`:** Closes the current modal dialog.  
      
-   **`ReplaceCommand`:** Replaces the HTML of a specific element, often used to update a form or part of a page after a modal action.  
      
-   **`InvokeCommand`:** Calls a jQuery method on a selector, useful for dynamic UI changes like adding a CSS class.  
      

**Developer Best Practice: Using the Trait**

When building entity forms that need to function within this system, use the **`ModalEntitySubformTrait`** (`src/Form/Modal/ModalEntitySubformTrait.php`). This trait automatically adapts your form's buttons and AJAX settings to work correctly when opened in a modal.

## Activity Answering Forms

Student activity answering is handled by a combination of a central form controller, and the specific Activity-Answer plugin.

1.  **AnswerForm** (`src/Entity/Form/AnswerForm.php`): This is the main controller.  It's responsible for:
    
    -   Building the overall form structure, including navigation buttons ("Next", "Back", "Submit").
        
    -   Handling navigation logic between activities.
        
    -   Processing the final submission and updating the student's progress.
        
    -   Checking for lesson time limits via its overTimeHandler() method.  
          
        
2.  **answeringForm() method** (within each Activity-Answer plugin, see `modules/lms_answer_plugins/src/Plugin/ActivityAnswer`): This method is called by AnswerForm to inject the actual question and answer fields into the form. Each plugin is responsible for:
    
    -   Displaying the question content (e.g., text, options).
        
    -   Providing the correct form elements for the student's response (e.g., textareas, radios, checkboxes).
        
    -   Populating default values if a student is revisiting an answer.
        

## Evaluation Forms

Manual evaluation of student answers is handled by **AnswerEvaluationForm** (`src/Entity/Form/AnswerEvaluationForm.php`).  This form is displayed to teachers in a modal dialog.  It's responsible for:

-   Displaying the student's submitted answer (using the plugin's evaluationDisplay() method).
    
-   Providing a field for the teacher to enter a score.
    
-   Handling submission, updating the Answer entity's status to "evaluated", and updating the LessonStatus and CourseStatus via the TrainingManager.
    
-   Managing AJAX updates to the underlying "Results" page after evaluation is complete.
    

### Key Methods in the Evaluation Workflow:

-   **`evaluationDisplay()`** (Plugin Method): This method is implemented in each Activity-Answer plugin. The `AnswerEvaluationForm` calls it to render the student's answer. This allows each plugin to present the answer in the most appropriate format (e.g., formatted text for an essay, or selected options for a multiple-choice question).
    
        // In the plugin class: 
        public function evaluationDisplay(Answer $answer): array { 
          // Return a render array for the student's answer. 
        }
    
-   **`submitForm()`** (in `AnswerEvaluationForm`): This method handles the core logic of an evaluation. It:
    
    -   Sets the score and "evaluated" status on the Answer entity.
    -   Calls the `TrainingManager` to update the `LessonStatus` and `CourseStatus`.  
          
-   **`ajaxUpdatePage()`** (in `AnswerEvaluationForm`): The AJAX callback that is triggered on submission. It returns an `AjaxResponse` containing commands to:
    
    -   Close the modal dialog.
    -   Update the student's results page with the new score and status without a full page reload.  
          

## Entity Form Classes

The module uses various custom entity form classes:

1.  **Activity Forms**:
    -   `src/Entity/Form/ActivityForm.php`: Activity creation/editing  
         
    -   `src/Entity/Form/ActivityTypeForm.php`: Activity type configuration  
         
    -   `src/Entity/Form/ActivityDeleteForm.php`: Activity deletion  
         
2.  **Lesson Forms**:
    -   `src/Entity/Form/LessonForm.php`: Lesson creation/editing  
         
    -   `src/Entity/Form/LessonDeleteForm.php`: Lesson deletion  
         
3.  **Data Integrity Warnings**:
    -   `src/Form/DataIntegrityWarningTrait.php`: Adds warnings about data impact  
          

**[Previous page: Plugin System](plugin-system.md)**

**[Next page: Services, Managers, Hooks, and Query Alters](services-managers-hooks-and-query-alters.md)**
