# Frequently Asked Questions

A collection of answers to questions that come up often in the issue queue and community discussions. Where a question is already covered in depth elsewhere in these guides, this page links to it rather than repeating it.

## Table of Contents

1.  [General and Architecture](#general-and-architecture)
2.  [Content Authoring and Revisions](#content-authoring-and-revisions)
3.  [Navigation and Routing](#navigation-and-routing)
4.  [Classes, Groups, and Permissions](#classes-groups-and-permissions)
5.  [Ecosystem, Integrations, and Media](#ecosystem-integrations-and-media)
6.  [E-Commerce and Paid Courses](#e-commerce-and-paid-courses)
7.  [Getting Help](#getting-help)

## General and Architecture

### What is the difference between Opigno and Drupal LMS?

Drupal LMS is not a fork of Opigno — see [How Drupal LMS Compares to Other Systems](course-creator/introduction.md#how-drupal-lms-compares-to-other-systems) for a full feature-by-feature comparison of the two projects as they exist today.

For context on the project's own history: an early `0.2.x` branch line did consist of patched, renamed Opigno submodules (`opigno_class`, `opigno_learning_path`, `opigno_module`, and various Opigno activity types) carried over for compatibility with older Drupal versions. Starting with the `1.x` line, the module was rewritten from scratch — later commits explicitly removed the remaining Opigno-derived code. All new sites should build on `1.x` or newer; `0.2.x` is legacy history at this point, not a supported starting point.

### What is the relationship between Courses and Classes?

See [Core Concepts](course-creator/introduction.md#core-concepts) for the conceptual definitions, and [Group Module Integration](developer/module-architecture.md#group-module-integration) for how this is implemented. In short: a Course is the central container entity that defines curriculum structure (lessons, activities, scoring, pass criteria), and a Class is a Group entity of its own, linked to a course, through which student enrollment, membership, and permissions are managed — see the [Student & Class Management guide](course-creator/student-class-management.md) for day-to-day operation.

## Content Authoring and Revisions

### Why don't active students see new activities or edits added to an existing lesson?

This is controlled by the [**"Use revisions" setting**](course-creator/general-settings.md#use-revisions-setting): with it checked, a student's progress is pinned to the specific revision of each lesson/activity that existed when they started, so editing course content mid-flight won't disrupt them — new students automatically see the latest version. This feature has been available since Drupal LMS `1.1.0`. See also [Allow to revisit finished course](course-creator/courses.md#allow-to-revisit-finished-course) for how this combines with course-level settings.

### Can I have multiple activities with the same generic title (e.g., "Assignment") across lessons?

Yes — activities are designed to be [reused across multiple lessons](course-creator/activities.md#activity-reusability). Since an activity's administrative title is what shows up in reference/autocomplete fields when assembling a lesson, it's worth giving each one a distinct, descriptive title (e.g., "Lesson 2 — Assignment: Essay") rather than a generic one, purely to make it easier to find the right one later.

### Why can't I unpublish a lesson that's part of a published course?

Drupal LMS enforces this relationship in both directions: a **published course cannot reference an unpublished lesson**, and equally, **you can't unpublish a lesson that's still referenced by a published course** — you'll need to either unpublish the course first or remove the lesson from it. The same two-way rule applies one level down, between published lessons and the activities they contain.

If you want to reveal lessons progressively over time (e.g., weekly drip content), there's currently no built-in scheduling mechanism for this — see [Can I gate a lesson's availability by date or progress?](#can-i-gate-a-lessons-availability-by-date-or-progress) below.

### Can I gate a lesson's availability by date or progress?

Not out of the box — there's no built-in "drip content" or lesson-scheduling feature. Drupal LMS's [event system](developer/module-architecture.md#event-system) currently only dispatches one event, `QaContentEvent`, and it's specifically for QA/test content creation, not access gating. To build this yourself, the most direct route is a custom `hook_entity_access()` implementation on the Lesson entity that denies access based on your own date or progress logic — see [Security Considerations](developer/testing-and-deployment.md#security-considerations) for the general pattern of implementing access control for LMS entities.

### Why do I only see the "No answer" plugin when creating an Activity Type?

The core `lms` module only ships the foundational "No answer" plugin itself. The rest of the built-in activity types (Free Text, Select, True/False, Fill in the Blanks, and their feedback-enabled variants — see [Activity Types and Plugins](course-creator/activity-types-and-plugins.md)) live in the bundled `lms_answer_plugins` submodule, which needs to be enabled separately on the Extend page. Additional activity types, like file uploads, come from entirely separate ecosystem modules — see [Optional LMS Add-ons](course-creator/installation-and-setup.md#optional-lms-add-ons).

## Navigation and Routing

### Why do course URLs look like `/course/{id}/{lesson_delta}/{activity_delta}` instead of a Pathauto alias?

The course "player" routes (used while a student is actively taking a lesson) address lessons and activities by their **positional delta** — their index within the course's ordered list of lessons, and within a lesson's ordered list of activities — not by entity ID or a friendly alias. This is deliberate: it lets the same route work for any course regardless of content, and it's how the module tracks "where a student currently is." The tradeoff is that these deltas are positions, not stable identifiers — reordering a course's lessons changes what a given delta points to.

**Practical implication:** avoid hardcoding a delta-based URL (like `/course/1/2/3`) in rich text content, since reordering will silently break it. Link to the underlying lesson/activity entity, or to the course's canonical page, instead.

### Why do custom local tasks or tabs (e.g., "Discussions") disappear while a student is taking a lesson?

The course player uses its own dedicated route, separate from the course's canonical Group page — so local tasks configured against the canonical route won't automatically follow the student into the player. If you need persistent tools available throughout a lesson (like a class forum link), the most reliable approach is to add them to the course navigation block or player template directly, rather than relying on local task tabs.

## Classes, Groups, and Permissions

### How are student permissions managed?

Entirely through the Group module — see [Group Module Integration](developer/module-architecture.md#group-module-integration) for the architecture, and [Managing Student Access](course-creator/student-class-management.md#managing-student-access) plus [Initial Setup > User Permissions](course-creator/installation-and-setup.md#2-user-permissions) for how to configure it in practice.

### Can a student belong to multiple classes within the same course?

Yes. Since each class is a separate Group entity linked to the same course, a student can be a member of more than one of a course's classes at once — see [Adding a Class to a Course](course-creator/student-class-management.md#adding-a-class-to-a-course).

## Ecosystem, Integrations, and Media

### Is there support for SCORM, H5P, and xAPI / LRS?

Yes, each via its own separate module — see the full list in [Optional LMS Add-ons](course-creator/installation-and-setup.md#optional-lms-add-ons). Briefly: **LMS H5P** integrates with the H5P module's activity library; **LMS Scorm** adds support for sharing SCORM packages; **LMS Xapi** provides xAPI/LRS integration, including its own lesson bundle for xAPI packages and a minimal built-in LRS for storing scores and state.

### How are completion certificates generated?

Via the separate **LMS Certificate** and **LMS Certificate Entity** modules — see [Optional LMS Add-ons](course-creator/installation-and-setup.md#optional-lms-add-ons) for links and details.

### Can I build an instructor-led or one-on-one lesson?

Not out of the box, but the architecture supports it: Drupal LMS's core module ships a single default Lesson bundle, but a custom module can add its own by implementing `hook_entity_bundle_info()` for the `lms_lesson` entity type — see [Lesson Entities and Handlers](developer/entity-structure.md#lesson-entities-and-handlers). An instructor-led or one-on-one lesson type (with its own scheduling, sign-up, and manual-grading fields instead of the usual activity list) is exactly the kind of thing this extension point is for; it's a module-development task, not a site-configuration one.

## E-Commerce and Paid Courses

### How do I sell courses on Drupal LMS?

The core module has no billing of its own, but since a Class is just a Group entity, any commerce solution that can grant group membership on a successful purchase will work — for example, configuring a product to add the buyer as a member of a specific Class group on checkout. There's no single prescribed integration; it's a matter of connecting your commerce module of choice to the Group module's membership APIs.

## Getting Help

If this page didn't answer your question:

-   **Issue queue**: search for an existing report, or file a new one, on the [Drupal LMS issue queue](https://www.drupal.org/project/issues/lms).
-   **Slack**: ask the community in the **#lms** channel on [Drupal Slack](https://www.drupal.org/community/contribute/drupal-slack).
