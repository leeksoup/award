# Entity Structure

The entity structure is designed with separation of concerns in mind:

-   **Content entities** store educational content (activities, lessons)
-   **Group entities** organize users and content (courses, classes)
-   **Status entities** track user progress (course status, lesson status)
-   **Answer entities** store user responses

## Activity and Activity Type Entities

### **Activity Type:** `lms_activity_type`

-   A configuration entity that defines a type of activity, including its fields and the Activity-Answer plugin it uses.  
      
-   Each type is associated with a specific ActivityAnswer plugin   
     
-   Stores configuration such as the default maximum score and the associated Activity-Answer plugin  
     
-   Code location:  
    `src/Entity/ActivityType.php` (Class), and  
       
    `src/Entity/ActivityTypeInterface.php` (Interface).  
     
-   Form handling: `src/Entity/Form/ActivityTypeForm.php`  
     

The base Activity entity contains only core fields like name and author. The functional fields that define the activity's content (e.g., question text, answer options) are added via the plugin configuration when you create an Activity Type, and they're defined uniquely by each activity-answer plugin for its own data storage needs.

### **Activity:** `lms_activity`

-   Content entity that represents a specific learning activity   
     
-   Fields include: name, activity type, author, creation date   
     
-   Activities are referenced from lessons using the LMSReference field type   
     
-   Code location: `src/Entity/Activity.php` and `src/Entity/ActivityInterface.php`   
     
-   Form handling: `src/Entity/Form/ActivityForm.php`   
     
-   List handler: `src/Entity/Handlers/ActivityListBuilder.php`   
     
-   Access control: `src/Entity/Handlers/ActivityAccessControlHandler.php`  
      

## Lesson Entities and Handlers

Lessons can be configured to: 

-   **Randomize activity order**: Present all activities in a random sequence.  
      
-   **Select a random subset of activities**: Draw a specific number of activities from a larger pool.  
      
-   **Control navigation**: Allow or prevent students from moving backwards to previous activities.  
      

### **Lesson:** `lms_lesson`

-   Content entity that groups related activities   
     
-   Contains settings for:   
     
    -   **Randomization**: Control whether activities appear in a fixed order, random order, or if a random subset is chosen.  
          
    -   **Backwards navigation**: Control whether students can return to previous activities within that lesson.  
          
        
        Other contextual lesson settings, like **Time Limits** and **Required Score**, are not stored on the lesson entity itself. They are configured when you add the lesson to a Course and are stored in the **`LMSReferenceItem`** field (see below).
        
-   Uses handlers (`LmsLessonHandlerInterface`) to control behavior   
     
-   Code location: `src/Entity/Lesson.php` and `src/Entity/LessonInterface.php`   
     
-   Form handling: `src/Entity/Form/LessonForm.php`   
     
-   List handler: `src/Entity/Handlers/LessonListBuilder.php`   
     
-   Access control: `src/Entity/Handlers/LessonAccessControlHandler.php`  
     

### **Lesson Handlers**

-   Control lesson initialization, navigation, and status updates   
     
-   Base interface: `src/Entity/Handlers/LmsLessonHandlerInterface.php`   
     
-   Base implementation: `src/Entity/Handlers/LmsLessonHandlerBase.php`   
     
-   Default handler: `src/Entity/Handlers/DefaultLmsLessonHandler.php`  
     

## Course/Class Group Entities

### **Course:** `lms_course`

-   A Group type that serves as the main container for lessons.  
     
-   Key Settings Include:
    -   **Free navigation:** Allows students to access lessons and activities in any order.  
          
    -   **Revisit mode:** Allows students to review a course after completion.  
          
-   **Relationships:** References one or more Lesson entities using the `LMSReferenceItem` field type.  
      
-   **Code Location:** `src/Entity/Bundle/Course.php`  
      

### Class: `lms_class`

Optionally provided by the **lms\_classes** sub-module.

-   A Group type designed to contain student members.  
      
-   **Relationships:** A Class is connected to a parent Course via a Group Relationship. This allows teachers to manage specific sets of students for a given course.  
      
