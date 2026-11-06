# Views Integration

Drupal LMS is built on top of Drupal's core **Views** module. The public course catalog, the admin course and class listings, and the student roster/results report are all views that ship with the module, and you can edit any of them like any other view at **Admin > Structure > Views** (`/admin/structure/views`).

This page has two purposes:

-   A catalog of every view the module includes, so you know what's already there before building something new.
-   A reference of the LMS-specific fields, filters, and relationships you can add to your own views, so you know what's available when you build a custom report.

For step-by-step instructions on creating a new report from scratch, see [Creating Custom Reports](course-creator/reporting-and-tracking.md#creating-custom-reports) in the Reporting and Tracking guide. For instructions on customizing the look of a course card, see [Customizing Course Cards](course-creator/courses.md#customizing-course-cards) in the Courses guide.

## Views Included with Drupal LMS

### Public Course Catalog

-   **Path:** `/courses`
-   **What it is:** The public, browseable listing of all published courses. Each course is displayed as a visual card (title, image, description, and a "Get Started" / "Resume Training" link) rather than a table row.
-   **Layout:** A responsive grid, 3 columns by default, 6 courses per page.
-   See [Customizing Course Cards](course-creator/courses.md#customizing-course-cards) for how to change what appears on each card.

### Course Admin Listing

-   **Path:** `/admin/lms/courses` (menu link "Courses" under **Administration > LMS**)
-   **What it is:** A backend table of every course — ID, title, published status, and who created it — with Edit/Delete actions. Sorted newest-first by default; every column is sortable.
-   **Access:** requires permission to create courses.

### Class Admin Listing

*Requires the optional LMS Classes sub-module.*

-   **Path:** `/admin/lms/classes` (menu link "Classes" under **Administration > LMS**)
-   **What it is:** The same kind of backend table as the Course Admin Listing, but for Classes.
-   **Access:** requires permission to create classes.

### Course Students Tab

*Requires the optional LMS Classes sub-module.*

-   **Path:** `/group/{course}/students` — the "Students" tab on a course's page.
-   **What it is:** The roster of every student enrolled in the course's classes, showing their name, class, status (New / In progress / Needs grading / Passed / Failed / Expired), a link to their results, and their last activity. Exposed filters let a visitor search by student name or narrow the list to specific classes. Sorted by most recently active by default.
-   This view lists students by way of their **class** membership — if a course has classes with no students, or students enrolled directly on the course with no class, they won't appear here.
-   **Access:** requires the "View students" course permission.
-   This is the same view documented in [Course Students Report](course-creator/reporting-and-tracking.md#course-students-report).

### Selection Pickers

Two additional views power the "browse and attach" popups used when editing course/lesson content — they aren't meant to be visited directly, but you can customize their filters like any other view if you want to change how editors search for content to attach:

-   **Activities selection** — the popup used when adding existing activities to a lesson. Exposes a "Name" text filter and a "Published" filter. Requires permission to create activities.
-   **Lessons selection** — the popup used when adding existing lessons to a course. Exposes a "Filter by name" text filter and a "Published" filter. Requires permission to create lessons.

## Building Your Own Views with LMS Data

When you create a new view (**Add view**), the **Show** dropdown lets you pick what kind of LMS data the view is based on. Each option below lists the LMS-specific fields, filters, and relationships available for that base, beyond Drupal's usual entity fields.

### Courses (base: Group)

Course and Class are both "Group" content in Drupal, so a Course-based view needs a filter for the "Course" group type — the built-in course views already do this for you.

-   **Row style "Course Card"** (under Format > Show): displays each course as a visual card instead of a table row — the same style used on the public catalog. See [Customizing Course Cards](course-creator/courses.md#customizing-course-cards) for the fields you can plug into a card (background image, description).
-   **Field "Take course"**: a smart call-to-action link for whoever is currently viewing the page — "Get Started" if they haven't enrolled, "Resume Training" if they're mid-course, or "Awaits grading.." if their last submission needs grading. Useful for a personalized "My Courses" or "Continue Learning" listing.

### Lessons

-   **Filter "Parent course"**: narrows the list to lessons that belong to one or more chosen courses.
-   **Filter "Orphaned"**: shows only lessons that aren't attached to any course — handy for finding stray content to clean up or reattach.
-   **Relationship "Course"**: pulls in fields from the lesson's parent course (e.g. its title), so you can show them alongside each lesson.

### Activities

-   **Filter "Parent lesson"**: narrows the list to activities that belong to one or more chosen lessons.
-   **Filter "Parent course"**: narrows the list to activities that ultimately belong to one or more chosen courses (two levels up, through their lesson).
-   **Filter "Orphaned"**: shows only activities that aren't attached to any lesson.
-   **Relationship "Lesson"**: pulls in fields from the activity's parent lesson.

### Course Status

A **Course Status** record is a single student's enrollment and progress record for one course — this is the richest base for building reports, and it's what powers the built-in Course Students tab.

-   **Field "Status"**: a colored status badge (New, In progress, Needs grading, Passed, Failed, Expired) linked to the student's results page.
-   **Filter "Status"**: narrows the report to one or more of those same statuses (e.g. only students who "Need grading").
-   **Field "Course progress"**: a progress bar (or, in compact display mode, a "3 / 8 Lessons" style count) of finished lessons out of the course total.
-   **Field "Student results link"**: a link to the student's detailed results, with configurable link text and a fallback message if they haven't started yet.
-   **Relationship "Course"**: pulls in fields from the course itself.
-   **Contextual filter "Class member or all classes' members statuses"** *(requires LMS Classes)*: automatically scopes the report to a single class's members, or to the whole course, depending on which group is passed in the page's URL — this is how one view can serve both a whole-course report and a per-class report.

### Lesson Status

A **Lesson Status** record tracks a student's progress through one lesson attempt.

-   **Relationship "Lesson"**: pulls in fields from the lesson itself.
-   **Relationship "Activity"**: pulls in the activities recorded for that lesson attempt.

### Answers

An **Answer** is a single submission a student made to an activity — useful for question-level analysis.

-   **Relationship "Activity"**: pulls in fields from the activity the answer was submitted for.

### Class Memberships (base: Group relationship)

*Requires the optional LMS Classes sub-module.*

-   **Filter "Is content of selected classes"**: narrows a membership listing to one or more specific classes (needs a course to be in context, e.g. via a contextual filter).
-   **Contextual filter "Group relationships of Course classes"**: expands "this course" (taken from the page's URL) into "every member of every class under this course" — this is how the Course Students tab shows one roster covering all of a course's classes.
-   **Relationship "Course status"** (grouped under category "LMS" when adding a relationship): pulls in a class member's Course Status record for whichever course is in context — this is what makes the Status, Results, and Last activity columns possible on a class-membership-based view.

### Restricting Access by Course Permission

When configuring a view's **Access** settings, the **"Course permission"** access type lets you restrict an entire view or page to visitors who hold a specific permission *within the course currently being viewed* — for example, restricting a report to only those with "View students" or "Grade students" for that course, rather than site-wide. This is how the Course Students tab itself is protected.

## Customizing an Included View: A Worked Example

Let's say you want to add the "Course progress" bar to the built-in **LMS Course students** view.

1.  Go to **Admin > Structure > Views** (`/admin/structure/views`) and edit **LMS Course students**.
2.  Click **Add** next to **Fields**.
3.  At the top of the dialog, set the **Relationship** dropdown to **Course status** — fields that belong to a student's Course Status record (like "Course progress") are only available once this relationship is selected, since the view's own base is class membership, not Course Status.
4.  Search for "progress," check **Course progress**, and click **Add and configure field**.
5.  Adjust the display mode (bar or compact count) if you like, then **Save**.

The same "set the relationship first" step applies any time you want to pull in a Course Status field (like Status or Student results link) on a view that isn't already based on Course Status.

**[Related: Reporting and Tracking](course-creator/reporting-and-tracking.md)**

**[Related: Courses](course-creator/courses.md)**
