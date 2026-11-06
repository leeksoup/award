# Drupal LMS API

The LMS module provides an API that allows other modules or sub-modules to interact with courses, track progress, and build custom integrations.

Use exception handling when working with the API — its methods may throw `TrainingException` when validation fails, with specific error codes that can be mapped to user feedback, administrative alerts, and logging requirements.

## TrainingManager Service API

The `TrainingManager` service (`@lms.training_manager`) is the central API for interacting with learning paths and student progress. To ensure data integrity, you should always use this service rather than manipulating status entities directly.

The methods in this service can throw a `\Drupal\lms\Exception\TrainingException` if a user attempts an invalid action (e.g., accessing a lesson out of sequence).  It's best practice to always wrap your API calls in a `try/catch` block.  See the **[Error Handling page](error-handling-troubleshooting.md)** for more details.

## Course and Lesson Status Retrieval

### `getCurrentCourseStatus()`

-   **Description:** Retrieves the current `CourseStatus` entity for a user and a course. If no "in-progress" status exists, it will create a new one, effectively starting the course for the user.  
      
-   **Function:** `$training_manager->getCurrentCourseStatus(CourseInterface $group, AccountInterface $user);`  
      
-   **Return Value:** A `CourseStatusInterface` object.
-   **Exceptions:** Can throw a `TrainingException` with code `NO_LESSONS` if no status exists yet and the course has no lessons to initialize one against (this is delegated internally to `initializeTraining()`).  
      

Use `TrainingManager::getCurrentCourseStatus()` as your starting point for most programmatic interactions with courses.

### `getRequestedLessonStatus()`

-   **Description:** Gets the appropriate `LessonStatus` for a user based on their current progress and the requested lesson/activity deltas, enforcing all navigation rules (e.g., free navigation, sequential progress).  
      
-   **Function:** `$training_manager->getRequestedLessonStatus(CourseInterface $course, AccountInterface $account, ?array $requested_deltas = []);`  
      
-   **Parameters:**
    -   `$requested_deltas`: An optional array with 'lesson' and 'activity' keys (e.g., `['lesson' => 1, 'activity' => 0]`). If empty, it retrieves the user's current position.  
          
-   **Return Value:** A `LessonStatusInterface` object.
-   **Exceptions:** This is the primary throw site for navigation-related errors. Can throw a `TrainingException` with code `COURSE_NEEDS_EVALUATION`, `NO_LESSONS`, `LESSON_REMOVED`, `ACTIVITY_REMOVED`, `FREE_NAV_DISALLOWED`, `BACKWARDS_NAV_DISALLOWED`, or `INCORRECT_ACTIVITY_REQUESTED`, and can also propagate `REQUIRED_NOT_STARTED`, `REQUIRED_NOT_FINISHED`, or `INSUFFICIENT_SCORE` re-thrown from the internal call to `initializeLesson()`.  
      

For custom reporting or analytics, use the `CourseStatus` and `LessonStatus` entities as your data sources rather than trying to aggregate data from answers directly.

## Answer Management

### `createAnswer()`

-   **Description:** Creates a new (unsaved) `Answer` entity for a user's response to an activity within a specific lesson attempt.  
      
-   **Function:** `$training_manager->createAnswer(LessonStatusInterface $lesson_status, ActivityInterface $activity);`  
      
-   **Return Value:** An `AnswerInterface` object.  
      

### `loadAnswer()`

-   **Description:** Loads an existing `Answer` entity for a specific lesson status and activity.  
      
-   **Function:** `$training_manager->loadAnswer(LessonStatusInterface $lesson_status, ActivityInterface $activity);`  
      
-   **Return Value:** An `AnswerInterface` object, or `NULL` if no answer has been submitted yet.  
      

## Status and Progress Updates

### `updateLessonStatus()`

-   **Description:** Recalculates and saves a lesson's status based on the scores and completion of its activities. This is typically called after an answer is submitted or evaluated.  
      
-   **Function:** `$training_manager->updateLessonStatus(LessonStatusInterface $lesson_status);`  
      
-   **Return Value:** `bool` - `TRUE` if the status was updated, `FALSE` if no changes were needed.  
      

### `updateCourseStatus()`

-   **Description:** Calculates and updates the overall course status (e.g., "Passed", "Failed", "Needs grading") based on the completion and scores of its mandatory lessons.  
      
-   **Function:** `$training_manager->updateCourseStatus(CourseStatusInterface $course_status, bool $last_activity = FALSE);`  
      

### `allAnswersEvaluated()`

-   **Description:** Checks if all answers within a given lesson attempt have been evaluated (i.e., none are pending manual grading).  
      
-   **Function:** `$training_manager->allAnswersEvaluated(LessonStatusInterface $lesson_status);`  
      
-   **Return Value:** `bool` - `TRUE` if all answers are evaluated, `FALSE` otherwise.  
      
-   **Usage:** Used to determine if a lesson's final score and status can be calculated.  
      

### `setLastActivityTime()`

-   **Description:** Updates the "last activity" timestamp on a `CourseStatus` entity.  
      
-   **Function:** `$training_manager->setLastActivityTime(CourseStatusInterface $course_status);`  
      
-   **Usage:** This should be called whenever a user interacts with course content to keep their session activity current. The `AnswerForm` calls this automatically on submission.  
      

## Course Navigation and Initialization

### `getOrderedLessons()`

-   **Description:** Retrieves an ordered array of all lessons for a course, structured as they are defined in the course configuration.  
      
-   **Function:** `$training_manager->getOrderedLessons(CourseInterface $group);`  
      
-   **Return Value:** An array of lesson data, indexed by lesson ID. The nested `activities` array is only pre-populated for lessons without answer randomization; for randomized lessons it is returned empty since activities are attempt-specific.  
      
-   **Usage:** Primarily used for building navigation interfaces and understanding a course's structure.  
      

### `initializeLesson()`

-   **Description:** Creates a new `LessonStatus` entity for a user's first attempt at a lesson. It also checks if prerequisites from previous lessons have been met.  
      
-   **Function:** `$training_manager->initializeLesson(CourseStatusInterface $course_status, LMSReferenceItem $lesson_item);`  
      
-   **Return Value:** The newly created `LessonStatusInterface` entity.  
      
-   **Usage:** This is called automatically by `getRequestedLessonStatus()` when a user accesses a lesson for the first time.  
      
-   **Exceptions:** Can throw a `TrainingException` (code `REQUIRED_NOT_STARTED`, `REQUIRED_NOT_FINISHED`, or `INSUFFICIENT_SCORE`) if a mandatory previous lesson's requirements are not met.  
      

## Utility and Debugging

### `resetTraining()`

-   **Description:** Deletes all progress data (status entities and answers) for a course. Can be targeted to a specific user or all users. **Use with caution.**  
-   **Function:** `$training_manager->resetTraining(string $group_id, ?string $user_id = NULL);`  
     
-   **Usage:** Primarily useful for testing, fixing data inconsistencies, or allowing a full course retake.  
      

**[Previous page: Theming & Frontend Integration](theming-and-frontend-integration.md)**

**[Next page: Error Handling & Troubleshooting](error-handling-troubleshooting.md)**
