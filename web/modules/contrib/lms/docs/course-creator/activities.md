# Activities

## Understanding Activities

Activities are the building blocks that make up lessons and courses in Drupal LMS. They are the interactive elements that students directly engage with throughout their learning journey. Activities give you the option to create course content in multiple formats:

-   Instructional content with no answer required
-   Various question-answer types requiring student responses
-   Interactive content with immediate feedback, for improved learning and retention

When deciding how to present a piece of educational content, first consider how you would like the student to interact with it.  That will dictate what kind of Activity you choose to create.  This interactivity is what gives an LMS platform its engagement and improved learning outcomes.

Each **Activity** is a piece of learning content, and is based on an **Activity Type**.  The Activity Type defines the functionality and content fields for a specific kind of activity (e.g., "True/False" or "Multiple Choice Question").  Each Activity Type is powered by an **Activity-Answer Plugin**, which is the underlying code that controls how the activity behaves, how it's graded, and how feedback is provided.

## Creating Activities

To create a new activity:

1.  Navigate to **Admin > LMS > Activities** (`/admin/lms/activity`)
2.  Click **Add Activity**
3.  Select the appropriate activity type from the available options (see **[Activity Types](activity-types-and-plugins.md)** for detailed explanations of each)
4.  Fill in the **Name** field. This is displayed both as the headline of the activity when it's shown to students, and it's also used in lists of activities when selecting them to include in one or more lessons.
5.  Fill in the type-specific fields that appear based on your selected activity type:
    -   Text-based activities include question prompts and response fields
    -   Multiple-choice activities contain option lists and correct answer indicators
    -   Feedback-enabled activities include interactive feedback to give to students based on their answer
6.  Click **Save** to create the activity

## Activity Configuration Options

In general terms, these are some of the configuration elements that activities may contain:

-   **Name**: The descriptive title displayed to students
-   **Description**: Instructional text explaining the activity purpose
-   **Question**: The specific prompts or content that students will respond to
-   **Feedback**: Response-specific guidance and feedback

Different activity types present specialized configuration fields based on their purpose:

-   Multiple choice activities include option lists with correct answer designation
-   Free text activities include minimum character requirements
-   True/False activities require an expected correct answer

The specific settings for each activity type are covered in more detail in the next section of this guide: [**Activity Types and Plugins**](activity-types-and-plugins.md).

Depending on the site's [**Text formats**](https://www.drupal.org/docs/user_guide/en/structure-text-formats.html) that are configured for use in Activities, course creators can add rich WYSIWYG content into the Question area, including pictures, videos, slideshows, and more.  This allows you to mix presentation of concepts with interactive elements for improved engagement and retention.

## Activity Reusability

Activities are designed for reuse across multiple lessons:

1.  Create a comprehensive activity once
2.  Reference it within any number of lessons
3.  Configure context-specific parameters (like maximum score) for each instance

This approach facilitates:

-   Development of a centralized activity repository
-   Simplified content maintenance with single-source updates
-   Consistent assessment elements across different learning contexts  
     

You can create a library of common activities that can be shared across courses to maintain consistency and save time.  But it's important to **remember that changing an activity will affect all lessons that include it**.  If you need a different version of an activity for a specific lesson, it's best to clone it and create a new one.

## Activity Design Best Practices

-   **Interaction-First**: Before writing content or questions, decide how you want a student to interact with it, and choose the activity type accordingly.
-   **Clear Instructions**: Include unambiguous directions so students understand your expectations.
-   **Appropriate Difficulty**: Calibrate complexity to match student knowledge levels and learning objectives.
-   **Consistent Formatting**: Maintain uniform presentation patterns across related activities.
-   **Constructive Feedback**: For feedback-enabled activities, offer specific guidance that facilitates learning — positive reinforcement for correct answers, and alternative approaches or viewpoints for wrong answers.
-   **Test with a Student Account**: Before making an activity live in a course, log in as a test student to experience it exactly as a learner will.

Activities are best kept focused on a single learning objective—a common instructional design mistake is creating overly complex activities that cover multiple concepts.

[**Previous page: General Settings**](general-settings.md) 

**[Next page: Activity Types & Plugins](activity-types-and-plugins.md)**
