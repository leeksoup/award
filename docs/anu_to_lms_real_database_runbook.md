# Anu LMS → Drupal LMS real-database runbook

## Purpose and current scope

This runbook began with the validated checklist-first migration slice and now
also covers the media/resource lesson-content slice:

1. Anu `lesson_checklist` paragraphs become Drupal LMS `checklist` activities.
2. Anu `module_lesson` nodes containing those checklists become Drupal LMS
   lessons with ordered activity references.
3. Text, approved YouTube/Vimeo, and audio section blocks become `content`,
   `video`, and `audio` display activities. Resource document blocks are
   appended as links inside the immediately preceding checklist activity body.
   Heading blocks are used as names/titles for the immediately following
   supported activity; they are not standalone LMS activities. Divider and
   currently unsupported image blocks are ignored for heading adjacency.

This is a staging/verification procedure, not a production cutover runbook.
Images, short/long-answer questions, scale/Likert questions, and complete
learning paths are not runnable yet. Single/multiple choice assessments are
available as a staging slice. Treat all new activity types as requiring staging
validation before production use.

The source and destination are in the same active Drupal database. Always take
a restorable backup before running database updates or migrations.

## Preconditions

- Deploy the current repository revision.
- Confirm `web/modules/contrib/lms/lms.info.yml` reports LMS 1.1.18.
- Confirm `anu_to_lms_migrate`, `lms`, `migrate_plus`, `migrate_tools`, and
  `paragraphs` are enabled.
- Run this first on a recent staging copy of the real database.
- Ensure no content editors are changing lessons during the run.

Record the expected source totals before changing anything:

```bash
drush php:eval 'echo "lesson_checklist: ", \Drupal::entityQuery("paragraph")->accessCheck(FALSE)->condition("type", "lesson_checklist")->count()->execute(), PHP_EOL;'
drush php:eval 'echo "module_lesson: ", \Drupal::entityQuery("node")->accessCheck(FALSE)->condition("type", "module_lesson")->count()->execute(), PHP_EOL;'
```

The lesson migration intentionally includes only lessons that contain at least
one checklist, so its total can be lower than the total `module_lesson` count.

## 1. Back up the database

Create a timestamped dump outside the web root:

```bash
mkdir -p ../backups
drush sql:dump --gzip --result-file=../backups/pre-anu-to-lms-$(date +%Y%m%d-%H%M%S).sql
```

Verify that the dump exists and is nonempty before proceeding:

```bash
find ../backups -maxdepth 1 -name 'pre-anu-to-lms-*.sql.gz' -type f -size +0 -print
```

## 2. Enable maintenance mode

```bash
drush state:set system.maintenance_mode 1
drush cr
```

Keep the shell session open until maintenance mode has been disabled at the end
of the procedure.

## 3. Install code updates and rebuild caches

```bash
drush en anu_to_lms_migrate -y
drush updb -y
drush cr
```

Update `anu_to_lms_migrate_update_10003` converts target-facing names from the
earlier test bundle/field (`anu_checklist` and
`field_anu_checklist_body`) to reusable LMS names (`checklist` and
`field_checklist_body`). It preserves activity IDs so existing lesson
references and migration map entries remain valid.

Verify that there are no pending database updates:

```bash
drush updatedb:status
```

## 4. Verify target configuration

```bash
drush config:get lms.lms_activity_type.checklist
drush config:get field.storage.lms_activity.field_checklist_body
drush config:get field.field.lms_activity.checklist.field_checklist_body
```

The activity type must use `pluginId: no_answer`. The field must be a
single-value `text_long` field attached to the `checklist` activity bundle.

If this database contains activities imported under an earlier test revision,
verify the bundle rename:

```bash
drush php:eval '
echo "checklist: ", \Drupal::entityQuery("lms_activity")->accessCheck(FALSE)->condition("type", "checklist")->count()->execute(), PHP_EOL;
echo "anu_checklist: ", \Drupal::entityQuery("lms_activity")->accessCheck(FALSE)->condition("type", "anu_checklist")->count()->execute(), PHP_EOL;
'
```

`anu_checklist` must be zero after update `10003` completes.

## 5. Verify active migration definitions

The expected plugin pairs are:

- `anu_lesson_checklist` → `entity:lms_activity`
- `anu_checklist_lesson` → `entity:lms_lesson`

Inspect the active definitions:

