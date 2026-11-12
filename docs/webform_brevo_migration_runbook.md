# Move the custom Brevo handler out of Webform

## Deployment

This module replaces the custom `brevo` plugin previously placed at
`web/modules/contrib/webform/src/Plugin/WebformHandler/WebformBrevoHandler.php`.
The plugin ID stays `brevo`, so existing handler instances and settings remain
in place. No handler needs to be deleted or recreated.

1. Back up the database and active Webform configuration. Treat configuration
   backups as private: the existing handler stores its API key in configuration.
2. Deploy `web/modules/custom/webform_brevo`. Keep the previous custom plugin
   file in place for this first step. The root Composer requirement already
   includes `getbrevo/brevo-php`; use the normal Composer deployment if vendor
   dependencies are missing.
3. From the site root, enable the new module and rebuild caches:

   ```bash
   drush en webform_brevo -y
   drush cr
   ```

   Installation resaves Webforms containing a Brevo handler to recalculate the
   plugin's module dependency. Handler instance IDs and settings are preserved.
   A definition-alter hook selects the new implementation while both plugin
   files exist, regardless of discovery order.
4. Verify the selected definition; this prints no credentials:

   ```bash
   drush php:eval '$d = \Drupal::service("plugin.manager.webform.handler")->getDefinition("brevo"); print $d["class"] . PHP_EOL . $d["provider"] . PHP_EOL;'
   ```

   Expected output:

   ```text
   Drupal\webform_brevo\Plugin\WebformHandler\WebformBrevoHandler
   webform_brevo
   ```

5. Open each affected Webform's Settings → Emails / Handlers. Verify its
   existing handler instance, list ID, state checkboxes, and custom data.
   Verify the affected Webform's configuration now includes
   `webform_brevo` in `dependencies.module`. Do not publish an export containing
   its API key.
6. Move the old custom plugin file out of the Webform contrib directory to a
   backup outside the web root. This file was supplied as reference material
   and is not removed by this patch. Run `drush cr` and repeat the definition
   check and settings-page check. The new module now owns the plugin fully.
7. Include the enabled module and affected Webform dependency changes in the
   site's usual configuration deployment workflow. On another environment,
   deploy the module code before importing configuration that depends on it.

This is a new module installation; there is no update hook to run for this
change. Cache rebuild alone does not enable the module.

## Site validation

Use development or staging and a dedicated test form/contact.

1. Confirm both the handler list and its settings form open while the current
   API key is disabled. Rendering must not make a Brevo API call.
2. Activate or replace the disabled API key in Brevo, then save the working
   key in the handler. The code change cannot enable a key rejected by Brevo.
3. Submit a completed test submission. Confirm it is saved, the contact is
   created in the configured Brevo list, and mapped attributes are correct.
   Submit again for the same email and confirm an update succeeds.
4. Where used, verify custom field mappings and response tokens. A contact
   creation response can contain `id`; an update may return HTTP 204 without
   response data. Verify configured status-specific messages and error redirects.
5. Disable the Completed checkbox on the test handler and submit again.
   Confirm no Brevo contact is created or updated. Restore the intended states.
   Updated and Deleted only send requests when explicitly enabled. Deleted
   still upserts the contact; it does not delete the CRM contact.
6. On the test handler only, temporarily use an invalid key. Submission should
   follow Webform's configured error message/redirect behavior, with a generic
   HTTP 401 log entry, and no PHP fatal error. Confirm the handler settings
   remain accessible. Restore the working key and turn debugging off.
7. If debugging is enabled for diagnosis, confirm ordinary submitters see no
   SDK debug output. Detailed response diagnostics require `administer webform`.

The unit suite can be run from the repository root:

```bash
vendor/bin/phpunit -c "$PWD/web/core/phpunit.xml.dist" web/modules/custom/webform_brevo/tests/src/Unit
```

No `SIMPLETEST_DB` is needed for these unit tests. Local verification used
Drupal 10.6.15, the supplied Webform 6.3.1 source, and Brevo SDK 1.0.2 in an
isolated test runtime with mocked HTTP. It does not establish successful live
contact delivery or validation of active site configuration.

## Rollback

Keep the database/configuration backup and the previous custom plugin file.
For a full rollback, restore that file and the pre-install database/configuration,
restore the matching code, and rebuild caches. Uninstalling the new module
directly is not a configuration-preserving rollback: Webforms now depend on its
handler provider, and dependency removal can remove handler instances.
