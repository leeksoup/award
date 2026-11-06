# Theming and Frontend Integration

## Theming with Single Directory Components (SDC)

Drupal LMS leverages Drupal's modern **Single Directory Components (SDC)** for its frontend rendering. This makes theming and customization predictable and powerful. Instead of relying on a large number of theme hooks, the module provides self-contained components that bundle their own Twig, CSS, and JavaScript.

All components are located in the `components/` directory.

### Key Components

-   **`course_card`**: Renders each course display card on the main `/courses` listing page.  
      
-   **`course_navigation`**: The main navigation block that appears on course pages, showing lessons and activities plus a progress indicator bar.  
      
-   **`lesson_item`** & **`activity_item`**: The individual rows within the course navigation component.  
      
-   **`start_link`** & **`course_action_info`**: Handle the display of the action link on each course (e.g., "Enroll", "Start", "Continue", "Awaits grading").  
      
-   **`course_status`**: A small component for displaying a student's current status (e.g., "In Progress," "Passed", "Failed").  
      
-   **`lesson_timer`**: Displays the countdown timer on timed lessons.  
      

### How to Customize Components

To override a component's appearance, you don't need to implement any hooks. Simply copy the component's directory from `lms/components/` into your theme's `components/` directory. For example, to customize the course card:

1.  Copy the entire `lms/components/course_card/` directory to `themes/custom/your_theme/components/course_card/`.  
      
2.  Clear Drupal's cache.  
      
3.  You can now safely modify `course_card.twig` and `course_card.css` within your theme. Drupal will automatically use your version.  
      

**Tip:** You can see all the properties (variables) available in a component's Twig file by inspecting its `*.component.yml` file. This is the definitive source for what data you have to work with.

## Common Theming Tasks

### Add a Custom Field to the Course Card

Let's say you've added a "Difficulty" (`field_difficulty`) field to your Course group type and want to display it on the course card.

1.  **Implement the hook:** In your theme's `.theme` file or a custom module, implement `hook_views_row_lms_course_card_alter()`.  
      
2.  **Add the field data to props:** This hook allows you to add your field's rendered output to the `extra_fields` array that gets passed to the component.  
      

    use Drupal\lms\Entity\Bundle\Course;
    use Drupal\Core\Cache\CacheableMetadata;
    
    /**
     * Implements hook_views_row_lms_course_card_alter().
     */
    function my_theme_views_row_lms_course_card_alter(array &$props, Course $course, CacheableMetadata $cacheability) {
      if (!$course->get('field_difficulty')->isEmpty()) {
        $props['extra_fields'][] = [
          'label' => t('Difficulty'),
          'content' => $course->get('field_difficulty')->view(['label' => 'hidden']),
        ];
        // Ensure Drupal knows that this component's output depends on the field.
        $cacheability->addCacheableDependency($course->get('field_difficulty'));
      }
    }

**Note:** You must also update your custom `course_card.twig` file to loop through and print the `extra_fields` array where you want it to appear.

### Add Custom JavaScript to a Component

If you need to add custom JavaScript interactions to an LMS component, you can attach a library from your theme.

**Note:** Components rendered with `#type: component` (like `course_card`) are rendered directly by Twig and do **not** invoke `hook_preprocess_HOOK()`. Each component automatically attaches its own auto-generated library, named `core/components.{provider}--{component}` (e.g. `core/components.lms--course_card`), so the correct way to attach an additional library is to add it as a dependency of that library via `hook_library_info_alter()`.

1.  **Define a library** in your theme's `my_theme.libraries.yml` file.  
      
2.  **Attach the library** as a dependency of the component's auto-generated library, in a `hook_library_info_alter()` implementation.  
      

    // In my_theme.libraries.yml
    lms-card-custom:
      js:
        js/lms-card-custom.js: {}
      dependencies:
        - core/drupal
        - core/jquery
    
    // In my_theme.theme
    /**
     * Implements hook_library_info_alter().
     */
    function my_theme_library_info_alter(array &$libraries, string $extension) {
      if ($extension === 'core' && isset($libraries['components.lms--course_card'])) {
        $libraries['components.lms--course_card']['dependencies'][] = 'my_theme/lms-card-custom';
      }
    }

## Block Plugins

The module provides block plugins for navigation and status display:

-   **CourseNavBlock** (`src/Plugin/Block/CourseNavBlock.php`): Provides the "Course navigation" block that is available only on answer form routes.  
      
-   **CourseNavBlockBuilder** (`src/CourseNavBlockBuilder.php`): Called by CourseNavBlock to build the render arrays that are passed to the `course_navigation` component.  
      

## Views Integration

### Views Custom Handlers

The module integrates with Views through custom handlers in `src/Plugin/views/`:

1.  **Field Handlers**:  
      
    -   **`LmsEntitySelection`**: AJAX selection for references  
        (`src/Plugin/views/field/LmsEntitySelection.php`)  
          
    -   **`CourseStatus`**: Course status display  
        (`src/Plugin/views/field/CourseStatus.php`)  
          
    -   **`CourseTakeLink`**: Link to start/continue a course  
        (`src/Plugin/views/field/CourseTakeLink.php`)  
          
    -   **`CourseStudentLatestActivity`**: Last activity timestamp  
        (`src/Plugin/views/field/CourseStudentLatestActivity.php`)  
          
2.  **Filter Handlers**:  
      
    -   **`ClassFilter`**: Filter group content by parent Class. This is in the optional LMS Classes sub-module.  
        (`modules/lms_classes/src/Plugin/views/filter/ClassFilter.php`)  
          
3.  **Relationship Handlers**:  
      
    -   **`ClassMemberCourseStatus`**: Link class members to course status. This is in the optional LMS Classes sub-module.  
        (`modules/lms_classes/src/Plugin/views/relationship/ClassMemberCourseStatus.php`)  
          
4.  **Access Handlers**:  
      
    -   **`CoursePermission`**: Course-specific permission checks  
        (`src/Plugin/views/access/CoursePermission.php`)  
          

### Included Views

Pre-configured views in the module include:

-   **`activities_selection`**: Activity selection interface  
    (`config/install/views.view.activities_selection.yml`)  
      
-   **`lessons_selection`**: Lesson selection interface  
    (`config/install/views.view.lessons_selection.yml`)  
      
-   **`lms_course_students`**: Student management. This is in the optional LMS Classes sub-module.  
    (`modules/lms_classes/config/optional/views.view.lms_course_students.yml`)  
      
-   **`courses_admin`**: Course management  
    (`config/install/views.view.courses_admin.yml`)  
      
-   **`courses`**: Public listing of available courses  
    (`config/install/views.view.courses.yml`)  
      

**[Previous page: Services, Managers,  
Hooks, and Query Alters](services-managers-hooks-and-query-alters.md)**

**[Next page: Drupal LMS API](drupal-lms-api.md)**