```bash
drush php:eval '$m = \Drupal::service("plugin.manager.migration")->createInstance("anu_to_lms_paragraph_lesson_checklists"); var_export([$m->getSourceConfiguration()["plugin"] ?? NULL, $m->getDestinationConfiguration()["plugin"] ?? NULL]); echo PHP_EOL;'
drush php:eval '$m = \Drupal::service("plugin.manager.migration")->createInstance("anu_to_lms_node_module_lessons"); var_export([$m->getSourceConfiguration()["plugin"] ?? NULL, $m->getDestinationConfiguration()["plugin"] ?? NULL]); echo PHP_EOL;'
```

Stop if either definition reports an empty source/destination plugin or an
`embedded_data` source. Rebuild caches and resolve plugin discovery before
importing anything.

## 6. Check migration status

```bash
drush migrate:status anu_to_lms_paragraph_lesson_checklists
drush migrate:status anu_to_lms_paragraph_lesson_sections
drush migrate:status anu_to_lms_node_module_lessons
```

On a first run, both migrations should be `Idle`, with zero imported rows and a
nonzero total when corresponding source content exists.

## 7. Import or update checklist activities

For a database where the checklist migration has never run:

```bash
drush migrate:import anu_to_lms_paragraph_lesson_checklists -y
```

For a database containing activities from an earlier test run, update them in
place instead. This retains destination IDs:

```bash
drush migrate:import anu_to_lms_paragraph_lesson_checklists --update -y
```

Require zero failed and zero ignored rows before continuing.

## 8. Audit checklist bodies

The following audit must produce no output:

```bash
drush php:eval '
$storage = \Drupal::entityTypeManager()->getStorage("lms_activity");
$ids = \Drupal::entityQuery("lms_activity")->accessCheck(FALSE)->condition("type", "checklist")->execute();
foreach ($storage->loadMultiple($ids) as $activity) {
  $body = $activity->get("field_checklist_body")->value;
  if ($body === NULL || $body === "" || $body === "Array" || is_array($body)) {
    echo $activity->id(), ": ";
    var_export($body);
    echo PHP_EOL;
  }
}
'
```

Spot-check at least three activities at `/admin/lms/activity`. Confirm that:

- the checklist body contains the expected source text;
- list items retain their source order;
- resource documents following a checklist appear as links in that checklist
  body using the resource name as the link text followed by the resource
  description;
- the checklist body text format is `filtered_html`;
- there is no literal `Array` value;
- paragraph markup is not wrapped in invalid `<strong><p>…</p></strong>`
  markup;
- advancing the activity provides the intended v1 whole-activity completion
  behavior.

## 9. Import section activities and lessons

Import supported non-checklist section activities first:

```bash
drush migrate:import anu_to_lms_paragraph_lesson_sections -y
```

If this migration has already run on the staging database, update the existing
activities in place so preceding Anu headings become activity names without
changing existing destination IDs:

```bash
drush migrate:import anu_to_lms_paragraph_lesson_sections --update -y
```

The import stops on unsupported video providers or missing audio files. Resolve
every reported source paragraph before continuing.

Audit the created media activities before importing/updating lessons:

```bash
drush php:eval '
$storage = \Drupal::entityTypeManager()->getStorage("lms_activity");
$issues = [];
foreach (["video", "audio"] as $bundle) {
  $ids = \Drupal::entityQuery("lms_activity")->accessCheck(FALSE)->condition("type", $bundle)->execute();
  foreach ($storage->loadMultiple($ids) as $activity) {
    if ($bundle === "video" && $activity->get("field_video_url")->isEmpty()) {
      $issues[] = ["video", $activity->id(), "missing URL"];
    }
    if ($bundle === "audio" && ($activity->get("field_audio_name")->isEmpty() || $activity->get("field_audio_file")->isEmpty())) {
      $issues[] = ["audio", $activity->id(), "missing name or file"];
    }
  }
}
var_export($issues);
echo PHP_EOL;
'
```

The audit must return an empty array. At `/admin/lms/activity`, spot-check at
least three videos across the providers present in the source and three audio
activities. Confirm iframe playback, audio controls, keyboard operation, and
that no player autostarts.

On a first run:

```bash
drush migrate:import anu_to_lms_node_module_lessons -y
```

If the lesson migration has already run and its process mapping changed:

```bash
drush migrate:import anu_to_lms_node_module_lessons --update -y
```

Run the lesson update after deploying the heading/checklist-resource slice so
existing LMS lessons drop old standalone heading or resource references and
preserve Anu source order. Require zero failed and zero ignored rows.

## 10. Audit lesson activity references

The following audit must return an empty array:

