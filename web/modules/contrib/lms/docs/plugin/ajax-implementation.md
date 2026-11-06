# AJAX Implementation

Interactive feedback is a powerful feature of the LMS module. AJAX allows plugins to give students immediate feedback without page reloads, creating dynamic and engaging activities.

There are two primary ways to implement AJAX in your Activity-Answer plugin:

1.  **Using the `WithFeedbackPluginTrait`**:  A core plugin trait for providing "Check Answer" style feedback.  
      
2.  **Manual AJAX Implementation:**  If you want to include custom interactions that go beyond simple answer checking.  
      

## Using the `WithFeedbackPluginTrait`

For the common use case of providing immediate feedback on a student's answer, the module includes a helper trait that handles most of the boilerplate code for you. This is the recommended approach for any plugin that has a "Check Answer" button.

### How to Use the Trait

1.  **Use the Trait in Your Plugin Class:** Add `use \Drupal\lms_answer_plugins\Plugin\WithFeedbackPluginTrait;` to your plugin class.  
      
2.  **Call the Helper Method:** In your `answeringForm()` method, after defining your answer fields, call `$this->addFeedbackElementsToAnsweringForm($form, $form_state, $answer);`. This will add the "Check Answer" button and a container for the feedback.  
      
3.  **Implement Required Methods:** The trait requires you to implement two methods in your class:
    -   `buildFeedbackRenderArray()`: This is where you define the render array for the feedback that gets shown to the user.  
          
    -   `addAnswerClassesToForm()`: This is where you can add CSS classes to your form elements for visual styling (e.g., highlighting a correct or incorrect radio button).

For a complete example, see the implementation in the **`SelectFeedback`** plugin at `modules/lms_answer_plugins/src/Plugin/ActivityAnswer/SelectFeedback.php`.

## Manual AJAX Implementation

If your plugin requires more complex AJAX interactions than the feedback trait provides (e.g., revealing hints, multi-step interactions), you can implement the AJAX functionality manually. This gives you full control over the process.

### Step 1: Add an AJAX-enabled Element and a Wrapper

In your plugin's `answeringForm()` method, you need to define the element that will trigger the AJAX call and a container that will be updated with the response.

    public function answeringForm(array &$form, FormStateInterface $form_state, Answer $answer): void {
      $activity_id = $answer->getActivity()->id();
    
      // 1. Create a wrapper with a unique ID that can be targeted by AJAX.
      $form['my_plugin_wrapper'] = [
        '#type' => 'container',
        '#attributes' => ['id' => 'my-plugin-wrapper-' . $activity_id],
      ];
    
      // 2. Add your form elements.
      $form['answer'] = [ /* ... */ ];
    
      // 3. Add a button or other form element with an #ajax property.
      $form['actions']['check'] = [
        '#type' => 'button',
        '#value' => $this->t('Get Hint'),
        '#ajax' => [
          'callback' => [$this, 'myAjaxCallback'],
          'wrapper' => 'my-plugin-wrapper-' . $activity_id, // Target the container.
        ],
      ];
    }

### Step 2: Implement the AJAX Callback Method

The callback method is responsible for processing the request and returning an `AjaxResponse` with commands to update the page.

    use Drupal\Core\Ajax\AjaxResponse;
    use Drupal\Core\Ajax\HtmlCommand;
    
    /**
     * AJAX callback for providing a hint.
     */
    public function myAjaxCallback(array &$form, FormStateInterface $form_state): AjaxResponse {
      $response = new AjaxResponse();
    
      // Your custom logic to generate the content goes here.
      $hint_text = 'This is a helpful hint for the student.';
    
      // The content to be placed in the wrapper.
      $content = [
        '#markup' => '&lt;div class="hint"&gt;' . $hint_text . '&lt;/div&gt;',
      ];
    
      // Create a command to replace the content of the wrapper.
      $activity_id = $form_state->getFormObject()->getEntity()->getActivity()->id();
      $response->addCommand(new HtmlCommand('#my-plugin-wrapper-' . $activity_id, $content));
    
      return $response;
    }

Remember to add the necessary `use` statements for any AJAX command classes you use at the top of your plugin file.

For implementing hints systems (like those in Khan Academy), your plugin could use AJAX to progressively reveal assistance without requiring full page reloads.

## Reference AJAX Examples

For complete examples of AJAX implementation in LMS plugins, see:

1.  **FreeTextFeedback plugin** (`modules/lms_answer_plugins/src/Plugin/ActivityAnswer/FreeTextFeedback.php`):
    -   Uses AJAX to provide feedback based on phrase matching
    -   Includes a `isPhraseFound()` method that checks for matched phrases
    -   Returns an AjaxResponse with ReplaceCommand  
         
2.  **SelectFeedback plugin** (`modules/lms_answer_plugins/src/Plugin/ActivityAnswer/SelectFeedback.php`):
    -   Adds visual feedback when a student submits their answer
    -   Adds CSS classes for correct/incorrect answers server-side in `addAnswerClassesToForm()`, which sets them on the element's `#wrapper_attributes['class']`; the AJAX response then uses `ReplaceCommand` (via `WithFeedbackPluginTrait::ajaxFeedbackCallback()`) to swap in the re-rendered elements with those classes already baked in
    -   Displays appropriate feedback based on the answer  
         

## AJAX Command Types

Drupal provides a number of AJAX commands that may be useful to use in your callbacks:

1.  **ReplaceCommand**: Replace content in a specific selector
    
        $response->addCommand(new ReplaceCommand($selector, $content));
    
2.  **HtmlCommand**: Set the HTML content of a specific selector
    
        $response->addCommand(new HtmlCommand($selector, $content));
    
3.  **InvokeCommand**: Call a jQuery method on a specific selector
    
        $response->addCommand(new InvokeCommand($selector, 'addClass', ['correct-answer']));
    
4.  **AppendCommand**: Append content to a specific selector
    
        $response->addCommand(new AppendCommand($selector, $content));
    
5.  **AlertCommand**: Display an alert dialog
    
        $response->addCommand(new AlertCommand($message));
    

For more information about Drupal's AJAX implementation, and a complete list of available commands, see:

-   [Drupal Core AJAX Forms](https://www.drupal.org/docs/develop/drupal-apis/javascript-api/ajax-forms)
-   [Core AJAX Callback Commands](https://www.drupal.org/docs/develop/drupal-apis/ajax-api/core-ajax-callback-commands)
-   [Core AJAX API](https://api.drupal.org/api/drupal/core%21core.api.php/group/ajax/11.x)

[**Previous page: Custom Field Types and Widgets**](custom-field-types-and-widgets.md)

[**Next page: Integration and Deployment**](integration-and-deployment.md)
