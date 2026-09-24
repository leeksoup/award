# LMS Course Discussions

Provides learner-facing course discussion navigation for the existing
`course_discussions` View installed by `lms_discussion_prompt`.

## Behavior

- Adds a **Discussions** local-task tab to the LMS course start route and every
  course activity route. The tab is injected only while a route has the
  current course, lesson, and activity context required by LMS.
- Both tabs link to the existing course-specific Discussions View.
- Adds a **Start a discussion** local action on that View. It opens Group's
  final, group-scoped `discussion` creation form directly, so the new node is
  attached to the current LMS Course group. It preserves a return destination
  to the discussion board after the post is saved.
- Grants non-anonymous LMS Course roles with the `take course` permission the
  two Group permissions required to create a `discussion` relationship and
  entity. It does not grant edit or delete permissions.
- Maps the Class-level view and create permissions to the parent Course. If an
  LMS Class role already has discussion entity access, installation/update
  also grants its matching relationship permission. This lets Class-enrolled
  learners access the Discussions View without creating direct Course
  memberships or broadening access to roles that lack discussion entity
  permission.

## Installation

```bash
drush en lms_course_discussions -y
drush cr
```

The module has no installation configuration to import. Its local-task and
local-action plugins are discovered when caches are rebuilt. Existing sites
run update `10001` to merge the required entries into
`lms_classes.settings:course_permission_mappings` and update eligible LMS Class
roles.

## Permission note

The action is shown only when Group grants the current member create access.
If a custom LMS Course role should be able to start discussions but does not
have `take course`, grant the following permissions to that Group role through
the Group role permissions UI:

- `create group_node:discussion relationship`
- `create group_node:discussion entity`