```bash
drush php:eval '
$activity_storage = \Drupal::entityTypeManager()->getStorage("lms_activity");
$lesson_storage = \Drupal::entityTypeManager()->getStorage("lms_lesson");
$lesson_ids = \Drupal::entityQuery("lms_lesson")->accessCheck(FALSE)->execute();
$missing = [];
foreach ($lesson_storage->loadMultiple($lesson_ids) as $lesson) {
  foreach ($lesson->get("activities")->getValue() as $delta => $reference) {
    $target_id = $reference["target_id"] ?? NULL;
    if (!$target_id || !$activity_storage->load($target_id)) {
      $missing[] = [$lesson->id(), $delta, $target_id];
    }
  }
}
var_export($missing);
echo PHP_EOL;
'
```

Spot-check at least three lessons at `/admin/lms/lesson`. Confirm checklist
activities and text/media activities appear in the same page/block order as the
Anu lesson. Confirm resource documents appear inside the immediately preceding
checklist activity, and confirm Anu heading text appears as the following
activity's name/title rather than as its own activity, including when a divider
or currently unsupported image block appears between the heading and activity.

## 11. Record final status and logs

```bash
drush migrate:status anu_to_lms_paragraph_lesson_checklists
drush migrate:status anu_to_lms_paragraph_lesson_sections
drush migrate:status anu_to_lms_node_module_lessons
drush migrate:messages anu_to_lms_paragraph_lesson_checklists
drush migrate:messages anu_to_lms_paragraph_lesson_sections
drush migrate:messages anu_to_lms_node_module_lessons
drush watchdog:show --severity=Error --count=100
```

Save the commands, totals, migration messages, warnings, and representative UI
screenshots with the staging test record.

## 12. Disable maintenance mode

```bash
drush state:set system.maintenance_mode 0
drush cr
```

Confirm the site is reachable before ending the maintenance window.

## Rollback and recovery

### Migration rollback

Rollback dependent lessons before activities:

```bash
drush migrate:rollback anu_to_lms_node_module_lessons -y
drush migrate:rollback anu_to_lms_paragraph_lesson_sections -y
drush migrate:rollback anu_to_lms_paragraph_lesson_checklists -y
drush cr
```

Migration rollback does not reverse completed Drupal database update hooks or
restore removed legacy field configuration.

### Database restore

If `drush updb` fails, bundle/field counts do not reconcile, activity IDs
change unexpectedly, or the audits find corrupt content, stop and restore the
pre-run database dump. Database restoration is the authoritative rollback for
update-hook failures.

Do not continue to video/audio, assessment, or course migrations until the
checklist and lesson audits in this runbook pass cleanly.

## Assessment staging slice

After the lesson-content gates pass, run the supported question and assessment
migrations in dependency order:

First inventory the active source rows. These commands distinguish an empty
source database from a source-discovery problem:

```bash
drush php:eval 'echo "module_assessment: ", \Drupal::entityQuery("node")->accessCheck(FALSE)->condition("type", "module_assessment")->count()->execute(), PHP_EOL;'
drush php:eval 'echo "single choice wrappers: ", \Drupal::entityQuery("paragraph")->accessCheck(FALSE)->condition("type", "question_single_choice")->count()->execute(), PHP_EOL; echo "multiple choice wrappers: ", \Drupal::entityQuery("paragraph")->accessCheck(FALSE)->condition("type", "question_multi_choice")->count()->execute(), PHP_EOL;'
```

The question source follows the current `field_module_assessment_items`
references from assessment nodes rather than relying on paragraph parent
metadata. Consequently, orphaned question paragraphs are intentionally not
migrated.

```bash
drush en lms_answer_plugins -y
drush updb -y
drush cr
drush migrate:import anu_to_lms_paragraph_lesson_sections -y
drush migrate:status anu_to_lms_paragraph_assessment_questions
drush migrate:status anu_to_lms_node_module_assessments
drush migrate:import anu_to_lms_paragraph_assessment_questions -y
drush migrate:import anu_to_lms_node_module_assessments -y
```

The slice supports only `question_single_choice` and
`question_multi_choice`. It preserves option order and correctness flags.
Assessment text and heading blocks are resolved through the section-activity
migration. Do not treat an assessment containing deferred question bundles as
complete; inventory those bundles before UAT.

Spot-check both activity bundles at `/admin/lms/activity` and assessment lessons
at `/admin/lms/lesson`. Confirm radio buttons for single choice, checkboxes for
multiple choice, correct option order, scoring behavior, and assessment item
order. Roll back assessment lessons before question activities:

```bash
drush migrate:rollback anu_to_lms_node_module_assessments -y
drush migrate:rollback anu_to_lms_paragraph_assessment_questions -y
```

## Course staging slice

The course migration intentionally discards Anu module titles and boundaries.
It traverses modules in field order, flattens their lesson references in field
order, and writes that sequence to the native LMS course `lessons` field. Run
it only after the lesson migration has completed successfully.

