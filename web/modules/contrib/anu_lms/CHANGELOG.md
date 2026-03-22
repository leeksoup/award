# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.11.2]
- #3490633: Added block visibility plugin for Anu LMS pages.

## [2.11.0]
- #3428168: Drupal 11 compatibility fixes for anu_lms.

## [2.10.12]
- #3487260: Fix failing to apply patch for the Paragraphs module.

## [2.10.11]
- Move `course_modules` from hook install into configs.

## [2.10.10]
- Fixed toolbar delimiters and toolbar item name for table editor.

## [2.10.9]
- Add cweagans/composer-patches version 2 support.

## [2.10.8]
- Fixed issue with IEF ^3 composer dependency.

## [2.10.7]
- #3468864: Fix failing to apply patch for the Paragraphs module.
- #3449177: Support for IEF ^3.

## [2.10.6]
- #3446911: Paragraphs browser patches are preventing module form being installed.
- #3446906: CI/CD has failing phpcs step.

## [2.10.5]
- #3446057: Offline mode is not working properly when aggregation enabled (Drupal 10.1+).
- #3432169: Update demo content to use new courses landing page.

## [2.10.4]
- Add scope property to group.role configs for anu_lms_permissions module.
- Allow to use any group module version for anu_lms_permissions module.
- Remove the patch "3281020" for "drupal/entity_reference_revisions" module, because that patch isn't compatible with the newest version 1.11 and looks like that problem was fixed in [this issue](https://www.drupal.org/project/entity_reference_revisions/issues/3098924).
- Update Settings::getPwaSettings method to be compatible with pwa module v.2.
- Improve the cache logic for DownloadCoursePopup component.
- Update metadata for assessment_question.
- Add `.npmrc` with `node-options="--openssl-legacy-provider"`, that will allow running `npm run build` without flags.
- #3440147: Multiple warnings related to form build functions
- #3440964: Likert scale type question

## [2.10.3]
- #3401521: Fixed compatibility with Drupal 9 and Drupal 10 at the same time.
- Update the dependencies of packages.

## [2.10.2]
- Update the restrictions for symfony/translation package.
- Update the restrictions for paragraphs_selection package.

## [2.10.1]
- #3383035: Upgrade Anu LMS to support Drupal10.
- Allow to use allowed_formats v2, v1 isn't supported anymore. v2 is completely compatible with v1, but supports D10.
- #3385516 Fixed checking for correct answer mor multiple choice questions.

## [2.9.5]
- #3331125 Added example modules explaining how to:
  - Override a component
  - Add a custom paragraph
  - Modify lesson or courses page data
- #3344889: JS exception: Cannot read properties of null (reading 'bundle') on lesson page.
- #3374379: Restrict access to lessons if a user doesn't have access to the lesson's course.
- #3377439: Fixed checklist checkboxes get hidden after the first submission

## [2.9.4]
- Code refactoring for localizeMessage method.
- #3342395: Some array items in form hooks don't exist and that causes warnings.

## [2.9.3]
- Remove normalizer from anu_lms.lesson and anu_lms_assessments.quiz.
- Handle edge-cases in JS application when #anu-application doesn't exist.
- #3336154: Update patches for Drupal core and paragraphs_browser module.

## [2.9.2]
- #3313429: Incorrect behavior for cases when a user didn't add answer for question and submitted the form.
- #3312857: Short answer question in quiz looks odd.
- #3312855: Placement of Submit button for lesson questions is off.

## [2.9.1]
- Get rid of "flatMap" in js code to provide better compatibility with old browsers.
- #3315901: Ability to download courses to use offline. Hide questions/quizzes if a user doesn't have necessary permissions.
- #3316125: Ability to change name of entities.
- Added "getPermissions" to Settings service.
- Added "data-test" attributes for "checklist items" and "loading indicator".
- Display "loading indicator" only if a user have permissions to tick/un-tick checklist items.
- #3312217: Cover creation of lessons with tests.
- #3313461: Cover quizzes with tests.
- #3313777: Cover questions in lessons with tests.
- #3314849: Cover NEW courses pages with tests.
- #3321565: Cannot redeclare ckeditor_help().

