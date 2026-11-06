# Student & Class Management

## Understanding Student Enrolment

In Drupal LMS, student enrolment can be handled in two ways:

1.  **Course-Level Enrolment:** Students are added directly as members of the Course group. This is the simplest method and is suitable for courses that do not need to be divided into separate cohorts.  
      
2.  **Class-Based Enrolment:** Students are enrolled in a Class, which is then associated with a Course. This method requires the optional **LMS Classes** sub-module.  
      

The **LMS Classes** sub-module provides advanced features, including the **Students tab** on the course page for detailed reporting and management. Most of the management features described on this page require this sub-module to be enabled.

### Benefits of Class-Based Enrolment

-   Better organization of student groups (e.g., by semester, location, or teacher).
-   More detailed reporting and filtering capabilities.
-   Ability to assign different teachers to different classes within the same course.
-   Support for multiple cohorts taking the same course content.

## Adding Students to Courses

### Course-Level Enrolment (Without Classes)

If you are not using the LMS Classes sub-module, you can add students directly to the course itself.

1.  Navigate to **Administration > Groups** (`/admin/group`).  
      
2.  Find the course you want to add students to and click on **Members** in the Operations column.  
      
3.  Click the **Add member** button to add existing users to the course.  
      

### Class-Based Enrolment (with the LMS Classes module)

#### Manually Adding Students

To manually enrol students into a course when using the LMS Classes module, they first need to have a user account, then you can add their username to one of the course's associated classes. The easiest way to add students is through the course's **Students** tab:

1.  Go to the course page, either by clicking on its course card, or by visiting **Admin > LMS > Courses** and clicking on the course Title.  
      
2.  Click the **Students** tab, then click the **Add student** button.  
      
3.  In the form, select the **Class** you want to add the student to.  
      
4.  Start typing a student's username in the **Student** field and select them from the autocomplete suggestions.  
      
5.  Click **Add**.  
      

#### Self-Enrolment Options

You can allow students to enrol themselves in courses as a site-wide setting. This is controlled by the permissions you assign to the **Outsider** role within the Course group type.

1.  Navigate to the Course group type's permissions page by visiting **Administration > Groups > Group types** and clicking **Edit permissions** next to Course (`/admin/group/types/manage/lms_course/permissions`).  
      
2.  Find the **Outsider (Authenticated user)** column to manage its permissions.  
      
3.  To control enrolment, grant one of the following permissions:
    -   **Join group:** Allows any authenticated user to enrol in the course immediately by clicking an "Enroll" button.  
          
    -   **Request group membership** (if you have the _LMS Membership Request_ module installed): Allows users to request enrolment. A teacher or admin must approve the request before the student is enrolled.

If a student self-enrols in a course (either directly or after an approved request) and the **LMS Classes** module is enabled, they will be automatically placed into the course's default class. If no class exists yet, one will be created automatically.

