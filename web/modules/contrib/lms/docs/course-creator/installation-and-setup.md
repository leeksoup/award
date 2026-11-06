# Installation and Setup

## What You Need

### Prerequisites

To install the Drupal LMS module, its prerequisites are:

1.  **Drupal Core**: Version 10.3 or newer
2.  **PHP**: Version 8.1 or newer
3.  **Database**: MySQL 5.7.8+ / MariaDB 10.3.7+ / PostgreSQL 12+ / SQLite 3.26+
4.  **Required Modules**:
    -   Group module (version 3.2 or newer)

### Recommended Drupal Modules

While not required for the module to function, the following modules are highly recommended as they provide a much better experience for administrators, teachers, and students. The QuickStart repository includes these by default.

-   **[Views Bulk Operations (VBO)](https://www.drupal.org/project/views_bulk_operations):** Makes managing students significantly easier. It is required for features like resetting student progress or removing multiple students from a course via the **Students** tab.
    
-   **[Admin Toolbar](https://www.drupal.org/project/admin_toolbar):** Provides a dropdown-style administration menu that makes navigating the Drupal backend much faster and more efficient for course creators and site administrators.
    
-   **[Group Membership Request](https://www.drupal.org/project/group_membership_request):** Required by the LMS Membership Request module to moderate student enrolment. With this module, students can request to join a course, and a teacher or administrator must approve the request before they are granted access.
    

### Optional LMS Add-ons

There are also a number of optional LMS ecosystem modules available, which you can add depending on your needs.  Some of these modules are in relatively early stages of development, but should work for most use cases; please report any issues.  Follow each sub-module's own README for installation and setup instructions:

-   **[LMS Membership Request](https://www.drupal.org/project/lms_membership_request)**: allows students to request membership on selected courses.  
      
-   **[LMS File Upload](https://www.drupal.org/project/lms_file_upload)**: Adds a file upload activity type.  
     
-   **[LMS Webform](https://www.drupal.org/project/lms_webform)**: Integrates LMS with the [Webform](https://www.drupal.org/project/webform) module to allow using webforms in activities.  
     
-   **[LMS Certificate](https://www.drupal.org/project/lms_certificate)** and **[LMS Certificate Entity](https://www.drupal.org/project/lms_certificate_entity)** : allow generating PDF certificates for completed courses.  
     
-   **[LMS Messages](https://www.drupal.org/project/lms_messages)**: provides integration with the [Message](https://drupal.org/project/message) module for automatic creation and sending of messages on various LMS events.  
     
-   **[LMS YAML](https://www.drupal.org/project/lms_yaml)**: Allows exporting / importing courses using zip packages with files in YAML format.  
     
-   **[LMS Xapi](https://www.drupal.org/project/lms_xapi)**: Integrates Xapi packages (for example from Articulate 360) with a Drupal site. Provides:
    -   a field formatter for any entity type
    -   integration with Drupal LMS by providing the Xapi Package lesson bundle
    -   a minimalistic Xapi LRS for storing scores and states of activities  
          
-   **[LMS H5P](https://www.drupal.org/project/lms_h5p)**: Integrates with the Drupal H5P module, which provides access to H5P's complete library of open-source activities.  
      
-   **[LMS Scorm](https://www.drupal.org/project/lms_scorm)**: Adds support for Scorm packages to share course content between platforms.  
     
-   **[LMS Migrate](https://www.drupal.org/project/lms_migrate)**: Migration plugins and template examples for migrating from Opigno to Drupal LMS.

## How to Install

### Option 1: QuickStart (for new websites)

The easiest way to have a running Drupal LMS installation is with the quickstart package:

1.  Clone the quickstart repository:
    
        git clone https://github.com/graber-1/drupal_lms_ddev
    
2.  Follow the instructions in the README.md file to set up with DDEV.
    

This will allow you to install Drupal LMS with all default settings, and optionally include sample content using `ddev composer sid`.  The sample content includes three test users: LMS Admin, LMS Teacher, and LMS Student, each with their relevant levels of permissions. All users have a default password of 123456.

You can then skip the rest of this page, although reading it will help you understand the LMS module setup in more detail.

If you're configuring your site while logged in as User 1, or another site admin user, make sure to assign the LMS Admin role to your account. Otherwise you'll have unexpected behaviors from the LMS functions. 

A DDEV setup is excellent for local development, collaboration, and testing, before configuring a production environment and deploying to a live site.

### Option 2: Adding to an Existing Drupal Site

If you want to add Drupal LMS to a site you already have:

1.  Install the Drupal LMS module and its dependencies, by copying the composer command from the module's [Releases](https://www.drupal.org/project/lms#project-releases).  For example:
    
        composer require drupal/lms:^1.1
    
2.  Enable the module and its dependencies:
    
        drush en lms -y
    
3.  If you'll be using the core Activity-Answer plugins (true/false, multiple choice, etc):  
    
        drush en lms_answer_plugins -y
    
4.  If you want to be able to group students into Classes, and have extra student management functions, enable the optional Classes sub-module:  
    
        drush en lms_classes -y
    
5.  Some manual configuration is then required (explained in detail in the next sections):
    -   Creation of activity types.
    -   Configuration of global and group roles and permissions.
    -   Configuration of views to display courses, results etc.
    -   Configuration of blocks (e.g., Course navigation) if desired.

Drupal LMS will not work properly just by installing the module into a Drupal site.  It depends on the configuration of appropriate user roles, Group users, and at least one Activity Type.

## Initial Setup

If you don't use the quickstart package, you will need to manually set up a few things after installing the Drupal LMS module, before you can start creating course content:

### 1\. Activity Types

Create at least one type of activity:

1.  Go to **Admin > LMS > Activity Types** (`/admin/lms/activity_type`)
2.  Click **Add Activity type**
3.  Make an activity type (e.g., “Multiple Choice Question”)
4.  Pick an Activity-answer plugin for the type (e.g., “Single answer select”)
5.  Set a default score value.  This is the score added to a student's lesson total for their answer, and depending on the activity type, it can be all-or-nothing or a percentage of this score.

### 2\. User Permissions

Set up permissions for different users:

1.  Go to **Admin > People > Roles** (`/admin/people/roles`)
2.  Create roles and set their permissions.  The default config includes:
    -   **LMS Admin**: needs permissions: Administer LMS, Activity: Administer fields, Activity: Administer form display, along with any desired site admin and teacher permissions
    -   **LMS Teacher**: needs permissions: Course: Create new group, Class: Create new group, Access Group Overview Page, Create LMS Activities, Create LMS Lessons, plus any desired site admin permissions.
    -   **Student**: Usually just the authenticated user, doesn't require any special permissions since course access is handled by the Group module.

### 3\. Group Setup

Set up groups for courses, and optionally for classes:

1.  Go to **Admin > Groups > Group Types** (`/admin/group/types`) and click Add Group Type.  Give it the name _Course_ and machine name _lms\_course_
2.  **Edit Group Roles** for the _Course_ group (`/admin/group/types/manage/lms_course/roles`)
3.  Set up these roles:
    -   **LMS Admin**: Outsider with _LMS Admin_ global role and group _Admin Role_.
    -   **LMS Admin member**: Insider with _LMS Admin_ global role and group _Admin Role_.
    -   **Teacher**: Insider with _LMS Teacher_ global role and all _General_ and _Group membership_ permissions except _Access all entities overview_.
    -   **Outsider**: Outsider with _Authenticated user_ global role, and permissions to: View published group, Join group, Request group membership.
    -   **Anonymous**: Outsider with _Anonymous user_ global role, and permissions to: View published group
4.  If you are using the **LMS Classes** submodule, repeat this process to create a _Class_ group type with machine name _lms\_class_ then set up its group roles (`/admin/group/types/manage/lms_class/roles`):
    -   **LMS Admin**: Outsider with _LMS Admin_ global role and group _Admin Role_.
    -   **LMS Admin member**: Insider with _LMS Admin_ global role and group _Admin Role_.
    -   **Member**: Insider with _Authenticated user_ global role, and permissions to: View published group.

If you aren't familiar with how the Group module works, you can [read through its documentation here](https://www.drupal.org/docs/extending-drupal/contributed-modules/contributed-module-documentation/group).

### 4\. Views Configuration

There are a number of optional but recommended [Views provided in the quickstart repository](https://github.com/graber-1/drupal_lms_ddev/tree/dev/config/sync).  Any of these that you want to include will need to be manually imported.  If you have the core Configuration Manager module enabled, you can import each `views.view` at **Admin > Config > Development > Synchronize > Import > Single Item** (`/admin/config/development/configuration/single/import`).

If you need to customize how courses, lessons, and results are displayed:

1.  Navigate to **Admin > Structure > Views** (`/admin/structure/views`)
2.  Look for views with names starting with "lms\_" or related to courses
3.  Edit these views to match your display preferences

### 5\. Block Configuration

Configure course navigation blocks:

1.  Navigate to **Admin > Structure > Block layout** (`/admin/structure/block`)
2.  Place the "Course navigation" block in an appropriate region
3.  Configure block visibility settings to show only on course pages

## Testing Your Setup

To make sure everything works:

1.  Create a test activity at **Admin > LMS > Activities** > **Add Activity** (`/admin/lms/activity/add`)
2.  Create a test lesson at **Admin > LMS > Lessons** > **Add Lesson** (`/admin/lms/lesson/add`) and add your test activity into the lesson
3.  Create a test course at **Admin > LMS > Courses** > **Add Course** (`/group/add/lms_course`) and add your test lesson into the course
4.  Make a test student account and add them to the course
5.  Log in as the test student and take the course

If you have problems, log back in with your site admin account and check the status report at `/admin/reports/status` for warnings or errors related to Drupal LMS or its dependencies.  You can also check the recent log messages for errors at `/admin/reports/dblog`.

If you'd like to examine the default quickstart config settings in detail, you can view the relevant config file at [https://github.com/graber-1/drupal\_lms\_ddev/tree/dev/config/sync](https://github.com/graber-1/drupal_lms_ddev/tree/dev/config/sync)

**[Previous page: Introduction](introduction.md)**

[**Next page: General settings**](general-settings.md)
