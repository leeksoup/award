# Error Handling & Troubleshooting

Errors thrown in Drupal LMS are handled in the `TrainingException` class (`src/Exception/TrainingException.php`). This extends Drupal's standard `\Exception` with custom error handling for various scenarios that may occur during course navigation, lesson progress, and activity completion.

## Understanding TrainingException

At the core of the LMS module's error handling is the `TrainingException` class, which provides granular error codes for different scenarios, and maintains a reference to the relevant `CourseStatus` entity.

    // Example of catching a TrainingException
    try {
      $course_status = $this->trainingManager->getCurrentCourseStatus($course, $this->currentUser());
      // Continue with your logic...
    }
    catch (TrainingException $e) {
      $message = $e->getMessage();
      $code = $e->getCode();
      $courseStatus = $e->getCourseStatus();
    
      // Log the specific error for debugging.
      $this->logger->warning('A training exception occurred for user {uid}: {message}', [
        'uid' => $this->currentUser()->id(),
        'message' => $message,
      ]);
    
      // Handle the exception, e.g., by redirecting the user.
      if ($courseStatus !== NULL) {
        // A user-specific progress error occurred.
      } else {
        // A system-level data integrity issue occurred.
      }
    }

When handling `TrainingException`, also check the `getCourseStatus()` method.  If it returns `NULL`, it typically indicates a system-level or data integrity issue rather than a user progress-related issue.

### TrainingException Error Codes

The LMS module defines the following error codes in `src/Exception/TrainingException.php`. These may be added to over time.

Constant

Value

Description

`REQUIRED_NOT_STARTED`

1

A required lesson has not been started

`REQUIRED_NOT_FINISHED`

2

A required lesson has not been completed

`INSUFFICIENT_SCORE`

3

The required score on a previous lesson was not achieved

`NO_LESSONS`

4

The learning path contains no lessons

`LESSON_REMOVED`

5

The requested lesson is no longer part of the course

`ACTIVITY_REMOVED`

6

The requested activity is no longer part of the lesson

`BACKWARDS_NAV_DISALLOWED`

7

Backwards navigation is not permitted for this lesson

`FREE_NAV_DISALLOWED`

8

Free navigation is not permitted for this course

`COURSE_NEEDS_EVALUATION`

9

The course requires evaluation by an instructor

`INVALID_BACKWARDS_PARAMETERS`

10

Cannot navigate backwards with the provided parameters (Internal error)

`COURSE_INITIALIZATION_ERROR`

11

Access to the course was denied during initialization

`INCORRECT_ACTIVITY_REQUESTED`

12

The requested activity is not the next sequential one in a course with restricted navigation

`CUSTOM_ACCESS_DENIED`

13

The user's account does not have access to the course (reserved for custom access checks in extending code; not currently thrown by LMS core)

`COURSE_REMOVED`

14

The course (group) entity referenced by a status entity could no longer be loaded (e.g. it was deleted)

## Common Error Scenarios

Here are some common scenarios where these exceptions might be thrown:

### Navigation Errors

Navigation errors occur when a user attempts to access parts of a course in a way that conflicts with the course's navigation rules. For example, trying to jump to an advanced lesson before completing prerequisite lessons:

    // In TrainingManager::getRequestedLessonStatus
    $reason = $this->checkActivityAccess($course_status, $deltas);
    if ($reason !== NULL) {
      throw new TrainingException($course_status, $reason);
    }
    

If you're implementing custom navigation in your module, make sure to respect the course navigation settings (free navigation, backwards navigation, etc.). These settings are crucial for maintaining the intended learning flow.

### Progress-Related Errors

Progress-related errors happen when prerequisites for moving forward aren't met:

    // In LmsLessonHandlerBase::checkRequirements
    // (the same method also throws REQUIRED_NOT_STARTED / REQUIRED_NOT_FINISHED
    // earlier on if the previous mandatory lesson wasn't started/finished).
    if ($previous_lesson_item->autoRepeatFailed()) {
      if ($score < $previous_lesson_status->getRequiredScore()) {
        throw new TrainingException($course_status, TrainingException::INSUFFICIENT_SCORE);
      }
    }

### Data Integrity Errors

These errors indicate problems with the course structure or missing components:

    if ($lesson === NULL) {
      throw new TrainingException(NULL, TrainingException::LESSON_REMOVED);
    }
    

## Handling TrainingExceptions in Custom Code

When extending the LMS module or integrating it with your custom modules, you'll likely need to handle these exceptions. Here's how to do it effectively:

### Controller-Level Handling

