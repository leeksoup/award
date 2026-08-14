# Anu to LMS migration module

Migration definitions in `migrations/` are discovered only while this module
is enabled. After installing or updating the module, rebuild Drupal's caches
before querying migration status.

```bash
drush pm:list --type=module --field=name --status=enabled | grep '^anu_to_lms_migrate$'
drush en anu_to_lms_migrate -y
drush cr
drush migrate:status | grep 'anu_to_lms'
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
