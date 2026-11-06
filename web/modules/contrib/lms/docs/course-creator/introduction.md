# Introduction

## What is Drupal LMS?

Drupal LMS is a highly flexible Learning Management System built natively in Drupal. It allows you to create and manage online courses, organize students, deliver various types of learning activities, and track student progress — all within your new or existing Drupal website, and alongside any other Drupal content types.

This module serves as both a standalone LMS solution and a foundation for additional extensions and customizations. It uses Drupal’s native entities, routes, controllers, and views, to provide tools for teaching and learning online.

Unlike standalone LMS platforms, Drupal LMS allows you to seamlessly blend learning content with other types of website content, plus use all of the other functionality available in Drupal.  This makes it ideal for organizations that need a combination of features and content on one platform.

## Key Features

-   **Course and Class Management**: Build courses with multiple lessons, and optionally organize students into cohesive class groups.
-   **Diverse Activity Types**: Create various interactive learning activities including free-text responses, multiple-choice questions (single or multiple select), and true/false assessments.
-   **Flexible Learning Paths**: Configure courses with required or optional lessons, randomized content, and/or time constraints to guide the learning journey.
-   **Progress Tracking**: Monitor student advancement through comprehensive reporting tools that show completion status, scores, and last activity.
-   **Assessment Options**: Implement both automatic grading for objective questions and manual evaluation for subjective, text-based answers.
-   **Interactive Learning with Immediate Feedback:** Guide the learning process with feedback-enabled activities that respond instantly to student input. This interactive approach corrects misunderstandings in the moment and is proven to increase knowledge retention.
-   **Role-Based Permissions**: Configure tailored access levels for administrators, teachers, and students.
-   **Robust Versioning**: Built on Drupal's core revision system, allowing you to update course content without disrupting students who are already in progress. This ensures a stable learning experience and maintains a full audit trail of changes.

## How Drupal LMS Compares to Other Systems

### Compared to Other Drupal-Based LMS Systems:  
 

Feature

[Drupal LMS](https://www.drupal.org/project/lms)

[Opigno](https://www.drupal.org/project/opigno_lms)

[Anu LMS](https://www.drupal.org/project/anu_lms)

**Works with Drupal**

Uses native Drupal elements, seamlessly compatible with other content types and modules

Built on a Drupal base plus custom code that is not very modifiable

Uses component-based design with decoupled React application for UI

**Activity Types**

Plugin system you can extend

H5P and quiz tools

Content with paragraph components

**Group Management**

Course and Class management with Group 3 module

Teams and private groups using Group 2 module

Organization-based groups

**Interactive learning**

Core feature with extendable functionality

Limited to specific H5P plugins

Limited

**Adding Features**

Built to be extended

Limited to in-house changes

Component-based extensions

**Updates**

Native Drupal module updates

Dependent on infrequent parent company releases; often far out of date with security updates

Native Drupal module updates

**Best Use**

Sites that mix flexible learning with other site content and features

Standalone learning platform

Content-focused learning sites

### Compared to Major LMS providers:  
 

Feature

[Drupal LMS](https://www.drupal.org/project/lms)

[Moodle](https://moodle.org/)

[Canvas](https://www.instructure.com/canvas)

[Blackboard](https://www.anthology.com/products/teaching-and-learning/learning-effectiveness/blackboard)

[Open edX](https://openedx.org/)

**Platform**

A flexible module for Drupal, fully open source

Standalone system

Cloud service

Enterprise system

Open source & service

**Stage of Development**

New but functional and expandable, full feature set in development

Mature (20+ years)

Mature

Mature

Mature

**Customization**

Designed for extension and development

Can be changed but complex; extensive plugin ecosystem

Limited options

Limited options

Some options

**Content Mix**

Works with any other form of Drupal content

LMS only, does not serve other content

Separate system

Separate system

Separate system

**Features**

Core extendable learning tools with a rapidly expanding feature set.

Many education features and plugins

Many education features

Many education features

Many education features

**Content versioning**

Uses Drupal's native revision system for live course edits

Limited to course backups and restore points

Limited to history tracking for content

Includes versioning and history tracking

Content versions managed via XML exports (OLX)

**Pedagogical Flexibility**

Core support for both formative (interactive, ungraded feedback) and summative (formal quizzing) learning models

Interactive feedback is limited to the quiz plugin

Can be configured for practice quizzes

Primarily focused on formal evaluation

Includes interactive problem-solving and immediate feedback

**Integration**

Compatible with almost any other Drupal module and content type

Needs extra work to connect to separate content site

Needs API connections

Needs API connections

Needs API connections

**Size Handling**

Enterprise capable, depending on hosting

Needs its own server

Handled by vendor

Handled by vendor

Depends on setup

**Cost**

Free (open source)

Free (open source)

Subscription fees

Subscription fees

Free or paid options

**Best Use**

Drupal sites that need flexible learning tools alongside other content

Schools and dedicated learning sites

Schools and universities

Large organizations

Online courses for many students

### Benefits of Drupal LMS

-   **Seamless Content Integration**: Integrates learning content with other Drupal-managed content
-   **Customizable UI/UX**: Leverage Drupal themes and layouts for a consistent user experience
-   **Extensible Architecture**: Build custom plugins and extensions using familiar Drupal patterns
-   **Flexible Pedagogical Approach**: Create both graded lessons and modern interactive learning
-   **Revisionable Course Content**: Continuously improve your courses while students are enrolled
-   **Community Support**: Benefit from both Drupal and Drupal LMS communities
-   **Cost-Effective**: Open source solution without licensing fees

### Considerations for Drupal LMS

-   **Drupal Knowledge Required**: Administrators need familiarity with Drupal concepts
-   **Setup Complexity**: More initial configuration than turnkey solutions
-   **Feature Set**: May require custom development for specialized requirements
-   **Hosting Requirements**: Needs a properly configured Drupal environment

This comparison should help you determine if Drupal LMS is the right solution for your needs.

The ideal use case for Drupal LMS is to add flexible learning options to a multi-use site, without having to integrate a completely separate LMS platform.

## Core Concepts

Understanding these fundamental elements will be helpful for working with Drupal LMS:

### Activities

Activities serve as the foundational building blocks of the learning experience. They represent individual interactions such as questions, content presentations, or interactive exercises that students engage with directly.

### Lessons

Lessons function as organized collections of related activities. Typically, a lesson can focus on a specific topic or learning objective, providing a coherent educational unit.

### Courses

Courses act as containers for multiple lessons, forming a complete educational program. Course settings control learning flow options, such as sequential progression requirements or free navigation between content.

### Classes

Classes can optionally create groups of students who are enrolled in a course. This structure allows multiple cohorts to experience the same course content, potentially with different teachers or timelines.  
 

When planning course content, consider mapping your instructional design to Drupal LMS components: conceptual units become courses, topic areas become lessons, and individual learning interactions become activities.

## User Roles

In the default configuration, Drupal LMS operates with three primary user roles:

-   **LMS Admin**: Users with complete LMS system access who can configure all of its aspects, manage students, and oversee the entire learning environment
-   **LMS Teacher**: Content creators and facilitators who develop courses, monitor student performance, and evaluate submissions requiring manual assessment
-   **Students**: Authenticated users who can enrol in courses, complete activities, and track their own progress through the learning materials

**[Next page: Installation and Setup](installation-and-setup.md)**
