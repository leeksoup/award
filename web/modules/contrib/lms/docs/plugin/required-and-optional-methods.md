# Required and Optional Methods

## Base Class Plugin Methods

Activity-Answer plugins can include a number of methods, which control their behavior at different stages in the activity's usage. This section will go over the required and optional methods that your plugin will need to include.

Only the `answeringForm()` method is strictly required if your plugin extends the `ActivityAnswerBase` class; other methods are conditional or optional, based on your plugin’s functionality.

### Required Method: `answeringForm()`

The method defines the form elements presented to students for answering the activity. It is called when rendering the answer form.

    /**
     * {@inheritdoc}
     */
    public function answeringForm(array &$form, FormStateInterface $form_state, Answer $answer): void {
      $activity = $answer->getActivity();
      $data = $answer->getData();
    
      // Add form elements for student answers.
      $form['answer'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Your answer'),
        '#default_value' => $data['answer'] ?? '',
        '#required' => TRUE,
      ];
    }

This method: 

-   Receives the current form array, form state, and Answer entity  
     
-   Has access to the Activity entity through `$answer->getActivity()`   
     
-   Can add any Form API elements needed for student interaction   
     
-   Should populate default values from the Answer entity’s data if available, for cases when the question is revisited  
      
-   Is called every time a student views the activity in a course.  
      

### Optional Method: `evaluatedOnSave()`

The `evaluatedOnSave()` method determines whether an answer should be automatically evaluated when submitted. The base class implementation returns `TRUE`.

    /**
     * {@inheritdoc}
     */
    public function evaluatedOnSave(Answer $answer): bool {
      // Return TRUE for auto-evaluated activities, FALSE for manual evaluation.
      return FALSE;
    }

This method: 

-   Returns a boolean value   
     
-   `TRUE` (default): The answer will be automatically evaluated using `getScore()`, which is a required method with this setting.  
     
-   `FALSE`: The answer requires manual evaluation by an instructor — no `getScore()` calculation method needed in the plugin; `evaluationDisplay()` method is required.  
      

An example of a manually evaluated plugin is `FreeText` in `modules/lms_answer_plugins/src/Plugin/ActivityAnswer/FreeText.php` which implements this method to return `FALSE`.  
  

### Conditional Method: `getScore()`

The `getScore()` method is required when `evaluatedOnSave()` returns `TRUE` (or is omitted). It calculates a score for the answer.

    /**
     * {@inheritdoc}
     */
    public function getScore(Answer $answer): float {
      // Get the student's submitted data from the Answer entity.
      $data = $answer->getData();
      $student_answer = $data['answer'] ?? '';
    
      // Get the expected correct answer from the parent Activity entity.
      $activity = $answer->getActivity();
      $correct_answer = $activity->get('field_correct_answer')->value;
    
      // Compare the values and return a float between 0.0 and 1.0.
      if (mb_strtolower($student_answer) === mb_strtolower($correct_answer)) {
        return 1.0; // 100% correct
      }
      
      return 0.0; // 0% correct
    }

This method: 

-   Returns a float between 0.0 and 1.0 (representing 0-100%) — this will later be multiplied by the MaxScore setting for the activity and rounded down  
     
-   Is called automatically when a student submits an answer   
     
-   Should implement the scoring logic specific to your plugin  
     

For example, the `true_false` plugin implements a `getScore()` method that checks if the student has selected the correct radio button, and returns a 1.0 value if so and a 0.0 value if not.

Other plugins give fractional levels of correctness, depending how many of the potential correct answers a student has selected.  
  

### Conditional Method: `evaluationDisplay()`

The `evaluationDisplay()` method is required for any plugin with manually evaluated answers (i.e., when you set `evaluatedOnSave()` to return `FALSE`).

This method should return a render array for displaying the student's answer, which is then used in evaluation and reporting.

    /**
     * {@inheritdoc}
     */
    public function evaluationDisplay(Answer $answer): array {
      $data = $answer->getData();
      $student_answer_text = $data['answer'] ?? $this->t('No answer submitted.');
    
      // It's good practice to show the original activity question for context.
      $build['activity'] = $this->entityTypeManager
        ->getViewBuilder('lms_activity')
        ->view($answer->getActivity(), 'activity');
    
      // Display the student's answer.
      $build['answer'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Student Answer'),
        'content' => [
          '#markup' => nl2br(htmlspecialchars($student_answer_text)),
        ],
      ];
      
      return $build;
    }

This method: 

-   Returns a render array for displaying the student’s answer  
     
-   Is used in the instructor’s evaluation form and answer details display  
     
-   Should present the answer clearly for assessment  
     

The `FreeText` plugin in `modules/lms_answer_plugins/src/Plugin/ActivityAnswer/FreeText.php` provides a good example of implementing this method, to allow teachers to give a score and provide rich-text feedback.  
 

The easiest development path is to implement methods incrementally — you can start with a minimal working plugin, then add advanced features one by one.

## Interactive Feedback Methods

If you use the `WithFeedbackPluginTrait` to add AJAX-powered feedback to your plugin, there are two required methods to implement in your plugin class.  The trait relies on these methods to get the feedback content and apply visual styles.  
  

### Required Method: `buildFeedbackRenderArray()`

This method is called by the trait's AJAX callback after a student's answer has been evaluated. Its purpose is to build the render array for the feedback that will be displayed to the student.

-   **Parameters:**
    -   `bool $is_correct`: Whether the student's answer was correct.  
          
    -   `Answer $answer`: The student's answer entity.  
          
    -   `ActivityInterface $activity`: The parent activity entity.  
          
-   **Returns:** A render array containing the feedback to be displayed.

#### Example

    /**
     * Builds the render array for feedback.
     *
     * This method is required by WithFeedbackPluginTrait.
     */
    private function buildFeedbackRenderArray(bool $is_correct, Answer $answer, ActivityInterface $activity): array {
      $feedback_text = $is_correct ? $this->t('Correct!') : $this->t('Not quite, try again.');
      
      return [
        '#markup' => '&lt;div class="feedback-message"&gt;' . $feedback_text . '&lt;/div&gt;',
        '#attributes' => ['class' => [$is_correct ? 'correct-answer' : 'wrong-answer']],
      ];
    }

### Required Method: `addAnswerClassesToForm()`

This method is called by the trait's AJAX callback to add CSS classes directly to the form elements (e.g., the radio buttons or checkboxes) for immediate visual feedback.

-   **Parameters:**
    -   `array &$form`: The form array (passed by reference).  
          
    -   `bool $is_correct`: Whether the student's overall answer was correct.  
          
    -   `Answer $answer`: The student's answer entity, containing their selection.  
          
-   **Returns:** `void`. This method modifies the `$form` array directly.

#### Example

For a single-select/radio button activity:

    /**
     * Adds CSS classes to the form for visual feedback.
     *
     * This method is required by WithFeedbackPluginTrait.
     */
    private function addAnswerClassesToForm(array &$form, bool $is_correct, Answer $answer): void {
      $current_answer_key = $answer->getData()['answer'] ?? NULL;
    
      if ($current_answer_key !== NULL && \array_key_exists($current_answer_key, $form['answer_feedback_wrapper']['answer'])) {
        $class_to_add = $is_correct ? self::CLASS_CORRECT : self::CLASS_WRONG;
        $form['answer_feedback_wrapper']['answer'][$current_answer_key]['#wrapper_attributes']['class'][] = $class_to_add;
      }
    }

[**Previous page: Fundamentals of Activity-Answer Plugins**](fundamentals-of-activity-answer-plugins.md)

[**Next page: Plugin Configuration Files**](plugin-configuration-files.md)
