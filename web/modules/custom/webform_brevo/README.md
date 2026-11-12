# Webform Brevo

Provides the `brevo` Webform handler outside the Webform contrib module.
Requires Webform 6.3 and `getbrevo/brevo-php` 1.x; the repository already
requires the SDK through its root Composer manifest.

The plugin ID, handler instance IDs, list ID, API key, custom data, excluded
fields, response messages, and submission state settings remain compatible
with the site's previous custom handler. Summary and settings rendering do
not contact Brevo. Only enabled submission states send requests.

The handler extends Webform's maintained Remote Post implementation for
data mapping, tokens, and controlled error messages. The SDK adapter catches
`Brevo\Client\ApiException`, reads its supported response body/header methods,
and supplies a real HTTP response to those helpers. Connection failures also
follow the controlled error path. Debug output requires `administer webform`
and never includes the API key or contact request body.

See [deployment and validation](../../../../docs/webform_brevo_migration_runbook.md)
before enabling the module or removing the previous custom plugin file.

## Contact mapping

Use an `email` element or map another element to `email` with custom data.
`firstname`/`first_name` and `lastname`/`last_name` map to `FIRSTNAME` and
`LASTNAME`. Included submission metadata maps `sid`, `completed`, `uri`, and
`remote_addr` to `SID`, `SUBMIT_DATE`, `FROM_FORM`, and `USER_IP` respectively.
Excluded metadata stays excluded. The corresponding custom attributes must
exist in Brevo, as with the previous handler.

The Deleted checkbox retains the previous contact upsert behavior. It does
not delete a Brevo contact. Leave it disabled unless that behavior is wanted.

## Tests

From the project root, with development dependencies installed:

```bash
vendor/bin/phpunit -c "$PWD/web/core/phpunit.xml.dist" web/modules/custom/webform_brevo/tests/src/Unit
```

These unit tests use the actual SDK with mocked HTTP responses and require
neither a database nor Brevo credentials. They cover rendering, API failures,
successful delivery, state selection, and installation handover. Browser and
active-configuration checks are documented in the runbook.
