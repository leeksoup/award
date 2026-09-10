# LMS Course Discussions

Provides learner-facing course discussion navigation for the existing
`course_discussions` View installed by `lms_discussion_prompt`.

## Behavior

- Adds a **Discussions** local-task tab to the LMS course start route and every
  course activity route. The tab is injected only while a route has the
  current course, lesson, and activity context required by LMS.
- Both tabs link to the existing course-specific View at
  `/group/{group}/course-discussions`.
- Adds a **Start a discussion** local action on that View. It opens Group's
  final, group-scoped `discussion` creation form directly, so the new node is
  attached to the current LMS Course group. It preserves a return destination
  to the discussion board after the post is saved.
- Grants non-anonymous LMS Course roles with the `take course` permission the
  two Group permissions required to create a `discussion` relationship and
  entity. It does not grant edit or delete permissions.

## Installation

```bash
drush en lms_course_discussions -y
drush cr
```

The module has no configuration to import. Its local-task and local-action
plugins are discovered when caches are rebuilt.

## Permission note

The action is shown only when Group grants the current member create access.
If a custom LMS Course role should be able to start discussions but does not
have `take course`, grant the following permissions to that Group role through
the Group role permissions UI:

- `create group_node:discussion relationship`
- `create group_node:discussion entity`
