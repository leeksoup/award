# Reporting and Tracking

## Available Reports

Drupal LMS has several built-in reports to track student progress and course effectiveness:

### Course Students Report

-   **Location**: Course page > Students tab
-   **Content**: List of all students with completion status, scores, and last activity
-   **Features**: Can be filtered by student name and class; can be sorted by various columns
-   **Uses**: Overall course monitoring, finding students who need help

### Individual Student Results

-   **Location**: Course page > Students tab > click the **show** link in the **Results** column for the desired student
-   **Content**: Detailed breakdown of student’s progress through lessons and activities
-   **Features**: Shows completion status, scores, and grading status for each activity
-   **Uses**: Detailed assessment of individual performance, finding specific areas of difficulty

## Custom Reporting

The system tracks a number of metrics that can be used to build custom report views.

### Course Completion Metrics

Using Views, you can build custom reports based on these key metrics that are tracked by the module:

-   **Completion Rate**: Percentage of enrolled students who have completed the course
-   **Pass Rate**: Percentage of completed courses with passing scores
-   **Average Score**: Average score across all completed courses
-   **Time to Completion**: Average time taken to complete the course

### Activity-Level Metrics

For individual activities, the system tracks:

-   **Completion Rate**: Percentage of students who have completed the activity
-   **Success Rate**: Percentage of students who answered correctly
-   **Average Score**: Average score across all attempts
-   **Evaluation Status**: For manual activities, percentage graded

Activity-level metrics can help you identify which content is most challenging for students.

### Creating Custom Reports

You can create custom reports using Views:

1.  Go to **Admin > Structure > Views** (`/admin/structure/views`)
2.  Click **Add view**
3.  Select “Course Status” or “Lesson Status” as the base entity
4.  Set up filters, fields, and relationships for your report
5.  Save and access via the provided URL or menu link

See [Views Integration](../views.md) for a full reference of the LMS-specific fields, filters, and relationships available when building a report, and for a catalog of the views already included with the module.

Some examples of custom reports you could consider building:

-   Student engagement by time period
-   Performance comparisons across classes
-   Lesson difficulty analysis
-   Activity success rate reports

It's a good idea to schedule regular reviews of analytics data, to guide course improvements and identify students who need additional support.

## Exporting Data

To export report data:

1.  View any report page
2.  Use Views’ export tools (CSV, Excel, etc.).  You may need to install modules like Views Data Export.
3.  Or use your browser’s print-to-PDF function

For programmatic access to reporting data, you can: 

-   Create custom Drupal modules that query the LMS entities 
-   Enable REST API endpoints for the relevant entities
-   Use Drupal’s database tools to create custom queries

## Analytics for Improvement

Consider these approaches for deeper analysis:

### Finding Course Strengths and Weaknesses

-   Compare performance across different activities
-   Look for patterns in low or high scores
-   Analyze time spent on specific lessons
-   Review manually graded responses for common themes

### Monitoring Student Engagement

-   Track how often students access the course
-   Analyze time between activities
-   Monitor completion patterns (steady progress vs. last-minute work)
-   Identify points where students stop progressing

### Improving Course Content

Use analytics to guide improvements:

-   Replace or revise activities with consistently low scores
-   Add more instruction before commonly misunderstood concepts
-   Adjust difficulty based on performance patterns
-   Update feedback based on common mistakes

## Reporting Best Practices

-   **Regular Review**: Schedule time to review reports weekly or biweekly
-   **Data-Driven Decisions**: Use report data to guide course revisions and student help
-   **Completion Tracking**: Monitor not just final completion but progress patterns
-   **Performance Comparison**: Look for differences across classes or cohorts
-   **Student Feedback**: Combine numbers with student comments
-   **Trends Over Time**: Track performance over time
-   **Action Plans**: Make specific improvements based on report findings

By using Drupal LMS’s reporting tools, you can track student progress, evaluate course effectiveness, and continuously improve your learning materials.

[**Previous page: Student & Class Management**](student-class-management.md)

[**Next page: Roadmap and Ongoing Development**](roadmap-and-ongoing-development.md)
