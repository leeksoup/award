# Fundamentals of Activity-Answer Plugins

This section will help you understand how the plugin components work together within the LMS architecture. Before diving into the code, let's go over the fundamental concepts and structure.

## Plugin Class Structure

Every Activity-Answer plugin consists of a PHP class that:

1.  Is located in the `src/Plugin/ActivityAnswer/` directory (either in your custom module, or the LMS lms\_answer\_plugins submodule)  
     
2.  Implements `ActivityAnswerInterface` on its own or by extending `ActivityAnswerBase` — this will be explained in detail later in the guide  
      
3.  Is given a name and ID in the `ActivityAnswer` attribute  
     
4.  Implements required methods (at minimum `answeringForm()`)  
     

The basic structure of an Activity-Answer plugin class looks like this:

    <?php
    
    declare(strict_types=1);
    
    namespace Drupal\my_module\Plugin\ActivityAnswer;
    
    use Drupal\Core\Form\FormStateInterface;
    use Drupal\Core\StringTranslation\TranslatableMarkup;
    use Drupal\lms\Attribute\ActivityAnswer;
    use Drupal\lms\Entity\Answer;
    use Drupal\lms\Plugin\ActivityAnswerBase;
    
    /**
     * Provides a custom activity plugin.
     *
     * @see \Drupal\lms\Plugin\ActivityAnswerInterface
     * @see \Drupal\lms\Plugin\ActivityAnswerBase
     */
    #[ActivityAnswer(
      id: 'my_custom_plugin',
      name: new TranslatableMarkup('My Custom Plugin'),
    )]
    final class MyCustomPlugin extends ActivityAnswerBase {
    
      /**
       * {@inheritdoc}
       */
      public function answeringForm(array &$form, FormStateInterface $form_state, Answer $answer): void {
        // Your form element logic goes here.
        $form['answer'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Your answer'),
          '#default_value' => $answer->getData()['answer'] ?? '',
        ];
      }
    
    }

## Activity-Answer Usage

Understanding the usage of an activity and answer is helpful for developing effective plugins. The typical lifecycle is:

1.  **Creation**: An instructor creates an Activity, selecting an Activity Type that's associated with your plugin  
     
2.  **Configuration**: The instructor configures the Activity using the fields defined by your plugin via its config files  
     
3.  **Presentation**: A student views the activity with form elements created by your plugin’s `answeringForm()` method  
     
4.  **Submission**: The student submits their answer. The raw input is stored in the `data` field of a dedicated Answer entity  
      
5.  **Evaluation**: The answer is evaluated either:
    -   Automatically by your plugin’s `getScore()` method, or  
          
    -   Manually by an instructor using your plugin’s `evaluationDisplay()` method  
          
6.  **Feedback**: The student receives feedback, either immediately through AJAX or after the teacher has completed their evaluation  
     

Each plugin handles both the instructor side (configuration and evaluation) and student side (interaction and feedback) of an activity.

## Data Flow and Storage

The data flow in Activity-Answer plugins involves several entity types:

1.  **Activity entity** (`lms_activity`): Stores the question and configuration
    -   Custom fields defined by your plugin store instructor-configured data  
          
    -   These fields determine how the question is presented  
          
    -   Depending on the plugin, may contain: possible answers to choose from, which answer is correct, and feedback for right and wrong answers  
          
2.  **Answer entity** (`lms_answer`): Stores the student’s answer and evaluation results
    -   `data` field: Contains the student’s answer as serialized data  
          
    -   `score` field: Stores the evaluated score  
          
    -   `evaluated` field: Indicates whether the answer has been evaluated  
          

It’s important to understand that your plugin doesn’t need to define any answer fields — the Answer entity’s `data` field handles this. Your plugin only needs to define fields required for configuring the activity.

In other words, your plugin defines the data that a course creator will store to present to a student, such as the question, possible answers to choose from, and feedback for right and wrong answers.

It may be helpful to consider your plugin’s user experience from the student’s perspective first, then work backwards to the instructor interface.

[**Previous page: Introduction**](introduction.md)

[**Next page: Required and Optional Methods**](required-and-optional-methods.md)
