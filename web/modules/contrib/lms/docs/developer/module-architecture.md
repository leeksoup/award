# Module Architecture

## Introduction

The Drupal LMS module builds a highly flexible Learning Management System by integrating multiple native Drupal components into a cohesive educational platform. At its core, the module leverages Drupal’s entity system and the Group module to create a structured learning environment with courses, classes, and educational content.

Understanding the Group 3 module’s concepts (groups, memberships, and permissions) is helpful for working on the LMS module. If you’re not already familiar with it, you can [review the Group module documentation here](https://www.drupal.org/docs/extending-drupal/contributed-modules/contributed-module-documentation/group).

## Architecture Overview

The Drupal LMS module combines a number of elements, which work together to provide a full LMS service:

1.  **Entity System**: Structured data storage for courses, lessons, and activities
2.  **Group Module**: User organization and permission management
3.  **Plugin System**: Extensible activity types and form handling
4.  **Service Layer**: Business logic for course progression and status tracking
5.  **AJAX System**: Dynamic user interfaces for answering and evaluating

These components create a complete learning management system in which: 

-   Administrators can create courses and organize content 
-   Teachers can create activities, manage classes, and evaluate student work
-   Students can take courses, submit answers, and track their progress

The architecture is designed for extensibility, allowing developers to create custom activity types, add new navigation patterns, and integrate with other Drupal modules.

When developing for the LMS module, keep the relationship structure in mind: courses contain lessons, lessons contain activities, and activities are based on activity types. User progress is tracked through parallel [status entities](entity-structure.md#status-entities).

## Entity Type Overview

The Drupal LMS module creates a comprehensive Learning Management System using Drupal’s entity system and the Group module. Here’s how the entities work together:

### **Content Entities**

-   **Activity** (`lms_activity`): Individual learning activities (questions, content, etc.)  
    `src/Entity/Activity.php`   
     
-   **Lesson** (`lms_lesson`): Collections of activities organized in a specific sequence  
    `src/Entity/Lesson.php`   
     
-   **Answer** (`lms_answer`): Student responses to activities  
    `src/Entity/Answer.php`  
      

### **Group Types**

-   **Course** (`lms_course`): Main container for learning content - `src/Entity/Bundle/Course.php`   
     
-   **Class** (`lms_class`): Sub-groups within courses for organizing students. Provided by the optional lms\_classes sub-module.  
      

### **Status Tracking Entities**

-   **CourseStatus** (`lms_course_status`): Tracks a user’s progress through an entire course  
    `src/Entity/CourseStatus.php`   
     
-   **LessonStatus** (`lms_lesson_status`): Tracks a user’s progress through a specific lesson  
    `src/Entity/LessonStatus.php`  
      

## Routes and Controllers

The module defines several key routes for navigating courses and tracking progress:

-   **Course Start**: `lms.course.start` - Handled by `CourseController::start()` in `src/Controller/CourseController.php`  
     
-   **Answer Form**: `lms.group.answer_form` - Handles activity presentation and answer submission  
     
-   **Results**: `lms.group.results` - Shows course and lesson completion status  
     
-   **Answer Evaluation**: `entity.lms_answer.edit_form` - Teacher interface for evaluating answers, which opens in a modal dialog.  
      

The main controllers include: 

-   **CourseController** (`src/Controller/CourseController.php`): Handles course navigation and activity display   
     
-   **ModalSubformController** (`src/Controller/ModalSubformController.php`): Manages modal form interactions

## Group Module Integration

The LMS module leverages the Group module to: 

-   Manage course and class memberships 
-   Handle permissions for teachers and students 
-   Create hierarchical relationships (courses contain classes) 
-   Provide student enrollment workflows

Group relationships connect courses to classes, and classes to students, creating a structured learning environment. The integration is primarily handled in: 

-   `modules/lms_classes/src/Plugin/Group/Relation/LmsClasses.php`: Defines the relationship that allows a Course to contain Classes.  
      
-   `modules/lms_classes/src/Hook/LmsClassesMembershipHooks.php`: Handles alterations to membership forms, such as adding a class selector when a student enrolls in a course.  
      

## Plugin System Architecture

The module uses a plugin-based architecture to support different types of activities:

-   **ActivityAnswer Plugins**: Define how questions are presented and answers are evaluated. Eight of the nine plugins (FreeText, FreeTextFeedback, Select, SelectFeedback, TrueFalse, TrueFalseFeedback, FillInTheBlanks, FillInTheBlanksFeedback) live in the optional submodule:  
    `modules/lms_answer_plugins/src/Plugin/ActivityAnswer/`  
    The remaining plugin, **NoAnswer**, ships in the main module instead, since it's used as the default/fallback plugin:  
    `src/Plugin/ActivityAnswer/NoAnswer.php`  
      
-   **ModalSubform Plugins**: Handle modal form rendering and processing  
    `src/Plugin/ModalSubform/`  
     
-   **Field Plugins**: Custom field types like `LMSReferenceItem` for relationships  
    `src/Plugin/Field/`

This plugin system makes the module highly extensible, allowing developers to create custom activity types.

## Service Structure

Key services include:

-   **TrainingManager** (`src/TrainingManager.php`): Core service that handles course progression logic  
     
-   **ActivityAnswerManager** (`src/ActivityAnswerManager.php`): Manages activity answer plugins  
     
-   **ModalSubformManager** (`src/ModalSubformManager.php`): Manages modal form display and processing  
     
-   **DataIntegrityChecker** (`src/DataIntegrityChecker.php`): Verifies relationships between entities  
     
-   **Hook Services**: Organized by dedicated service classes in the `src/Hook/` directory  
      

**Developer Best Practice:** When extending the module, it is recommended to follow the service-based pattern for implementing your own hooks, rather than placing them in your `.module` file. This is the modern standard for Drupal development, and helps improve organization and leverage dependency injection.

## Event System

The module also uses Drupal’s event system to allow for extensibility: 

-   **QaContentEvent**: Dispatched after test content creation  
    `src/Event/QaContentEvent.php`

## Queue Workers

The module implements queue workers for background processing: 

-   **DeleteEntitiesWorker**: Handles cleanup of entities  
    `src/Plugin/QueueWorker/DeleteEntitiesWorker.php`  
      

[**Next page:  Local Development**](local-development-qa.md)
