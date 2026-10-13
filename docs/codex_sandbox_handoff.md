# Sandboxed Codex Handoff

## Purpose

Use this document when starting Codex as a sandboxed user on the Anu LMS
decommission work. It supplements, but does not replace,
`AGENTS.md`, `docs/codex_anu_to_lms_context.md`, and
`docs/anu_to_lms_real_database_runbook.md`.

## Repository State

At the time this document was written, the relevant branch is
`anu-lms-decommission` at commit `0b0d23f Add guarded Anu LMS decommission
commands`.

The standalone module is `web/modules/custom/anu_lms_decommission`. Its
commands are:

```text
anu-lms-decommission:audit
anu-lms-decommission:archive
anu-lms-decommission:purge-content
anu-lms-decommission:remove-config
anu-lms-decommission:uninstall
anu-lms-decommission:verify
```

At session start, confirm the actual checkout rather than trusting this
snapshot:

```bash
git status --short --branch
git log -5 --oneline
sed -n '1,240p' AGENTS.md
sed -n '1,260p' docs/codex_anu_to_lms_context.md
sed -n '300,370p' docs/anu_to_lms_real_database_runbook.md
```

The worktree may contain an untracked `reference/` directory. Treat it as
user-owned input and do not delete or modify it unless explicitly requested.

## Sandbox Boundary

- Give the sandboxed user read/write access only to the repository checkout
  and an explicitly approved temporary directory.
- Do not put database passwords, SSH keys, Drupal settings files, backups, or
  staging credentials in this repository or this document.
- Repository checks are safe to run in the sandbox. Staging `drush` commands
  must run only in the bootstrapped staging environment, under the operator's
  control.
- If the sandbox checkout lacks `vendor/` or `web/core/`, do not claim Drupal
  runtime, PHPCS, PHPUnit, or Drush checks passed. Report the limitation and
  give copyable staging commands instead.
- Preserve unrelated worktree changes. Do not use destructive Git commands or
  raw SQL for Drupal entity cleanup.

## Required Reading Before Migration Changes

Before changing migration or decommission code, read the files required by
`AGENTS.md`:

```text
docs/codex_anu_to_lms_context.md
docs/anu_to_lms_real_database_runbook.md
docs/anu_to_lms_migration_plan.md
web/modules/custom/anu_to_lms_migrate/README.md
```

Also read `web/modules/custom/anu_lms_decommission/src/Drush/Commands/AnuLmsDecommissionCommands.php`
before changing its behavior.

## Decommission Safety Rules

- Do not run `drush pmu anu_lms` directly. Its uninstall behavior is not a
  complete or safe cleanup path for this site.
- Keep `anu_to_lms_migrate` enabled during decommission. It retains target
  LMS activity configuration and migration-map provenance.
- Run `audit` and resolve its blockers before any destructive phase.
- Create and verify a database backup, protected-files backup, and inventory
  archive outside the web root before `purge-content`.
- `purge-content`, `remove-config`, and `uninstall` require distinct literal
  confirmation tokens. Run them separately, in that order, only after an
  explicit operator decision.
- Never delete managed files as part of source purging. Migrated LMS audio can
  still reference the same file entities.
- If a destructive phase reports unexpected dependencies or shared resources,
  stop and restore the pre-purge backup rather than deleting tables manually.

## Known Current State

- The LMS course-progress repair on another branch was validated by the
  operator in staging. It removed only `lms_course_status` rows that pointed
  to deleted or non-course Groups.
- The three visible `lms_class` Groups named `Course #N class` are expected
  child Groups used by `lms_classes`, not duplicate course imports.
- A separate unresolved staging warning appears once after `drush cr` on the
  first `/admin/lms/*` request: `Plugin ID 'default' was not found.` It is not
  logged to watchdog. Activity-type and active-config checks did not identify
  its source. Do not attribute it to decommission without fresh evidence.

## Validation And Delivery

For PHP/YAML/configuration changes, run the applicable checks from `AGENTS.md`:

```bash
git diff --check
php -l path/to/changed.php
ruby -e 'require "yaml"; ARGV.each { |f| YAML.load_file(f) }' path/to/changed.yml
composer validate --no-check-publish
```

Commit focused completed changes on the current branch. In the handoff, state
which checks actually ran and distinguish sandbox checks from operator-provided
staging results.
