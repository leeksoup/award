# Anu LMS → Drupal LMS real-database runbook

## Purpose and current scope

This runbook began with the validated checklist-first migration slice and now
also covers the media-first lesson-content slice:

1. Anu `lesson_checklist` paragraphs become Drupal LMS `checklist` activities.
2. Anu `module_lesson` nodes containing those checklists become Drupal LMS
   lessons with ordered activity references.
3. Text, heading, approved YouTube/Vimeo, and audio section blocks become
   `content`, `video`, and `audio` display activities.

This is a staging/verification procedure, not a production cutover runbook.
Images, resources, assessments, course groups, and complete learning paths are
not runnable yet. Treat video/audio as requiring staging playback validation,
not as production-validated until the media audits pass.

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

Require zero failed and zero ignored rows.

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
activities appear in the same page/block order as the Anu lesson.

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
