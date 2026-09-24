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
anu_to_lms_media_remote_videos
anu_to_lms_paragraph_lesson_sections
anu_to_lms_paragraph_assessment_questions
anu_to_lms_node_module_lessons
anu_to_lms_node_module_assessments          (currently zero source rows)
anu_to_lms_node_courses
```

The lesson-section activity migration currently supports text, approved
YouTube/Vimeo Remote Video media references, audio files, and checklist
references. Anu heading blocks are not standalone LMS activities; the nearest
immediately preceding heading is used as the migrated activity name/title for
the following supported activity.
Divider and currently unsupported image blocks are ignored for this heading
association, so a heading can still name the next text/checklist activity when
one of those blocks appears between them.
Text/content activity bodies are stored as `filtered_html` so existing Anu
paragraph tags render as paragraph breaks; Anu `minimal_html` strips `<p>` tags
and can make separate paragraphs run together.
Anu resource documents are appended inside the immediately preceding checklist
activity body using `RESOURCE_NAME: RESOURCE_DESCRIPTION`, with the resource
name linked to the document file. Unsupported providers and unresolved
required audio or resource files must fail with source context.
Checklist fallback names are shortened from the first four words of the first
checklist item when there is no preceding heading, except the first checklist
in a lesson is named `Ready Check`. The first checklist also receives
`Silence distractions` and `Have a pen ready` at the end of its body bullets.
Text/content fallback names are shortened from the first four source words
when there is no preceding heading. Video fallback names are `Video` when a
lesson has one video, or numbered per source lesson when a lesson has multiple
videos.
Update `10015` deletes unreferenced migrated activities from older
lesson-section test runs only when their source paragraph bundle is no longer
supported by the current migration.
The lesson-section activity source traverses current `module_lesson` and
`module_assessment` paragraph references. It intentionally ignores orphaned
paragraphs and paragraphs retained only by deleted parent content, so stale
parent metadata cannot block migration discovery with an unsupported media
error.
Question activity migration supports single/multiple-choice wrappers as LMS
`select` activities and short/long-answer wrappers as manually evaluated LMS
`free_text` activities. Scale and Likert questions remain deferred.
Adjacent short/long-answer question blocks in a lesson or assessment are
grouped into one LMS `free_text` activity keyed by the first source paragraph
ID, with each Anu prompt stored as an item in the LMS `questions` field. When
more than one prompt is grouped, the LMS activity name is `Questions`.
Question activity titles are shortened from the source prompt; the complete
Anu question prompt belongs in the LMS question/questions field, not in the
activity title.
If Drupal UI lesson edit forms warn about an undefined `free_text` array key in
the LMS reference-table widget, run update `10014`; it covers staged databases
that may have already executed an earlier cache-only `10013` before the
`free_text` bundle config was added.
If student/course playback reaches a `free_text` activity but renders only
Back/Submit buttons, re-run `anu_to_lms_paragraph_assessment_questions` with
`--update`, update `anu_to_lms_node_module_lessons`, and reset the test course
status. The missing answer widget means the active activity revision has an
empty LMS `questions` field.

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
- `drush anu-to-lms:make-teacher USER_ID` grants an arbitrary user the global
  `lms_teacher` role and adds them as a member of every migrated course from
  `migrate_map_anu_to_lms_node_courses`.

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

## Deleted course progress recovery

Deleting and recreating LMS course Groups during staging recovery leaves any
existing `lms_course_status` records pointing to the deleted Group IDs. LMS
1.1.18 throws while checking that stale progress during lesson-form builds,
which can make `/admin/lesson/ID` fail with `The course doesn't exist anymore.`
Use `drush anu-to-lms:repair-orphaned-progress` to report the stale records,
then repeat it with `--delete` after review. The destructive form uses the LMS
entity deletion hooks and drains their cleanup queue, removing dependent
lesson statuses and answers without touching progress for existing courses.

## Immediate next action

Next deploy the checklist-resource slice, run database updates, update the
checklist and lesson migrations in place, and re-check browser lesson playback.
Only after lesson order, headings, and checklist resource links pass should
implementation continue to the next content-parity or achievements slice.

## Commerce LMS entitlement boundary

Commerce enrollment is a separate runtime slice. The custom
`commerce_lms_entitlements` module uses contributed Commerce PayPal checkout
and subscriptions modules, then maps verified purchases to administrator-
selected LMS Class memberships. It does not alter migration ordering or migrate
historical attempts or progress.

## Forum Prompt activity feature

