# Anu LMS → Drupal LMS Migration Pseudocode Spec

This document is the code-ready pseudocode companion to the migration plan and is intended for implementation kickoff.

## Execution order

1. `anu_to_lms_taxonomy`
2. `anu_to_lms_media_files`
3. `anu_to_lms_paragraph_checklist_items`
4. `anu_to_lms_paragraph_lesson_checklists`
5. `anu_to_lms_paragraph_lesson_sections`
6. `anu_to_lms_node_module_lessons`
7. `anu_to_lms_node_module_assessments`
8. `anu_to_lms_paragraph_course_modules`
9. `anu_to_lms_node_courses`
10. `anu_to_lms_course_structure`

## Shared transform rules

- Preserve source order for all `entity_reference_revisions` arrays.
- Normalize text formats (`minimal_html`, `filtered_html`, `basic_html`, `full_html`) to destination-supported formats.
- Checklist transform (v1):
  - `lesson_checklist` + `checklist_item` => LMS `no_answer` activity payload.
  - `checklist_item.field_checkbox_option.value` is the canonical item text.
  - `checklist_item.field_lesson_text_content.value` is optional description.
- Keep source IDs in migration map tables to support later relationship assembly.

## Migration ID specs

### `anu_to_lms_taxonomy`

Purpose: migrate course taxonomy terms used by source content.

Pseudo process:
- Source: taxonomy terms from target vocabularies used in course fields.
- Map: `tid`, `vid`, `name`, `description` (+ normalized format).
- Destination: taxonomy term entity.

### `anu_to_lms_media_files`

Purpose: migrate files referenced by course/lesson blocks.

Pseudo process:
- Source: file entities referenced by course image/resource/media fields.
- Map: fid, uri, filename, mime, status.
- Destination: file entity.

### `anu_to_lms_paragraph_checklist_items`

Purpose: migrate checklist item paragraph payloads for reuse in checklist activity assembly.

Pseudo process:
- Source bundle: `checklist_item`.
- Map:
  - source paragraph id
  - `field_checkbox_option.value` / format
  - `field_lesson_text_content.value` / format (optional)
- Destination: checklist-item staging record/entity.

### `anu_to_lms_paragraph_lesson_checklists`

Purpose: convert checklist paragraphs to LMS `no_answer` activity payloads.

Pseudo process:
- Source bundle: `lesson_checklist`.
- Resolve ordered `field_checklist_items` via migration lookup.
- Build final activity payload body by concatenating ordered checklist items.
- Destination: LMS activity staging record/entity.

### `anu_to_lms_paragraph_lesson_sections`

Purpose: convert lesson pages and nested block chains.

Pseudo process:
- Source bundle: `lesson_section`.
- Traverse ordered `field_lesson_section_content`.
- For each block paragraph:
  - map by bundle (`lesson_text`, `lesson_heading`, `lesson_image`, etc.)
  - route `lesson_checklist` to checklist activity lookup.
- Destination: lesson-section staging entity.

### `anu_to_lms_node_module_lessons`

Purpose: migrate lesson nodes and attach transformed sections.

Pseudo process:
- Source bundle: `module_lesson`.
- Map title/status/path metadata.
- Resolve `field_module_lesson_content` via section migration lookup.
- Preserve lesson completion email fields for `achievements_learning` continuity.
- Destination: LMS lesson destination.

### `anu_to_lms_node_module_assessments`

Purpose: migrate quiz content into LMS activity plugin model.

Pseudo process:
- Source bundle: `module_assessment`.
- Traverse ordered `field_module_assessment_items`.
- Map question bundles to LMS plugin types:
  - single/multi choice => select plugin
  - short/long answer => free_text plugin
  - scale/likert => nearest available plugin or custom plugin fallback
- Destination: LMS activity set destination.

### `anu_to_lms_paragraph_course_modules`

Purpose: migrate course module grouping paragraphs.

Pseudo process:
- Source bundle: `course_modules`.
- Map lessons via lookup from lesson migration.
- Map optional final assessment via lookup from assessment migration.
- Keep module paragraph order for later course structure assembly.
- Destination: course-module staging entity.

### `anu_to_lms_node_courses`

Purpose: migrate course nodes and metadata.

Pseudo process:
- Source bundle: `course`.
- Map title/description/image/taxonomy/linear progress settings.
- Resolve `field_course_module` via course-module migration lookup.
- Destination: LMS course destination.

### `anu_to_lms_course_structure`

Purpose: final assembly of LMS learning path.

Pseudo process:
- Source: staged migrated course + module + lesson + activity maps.
- Build ordered LMS learning path payload.
- Write final LMS course structure records and relationships.

## Validation gates

- Course module order preserved.
- Lesson section order preserved.
- Checklist item order preserved.
- Assessment plugin mapping is deterministic.
- 3/3 courses load with expected lesson/activity sequence.