If you want to restrict student enrolment in courses so that they require approval to join, first install the [**LMS Membership Request module**](https://www.drupal.org/project/lms_membership_request), then grant the Outsider role for Course group types the "Request group membership" permission, and remove their "Join group" permission.

## Managing Classes

Remember that in Drupal LMS, Classes are Groups.  This makes managing them a little awkward and not very intuitive.  More UI improvements will be coming in the near future, but in the meantime, here's how to manage them:

### Viewing Classes

#### Classes in a specific course

1.  Go to the course page, either by clicking its course card, or visit **Admin > LMS > Courses** (`/admin/lms/courses`) and click the course **Title**.  
     
2.  Click the **All entities** tab  
     
3.  You’ll see a list of users and classes in this course.  Classes will have an **Entity type** of **Group** and a **Plugin used** of **LMS Class**.  
     
4.  If you want to make changes to the class from here, in its Operations dropdown select **View entity**, then select the tab you want to manage.  
     

#### List all classes

1.  Go to the Groups page: **Admin > Groups** (`/admin/group`)  
     
2.  If necessary, click the **Type** column heading to sort by type  
     
3.  In the list of classes, you can either select a management option in a class's **Operations** dropdown, or click on the class Title and then select a tab you want to manage.  
     

### Editing a Class Name

To change the name of a class:

1.  View all Groups: **Admin > Groups** (`/admin/group`)  
     
2.  In the **Operations** column next to the class name you want to change, click **Edit** 
3.  Edit the Title and click **Save** 

### Adding a Class to a Course

Each course can have multiple associated classes.  To add a class to a course:

1.  First, create the new class:  
     
    1.  Go to **Admin > Groups** (`/admin/group`), click the **Add group** button, and select **Class** 
    2.  Give it a descriptive Title — it's helpful to include the course name in the class title  
         
    3.  Click **Create class** 
2.  Now go to the course page: **Admin > LMS > Courses** (`/admin/lms/courses`) and click the **Title** of the course you want to add the class to  
     
3.  Click the **All entities** tab then press the **Add existing entity** button and select **LMS Class** 
4.  Start typing the name of your new class, and an autocomplete selection list will allow you to choose the class to add  
     
5.  Click **Save** 

### Managing Students in a Class

When using the LMS Classes module, students are members of a Class group.  You can view, edit, add, and remove members:

1.  View all Groups: **Admin > Groups > List** (`/admin/group`)  
     
2.  In the **Operations** column next to the class whose members you want to manage, click Members  
     
3.  In the **Operations** column next to the student you want to change, click View member, Edit member, or Remove member  
     
4.  There is also an **Add member** button near the top of the class Members page.  To add a student to the class, click this button, and start typing their username in the User field.  A list will appear with autocomplete suggestions that you can choose from, then click Save.  
     

Some of the student management features mentioned on this page depend on the [**Views Bulk Operations**](https://www.drupal.org/project/views_bulk_operations) module.  Although not a hard dependency of Drupal LMS, you will likely want to have it installed on your site to enable all features.

## Managing Student Access

### Viewing Enrolled Students

To see all students in a course:

1.  Go to the course page, either by clicking on its course card or by visiting **Admin > LMS > Courses** and then clicking on the course **Title**  
2.  Click the **Students** tab

This shows a table of all students, their classes, completion status, and last activity.  
 

### Filtering and Sorting

The students table can be filtered by:

-   Student name 
-   Class

Results can be sorted by clicking on sortable column headings: 

-   Name 
-   Status
-   Last activity  
     

### Removing Students or Resetting Progress

You can reset students' progress in a course, which returns them to the enrolled but just-started state, or you can completely remove students from a course or class. This bulk workflow requires the [**Views Bulk Operations**](https://www.drupal.org/project/views_bulk_operations) module, with a bulk operations field added to the Students view (`/admin/structure/views/view/lms_course_students`) — this field is not added by default, so you (or your site builder) will need to configure it first:

1.  Go to the course's **Students** tab  
      
2.  Find the student(s) and select the checkbox next to their name(s)  
      
3.  From the **Action** dropdown menu at the top of the list, select either **Remove students** or **Reset course progress**  
4.  Click **Apply to selected items** and confirm the removal  
      

Removing a student from a course, or resetting their progress, will permanently delete their progress data and scoring for that course.

## Tracking Student Progress

### Individual Progress

To view one student’s progress:

1.  Go to the course’s **Students** tab  
     
2.  Find the student in the list  
     
3.  In the **Results** column, click the **show** link. This will take you directly to their detailed report for that course.  
     

### Course Completion Status

Student progress in a course is tracked with these status labels:

-   **In progress**: Student has started but not completed  
     
-   **Needs grading**: Student has completed all activities, but some need manual grading  
     
-   **Passed**: Student completed with a score at or above the required threshold  
     
-   **Failed**: Student completed but did not achieve the required score  
     

### Activity Completion

Within each lesson, you can see:

-   Which activities the student has completed   
     
-   Which activities are not done yet  
     
-   Scores for completed activities  
     
-   Which activities need manual grading  
     

It's good practice to regularly review student completion rates and scores, both to identify and assist struggling students before they fall too far behind, and to identify areas for improvement of the course content.

## Grading Student Work

### Automatic Grading

Activities using plugins like multiple choice and true/false are graded automatically: 

-   Scores are calculated based on correct answers
-   Results are available right away
-   No teacher action is needed  
     

### Manual Grading

Activities using the free text plugin need teacher grading:

1.  Go to the course’s Students tab and find students with “Needs grading” status  
     
2.  Click on the student’s Status to see their detailed results  
     
3.  Expand each lesson to find unevaluated activities (marked “No” in the Evaluated column). Click the “No” link to open the grading form  
     
4.  Review the student’s work, assign a score, and optionally add feedback  
     
5.  Click “Evaluate”  
     
6.  Repeat for any other unevaluated activities  
     
7.  Also repeat for any other students with “Needs grading” status  
      

The course status will immediately update based on the grading.  Note that a course that contains any manual grading activity will not give the student their results or score until the grading is complete.

## Student Management Best Practices

-   **Class Organization**: Group students logically by cohort, section, or other categories  
     
-   **Regular Monitoring**: Check the Students tab often to find students who may be struggling  
     
-   **Timely Grading**: Grade manual activities quickly to provide timely feedback  
     
-   **Reach Out**: Contact students who show patterns of low scores or inactivity  
     
-   **Quality Feedback**: Give specific, helpful feedback in manual grading  
     
-   **Review Data**: Look at completion rates and scores to find ways to improve course materials

**[Previous page: Courses](courses.md)**

**[Next page: Reporting & Tracking](reporting-and-tracking.md)**