The `lms_forum_prompt` module adds a reusable LMS `forum_prompt` activity type
for lesson prompts that send learners to a Drupal Forum topic. It is separate
from `anu_to_lms_migrate` because it is LMS runtime/authoring behavior, not
Anu-specific migration logic.

Forum Prompt activities use the LMS `no_answer` plugin with a default max
score of 10. Each `lms_course` has a `field_default_forum` term reference to
the Forum vocabulary. When a Forum Prompt activity can be resolved to a course,
the module creates one `node.forum` topic from the activity title and prompt
body, owned by the activity author, and stores it on
`field_forum_topic`. Later edits do not overwrite the linked topic.

Student activity pages show a `Go to discussion` link. The link records an LMS
answer with full score, advances the current lesson/course status using the
same flow as LMS no-answer submission, and redirects to the forum topic with a
safe `/course/{group}/start` return URL. The optional `Forum Prompt return
link` block displays that return link on forum pages when the `return` query
parameter starts with `/course/`.

## Discussion Prompt activity feature

The `lms_discussion_prompt` module adds a reusable LMS `discussion_prompt`
activity type for prompts backed by group-attached `discussion` nodes with
comments enabled. It reuses the Forum Prompt runtime pattern but avoids Drupal
Forum taxonomy entirely: each discussion node is attached to the containing
`lms_course` group through the Group 3 `group_node:discussion` relationship.

Discussion Prompt activities use the LMS `no_answer` plugin. Author-facing
fields store the prompt body and the desired discussion title; the internal
node reference is hidden from the activity form. When the activity is saved or
opened in course context, the module reuses a same-titled discussion already
attached to that course, or creates one and links it back to the activity.

Student activity pages show a `Go to discussion` link. The link records an LMS
answer with full score, advances lesson/course status, and redirects to the
discussion node with a safe return URL for the exact next LMS activity. If the
prompt finishes the course, the return URL points to the course page instead.
Discussion node pages show the return link after the prompt body and again near
the bottom of the page.

The module also owns a `course_discussions` View for
`/group/{group}/course-discussions`. Update `10002` repairs existing active
View config by replacing the mistaken LMS Classes contextual filter with the
Group relationship `gid` contextual filter, requiring `gc__node`, and filtering
to `lms_course-group_node-discussion` relationships for published
`discussion` nodes. Update `10003` broadens discussion view grants to every
non-anonymous LMS Course role that can already view or take the course, changes
the tab access check to `view group_node:discussion relationship`, and grants
course-editing roles `access group_node overview` for Group's generic
`/group/{group}/nodes` page. Update `10004` repairs activities whose
`field_discussion_node` points at a discussion node that was never attached to
the containing course as a `group_node:discussion` relationship. Rows require
that relationship plus the generated Group permissions
`view group_node:discussion relationship` and
`view group_node:discussion entity`; the UI label for the latter is
`Entity: View any node entities` or similar depending on Group's provider, not
necessarily `Entity: View any content item entities`.

`lms_course_discussions` update `10001` closes the corresponding LMS Classes
permission gap. It aligns relationship access for Class roles that already
have discussion entity access and maps the Class view/create permissions onto
the parent Course. Without this mapping, a Class-enrolled learner can view an
individual discussion node while the Course Discussions View returns access
denied and its local-task tab remains hidden.

## LMS Classes student management

The LMS `Students` tab is not part of the base Group members page. It is
provided by the optional `lms_classes` module as the
`lms_course_students` View at `/group/{group}/students`, with the Add student
action at `/group/{group}/students/add`.

Update `10018` in `anu_to_lms_migrate` repairs existing migrated courses after
`lms_classes` is enabled: it installs missing LMS Classes default config,
grants course teacher roles `view students` and `add students`, and creates a
default `lms_class` child group for migrated courses that do not already have
one. This is needed because the migrated courses bypassed the normal LMS course
creation workflow that can create a default class for new courses. The
rerunnable `drush anu-to-lms:repair-students` command can also target a
non-migrated LMS Course with `--course-id=ID`, or all LMS Courses with
`--all-courses`, when test/manual Course groups have no target classes.

## Group 3 upgrade audit

Some staging databases may have needed a manual Group 2 to Group 3 repair
before Group's own update hooks ran cleanly. The read-only
`drush group3-schema-repair:audit [USER_ID]` command reports likely leftovers:
stale `group_content` config/View references, malformed `group.role.*` config,
missing `group_relationship.group_roles` field storage or membership field
instances, orphan rows in `group_relationship__group_roles`, and migrated LMS
course owner/user membership access. Use it to identify exact drift before
adding any repair command or update hook. If stale View references are the only
reported issue, `drush group3-schema-repair:repair-views` rewrites Views config
from Group 2 `group_content` references to Group 3 `group_relationship`
references using the same replacement pattern as Group's update hook.

