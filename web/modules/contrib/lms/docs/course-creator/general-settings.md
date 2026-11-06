# General Settings

The Drupal LMS module includes a general settings page where you can configure site-wide behavior for all courses.

Navigate to **Administration > LMS > LMS Settings > General settings** (`/admin/lms/settings/lms`) to configure the available options:

## "Allow to enter ungraded courses" setting

When this setting is enabled, students can re-enter a completed course that is still marked as "Needs grading" (it contains manually evaluated activities that are not yet graded). This allows them to review content or even change their answers before a teacher has graded their work.

If this is checked, students can alter their submissions during the time after they've completed the course but before a teacher has reviewed it. By default, this is disabled to lock the student's progress until evaluation is complete.

The default unchecked setting is safer for formal assessments, but here are some situations where you might prefer to have it checked:

-   **Low-stakes or practice courses:** Where completion and review are more important than a formal, locked-in grade.
-   **Iterative or portfolio-style work:** For activities like essays, you might want to allow students to make corrections or additions before a teacher's final review.
-   **Feedback-focused courses:** If the primary goal of manual review is to provide feedback rather than a formal grade, this setting allows a more fluid interaction between student and teacher.

Leave the "Allow to enter ungraded courses" setting unchecked if you intend to have formal graded courses that will include manually evaluated activities.  You can set it to checked if your site's focus is more on informal learning than on required grades.

See also the [course-level "**Allow to revisit finished course**" setting](courses.md#allow-to-revisit-finished-course).

## "Use revisions" setting

This setting determines which version of a lesson or activity a student will see if course content gets updated. It affects whether students who have started a course will be affected by edits or grading changes that are made while they're still taking the course.

Understanding this setting is important for managing course content while students are actively enrolled. This is especially true on a site where there is continuous enrolment and the course content will keep evolving over time.

Here is a detailed breakdown of what happens in different scenarios:

### "Use revisions" Checked

With "Use revisions" checked, when a student starts a course, the system saves a reference to the **specific revision** of each lesson and activity that they're assigned in the course. This ensures their learning path is stable and consistent.  The content and scoring of a course is locked in to the context of when they first started the course.

-   **When you edit course content and save as a **new revision**:**
    -   Students already in progress will continue to see the **original version** of the content from when they started the course. Their course content and scoring will not be affected by the changes, including when they may revisit the course in the future.
    -   New students starting the course will see the **new version** and will be locked in to its course contents and scoring, both while they are taking the course and on revisits.
    -   If any student's progress is reset, they will start fresh with the newest version of all course content.
-   **When you edit and save _without_ creating a new revision:**
    -   Both new students, and in-progress students who are on the latest revision, will see the **new version** immediately. This can be used for minor changes, such as fixing a typo, but can potentially cause confusion or disrupt their learning if the changes are significant.

### "Use revisions" Unchecked

With "Use revisions" unchecked, the system only tracks the main ID, not the revision ID, of lessons and activities. It will always load the **currently published revision** for every student.

-   **Content Edits:** If you edit any lesson or activity, **all students** (new and in-progress) will see the changes, regardless of whether you create a new revision.
-   **Scoring & Revisits:** All scoring and revisits will be based on the latest version of the content, which may not match a student's original course experience.

For most production sites, it is recommended to keep **"Use revisions" checked** and to always save content changes as a **new revision**, unless you're only making minor corrections or edits for clarity. This provides the most stable and predictable experience for your students, and protects their course history and scoring from content changes.

[**Previous page: Installation & Setup**](installation-and-setup.md)

[**Next page: Activities**](activities.md)
