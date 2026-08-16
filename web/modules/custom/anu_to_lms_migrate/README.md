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

## Group 3 preflight

`anu_to_lms_migrate` does not reference either the Group 2 `group_content`
entity type or the Group 3 `group_relationship` entity type. If enabling this
module fails with `The "group_content" entity type does not exist`, the failure
comes from another enabled module or active configuration that still assumes
Group 2. Enabling any module rebuilds entity and configuration definitions, so
that stale dependency can surface while `anu_to_lms_migrate` is being enabled
even though this migration module did not cause it.

Anu LMS 2.11.2's `anu_lms_permissions` submodule is Group 2-specific. Its
shipped configuration, controller routes, fields, and displays refer directly
to `group_content`. Do not patch those contributed files in this migration
module. Before enabling `anu_to_lms_migrate` on a Group 3 site, either upgrade
`anu_lms_permissions` to a Group 3-compatible release/patch or disable it for
the migration window after taking a database backup and confirming that its
organization access behavior is not needed during the migration.

The `group_content` strings in the Anu LMS Assessments form display are field
group machine names, not necessarily Group entity-type references. However,
`anu_lms_assessments.install` also contains a legacy
`group.content_type.*` configuration name, so its installed/active
configuration must be audited separately.

Confirm the migration module itself is clean and inspect the active site with:

```bash
rg -n 'group_content|group\.content_type' web/modules/custom/anu_to_lms_migrate
rg -n 'group_content|group\.content_type' \
  web/modules/contrib/anu_lms/modules/anu_lms_permissions \
  web/modules/contrib/anu_lms/modules/anu_lms_assessments

drush php:eval 'print (int) \Drupal::entityTypeManager()->hasDefinition("group_relationship") . PHP_EOL; print (int) \Drupal::entityTypeManager()->hasDefinition("group_content") . PHP_EOL;'
drush php:eval '$storage = \Drupal::service("config.storage"); foreach ($storage->listAll() as $name) { $data = $storage->read($name); if (str_contains($name, "group_content") || str_contains(serialize($data), "group_content")) { print $name . PHP_EOL; } }'
```

For a completed Group 3 update, the entity checks should print `1` and `0`.
Do not delete matching active configuration blindly: identify its owning module
and convert, upgrade, or uninstall it using that module's supported Group 3
upgrade path.
