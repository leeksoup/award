# Activity Types and Plugins

## Purpose of Activity-Answer Plugins

Activity-Answer plugins define how educational content is presented to students, and how their responses are evaluated. These plugins provide the basic building blocks that allow the LMS to serve as both an assessment tool and an interactive learning environment.

The plugin system serves two primary educational approaches:

1.  **Assessment plugins**: Used for tests, quizzes, and evaluations (e.g., multiple-choice, true/false, free text)  
     
2.  **Interactive learning plugins**: Provide immediate feedback to guide learning (e.g., select with feedback, true/false with feedback)  
     

Educational research has shown that interactive learning with immediate feedback can make a significant improvement to knowledge retention and understanding — learners can immediately correct misconceptions and strengthen neural pathways associated with correct information. This approach is employed in many successful educational platforms like Duolingo, Khan Academy, and Coursera’s interactive exercises.

At the same time, the use of testing and quizzes is another pedagogical approach which has been shown to increase retention.  Known as the testing effect, presenting a quiz shortly after teaching the materials engages students' active recall and helps to anchor the information in long-term memory.

The flexibility of Drupal LMS's plugin system allows course creators to create lessons that provide interactivity and immediate feedback, which can then be followed by a lesson that quizzes students on what they have learned.  Or you can set up any other combination of learning activities to suit the material you're presenting.

The “with Feedback” plugin variants provide immediate responses to student input, which research shows improves learning outcomes.  The non-feedback variants can be more useful for tests and quizzes.

## How the Plugin System Works

Drupal LMS uses plugins to create different types of learning activities. Each plugin controls:

-   What students see and do with the activity
-   How the activity data is stored
-   How scores are calculated
-   What feedback students get

When you add an activity type as an LMS Admin user, you choose which plugin it will use. This sets what options and fields that activity type will have, and LMS Teacher users can then fill them out to create individual Activities.

Here are detailed descriptions of the different default plugins and their uses:

## No-Answer Activities

**Plugin ID**: `no_answer`

#### What It’s For

These activities present information without asking for answers. Use them for:

-   Instructions
-   Examples
-   Reading material
-   Transitions between other activities
-   Recap of lesson content

#### Setup

No Answer activities have a simple setup:

1.  **Name**: The title of the activity
2.  **Image**: An optional image to display with the content.  Available if the site has an image field type enabled.

You can add any other fields (like a rich text "Body" field) to the Activity Type, by navigating to its "Manage fields" page at `/admin/lms/activity_type/no_answer/edit/fields`.

#### How Students Use It

Students read the content and click “Next” to continue. There’s no grading for these activities.

#### Tips

-   Keep content short and clear
-   Use headings and bullet points
-   Add images or videos when helpful
-   Don’t make these too long  
     

No-Answer activities can serve as effective "knowledge checks" when placed between content sections—ask a question, then go over the answer in a follow-up No-Answer activity.

## Free Text Activities

### Basic Free Text

**Plugin ID**: `free_text`

#### What It’s For

Get written answers from students that teachers will grade after the lesson is complete. Good for:

-   Essays
-   Short answers
-   Reflections
-   Case study responses

#### Setup

-   **Name**: Activity title
-   **Questions**: Content or questions that you want students to respond to.  You can add multiple questions, and students will be given an answer area for each.
-   **Text Format**: What formatting options students have
-   **Minimum Characters**: How long answers need to be

#### How Students Use It

Students see the question and type their answer in a text box. A teacher will need to grade it later.

#### Grading

Teachers read student answers and give scores manually.  
 

Free Text activities are valuable for asking open-ended questions that can then be manually evaluated for formative assessment.

### Free Text with Feedback

**Plugin ID**: `free_text_feedback`

#### What It’s For

Give automatic feedback based on what students write, based on phrase matches. Good for: 

-   Guided thinking exercises
-   Knowledge checks with hints
-   Self-check activities

#### Setup

-   **Name**: Activity title
-   **Questions**: What you want students to answer
-   **Phrase Feedback**: Words to look for and feedback to give
    -   **Phrase**: Words or phrases to match with the student answer (use | for alternatives)
    -   **Feedback If Present**: Message when the phrase is found
    -   **Feedback If Absent**: Message when the phrase is missing
-   **Minimum Characters**: How long answers need to be

#### How Students Use It

Students type their answer and click “Check Answer” to get feedback about what they included or missed. They can improve their answer before final submission.

#### Grading

