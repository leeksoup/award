# Anu to LMS migration module

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
to an LMS `anu_checklist` activity backed by the LMS 1.1.18 `no_answer`
plugin. It then migrates every `module_lesson` containing at least one of those
checklists to an `lms_lesson`, preserving checklist order across lesson pages.
Other lesson content is intentionally not included yet.

The migration reads the Anu nodes and paragraphs from the active Drupal site;
it does not require a second database connection. Install the module so its
activity type and field configuration are created, then run the slice:

```bash
drush en anu_to_lms_migrate -y
drush cr
drush migrate:import anu_to_lms_paragraph_lesson_checklists -y
drush migrate:import anu_to_lms_node_module_lessons -y
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
