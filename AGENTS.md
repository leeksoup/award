# Codex project instructions

## Scope

These instructions apply to the entire repository.

## Start here

Before changing migration code, read:

1. `docs/codex_anu_to_lms_context.md`
2. `docs/anu_to_lms_real_database_runbook.md`
3. `docs/anu_to_lms_migration_plan.md`
4. `web/modules/custom/anu_to_lms_migrate/README.md`

Inspect `git status`, the latest commits, and the relevant vendored modules
before assuming that a previous chat or patch is present.

## Repository and architecture

- The migration module is `web/modules/custom/anu_to_lms_migrate`.
- The target is the vendored Drupal LMS 1.1.18 module at
  `web/modules/contrib/lms`.
- Anu source configuration is vendored under `web/modules/contrib/anu_lms` and
  `web/modules/contrib/anu_lms_assessments`.
- Read contrib code to confirm APIs, but do not modify Drupal core or contrib
  modules. Put compatibility behavior in the custom migration module.
- The migration reads Anu entities from the active site database; it does not
  use a separate source database connection.

## Migration invariants

- Preserve source ordering at every reference level.
- Use migration lookups with `no_stub: true`; never create silent placeholder
  activities, lessons, courses, users, or files.
- Fail with source entity IDs when required references or media are missing.
- Keep migration IDs and source plugin IDs prefixed with `anu_to_lms`/`anu_`
  for provenance and migration-map stability.
- Use reusable, Anu-neutral names for target bundles and fields.
- Do not map Anu course-module paragraphs to destination entities. Traverse
  modules in order and flatten their lesson references into `lms_course.lessons`.
- Keep Group access enforcement enabled. Do not solve authorization problems
  by disabling entity-access query rewriting on learner-facing data.
- Course owners need both a Group membership and an authorized synchronized
  Group role. Verify memberships, roles, and `view`/`take` access separately.
- Do not migrate historical attempts or progress unless scope is explicitly
  changed.

## Drupal implementation conventions

- Follow Drupal coding standards and use `declare(strict_types=1);` in PHP.
- Never wrap imports in `try/catch` blocks.
- Prefer dependency injection for services in reusable runtime classes. The
  existing source plugins use the active Drupal container directly; keep a
  change internally consistent unless refactoring the whole slice.
- Add new default configuration under the module's `config/install` directory.
- Existing installations require monotonic update hooks. Inspect the current
  highest hook before adding one; do not reuse an update number already merged
  or executed. The next number after the current context is expected to be
  `10011`, but verify the file first.
- Update hooks must be idempotent and preserve existing entity IDs and
  migration-map references.
- Do not claim a real-database migration passed unless the user provides its
  output or the command was run against a bootstrapped site in this environment.

## Required checks

Run the applicable subset before committing:

```bash
git diff --check
php -l path/to/changed.php
ruby -e 'require "yaml"; ARGV.each { |f| YAML.load_file(f) }' path/to/changed.yml
composer validate --no-check-publish
```

Run PHPCS/PHPUnit when their executables and dependencies are available. If
they are unavailable, report that as an environment limitation rather than a
passing test. Use the runbook for staging `drush` validation.

## Documentation and delivery

- Update the runbook whenever import order, update hooks, audits, rollback, or
  permissions change.
- Update `docs/codex_anu_to_lms_context.md` when a slice is validated or a new
  blocker is discovered.
- Keep commands copyable and distinguish repository checks from staging-only
  checks.
- Commit completed changes on the current branch with a focused message.
- Do not overwrite unrelated user changes. If the worktree is dirty, inspect
  and preserve those changes.

