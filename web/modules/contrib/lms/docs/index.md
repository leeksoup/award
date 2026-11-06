# Drupal LMS

Documentation for Drupal LMS includes three guides, which have different purposes depending on whether you're a course creator, a developer who wants to work with the module or its API, or if you want to expand the available activity-answer plugin library. A dedicated [Views Integration](views.md) reference is also available for site builders creating custom reports and listings.

See the **[Drupal LMS project page](https://www.drupal.org/project/lms)** for the latest module releases, issue queue, and other resources.

## [Course Creator's Guide for Drupal LMS](course-creator/index.md)

Explanations and instructions for end-users of the Drupal LMS module.

-   [Introduction](course-creator/introduction.md "Introduction to course creation in Drupal LMS, with comparisons to other LMS platforms")
-   [Installation & Setup](course-creator/installation-and-setup.md "Guide to installing and setting up the Drupal LMS module")
-   [General Settings](course-creator/general-settings.md "Global site-wide settings that affect how all courses work in Drupal LMS")
-   [Activities](course-creator/activities.md "Activities are the content and question-answer building blocks that make up courses.")
-   [Activity Types and Plugins](course-creator/activity-types-and-plugins.md "Drupal LMS uses plugins to create various Activity Types, which provide the structure for Activities.")
-   [Lessons](course-creator/lessons.md "Lessons are collections of activities that form learning units.")
-   [Courses](course-creator/courses.md "Courses are containers for lessons, and have their own navigation and progress tracking. Each course can have multiple classes of students.")
-   [Student & Class Management](course-creator/student-class-management.md "Students may be enrolled or removed from courses, graded, and tracked through their course completion")
-   [Reporting and Tracking](course-creator/reporting-and-tracking.md "Drupal LMS has several built-in reports to track student progress and course effectiveness")
-   [Roadmap and Ongoing Development](course-creator/roadmap-and-ongoing-development.md "Drupal LMS is actively being developed. Here are some features planned for upcoming releases.")

## [Developer's Guide for Drupal LMS](developer/index.md)

This guide gives developers working with the Drupal LMS module a detailed look at the module's architecture and functionality.

-   [Module Architecture](developer/module-architecture.md "An overview of the structure and function of the Drupal LMS module")
-   [Local Development & QA](developer/local-development-qa.md "How to set up a local development environment for Drupal LMS")
-   [Entity Structure](developer/entity-structure.md "A detailed listing of the Entities and Entity IDs used in Drupal LMS, and the code that controls them")
-   [Plugin System](developer/plugin-system.md "The LMS module uses a plugin-based architecture to make it easy to develop custom activities and interactions.")
-   [Form System](developer/form-system.md "A detailed listing of Drupal LMS forms, with additional AJAX capabilities and modal integration.")
-   [Services, Managers, Hooks, and Query Alters](developer/services-managers-hooks-and-query-alters.md "A detailed listing of the Services, Managers, Hook implementations, and Query Alters in Drupal LMS")
-   [Theming and Frontend Integration](developer/theming-and-frontend-integration.md "Drupal LMS provides frontend integration with theming, blocks, views integration, and AJAX components.")
-   [Drupal LMS API](developer/drupal-lms-api.md "The LMS module provides an API for other modules to interact with courses, track progress, and build custom integrations.")
-   [Error Handling & Troubleshooting](developer/error-handling-troubleshooting.md "Drupal LMS includes custom exceptions that handle errors during course navigation, lesson progress, and activity completion.")
-   [Developer's Cookbook](developer/developers-cookbook.md "Practical, copy-and-paste-friendly code snippets for common tasks when developing with or extending the Drupal LMS module")
-   [Testing and Deployment](developer/testing-and-deployment.md)

## [Plugin Developer's Guide for Drupal LMS](plugin/index.md)

This guide gives you full instructions for creating custom Activity-Answer plugins to extend your learning platform experiences.

-   [Introduction](plugin/introduction.md "An overview of the purpose and architecture of Activity-Answer Plugins in Drupal LMS")
-   [Fundamentals of Activity-Answer Plugins](plugin/fundamentals-of-activity-answer-plugins.md "Before diving into the code, it's helpful to understand how the plugin components work together within the Drupal LMS architecture.")
-   [Required and Optional Methods](plugin/required-and-optional-methods.md "Activity-Answer plugins for Drupal LMS include some required and optional methods. This section goes into detail on each of them.")
-   [Plugin Configuration Files](plugin/plugin-configuration-files.md "Activity-Answer plugins generally need custom fields to store configuration data. These fields are defined through YAML configuration files.")
-   [Custom Field Types and Widgets](plugin/custom-field-types-and-widgets.md "For more advanced plugins, you may need to create custom field types and widgets to handle specialized data structures and user interfaces.")
-   [AJAX Implementation](plugin/ajax-implementation.md "Interactive feedback is a powerful feature of the LMS module, and requires an understanding of several key components.")
-   [Integration and Deployment](plugin/integration-and-deployment.md "This section covers the essential steps for successfully deploying your Drupal LMS plugin.")
-   [Reference: Existing Plugins](plugin/reference-existing-plugins.md "The LMS module includes a number of core Activity-Answer plugins, which serve as excellent examples for you to write your own custom plugins")

## [Views Integration](views.md)

A catalog of the views Drupal LMS includes, and a reference of the LMS-specific fields, filters, and relationships available for building your own custom reports and listings with Views.

## [Frequently Asked Questions](faq.md)

Answers to questions that come up often in the issue queue and community discussions, covering architecture, content authoring, navigation, permissions, and the wider module ecosystem.
