# Anu to LMS migration module

The backed-up staging/real-database procedure is documented in
[`docs/anu_to_lms_real_database_runbook.md`](../../../../docs/anu_to_lms_real_database_runbook.md).

This slice targets the vendored Drupal LMS **1.1.18** API. In particular, it
uses the `lms_activity` and `lms_lesson` entity destinations, the
`no_answer` activity plugin, and the lesson `activities` LMS reference field.

Migration definitions in `migrations/` are discovered only while this module
is enabled. After installing or updating the module, rebuild Drupal's caches
before querying migration status.

```bash
drush pm:list --type=module --field=name --status=enabled | grep '^anu_to_lms_migrate$'
drush en anu_to_lms_migrate -y
drush updb -y
drush cr
drush migrate:status | grep 'anu_to_lms'
```

## First runnable vertical slice

The first executable slice migrates every current `lesson_checklist` paragraph
to an LMS `checklist` activity backed by the LMS 1.1.18 `no_answer`
plugin. It then migrates every `module_lesson` containing at least one of those
checklists to an `lms_lesson`, preserving checklist order across lesson pages.
Other lesson content is intentionally not included yet.

Target LMS configuration uses reusable names (`checklist` and
`field_checklist_body`) so authors can create new LMS-native checklist
activities after migration. Migration IDs, module names, and source plugins
retain the `anu_to_lms`/`anu_` prefix because they describe migration
provenance and are required to preserve existing migration map tables.

The migration reads the Anu nodes and paragraphs from the active Drupal site;
it does not require a second database connection. Install the module so its
activity type and field configuration are created, then run the slice:

```bash
drush en anu_to_lms_migrate -y
drush updb -y
drush cr
drush migrate:import anu_to_lms_paragraph_lesson_checklists -y
drush migrate:import anu_to_lms_node_module_lessons -y
```

If checklist activities were imported by a version earlier than the formatted
field-item fix, update them in place after deploying current code. This
replaces body values stored as the literal string `Array` without changing the
activity IDs referenced by migrated lessons:

```bash
drush cr
drush migrate:import anu_to_lms_paragraph_lesson_checklists --update -y
```

### Stale placeholder definitions

An import failure saying that destination plugin `""` does not exist means
Drupal is still using the old scaffold definition, whose YAML destination was
`null`. A matching `migrate:status` total of zero is another indication that
the cached `embedded_data` source is still active. Update `10002` clears those
cached plugin definitions. After deploying the current code, always run:

```bash
drush updb -y
drush cr
```

The active definitions can be checked directly. The expected result is
`anu_lesson_checklist` / `entity:lms_activity` for the checklist migration and
`anu_checklist_lesson` / `entity:lms_lesson` for the lesson migration.

```bash
drush php:eval '$m = \Drupal::service("plugin.manager.migration")->createInstance("anu_to_lms_paragraph_lesson_checklists"); var_export([$m->getSourceConfiguration()["plugin"] ?? NULL, $m->getDestinationConfiguration()["plugin"] ?? NULL]);'
drush php:eval '$m = \Drupal::service("plugin.manager.migration")->createInstance("anu_to_lms_node_module_lessons"); var_export([$m->getSourceConfiguration()["plugin"] ?? NULL, $m->getDestinationConfiguration()["plugin"] ?? NULL]);'
```

If the definitions are current but the total remains zero, verify that the
active database actually contains current revisions of the source bundles:

```bash
drush php:eval 'echo \Drupal::entityQuery("paragraph")->accessCheck(FALSE)->condition("type", "lesson_checklist")->count()->execute(), PHP_EOL;'
drush php:eval 'echo \Drupal::entityQuery("node")->accessCheck(FALSE)->condition("type", "module_lesson")->count()->execute(), PHP_EOL;'
```

Rollback is the reverse of import order:

```bash
drush migrate:rollback anu_to_lms_node_module_lessons -y
drush migrate:rollback anu_to_lms_paragraph_lesson_checklists -y
```

To inspect and run the Milestone 6 parity audit:

```bash
drush migrate:status anu_to_lms_achievements_semantics
drush migrate:import anu_to_lms_achievements_semantics -y
```

If the migration remains undiscovered after a cache rebuild, verify that
`migrate_plus`, `migrate_tools`, `paragraphs`, and `lms` are installed and
enabled, then check `drush watchdog:show --severity=Error` for plugin discovery
errors.

The parity audit currently uses an empty `embedded_data` source and a `null`
destination. Discovery confirms that the plugin is registered, but a run will
process zero rows until staging parity counter rows or a real source plugin are
configured.