Scores are calculated based on how many expected phrases the student included.

Free Text with Feedback is useful as a fully automated way to check up on the students' knowledge base, in situations where they may be expected to cover a number of particular topics in an open-ended answer.

## Multiple Choice Activities

Multiple choice activities can be configured for either single-answer (radio buttons) or multiple-answer (checkboxes) questions. They are handled by two flexible plugins that give you the choice between automatically graded non-feedback questions, and the with-feedback variant that gives learners immediate feedback on their selected answer(s).

### Select Answer(s) Plugin

**Plugin ID**: `select`

#### What It’s For

Use this for classic multiple-choice or "select all that apply" questions, for student evaluations like quizzes and tests where feedback is not given immediately. Good for: 

-   Knowledge testing 
-   Concept checks 
-   Term definitions
-   Scenarios with one or more valid options

#### Configuration

The choice between a single or multiple answer question is configured in the **Activity Type**, not on the individual activity. When creating or editing the Activity Type, choose the "Selection type" in the plugin configuration.

This one plugin allows you to add two `select` Activity Types to your site, which you could name **Multiple Choice** and **Multiple Select**, or whatever names suit your site.

#### Activity Setup

-   **Name**: Activity title
-   **Question**: What you’re asking
-   **Answers**: A list of options for the student to choose their answer from. For single-answer types, mark only one as correct. For multiple-answer types, mark all correct options.
    -   **Answer Text**: The text of each option
    -   **Correct**: Checkbox to select if this is a correct answer

#### How Students Use It

Students select an option (or options) and click Submit. The system grades it automatically.

#### Grading

-   **Single Answer:** Full points for the correct answer, zero for a wrong answer.
-   **Multiple Answer:** The score is a fraction of the maximum score, based on the percentage of correctly identified options.

Multiple Choice and True/False questions are an efficient way to test large numbers of students for a summative assessment.

### Select Answer(s) with Feedback Plugin

**Plugin ID**: `select_feedback`

#### What It’s For

Give immediate, interactive feedback on multiple-choice and multi-select answers. Good for: 

-   Interactive and guided learning
-   Practice questions 
-   Correcting common mistakes

#### Configuration

As with the basic `select` plugin, the choice between a single or multiple answer question is configured in the **Activity Type**. When creating or editing the Activity Type, choose the "Selection type" in the plugin configuration.

This one plugin allows you to add two `select_feedback` Activity Types to your site, which you could name, for example, **Multiple Choice with Feedback** and **Multi-Select with Feedback**.

#### Activity Setup

-   **Name**: Activity title
-   **Question**: What you’re asking
-   **Answers**: A list of options for the student to choose their answer from. For single-answer types, mark only one as correct. For multiple-answer types, mark all correct options.
-   **Feedback If Correct**: Text shown for right answers
-   **Feedback If Wrong**: Text shown for wrong answers

#### How Students Use It

Students select an option and click “Check Answer” to get immediate feedback. Their selected option(s) may change style and color, depending on theme settings, and the feedback text is shown. Based on the feedback, they can change their answer and re-check before submitting their final answer.

#### Grading

Grading logic is identical to the non-feedback version, but it occurs after the student has had a chance to learn from the interactive feedback.

The "with feedback" variants of multiple-choice and multi-select activities are useful to engage students in active learning.  They promote learning through mistakes, metacognitive processes ("I think this is right because..."), and improved retention through interaction.

## True/False Activities

### Basic True/False

**Plugin ID**: `true_false`

##### What It’s For

Create simple true/false questions. Good for:

-   Fact checking
-   Statement evaluation 
-   Quick knowledge tests

#### Setup

-   **Name**: Activity title
-   **Description**: The statement to judge as true or false
-   **Expected Answer**: Whether the statement is true or false

#### How Students Use It

Students read the statement and select “True” or “False”. The system grades it automatically.

#### Grading

All points for the correct judgment, zero for the incorrect one.

### True/False with Feedback

**Plugin ID**: `true_false_feedback`

#### What It’s For

Give immediate feedback on true/false answers. Good for: 

-   Correcting misunderstandings 
-   Guiding student thinking 
-   Explaining important concepts

#### Setup

-   **Name**: Activity title
-   **Description**: The statement to judge as true or false
-   **Expected Answer**: Whether the statement is true or false
-   **Feedback If Correct**: Text shown for the right judgment
-   **Feedback If Wrong**: Text shown for the wrong judgment