On staging, Group update `10305` also aborted with
`Attempt to create a field without a field_name`. Diagnostics showed
`group_update_10300_detected_legacy_version = 1`, no old
`group_update_10300_detected_version` value, and Drupal's last-installed
schema repository still had `group_content fields=14` with
`group_relationship fields=0`. That means `10305` was running as if the Group
2 to 3 legacy path had completed, but the installed field-storage definitions
had not been copied to `group_relationship`. The standalone
`group3_schema_repair` module has no Anu or LMS dependency and provides
`drush group3-schema-repair:repair-repository`, which copies only those
missing installed definitions and refuses to overwrite existing
`group_relationship` definitions. Run it only in that exact
old-present/new-empty state, then run `drush updb -y`, `drush cr`, and
`drush group3-schema-repair:repair-group-roles-storage` if enabling LMS fails
because `field.storage.group_relationship.group_roles` is missing. Once
`anu_to_lms_migrate` is available, `drush group3-schema-repair:audit USER_ID` may
still report active-config/data drift. Use
`drush group3-schema-repair:repair-group-roles-instances` for missing
membership `group_roles` field instances and
`drush group3-schema-repair:repair-group-roles-table` for role-reference rows
attached to non-membership relationships.
`drush group3-schema-repair:repair-stale-config` handles the known remaining
Group 2 config names from Group update `10300`: `group.content_type.*`,
`field.storage.group_content.*`, `field.field.group_content.*`, and
`core.entity_*_display.group_content.*`.
It also repairs `dependencies.config` entries in other active configuration,
such as Pathauto patterns that still depend on a renamed
`group.content_type.*` object. The audit reports those dependencies before a
config import is attempted.

## Known documentation debt

Some older milestone/next-action prose in
`docs/anu_to_lms_migration_plan.md` predates the now-runnable media,
assessment, and course slices. Treat this handoff and the real-database runbook
as the current operational status, and correct the plan when the access gate is
validated.

## Anu LMS decommission

`anu_lms_decommission` is a standalone maintenance module for post-acceptance
removal of Anu LMS. Its commands inventory source nodes, paragraphs, terms,
ECK checklist results, Anu-owned config, external config dependencies,
source-referenced files, and target migration-map health before any destructive
action. `purge-content`, `remove-config`, and `uninstall` each require a
different literal confirmation token. The source purge retains every managed
file; file deletion must be a separately audited decision because target LMS
audio can still refer to the original file entity.

`lms_runtime` owns the live LMS activity bundles, fields, displays, roles, and
future LMS-course owner setup. Video activities reference Drupal core Remote
Video media entities; no custom video formatter is required. Its configuration
is optional so it can be enabled alongside the active migrated configuration
without recreating it. It is the runtime replacement for
`anu_to_lms_migrate`; the migration module is now only needed to operate or
inspect its migrations. Do not uninstall the migration module until
`lms_runtime` has been enabled and browser UAT has confirmed video playback,
activity editing, and course-owner access.

`lms_runtime` also contains a narrow compatibility override for LMS 1.1.18's
backwards-navigation calculation. Without it, opening a first activity in a
later lesson can emit an undefined `activity` delta warning when the prior
lesson status is initialized on demand.

The early `anu_checklist` target bundle is retired. Update `10003` converted
existing activities to the reusable `checklist` bundle while preserving their
IDs and lesson references, then removed the old bundle, field, and display
configuration. The obsolete defaults are no longer shipped, so a fresh
installation cannot recreate that bundle.

The decommission helper preserves the generic Document media type, its file
field, and its form/view displays. Although Anu LMS ships default definitions
for those objects, an existing site may own them and migrated resource links or
other content can still depend on them.

Malformed legacy Anu Assessment field-storage config is removed only after the
helper derives its identity from the config name and verifies that no active
field instance still uses it.

The decommission inventory and purge also include Anu Assessment Question and
Question result ECK entities. They are source-only historical assessment data
and remain out of migration scope.

For recovery after a failed uninstall, the helper treats an optional Anu ECK
entity type with no remaining base table as empty and removes only its stale
field config records rather than querying or recreating that table. Before
uninstall, it also clears orphaned deleted-field metadata for such missing
tables through Drupal's Field API repository service.

If a failed uninstall removes a static Anu content-entity table first, the
guarded command recreates its empty schema through Drupal's entity-definition
update API immediately before Core validates and completes the uninstall. This
applies to every missing static content-entity table provided by an anu_lms
module.
