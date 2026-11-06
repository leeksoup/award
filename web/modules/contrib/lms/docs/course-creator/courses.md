# Courses

## What Are Courses?

In Drupal LMS, courses are the main containers for your learning content. Each course:

-   Contains multiple lessons
-   Can have multiple classes of students
-   Has its own navigation and progress tracking
-   Is set up as a specialized group type

## Creating Courses

To create a new course:

1.  Go to **Admin > LMS > Courses** (`/admin/lms/courses`)
2.  Click **Add Course**
3.  Fill in the basic information:
    -   **Title**: The name of your course
    -   **Description**: What the course is about
4.  Set course options (explained below)
5.  Add lessons to the course (explained below)
6.  Click **Save**

When you create a course, if you have the optional **LMS Classes** sub-module enabled, a default class will be automatically created and associated with your new course. You can edit the class name or add more classes later.

## Course Settings

### Free Navigation

-   **Enabled**: Students can jump to any activity at any time
-   **Disabled** (Default): Students must complete activities and lessons in sequential order

Free navigation allows students to jump between activities, while disabled free navigation forces sequential completion. Enabling Free Navigation at the course level overrides the lesson-level **[Backwards navigation setting](lessons.md#backwards-navigation)**, allowing students to move freely in all directions.

### Allow to revisit finished course

This setting controls what happens after a student completes a course (i.e., their status is "Passed," "Failed," or "Needs grading").

-   **Enabled** (Default): Students can return to a completed course to review content and their submitted answers. Importantly, this also allows them to **change and resubmit their answers**, which will update their score.  
      
-   **Disabled**: Once a course is completed, it’s locked. The student cannot re-enter to view content or change answers.  Once their course is graded (which can be immediate if there are no manually graded activities), they can access their results, and the course will display a Restart button which starts a new, separate attempt from the beginning.

#### How Global & Course Revisit Settings Interact

The course-level "Allow to revisit" setting works together with the global "Allow to enter ungraded courses" setting (found at `/admin/lms/settings/lms`). The behavior changes depending on the student's course status:

Student's Course Status

"Allow to revisit"  
course setting

Student Access

In Progress

No effect

✓ Student can always access the course.

Passed / Failed

**Enabled**

✓ Student can revisit, review, and change answers.

Passed / Failed

**Disabled**

✗ Student can access their results but not the course. The course will display a **Restart** button which will begin a new attempt.

Needs grading

Global "Allow to enter ungraded courses": **Enabled**

✓ Student can continue into the course (ungraded) regardless of the course-level "Allow to revisit" setting.

Needs grading

Global "Allow to enter ungraded courses": **Disabled**

✗ Student can't access the course until a teacher submits a grade. The course-level "Allow to revisit" setting has no effect on courses in this status.

You can consider the course-level "Allow to revisit" setting as the control for **Passed/Failed** courses. For courses in "Needs grading" status, the course-level setting has no effect — only the global "Allow to enter ungraded courses" setting controls access, until grading is complete.

## Adding Lessons to Courses

Lessons are added to courses through a reference field:

1.  In the course edit form, find the **Lessons** section
2.  Click **Create lesson** to add a new lesson, or **Reference lessons** to add an existing lesson
3.  Select a lesson from the list
4.  Set lesson parameters:
    -   **Mandatory**: If checked, students must complete this lesson to progress
    -   **Auto-repeat if failed**: If checked, students who fail must retake the lesson
    -   **Required score \[%\]**: Minimum percentage score needed to pass
    -   **Time limit \[min\]**: Maximum time allowed (0 = no limit)
5.  Arrange lessons in the order you want using drag-and-drop
6.  Click **Save** on the course form

## Configuring the Courses Display

The main `/courses` page displays each course using a "Course Card" component in a responsive grid. You can customize the information shown on these cards — such as a background image, a description, and other custom details — by adding fields to the **Course** group type and configuring the view.

### Customizing Course Cards

Here's how to add a custom background image and description to each course card. You can follow this general process to add any other custom fields.

1.  Navigate to the Course group type's fields page: **Administration > Groups > Group types** and click **Manage fields** for the **Course** group type (`/admin/group/types/manage/lms_course/fields`).  
      
2.  **Add new fields**: Click **\+ Add field** to create each new field.
    
    -   For a **description**, a `Text (formatted, long, with summary)` field is recommended, it allows you to write a main rich-text description for the course page, and also a short summary specifically for the course card. You can use your site's Body field (or equivalent), or you can add one specifically for courses and give it a label like "Course Description".
    -   For a **background image**, an `Image` or `Media` field is suitable.  Make sure the Image and/or Media modules are enabled and that there is an appropriate image type configured.
    
    Save each field and its settings.
    
3.  **Configure Field Display:** On the same page, go to the **Manage form display** and **Manage display** tabs to adjust how your new field appears on the course creation form and the main course page itself. You will most likely want to make their labels hidden, and disable the background image from display.  
      
4.  **Navigate to the Courses View:** Go to **Administration > Structure > Views** and edit the **Courses** view (`/admin/structure/views/view/courses`).  
      
5.  **Configure the Course Card Row Style:** In the "Format" section, click the **Settings** link next to Show:  Course Card. In the settings pop-up, map your new fields:
    -   In the **Description Field** dropdown, select the text field you created. If it has a summary, you can choose to display only the summary.  The full text will appear on the course page.
    -   In the **Background Image Field** dropdown, select the image/media field you created. You can also select an **Image Style** to apply, which is useful for controlling its size and performance.
6.  **Click Apply and Save the view.**  
      
7.  Finally, **edit each course to give it a description and image**.  Your course cards will then be displayed with the new custom information.  
      

### Add Extra Fields

For themers and developers, the module provides a hook to programmatically alter the data sent to a course card. The `hook_views_row_lms_course_card_alter()` hook is called before the props are passed to the course\_card SDC component.  It allows your theme or custom module to add, remove, or change the data that the card will render.

Your hook will need to pass by reference a `$props` array of properties to the component.  This array includes keys such as `'title'`, `'url'`, `'description'`, and `'extra_fields'`.  To add a new custom field, append an array with `'label'` and `'content'` keys to the `'extra_fields'` array. The `'content'` should be a render array.

For more details, see `lms.api.php` and `/components/course_card/course_card.twig` in the module code.  See also the [**Theming page in the Drupal LMS Developer's Guide**](../developer/theming-and-frontend-integration.md).

## Course Statistics and Reporting

To view course statistics:

1.  Go to the course page
2.  Click the **Students** tab

This shows a report with:

-   All enrolled students 
-   Completion status for each student 
-   Score (where applicable) 
-   Last activity timestamp 
-   Class membership

You can filter this report by: 

-   Student name
-   Class

This report is itself a Drupal view, and you can customize it or build your own using the fields, filters, and relationships described in [Views Integration](../views.md).

## Managing Course Content

To update course content:

1.  Go to the course edit form. You can either access this by clicking on its course card to view the course page, then click the Edit tab; or by visiting the Courses listing in the LMS menu, then click Edit next to the course you want to update.
2.  Add, remove, or reorder lessons as needed.
3.  Update any course settings you want to change.
4.  Click **Save.**

If you have course revisioning turned off, making big changes to course structure after students have started may cause problems with their progress tracking. Consider making a new version of the course instead of heavily changing an active one.  Also see the [global "**Use revisions**" setting](general-settings.md#use-revisions-setting) which prevents this issue.

**[Previous page: Lessons](lessons.md)**

**[Next page: Student & Class Management](student-class-management.md)**
