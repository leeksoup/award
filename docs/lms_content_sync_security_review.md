# LMS Content Sync security review

Reviewed: 2026-10-19. Scope: the `lms_content_sync` reference processor and its
use of Single Content Sync for lesson and activity export/import, at commit
`a4de70f`. This is a static code review. It does not establish which roles have
the relevant permissions on a running site.

## Findings

### 1. Export permissions can expose restricted lessons and activities (high if delegated)

Single Content Sync grants the `single-content:export` entity operation when
the account has either `export single content` or the entity-specific
`export {entity_type} content` permission. That grant does not require `view`
access. The export route checks that operation, and the exporter does not
perform another entity access check. The custom `LmsReference` processor loads
each referenced activity and exports its full payload without checking the
account's access to that activity. As a result, granting export permission to
a user who cannot view every affected lesson and activity may bypass LMS/Group
view restrictions. This is conditional on the site's permission assignments;
the review found no evidence of the live assignments.

Keep Single Content Sync export permissions with trusted administrators until
the root entity and every referenced entity are checked against the intended
LMS/Group access rules. An additional entity access hook returning a denial
is not by itself a reliable fix: Drupal combines access results from multiple
providers, and the module's permission-based grant can still allow the custom
operation.

Evidence: `web/modules/contrib/single_content_sync/single_content_sync.module`
(`single_content_sync_entity_access()`),
`src/Routing/ContentExportRoutes.php`, `src/ContentExporter.php`
(`doExportToArray()`), and
`web/modules/custom/lms_content_sync/src/Plugin/SingleContentSyncFieldProcessor/LmsReference.php`
(`exportFieldValue()`). Drupal core's entity access result combination was
also checked through the Drupal MCP.

### 2. Import permission permits writes through nested references (high if delegated)

The import route requires `import single content`, but Single Content Sync's
`ContentImporter::doImport()` loads or creates entities by UUID and saves them
without per-entity create/update access checks. `LmsReference::importFieldValue()`
calls that importer for full referenced activity payloads. A user who can
submit a crafted import can therefore update activity content even if normal
LMS edit access would deny it. The import permission is effectively a
privileged content-writing permission; export entity-type settings are not an
import authorization boundary.

Keep `import single content` limited to trusted administrators. If teachers
or other roles need imports, add explicit authorization for the root lesson
and each nested activity, including create versus update, before accepting
their payloads. Test that authorization with real Group memberships and roles.

Evidence: `web/modules/contrib/single_content_sync/single_content_sync.routing.yml`,
`src/ContentImporter.php` (`doImport()` and `createOrUpdate()`), and
`web/modules/custom/lms_content_sync/src/Plugin/SingleContentSyncFieldProcessor/LmsReference.php`
(`importFieldValue()`).

### 3. Export files may contain personal data (medium)

The generic Single Content Sync base-field processor exports entity base
fields, including references such as `uid`. Referenced user records can include
email and initial email addresses through its user processor. Inspect a sample
lesson export for user records and other sensitive fields, then handle YAML/ZIP
exports as sensitive data. Avoid committing them to a public repository or
sharing them beyond the intended administrators.

Evidence: `web/modules/contrib/single_content_sync/src/Plugin/SingleContentSyncBaseFieldsProcessor/GenericContentEntity.php`
and `User.php` in the same directory.

### 4. A failed import can leave partial activity changes (lower, integrity)

The custom processor verifies the referenced entity type, UUID, and that
reference metadata is an array. It does not limit payload size or nesting.
Full referenced activities are imported and saved before the parent lesson;
if a later item fails, earlier activity writes can remain. Large or malformed
imports could also consume substantial resources. Restricting import access
contains the immediate exposure. For routine transfers, use a backup and
check the import result before repeating a failed import.

Evidence: `LmsReference::importFieldValue()` and Single Content Sync's
`ContentImporter::doImport()`.

## Review limits

No running site's role assignments, Group permissions, or import/export output
were available in this checkout. The review found no direct SQL interpolation,
shell execution, or PHP object deserialization in the new custom processor.
The findings above concern the access behavior of the combined modules and
the handling of generated content. Confirm the permission assignments and
test denied view/edit cases on the target site before delegating these tools.