```bash
drush updb -y
drush cr
drush migrate:status anu_to_lms_node_module_lessons
drush migrate:status anu_to_lms_node_courses
drush migrate:import anu_to_lms_node_courses -y
```

The expected source total is three courses. Audit destination counts, ordered
lesson IDs, broken references, and navigation settings:

```bash
drush php:eval '
$lookup = \Drupal::service("migrate.lookup");
$course_storage = \Drupal::entityTypeManager()->getStorage("group");
$lesson_storage = \Drupal::entityTypeManager()->getStorage("lms_lesson");
$source_ids = \Drupal::entityQuery("node")
  ->accessCheck(FALSE)
  ->condition("type", "course")
  ->sort("nid")
  ->execute();

foreach ($source_ids as $source_id) {
  $destinations = $lookup->lookup("anu_to_lms_node_courses", [$source_id]);
  $destination_id = $destinations[0]["id"] ?? NULL;
  if (!$destination_id || !($course = $course_storage->load($destination_id))) {
    printf("ERROR source course %d has no destination course\n", $source_id);
    continue;
  }

  $lesson_ids = [];
  $broken = [];
  foreach ($course->get("lessons") as $item) {
    $lesson_ids[] = (int) $item->target_id;
    if (!$lesson_storage->load($item->target_id)) {
      $broken[] = (int) $item->target_id;
    }
  }

  printf(
    "source:%d destination:%d lessons:[%s] free_navigation:%d broken:[%s]\n",
    $source_id,
    $course->id(),
    implode(",", $lesson_ids),
    (int) $course->get("free_navigation")->value,
    implode(",", $broken),
  );
}
'
```

Confirm that the three destination courses contain 11, 15, and 13 lessons in
the same order as the source audit. Spot-check guided/free navigation in the
course UI at `/admin/lms/courses`.

If that URL is missing, verify and install its default View through the module
update before treating it as a migration failure:

```bash
drush config:get views.view.courses_admin status
drush updb -y
drush cr
drush config:get views.view.courses_admin status
```

The final command must report `true`. The listing requires the current user to
have the `create lms_course group` permission. A missing or inaccessible View
does not mean that the migrated group entities are absent; use the entity and
migration-map audit above as the authoritative data check.

If the page loads but its table is empty, deploy the current migration module
and run its View access update:

```bash
drush updb -y
drush cr
drush config:get views.view.courses_admin display.default.display_options.query.options.disable_sql_rewrite
```

The final value must be `false`. Update `10009` adds the missing owner
memberships and restores Group access query rewriting so the listing follows
normal Group row-level access. Do not reintroduce the temporary
`disable_sql_rewrite: true` admin listing bypass from update `10008`.

Course owners also require an authorized insider Group role. Update `10010`
installs the synchronized `lms_teacher` user role and `lms_course-teacher`
Group role, then assigns the user role to every migrated course owner:

```bash
drush updb -y
drush cr
drush config:get user.role.lms_teacher status
drush config:get group.role.lms_course-teacher status
drush config:get group.role.lms_course-teacher global_role
drush config:get group.role.lms_course-teacher permissions
drush php:eval '$ids = \Drupal::entityQuery("group")->accessCheck(FALSE)->condition("type", "lms_course")->execute(); foreach (\Drupal::entityTypeManager()->getStorage("group")->loadMultiple($ids) as $course) { $owner = $course->getOwner(); $member = $owner ? $course->getMember($owner) : NULL; $roles = $member?->getRoles() ?? []; printf("course:%d owner:%s member:%s user_teacher:%s group_roles:[%s] view:%s take:%s update:%s\n", $course->id(), $owner?->id() ?? "none", $member ? "yes" : "no", $owner?->hasRole("lms_teacher") ? "yes" : "no", implode(",", array_keys($roles)), $owner && $course->access("view", $owner) ? "yes" : "no", $owner && $course->access("take", $owner) ? "yes" : "no", $owner && $course->access("update", $owner) ? "yes" : "no"); }'
```

Every owner must report `user_teacher:yes`, include
`lms_course-teacher` in `group_roles`, and report
`view:yes take:yes update:yes`.

To grant a specific Drupal user teacher access to all migrated courses, run:

```bash
drush updb -y
drush cr
drush anu-to-lms:make-teacher USER_ID
```

The command grants the global `lms_teacher` user role, adds the user as a
member of every destination course in `migrate_map_anu_to_lms_node_courses`,
and prints per-course `view`/`take`/`update` access results.

Roll back courses before rolling back lessons:

```bash
drush migrate:rollback anu_to_lms_node_courses -y
drush cr
```
