# Drupal 10.6 to Drupal 11.4 upgrade handoff

## Decision and current readiness

Perform the upgrade on a fresh development clone first, then staging, then
production. Do not wait until the Drupal 10 end of life on 9 December 2026.
Target the newest stable `11.4.x` release available on the day of the upgrade;
do not use Drupal 11.5 pre-release versions.

The environment and dependency review completed on 3 September 2026 found:

- Drupal `10.6.15`, PHP `8.3.27`, Drush `13.7.6`, and MariaDB `10.11.11`.
  PHP and MariaDB meet Drupal 11 requirements.
- The active Bootstrap package is `drupal/bootstrap` `3.40.0`. It supports
  Drupal 11 and Bootstrap 3 with jQuery 4 compatibility. The custom
  `essential1_1` subtheme still requires visual and JavaScript regression
  testing.
- Contributed Book and Forum packages are already installed. Keep them: they
  replace the Drupal core Book and Forum modules removed in Drupal 11.
- A Drupal MCP Composer scan of the copied production `composer.json` and
  `composer.lock` found 55 packages compatible with Drupal 11.4. The only
  package with no Drupal 11 release is `drupal/variationcache` `1.5.0`.
- `drupal/rat` is an indirect library required by Inline Entity Form. The MCP
  data set does not index it, but it has no runtime Drupal core constraint;
  Composer's dry run is the required compatibility gate.
- The Composer patch list is empty, so no patch re-roll is currently needed.

The project source currently contains untracked migration handoff and patch
artifact files. Preserve them; this upgrade work must not overwrite them.

## 1. Prepare a development clone

Run these commands at the Drupal project root, only on a development copy
containing a fresh production database and all public/private/protected files.

```bash
drush status --fields=drupal-version,php-version,drush-version
composer validate --no-check-publish
drush updb:status
drush config:status
drush watchdog:show --severity=Error --count=50 --extended
drush pm:list --type=module --status=enabled --field=name
```

Record all existing warnings and errors. Confirm that usable anonymous,
learner, teacher, and administrator test accounts exist.

Before changing anything, take a database dump, a complete public-files backup,
a private/protected-files backup when applicable, and copies of the two
Composer files. Use the site's normal backup process where it exists.

```bash
drush sql:dump --result-file=/safe/backup/location/pre-d11-dev.sql
cp composer.json /safe/backup/location/composer.json.pre-d11
cp composer.lock /safe/backup/location/composer.lock.pre-d11
```

## 2. Remove VariationCache on Drupal 10

VariationCache should be removed before the Drupal 11 change. First verify that
no custom code or configuration still refers to it:

```bash
rg -n -i 'variationcache|Drupal\\variationcache' web/modules/custom web/themes/custom
drush config:get core.extension module.variationcache
composer remove drupal/variationcache --dry-run
```

The code search must be empty, and Composer must not propose removal of a
required package. Then uninstall the module while its code is still present,
and remove the Composer requirement without updating yet:

```bash
drush pm:uninstall variationcache -y
composer remove drupal/variationcache --no-update
```

Do not export configuration yet. Export it once the full development upgrade
has passed verification.

## 3. Analyze Drupal 11 readiness

On the still-Drupal-10 development clone, install temporary analysis tooling
and scan all projects:

```bash
composer require --dev drupal/core-dev:10.6.15 drupal/upgrade_status:^4.3 --update-with-all-dependencies
drush en upgrade_status -y
drush upgrade_status:analyze --all --format=plain --phpstan-memory-limit=1G
```

Resolve every error-level finding before changing core. The scan must cover the
enabled custom modules (`achievements_learning`, `lms_runtime`,
`lms_discussion_prompt`, and `group3_schema_repair`) as well as the Essential
subtheme and other custom theme/template code. Warnings require review; errors
are upgrade blockers.

Do not change from Group 3.x to the Group 4 alpha during this upgrade.

Optionally repeat the MCP Composer scan using the files on the development
server:

```bash
curl -sS https://api.tresbien.tech/v1/composer/scan \
  -F composer_json=@composer.json \
  -F composer_lock=@composer.lock \
  -F target_core=11.4
```