-   **Group Relations Plugin:** `modules/lms_classes/src/Plugin/Group/Relation/LmsClasses.php`  
      

## Status Entities

### **CourseStatus:** `lms_course_status`

-   Tracks a user’s overall progress in a course   
     
-   Status can be: new, in progress, needs evaluation, passed, failed, or expired   
     
-   Stores score, start/finish times, and current position   
     
-   Code location: `src/Entity/CourseStatus.php` and `src/Entity/CourseStatusInterface.php`  
     
-   Views integration: `src/Entity/Handlers/CourseStatusViewsData.php`  
     

### **LessonStatus**: `lms_lesson_status`

-   Tracks a user’s progress through a specific lesson  
     
-   Contains the sequence of activities for this attempt   
     
-   Records answers, scores, and completion status   
     
-   Code location: `src/Entity/LessonStatus.php` and `src/Entity/LessonStatusInterface.php`  
     

## Answer Entity

### **Answer:** `lms_answer`

-   Stores a student’s response to an activity  
     
-   Connected to a specific activity and lesson status  
     
-   Contains both the raw answer data and evaluation results  
     
-   Uses activity-specific plugins for display and scoring  
     
-   Code location: `src/Entity/Answer.php` and `src/Entity/AnswerInterface.php`   
     
-   Form handling: `src/Entity/Form/AnswerForm.php` and `src/Entity/Form/AnswerEvaluationForm.php`   
     
-   Access control: `src/Entity/Handlers/AnswerAccessControlHandler.php`  
     

## Field Definitions and Storage Patterns

### **LMSReferenceItem**

-   **Purpose:** This custom field type is the glue that connects Courses to Lessons, and Lessons to Activities. It extends Drupal's standard `EntityReferenceItem` to store not just the link to another entity, but also crucial **context-specific settings**.  
      
-   **Examples of Stored Data:**
    -   When linking a Lesson to a Course: Required Score, Time Limit, Mandatory status.  
          
    -   When linking an Activity to a Lesson: Maximum Score.  
          
-   This design allows a single Lesson or Activity to be reused in multiple places with different rules each time (e.g., the same lesson can have a 30-minute time limit in one course and no time limit in another).  
      
-   **Code Location:** `src/Plugin/Field/FieldType/LMSReferenceItem.php`  
      
-   **Widgets:**
    -   `LMSReferenceItemWidget.php`: Provides the form elements for the contextual data (e.g., the "Maximum score" text field).  
          
    -   `LMSReferenceTable.php`: Provides the table-based UI for managing lists of referenced entities, including adding, creating, and removing them.  
          

### **StartLinkFieldItemList**

-   **Purpose:** This is a custom **computed field** attached to the Course entity. It does not store any data in the database. Its job is to dynamically generate the correct "call to action" link for the current user viewing a course.  
      
-   **Logic Handled:**
    -   Show an "Enroll" link for a user who is not a member.
    -   Show a "Start" link for a newly enrolled student.
    -   Show a "Continue" link for a student in progress.
    -   Show a "Revisit" or "Restart" link for a student who has finished the course.
    -   Display status information like "Awaits grading" if no action is possible.  
          
-   **Code Location:** `src/Plugin/Field/FieldType/StartLinkFieldItemList.php`  
      

### **Data Storage**

-   Activity answers stored as serialized data in the Answer entity (`data` field)   
     
-   Configuration for activities stored in the `LMSReference` field   
     
-   Status tracking uses dedicated entities rather than user fields   
     
-   Entity field definitions in `hook_entity_base_field_info()` implementations:  
    `src/Hook/LmsEntityHooks.php`  
     

### **Database Schema**

-   Entity base tables defined in entity class annotations  
     
-   Field storage in `baseFieldDefinitions()` methods  
     
-   Custom field schemas in `schema()` methods (e.g., `LMSReferenceItem::schema()`)  
      

**[Previous page: Local Development](local-development-qa.md)**

**[Next page: Plugin System](plugin-system.md)**
