# Codex context: Anu LMS to Drupal LMS migration

## Purpose

This is the compact handoff for continuing the migration in Codex CLI. The
real-database procedure remains authoritative for execution and rollback:
`docs/anu_to_lms_real_database_runbook.md`.

## Target and source

- Target: vendored Drupal LMS 1.1.18 (`web/modules/contrib/lms`).
- Source: Anu LMS entities in the active Drupal database.
- Implementation: `web/modules/custom/anu_to_lms_migrate`.
- No historical learner progress or attempts are in scope.

## Confirmed real-data inventory

- 86 `lesson_checklist` source paragraphs were migrated and their formatted
  target bodies were audited successfully after fixing the literal `Array`
  value problem.
- 39 `module_lesson` nodes were migrated to LMS lessons.
- The source database contains no assessments to migrate. Assessment support
  exists for later content but zero rows is currently expected.
- There are 3 courses containing 39 distinct ordered lesson references:
  - course 870: 11 lessons
  - course 896: 15 lessons
  - course 905: 13 lessons
- Anu course modules are intentionally not preserved. Their lesson references
  are flattened in module-delta and lesson-delta order.

Do not put redacted course or lesson titles into committed documentation.

## Runnable migration dependency order

```text
anu_to_lms_paragraph_lesson_checklists
anu_to_lms_paragraph_lesson_sections
anu_to_lms_node_module_lessons
anu_to_lms_paragraph_assessment_questions   (currently zero source rows)
anu_to_lms_node_module_assessments          (currently zero source rows)
anu_to_lms_node_courses
```

The lesson-section activity migration currently supports text, headings,
approved YouTube/Vimeo URLs, audio files, and checklist references. Unsupported
providers and unresolved required audio files must fail with source context.

## Course migration decisions

- Destination entity: Group bundle `lms_course`.
- Destination order: native unlimited `lessons` LMS reference field.
- Anu `field_course_linear_progress` is inverted into LMS
  `free_navigation`.
- Lesson references resolve through `anu_to_lms_node_module_lessons` with no
  stubs.
- Source publication, owner, and timestamps are preserved.
- Drupal LMS course routes continue to use Group `take`, `update`, and
  `results` entity access.

## Course access issue and current status

The generic `entity:group` migrate destination created course Group entities,
but it bypassed the normal Group creation form behavior that creates a creator
membership. This caused the Group and LMS administrative Views to hide the
courses and caused `/group/ID` and `/course/ID/start` to deny access.

The following repairs are committed:

- update `10007`: installs the missing LMS `courses_admin` View;
- update `10009`: adds missing owner memberships and restores Group query
  access rewriting;
- `hook_group_insert()`: adds owner memberships on future course inserts;
- update `10010`: installs `lms_teacher` and synchronized
  `lms_course-teacher`, assigns `lms_teacher` to course owners, and invalidates
  access caches;
- future course inserts also assign the owner the `lms_teacher` user role.

The user confirmed owner memberships existed but had no roles before update
`10010`. **Update `10010` and its browser/access acceptance criteria have not
yet been reported as passing.** This is the current validation gate.

Expected post-update conditions for every migrated course owner:

```text
owner membership exists
user has lms_teacher
membership roles include lms_course-teacher
course->access('view', owner) is allowed
course->access('take', owner) is allowed
course->access('update', owner) is allowed
```

Group query rewriting on `views.view.courses_admin` must be `false` for
`disable_sql_rewrite` (that is, rewriting remains enabled). Do not reintroduce
the temporary admin-View bypass from update `10008`.

## Validated behavior versus pending behavior

Validated from user-provided staging output:

- checklist and lesson plugin discovery;
- checklist activity import and formatted bodies;
- lesson import and ordered activity references;
- media slice after correcting one unsupported source video;
- course source inventory and flattened lesson counts/order;
- course migration programmatic audit before UI access was tested;
- missing LMS courses admin View repair;
- owner membership repair itself (`owner_member:yes`).

Still pending explicit staging acceptance:

- synchronized teacher role assignment and access after update `10010`;
- courses appearing through normal Group-filtered `/admin/group` and
  `/admin/lms/courses` listings;
- owner access to `/group/COURSE_ID` and `/course/COURSE_ID/start`;
- denial for an unrelated account without authorized Group access;
- full learner playback/navigation UAT for all three courses;
- remaining unsupported lesson paragraph bundles;
- achievements/completion integration and Commerce enrollment.

## Immediate next action

First validate update `10010` using the commands in the runbook. If role
configuration exists but the synchronized role is absent from a membership,
inspect Group 2.3 role synchronization and permission calculation in the
installed code before adding another update hook. Do not bypass Group access.

Only after course access passes should implementation continue to the next
content-parity or achievements slice.

## Known documentation debt

Some older milestone/next-action prose in
`docs/anu_to_lms_migration_plan.md` predates the now-runnable media,
assessment, and course slices. Treat this handoff and the real-database runbook
as the current operational status, and correct the plan when the access gate is
validated.

