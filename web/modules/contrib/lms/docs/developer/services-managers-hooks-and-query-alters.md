# Services, Managers, Hooks, and Query Alters

## TrainingManager Service

The `TrainingManager` is the central service managing learning paths (`src/TrainingManager.php`):

    // Get the current status for a user
    $course_status = $training_manager->getCurrentCourseStatus($course, $account);
    
    // Initialize a new lesson
    $lesson_status = $training_manager->initializeLesson($course_status, $lesson_item);
    
    // Update course status after completion
    $training_manager->updateCourseStatus($course_status, true);

Key functions: 

-   `getCurrentCourseStatus()`: Get or create status for a user   
     
-   `getRequestedLessonStatus()`: Get the correct lesson based on navigation  
     
-   `updateLessonStatus()`: Update completion and scores  
     
-   `updateCourseStatus()`: Calculate overall course status  
     
-   `resetTraining()`: Reset progress for testing or fixing data  
       
     

The `TrainingManager` is the central service controlling learning paths and student progress. To ensure proper state tracking, student interactions should always go through this service rather than manipulating status entities directly.

External functions can implement event subscribers that react to `TrainingManager` status updates.

## ActivityAnswerManager

The `ActivityAnswerManager` handles activity-answer plugins (`src/ActivityAnswerManager.php`):

    // Check if a plugin exists
    $has_plugin = $activity_answer_manager->hasDefinition($plugin_id);
    
    // Create a plugin instance
    $plugin = $activity_answer_manager->createInstance($plugin_id);
    
    // Get all available plugins
    $definitions = $activity_answer_manager->getDefinitions();

The manager handles:

-   Plugin discovery and instantiation (inherited from Drupal's `DefaultPluginManager`)
-   Altering plugin definitions via the `activity_answer_info` alter hook

Per-plugin configuration installation (installing a plugin's optional config from its `config/activity_answer/{plugin_id}` directory) is handled separately by the `plugin.config_installer.activity_answer` service (`Drupal\lms\Config\PluginConfigInstaller`), not by the manager itself.

## Hook Implementations

To improve organization and leverage dependency injection, Drupal LMS implements its hooks within dedicated service classes located in the `lms/src/Hook/` directory. This is the modern standard for Drupal development.

-   **`LmsEntityHooks.php`:** Implements entity-related hooks, such as:
    -   `hook_entity_bundle_info_alter()`: Adds the custom `Course` class to the group bundle info.  
          
    -   `hook_entity_base_field_info()`: Adds fields to group entities  
         
    -   `hook_group_delete()`: Cleans up course status entities when a course is deleted.  
          
    -   `hook_group_access()`: Handles special course access conditions like `take course`.  
          
-   **`LmsFormHooks.php`:** Implements form alteration hooks, such as:
    -   `hook_form_views_exposed_form_alter()`: Adjusts filter forms for modal dialogs.  
          
    -   `hook_form_group_form_alter()`: Adds data integrity warnings to the course edit form.  
          
-   **`LmsQueryHooks.php`:** Implements query alteration hooks to enforce access control on entity queries for lessons and activities.  
      
    -   `hook_query_lms_activity_access_alter()`: Controls activity access  
         
    -   `hook_query_lms_lesson_access_alter()`: Controls lesson access  
         
-   **`LmsOtherHooks.php`:** Implements miscellaneous hooks like `hook_element_info_alter()` to add CSS to the toolbar.  
      
-   **`LmsTokensHooks.php`:** Implements `hook_token_info()` and `hook_tokens()` to provide tokens for LMS entities (e.g. `lms_answer`, `lms_activity`).  
      
-   **`LmsClassesMembershipHooks.php`** (in `lms_classes` sub-module): Handles membership-related form alterations and logic, including `hook_group_insert()`, which creates the default class when a course is created.  
      
-   **`LmsClassesViewsHooks.php`** (in `lms_classes` sub-module): Implements `hook_views_data_alter()` to register the class-filter, class-relationship, and course-status Views handlers.  
      

## Query Alters

Query alters handle access control and filtering in `src/Hook/LmsQueryHooks.php`:

### **Activity Access Control**:

    // Implementation in LmsQueryHooks.php
    public function lmsActivityQueryAccessAlter(AlterableInterface $query): void {
      $this->lmsEntityQueryAccessAlter($query, 'lms_activity');
    }

This alters queries to: 

-   Add user ownership conditions for non-admin users 
-   Join necessary data tables 
-   Apply permission-based filtering  
      

### **Custom Views Queries**:

The module includes custom Views query handlers, provided by the optional `lms_classes` sub-module, in `modules/lms_classes/src/Plugin/views/`: 

-   `ClassOrParentCourseStatuses`  
    (`modules/lms_classes/src/Plugin/views/argument/ClassOrParentCourseStatuses.php`): Filters course statuses by class  
     
-   `CourseClassesRelationships`  
    (`modules/lms_classes/src/Plugin/views/argument/CourseClassesRelationships.php`): Filters relationships by class  
     
-   `ClassFilter`  
    (`modules/lms_classes/src/Plugin/views/filter/ClassFilter.php`): Filters by class membership  
     
-   `ClassMemberCourseStatus`  
    (`modules/lms_classes/src/Plugin/views/relationship/ClassMemberCourseStatus.php`): Relates class members to their Course status  
      

Example from `ClassFilter`:

    public function query(): void {
      $table = $this->ensureMyTable();
      \assert($this->query instanceof Sql);
      $this->query->addWhere($this->options['group'], "$table.gid", $this->value, 'IN');
    }

**[Previous page: Form System](form-system.md)**

**[Next page: Theming & Frontend Integration](theming-and-frontend-integration.md)**