The `CourseController` class in `src/Controller/CourseController.php` offers a good reference example of handling `TrainingException` at the controller level:

    try {
      $lesson_status = $this->trainingManager->getRequestedLessonStatus($group, $this->currentUser());
    } catch (\Exception $e) {
      $url = $this->handleError($group, $e);
      return $this->redirect($url->getRouteName(), $url->getRouteParameters());
    }

The `handleError` method in `src/Controller/CourseControllerTrait.php` differentiates between `TrainingException` and other types of exceptions:

    private function handleError(CourseInterface $group, \Exception $e): Url {
      // Training exception - reset current lesson and activity and redirect
      // to the lesson.
      if ($e instanceof TrainingException) {
        // Handle TrainingException specifically
        $course_status = $e->getCourseStatus();
        if ($course_status !== NULL) {
          $this->messenger()->addWarning($e->getMessage());
          // ... handle course status related errors
        } else {
          // ... handle system level errors
        }
      } else {
        // Handle other exceptions
        $this->messenger()->addError($this->t('An error occurred...'));
        // ... log the error
      }
      // ... return appropriate URL for redirection
    }
    

When developing custom controllers that interact with the Training Manager, you can use a similar approach to error handling as above.  This will give your users a consistent user experience.

### Service-Level Integration

When integrating with the `TrainingManager` service (`src/TrainingManager.php`), your code should catch exceptions and handle them appropriately:

    public function customLmsFunction(Course $course, AccountInterface $user) {
      try {
        $courseStatus = $this->trainingManager->getCurrentCourseStatus($course, $user);
        // Process course status
      }
      catch (TrainingException $e) {
        $this->logger->error('LMS training error: @message', [
          '@message' => $e->getMessage(),
          '@code' => $e->getCode(),
        ]);
    
        // Handle based on error code
        switch ($e->getCode()) {
          case TrainingException::NO_LESSONS:
            // Handle empty course
            break;
          case TrainingException::COURSE_NEEDS_EVALUATION:
            // Handle evaluation requirement
            break;
          // ... other cases
        }
      }
    }

## Best Practices for Error Handling

1.  **Catch TrainingException specifically**: This allows you to handle LMS-specific errors differently from other PHP exceptions.
    
2.  **Check the course status**: Use `$e->getCourseStatus()` to determine if the error is related to user progress.
    
3.  **Provide user-friendly messages**: The exception messages are developer-focused; you may want to translate them into user-friendly messages.
    
4.  **Log detailed information**: Include the error code, message, and course/user context in your logs.
    
5.  **Implement proper redirects**: Follow the pattern in `CourseController::handleError` to redirect users to appropriate pages based on the error.
    

## Extending Error Handling

If you're extending the LMS module with new functionality, you might need to throw `TrainingException` yourself:

    public function validateCustomLmsFunction(CourseStatusInterface $courseStatus) {
      if (!$this->meetsCustomRequirement($courseStatus)) {
        throw new TrainingException(
          $courseStatus, 
          TrainingException::FREE_NAV_DISALLOWED, 
          new \Exception('Custom requirement not met')
        );
      }
    }
    

Whenever possible, it's best practice to use the closest-matching existing code.  If necessary, you can also extend the `TrainingException` class to add new codes.

## Troubleshooting Common Issues

If you encounter unexpected behaviors when integrating Drupal LMS, these common scenarios may help you debug the issue.

### Course Navigation Problems

If users report being unable to access a lesson or activity, check the following:

1.  **Course Settings:** Verify the `free_navigation` and `revisit_mode` settings on the Course entity.  
      
2.  **Lesson Settings:** Verify the `backwards_navigation` setting on the Lesson entity.  
      
3.  **Lesson Requirements:** Check the `mandatory` and `required_score` parameters on the LMSReferenceItem field that connects the lesson to the course.  
      

### Missing or Incorrect Error Messages

If error messages are not displaying as expected:

1.  Ensure your custom controller is correctly calling a method like `CourseControllerTrait::handleError` to catch and display exceptions.  
      
2.  Confirm that the Drupal messenger service is functioning and its messages are being rendered in your theme.  
      

### Lesson Access Issues

If users can't access certain lessons when they should:

1.  Examine the `mandatory` and `required_score` settings on lesson references  
      
2.  Check for proper `CourseStatus` and `LessonStatus` records in the database  
      
3.  Look for exceptions related to `REQUIRED_NOT_FINISHED` or `INSUFFICIENT_SCORE`  
      

[**Previous page: Drupal LMS API**](drupal-lms-api.md)

[**Next page: Developer's Cookbook**](developers-cookbook.md)