After VariationCache is removed, this scan should have no `no_release` row.
If patches are added later, use the MCP patch-check workflow before updating.

## 4. Update Composer dependencies

Change constraints without resolving them yet:

```bash
composer require \
  'drupal/core-recommended:^11.4' \
  'drupal/core-composer-scaffold:^11.4' \
  'drupal/core-project-message:^11.4' \
  --no-update
composer config allow-plugins.symfony/runtime true
composer require drush/drush --no-update
composer update --dry-run
```

Review the dry-run output. It must resolve stable Drupal `11.4.x`; it must not
remove Book, Forum, LMS, Group, Commerce, Webform, Bootstrap, or another
required package; and it must not need a dev/alpha/beta release or an
unreviewed patch. The dry run must also resolve `drupal/rat` successfully.

If clean, perform the same resolution and validate the result:

```bash
composer update --with-all-dependencies
composer install
composer validate --no-check-publish
composer audit
```

Keep the resulting `composer.json` and `composer.lock` together. They are the
exact deployment artifact.

## 5. Run updates and capture configuration

```bash
drush updatedb:status
drush updatedb -y
drush cr
drush updatedb:status
drush config:status
drush watchdog:show --severity=Error --count=50 --extended
```

Review `/admin/reports/status`; no new entity/field definition mismatches or
new errors are acceptable. Since VariationCache was intentionally uninstalled,
export the now-tested configuration only after the development site is clean:

```bash
drush cex -y
drush config:status
```

`drush updb` does not perform a configuration export. Preserve the exported
configuration alongside the Composer files.

If no longer needed after the final clean report, remove temporary analysis
tools and revalidate:

```bash
drush pm:uninstall upgrade_status -y
composer remove --dev drupal/upgrade_status drupal/core-dev --with-all-dependencies
composer validate --no-check-publish
drush cr
```

## 6. Acceptance testing

Test as anonymous, learner, teacher, and administrator users.

| Area | Required checks |
| --- | --- |
| Essential / Bootstrap 3 | Desktop and mobile layout, menus, dialogs, draggable/resizable controls, forms, validation errors, and administration pages. |
| Content | Book navigation, Pathauto aliases, document media, document replacement, CKEditor plugins, and content editing. |
| Forum | Listing, viewing, posting, commenting, read/unread behavior, and Group-restricted access. |
| LMS | Course access, Group membership, teacher editing, lesson navigation, mandatory/optional activities, checklists, scores, completion, remote-video playback, and discussion prompts. |
| Commerce | Product display, cart, checkout, stock, and PayPal using a safe sandbox/non-production transaction. |
| Operations | Webform submission/export, cron, queue processing, cache rebuild, and watchdog review. |

Development acceptance is complete only when `drush updatedb:status` is empty,
the status report has no new errors, no new PHP errors appear during testing,
and `drush config:status` is clean after the intended export.

Repeat this exact deployment and test process on staging using the same locked
dependencies and exported configuration before scheduling production.

## 7. Production rollout and rollback

1. Schedule a maintenance window and take fresh production database and full
   files backups.
2. Deploy the already-tested code, `composer.json`, `composer.lock`, and
   exported configuration.
3. Run:

   ```bash
   composer install --no-dev --optimize-autoloader
   drush cim -y
   drush updatedb -y
   drush cr
   drush updatedb:status
   ```

4. Smoke-test public pages, LMS learner and teacher paths, document media,
   forums, and checkout. Review the status report and new watchdog errors
   before ending maintenance.

Never roll back a completed major upgrade by downgrading Composer alone. A
rollback restores the pre-upgrade code/lock file, database, and files together
from the same backup point.

## Reference sources

- [Drupal 10 to 11 upgrade procedure](https://www.drupal.org/docs/upgrading-drupal/upgrading-drupal/how-to-upgrade-from-drupal-10-to-drupal-11)
- [Drupal core release schedule](https://www.drupal.org/about/core/policies/core-release-cycles/schedule)
- [Drupal 11 database requirements](https://www.drupal.org/docs/getting-started/system-requirements/database-server-requirements)
- [Bootstrap 3.40 Drupal 11 release notes](https://www.drupal.org/project/bootstrap/releases/8.x-3.40)