## [2.9.0]
- #3312107: Highlighting should be applied for whole words.
- Improved global styles: set box-sizing and reflecting icons for RTL languages.
- Fixed package name in composer.json.
- Added Topics - an additinal way for courses categorization.
- Added new courses page with sidebar filtering.
- Introduced new course card styles for the new courses page.
- The "Old" courses page got deprecated and hidden from the "Add content" page.

## [2.8.2]
- The lesson title will be displayed without cutting, no matter how long it is.
- Add redirect from "#section-X" to "#page-X" for lessons.
- Fixed bottom spacing for quiz page and mobile layout.
- Adjusted "Make available offline" button text when processed.
- Fixed the problem "Unable to install Anu LMS Permissions due to unmet dependencies". That was happening in a case when "Anu LMS Assessments" module was disabled when a user tried to enable "Anu LMS Permissions" module.
- Renamed "CoursesTest" to "DemoContentTest".
- Added CoursesCreationTest.
- Fixed a bug broken html output for lessons when highlighting was requested.
- #3308215: Make bigger area for Play audio button.
- #3308596: Cover courses pages with tests.
- #3309362: Cover API endpoints for checklists & progress saving with automated tests.
- Fixed a bug in implementation of "CourseProgress::getCompletedLessons" and "CourseProgress::getCompletedQuizzes" methods.

## [2.8.1]
- Fixed spacing for the lesson sidebar and lesson mobile navigation.
- Moved "Make available offline" button below the lesson title.

## [2.8.0]
- Added a new dependency on Paragraphs Browser and configured it to be used for adding new paragraphs
- Renamed several paragraphs and changed their description to better explain what they do
- Fixed bug with display of "This audio is ready to be used offline" on audio paragraphs when offline feature is not enabled
- Added small bottom margin for the "List (thumbnails)" block between the items
- Made color of the description of the "Resource" block darker for better contrast
- Fixed a bug in numbered divider which caused numeric divider to always show "1" instead of actual divider counter

## [2.7.2]
- Fixed the case when Top Navigation wasn't sticky if a user didn't have Admin Menu.
- Removed obsolete paragraph mapping (`lesson_image_bullet_list`).

## [2.7.1]
- Added the sticky top navigation for lessons and quizzes
- Changed styles and labels for navigation buttons
- Added lesson title into section above lesson content
- Improved styles for disabled buttons
- Removed toggleable "Hide modules" / "Show modules" course navigation link. Now the navigation is static
- Removed custom hook useLocalStorage() since it's not used anywhere in the code anymore
- Changed css "position" of the course navigation from absolute to static since it does not have to be absolute anymore
- Changed styles of an active lesson / quiz item in the course navigation
- The mobile navigation moved to "Top navigation" section and got new style
- Added "Page number" and "total amount of pages" to "Top navigation"
- Added "Progress bar" to "Top navigation"
- Renamed "Sections" to "Pages" in Admin UI
- Removed ability to modify label of the Finish button
- Restored Pathauto dev dependency for running tests (testLessons test).
- Migrated to Drupal.org Gitlab CI.

## [2.7.0]
- Dropped support of Drupal 8
- Removed dependency of SHS and Config pages
- Removed dependencies from composer.json which are not required to run the main anu_lms module (Group, Range, Views data Export, XLS Serialization, Features)
- Cleaned up dependencies and rephrased module descriptions in .info.yml files
- Removed default search_api configs from the anu_lms_search module
- Changed cardinality of course categories to 1 (as originally intended)
- Fixed issue not picking up allowed formats for the formatted text fields
- Made text field inside of footnotes required (it was a bug that it was not required)
- Added proper descriptions to each paragraph
- Changed name of lesson paragraphs from "Lesson: [name]" to "Block: [name]" for better editorial experience

## [2.6.5]
- Added module's title to the search index of the first lesson.

## [2.6.4]
- Added automatic switching to the section with search keywords if the first section does not contain any
- Use "span" html tag instead of "marker" for highlighted paragraph
- Fixed allowed values for "align" property in ImageBulletItem component
- Changed default parse mode for search from "Single phrase" to "Multiple words" for better searchability

## [2.6.3]
- Changed range of weights on page where courses are sorted to support ordering of more than 40 courses per category.

## [2.6.2]
- Fixed Download button label translation for Resource paragraph.
- Added submodule which provides default configuration for LMS search functionality.
- Fixed course navigation overlapping by tables on mobiles.
- Added search keywords highlighting in lesson content.

