# Lessons

## What Are Lessons?

Lessons are collections of activities that form learning units. A lesson can:

-   Contain multiple activities (questions, content, interactions)
-   Have settings for timing, scoring, and navigation
-   Present activities in a fixed or random order
-   Present a sub-set of the available activities in random order
-   Be required or optional in a course
-   Be reused in multiple courses, with custom settings for each

## Creating Lessons

To create a new lesson:

1.  Go to **Admin > LMS > Lessons** (`/admin/lms/lesson`)
2.  Click **Add Lesson**
3.  Fill in the basic information:
    -   **Name**: The title of your lesson
    -   **Description**: What the lesson is about
4.  Set the lesson options (explained below)
5.  Add activities to the lesson
6.  Click **Save**

## Lesson Settings

Lesson settings can make a big difference to the student experience.  It's a good idea to carefully choose the settings that are appropriate for the lesson objectives.

### Backwards Navigation

-   **Enabled**: Students can go back to previous activities in the lesson
-   **Disabled** (default): Students can only move forward

To set this: 

-   Check/uncheck the **Backwards navigation** box when creating or editing a lesson 
-   Default: Disabled

If the course-level **[Free Navigation setting](courses.md#free-navigation)** is turned on, it will override the lesson-level **Backwards navigation** setting. In a course with Free Navigation, students can always move backwards, regardless of this lesson's setting.

### Randomization Options

Lessons offer three ways to show activities:

-   **No randomization**: Activities appear in the order you set
-   **Random order**: All activities appear, but in a random order for each student
-   **Random activities**: A set number of activities are randomly picked from the full set

To set this: 

1.  Select an option under **Randomize activities** when creating or editing a lesson
2.  If you choose “Random activities”, set the **Number of random activities** to use

One good use of randomization is for assessment lessons, like quizzes and final tests, to reduce the likelihood of students sharing answers.

### Time Limits

Time limits are set when adding a lesson to a course. When time runs out:

-   The lesson is automatically submitted with any unanswered questions marked incomplete.
-   The student is moved to the next lesson or the course end page.
-   A message tells the student that time has expired.

## Adding Activities to Lessons

Activities are added to lessons through a reference field:

1.  In the lesson edit form, find the Activities section
2.  Click **Create activity** to add a new activity, or **Reference activities** to add an existing activity
3.  Select an activity from the list
4.  Set the activity options for this specific instance of the activity:
    -   **Maximum score**: The points awarded for correct completion in this lesson. The same activity could have a different maximum score in another lesson.
5.  Arrange activities in the order you want using drag-and-drop (unless using randomization)
6.  Click **Save** on the lesson form

### Organizing Activities in Lessons

Consider these ways to organize activities:

-   **Easy to Hard**: Start simple, then get more complex
-   **By Topic**: Group activities by subject or concept
-   **Learning Flow**: Start with information, then practice, then test
-   **Check Understanding**: Mix content with quick questions

## Lesson Scoring

Each lesson has its own scoring system:

-   **Activity Scores**: Each activity within a lesson is assigned a Maximum score. A student's earned points for that activity are stored as their Score.
-   **Lesson Total**: The system calculates a student's total score for the lesson as a percentage. It is calculated by summing the student's scores for all activities and dividing by the sum of all possible maximum scores.
-   **Required Score**: The minimum percentage a student must achieve to pass the lesson. This is set when you add the lesson to a course.
-   **Lesson Status**: Shows if a lesson is "In progress", "Needs grading", "Passed", or "Failed" based on the student's progress and final score.

### Automatic vs. Manual Grading

Lesson grading depends on the activities it contains:

-   Lessons with only auto-graded activities (like multiple choice) are scored right away  
     
-   Lessons that contain teacher-graded activities (like essays) show as “Needs grading” until a teacher grades them

## Reusing Lessons

A key feature of Drupal LMS is that any lesson can be used in multiple courses:

1.  Create the lesson once;
2.  Add it to any course you want;
3.  Set course-specific options for the lesson in each course where it's used. For each instance of a lesson, you can define unique parameters, including:
    -   Whether the lesson is **Mandatory**.
    -   The **Required Score** to pass.
    -   A **Time Limit**.

This lets you: 

-   Keep a central collection of lessons.
-   Update lesson content in one place and have it reflect everywhere.
-   Create different learning paths using the same content.
-   Customize each instance of a lesson. For example one could be an easy version with a low required score, and another could be a hard version with a high required score.

## Lesson Best Practices

-   **Right Length**: Keep lessons focused on specific learning goals
-   **Consistent Structure**: Use similar patterns in all lessons
-   **Clear Instructions**: Start with an activity that explains what to do
-   **Mix Content Types**: Include both information and interactive elements
-   **Include Feedback**: Use activities with feedback for better learning
-   **Test First**: Try the lesson yourself before giving it to students

**[Previous page: Activity Types & Plugins](activity-types-and-plugins.md)**

**[Next page: Courses](courses.md)**