#### How Students Use It

Students select “True” or “False” and click “Check Answer” to get feedback. Their selected option may change style and color, depending on theme settings, and the feedback text is shown. They can change their answer and re-check, then submit their final answer.

#### Grading

All points for the correct judgment, zero for the incorrect one.

True/False with Feedback is useful to immediately correct common misconceptions.  For example a corporate compliance training could use this activity type for better understanding of company policies or regulations.

## Fill in the Blanks Activities

Fill in the Blanks activities present a passage of text with one or more missing words, which students fill in by dragging word options into blank spaces rather than typing. They are handled by two related plugins that give you the choice between an automatically graded non-feedback question, and a with-feedback variant that gives learners immediate, per-blank feedback.

### Basic Fill in the Blanks

**Plugin ID**: `fill_in_the_blanks`

#### What It’s For

Test whether students can recall specific words, terms, or figures in context, using a drag-and-drop interface instead of free typing. Good for:

-   Vocabulary and terminology checks
-   Recalling key facts or figures within a passage
-   Sentence or passage completion exercises

#### Setup

-   **Name**: Activity title
-   **Question text**: The passage of learning content, with each correct answer enclosed in square brackets (e.g., "The capital of France is \[Paris\]."). Each bracketed word or phrase becomes a blank with its own drop zone.
-   **Distraction Words**: Optional incorrect words to mix into the pool of draggable options, so students can't simply match the number of blanks to the number of available words.

#### How Students Use It

Students see the passage with a drop zone in place of each bracketed word, plus a shuffled pool of draggable words (the correct answers combined with any distraction words). They drag words from the pool into the blanks, and must fill every blank before they can submit. The system grades it automatically.

#### Grading

The score is a fraction of the maximum score, based on the percentage of blanks filled with the correct word.

Fill in the Blanks activities are a more hands-on alternative to free text or multiple choice for testing recall of specific terms within a passage.

### Fill in the Blanks with Feedback

**Plugin ID**: `fill_in_the_blanks_feedback`

#### What It’s For

Give immediate, interactive feedback on a drag-and-drop fill-in-the-blanks exercise. Good for:

-   Interactive vocabulary or terminology practice
-   Guided reading comprehension
-   Reinforcing key facts within a passage

#### Setup

-   **Name**: Activity title
-   **Question text**: Same bracketed-answer format as the basic plugin
-   **Distraction Words**: Optional incorrect words to mix into the draggable pool
-   **Feedback If Correct**: Text shown when the student has correctly filled all blanks
-   **Feedback If Wrong**: Text shown when the student's answer is incorrect or incomplete

#### How Students Use It

Students drag words into the blanks and click "Check Answer" to get feedback. Each blank changes style and color, depending on theme settings, to show whether it was filled correctly, and the feedback text is shown. They can rearrange their answers and re-check before submitting their final answer.

#### Grading

Grading logic is identical to the non-feedback version — a fraction of the maximum score based on the percentage of correctly filled blanks — but it occurs after the student has had a chance to learn from the interactive feedback.

Fill in the Blanks with Feedback is useful for reinforcing terminology or key facts through guided, hands-on practice rather than passive reading.

## Choosing the Right Activity Type

Here are some things to think about when picking activity types:

### What's the Learning Goal?

-   **Remember Facts**: Multiple choice, true/false
-   **Understand Concepts**: Free text, multiple choice with feedback
-   **Apply Knowledge**: Free text, scenario-based multiple choice
-   **Analyze Information**: Free text with feedback
-   **Create Something New**: Free text (teacher-graded)

### What are the Grading Needs?

-   **Immediate Results**: Use auto-graded types (multiple choice, true/false)
-   **Detailed Feedback**: Use teacher-graded types (free text)
-   **Learning Support**: Use feedback-enabled types

### Maintaining Student Interest

-   **Variety**: Use different activity types to keep things interesting
-   **Interaction**: Feedback activities keep students engaged
-   **Difficulty**: Start with easier activities, then move to harder ones

### Course Structure

-   **Tests**: Use non-feedback types for lessons with the purpose of giving a quiz or final test
-   **Practice**: Use feedback types for interactive learning and practice
-   **Content Delivery**: Use no-answer activities for information

Using the right activity types helps make your course more effective and engaging.

It helps to mix up a lesson with a variety of different activities to maintain engagement and improve retention.

[**Previous page: Activities**](activities.md)

**[Next page: Lessons](lessons.md)**
