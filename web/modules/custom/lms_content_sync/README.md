# LMS Content Sync

This optional module teaches Single Content Sync how to handle LMS's
`lms_reference` field. A lesson export includes its referenced activities and
their content. Each reference keeps its original order and `data` map, such as
`max_score`. On import, references are resolved by entity UUID so activity IDs
may differ between sites. The same processor also handles the course-to-lesson
`lms_reference` field.

Enable `lms_content_sync` on both sites and enable the relevant entity types and
bundles in Single Content Sync settings. Export the lesson again after enabling
this module: earlier exports contain only numeric `target_id` values and are
intentionally rejected by this processor. Inspect a new lesson export for
`base_fields.activities` entries containing `entity`, `uuid`, `entity_type`,
`base_fields`, and `custom_fields`, alongside each item's `data`.
The lesson and its nested activities must each have a scalar
`base_fields.langcode` such as `en`. Re-export after installing this update;
older exports with a list under `langcode` cannot be imported safely.

Import a newly exported lesson on a staging copy first. Single Content Sync
updates an existing referenced entity when its UUID matches and otherwise
creates it. This plugin does not export historical revisions. It rejects
missing references instead of creating placeholder activities or lessons.