## [2.6.1]
- Optimized heavy ->getAudios() method.
- Fixed ->getFirstAccessibleLesson() return type.
- Fixed Anu LMS module install on Drupal 9.4.4 by updating Pathauto configs.

## [2.6.0]
- Separated generic NodeNormalizer for all ANU nodes into individual normalizers
- Moved logic of getting page data from node managers to children of AnuLmsContentTypePluginBase (->getData() method)
- Added a patch to composer.json which improves performance of ->referencedEntities() for paragraphs
- For offline course download now we pass courses page urls instead of full course page entities
- Quiz node view handler now inherits from ModuleLesson instead of generic AnuLmsContentTypePluginBase (anu_lms_assessments)
- All methods related to Lesson manager now take lesson ID as an argument instead of loaded lesson node object
- Added strict types for method arguments and returning values for PHP
- Removed support of legacy Module node type for Groups (anu_lms_permissions) which prevented the module from install
- Removed handler of content type view for legacy Module node type
- Added a new method ->isOfflineSupported() which is a wrapper around checking whether pwa is enabled
- Optimized heavy ->getFirstAccessibleLesson() method
- Optimized heavy ->getLessonsAndQuizzes() method
- Optimized heavy ->getCourseProgress() method
- Optimized heavy ->getCoursesPagesByCourse() method
- LessonCompletedEvent now passes lesson_id instead of lesson object
- Method ->getPreviousLesson() was replaced with ->getPreviousLessonId()
- Method ->setPreviousLessonCompleted() was removed (it was unused)
- Added cache context to Normalizer for Groups permissions when anu_lms_assessment is enabled
- Enabled support of Normalizer caching when course progress is enabled
- Load certain parts of lessons and courses only when offline support is enabled (i.e. content_urls or audio)

## [2.5.7]
- Fixed Download button label translation for Resource paragraph.
- Fixed wrong domain name in the lesson sidebar on multi-domain sites.
- Fixed caching of unpublished content in the lesson sidebar.

## [2.5.6]
- Disabled cache when the progress is enabled.

## [2.5.5]
- Improved page loading performance by adding caching for serialized data.

## [2.5.4]
- Fixed issue with non-existing first lesson within the course progress.

## [2.5.3]
 - Added Event for completed all lessons.

## [2.5.2]
 - Fail CI build if there are uncommitted changes after `npm run build`.
 - Fix the issue when SW can't be registered due to a script redirect.
 - Fix course downloading the course from the courses page.

## [2.5.1]
 - Fixed bug when the quiz can't be finished due to missing complete callback.

## [2.5.1-beta]
 - Adding shamelessly forgotten built version of the app.

## [2.5.0-beta]
 - Added "Anu LMS Demo content" module.
 - Added path alias patterns for Anu LMS content types.
 - Added js-enabled basic testing.
 - Fixed no lesson error for quizzes.
 - Fixed bug with no previous lesson in quizzes.

## [2.5.0-alpha]
 - Move progress tracking to frontend and make progress available offline.
 - Add new DownloadCoursePopup component that offers to download courses with or without audio (for courses with audios)
 - Add a new isCompletedByUser helper method to lesson service.
 - Track the date when a lesson is completed.

## [2.4.13]
 - Fixed checkbox text wrap, so it is readable and go in multiple lines if text is longer

## [2.4.12]
 - Improved courses page loading performance by reducing the depth of references to load and explicitly loading lessons and quizzes urls.

## [2.4.11]
 - Fixed the finish button didn't respect language.

## [2.4.10]
 - Remove back button in header of lessons.
 - Deleted back button component.
 - Added back button in content navigation for easier navigation through lessons.
 - Small styles for buttons.

## [2.4.9]
 - Fixed Drupal 9 compatibility when pwa is enabled.
 - Fixed warning when PWA module didn't provide a version.
 - Added AudioPlayer and AudioBase components for handing audio.
 - Added Audio item for lessons.

## [2.4.8]
 - Updated REST endpoints for assessment to work with Drupal 9

## [2.4.7]
 - Do not lock drupal/shs and drupal/shs_chosen versions in composer

## [2.4.6]
 - Fixed a bug with denied access to lessons when course has linear progress enabled.

