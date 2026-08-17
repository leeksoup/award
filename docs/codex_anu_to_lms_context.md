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
- The source database contains no standalone assessment nodes to migrate, but
  lesson-embedded question paragraphs may produce question activity rows.
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
anu_to_lms_paragraph_assessment_questions
anu_to_lms_node_module_lessons
anu_to_lms_node_module_assessments          (currently zero source rows)
anu_to_lms_node_courses
```

The lesson-section activity migration currently supports text, approved
YouTube/Vimeo URLs, audio files, and checklist references. Anu heading blocks
are not standalone LMS activities; the nearest immediately preceding heading is
used as the migrated activity name/title for the following supported activity.
Divider and currently unsupported image blocks are ignored for this heading
association, so a heading can still name the next text/checklist activity when
one of those blocks appears between them.
Anu resource documents are appended inside the immediately preceding checklist
activity body using `RESOURCE_NAME: RESOURCE_DESCRIPTION`, with the resource
name linked to the document file. Unsupported providers and unresolved
required audio or resource files must fail with source context.
Question activity migration supports single/multiple-choice wrappers as LMS
`select` activities and short/long-answer wrappers as manually evaluated LMS
`free_text` activities. Scale and Likert questions remain deferred.

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
`10010`. Later staging output showed update `10010` installed the synchronized
roles and granted every migrated owner `view`, `take`, and `update` access.

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
- owner membership repair itself (`owner_member:yes`);
- synchronized teacher role configuration after update `10010`
  (`global_role: lms_teacher`);
- owner authorization after update `10010` for destination courses 10, 11,
  and 12: `member:yes`, `user_teacher:yes`,
  `group_roles:[lms_course-teacher]`, `view:yes`, `take:yes`, and
  `update:yes`.

Validated from user-provided browser feedback:

- courses appear through normal Group-filtered `/admin/group` and
  `/admin/lms/courses` listings;
- owner route access and course navigation appear to work;
- an unrelated account receives a "Page not found" response for migrated
  course access, which is acceptable if owner access to the same route works.

New staging blockers from browser UAT:

- lesson activities were not appearing in the correct Anu order;
- Anu heading blocks were incorrectly migrated as standalone activities instead
  of naming the following activity;
- student resource/worksheet document blocks must be inserted into the
  immediately preceding checklist activity rather than shown as activities.

Still pending explicit staging acceptance:

- updated lesson activity order after re-running section and lesson imports;
- heading-to-following-activity title behavior;
- resource document links inside checklist activity bodies;
- achievements/completion integration and Commerce enrollment.

## Immediate next action

Next deploy the checklist-resource slice, run database updates, update the
checklist and lesson migrations in place, and re-check browser lesson playback.
Only after lesson order, headings, and checklist resource links pass should
implementation continue to the next content-parity or achievements slice.

## Known documentation debt

Some older milestone/next-action prose in
`docs/anu_to_lms_migration_plan.md` predates the now-runnable media,
assessment, and course slices. Treat this handoff and the real-database runbook
as the current operational status, and correct the plan when the access gate is
validated.