## [2.4.5]
 - Removed huge white space at the end of a lesson for mobile devices.

## [2.4.4]
 - Fix empty checkboxes bug.

## [2.4.3]
 - Fix service existence.
 - Fix empty quiz.

## [2.4.2]
 - Fix TypeError for content_moderation_entity_form_display_alter().

## [2.4.1]
 - Fixed static cache for lessons progress.
 - Fixed issue with not displaying unpublished courses on sorting of courses per category.
 - Refactored quiz submission API endpoint for better code readability, improved validation & access checks.
 - Refactored TODO list. Removed completed item.
 - Updated "ansi-regex" package to fix security vulnerabilities.
 - Added an event to be able to modify output of lesson page.
 - Added PHP code sniffer check to the CI.

## [2.4.0]
 - Quizzes submodule is optional and the core module has no longer a dependency on it.
 - Legacy module content type removed.
 - Use paragraph_selection to allow bundles to select the fields in which they appear as a choice.

## [2.3.19]
 - Fixed request for the correct answer to respect translation.

## [2.3.18]
 - Hides lesson/quiz title on mobile view (600px or smaller) based on results of user research.

## [2.3.17]
 - Added alignment option for "Highligt (with image)" paragraph.

## [2.3.16]
 - Added an event to be able to modify output of courses page.
 - Added an example module to show how to extend anu.

## [2.3.15]
 - Improved image quality for all image styles of ANU LMS.

## [2.3.14]
 - Added footnotes functionality for Table HTML format.

## [2.3.13]
 - Added Footnotes component.

## [2.3.12]
 - Fixed a bug where a course page with multiple categories timed out.
 - Fixed not working Finish button for quizzes.

## [2.3.11]
 - Reduced padding for `<p>` elements.
 - List bullets aligned to the top of the element.
 - Added ul/ol lists for Minimal HTML editor.

## [2.3.10]
 - Fixed bug where a course with no categories was displayed as locked.

## [2.3.9]
 - Added ability to sort order of courses in the same category.
 - Added locking of courses based on the order within their category.

## [2.3.8]
 - Added a progress bar for courses with linear progress enabled.
 - Added finish button at the end of courses without a quiz with customizable text and path.

## [2.3.7]
 - Updated @material-ui/core to the latest version.

## [2.3.6]
 - Fixed bug with not defined function in theme.js.

## [2.3.5]
 - Fixed compatibility with latest @material-ui version.

## [2.3.4]
 - Fixed Eslint errors and added jsx-a11y Eslint plugin to check for Accessibility problems.

## [2.3.3]
 - Fixed error when a course had no modules

## [2.3.2]
 - Fixed non-clickable links in tables

## [2.3.1]
 - Adds text formatting ability for paragraph types: "Image with caption (thumbnail)" and "List"

## [2.3.0]
 - Adds offline mode support for using with Progressive Web App module
 - Adds ability to translate strings for quiz submit buttons and info message
 - Improve data migration script for update from 1.x
 - Improve Course card on Courses page to lead directly to first lesson
 - Adds Table lesson section item

## [2.2.0]
 - Added linear progress support for courses
 - Improve 'Hide correct answers' functionality to answers are not returned at all to front end on submission
 - Improve 'Make single submission' functionality to not allow submission in different browser window
 - Fixed Course nested content clean up on deletion
 - Fixed Course redirecting for translated lesson

## [2.1.0]
 - Navigation button for end of module quizzes to move to the next module if it exists.
 - Adds multiple highlight paragraph types: full width, with icon, marker – with four colors each.
 - Adds additional space at bottom of lesson content and quiz pages.

## [2.0.0]
 - Changelog for documenting features
 - New section 'Settings' for Quizzes for configuring quiz settings. Available to any user with the permission to add or edit
   quiz content.
 - New quiz setting 'Hide correct answers' which will hide the display of correct answers to users
   when they submit a quiz. Default value is disabled.
 - New quiz setting 'Make single submission' which will only allow one user submission per quiz. On clicking
   submit button, users will see an alert prompting them to confirm submission. Once submitted correct andswers and
   scores will be displayed. On page refresh the quiz displays the users answers, their score, and the quiz is not able to be
   submitted. Default value is disabled.
