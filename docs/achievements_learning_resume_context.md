# achievements_learning Resume Context (Post-Migration Hand-off)

## Why this document exists
This document captures the implementation context needed to resume `achievements_learning` work after Anu LMS content/data migration and Drupal LMS rollout.

It is intended to be the first file a developer reads before modifying `achievements_learning` again.

---

## Current product contract (must remain true)
1. Points are recognition/progress only (not currency).
2. Reward choices are available only on configured major milestones.
3. One reward choice per eligible milestone.
4. Titles are auto-selected by priority and projected to `field_learning_current_title`.
5. Student + parent lesson completion emails are supported.
6. Forum milestones include topic/reply participation and exclude configured staff roles.

---

## LMS direction and technical implications

### Integration direction
- `achievements_learning` is now aligned toward **Drupal LMS** integration.
- Event subscriber listens for:
  - `lms.lesson.completed` (target)
  - `anu_lms.lesson_completed` (legacy compatibility)
- Event payload parsing is intentionally dynamic to avoid hard dependency on vendor-specific event classes.

### Completion source of truth used by this module
- Completion tracking in this module is currently based on event-driven storage of completed item IDs in:
  - `al:completed_items`
- Section and course completion checks use these stored IDs.
- Where available, course completion checks now first consult Drupal LMS
  `TrainingManager::getCurrentCourseStatus()` and only fall back to
  `al:completed_items` when status completion is not determinable.

This reduces direct coupling to LMS internals while migration stabilizes.

---

## Config keys that now matter for LMS compatibility
In `achievements_learning.settings`:
- `course_entity_type` (default: `node`)
- `course_bundle` (default: `course`)
- `course_section_reference_field` (default: `field_course_module`)
- `section_entity_type` (default: `paragraph`)
- `section_bundle` (default: `course_modules`)
- `section_lesson_reference_field` (default: `field_module_lessons`)
- `section_assessment_reference_field` (default: `field_module_assessment`)

These fields are now exposed in the settings form to make LMS model changes configurable rather than hardcoded.

---

## What was intentionally preserved from pre-migration behavior
- Existing achievement IDs and baseline threshold config remain in place.
- Title behavior and reward-rule model were not redesigned.
- Notification templates and parent email field pattern were preserved.
- Forum integration remains via node/comment hooks and role exclusion list.

---

## Known constraints and follow-ups

### 1) Event naming and payload normalization
The module currently handles legacy Anu and target LMS event names by string and reflection-style accessors.

Follow-up:
- confirm canonical Drupal LMS completion event names and payload methods
- tighten parser once stable

### 2) Completion parity validation
Because section/course completion currently derives from stored completed item IDs, validate parity against Drupal LMS native progress reporting once migration is complete.
Also confirm the canonical completion field/method on course status entities so
the TrainingManager branch can be tightened from heuristics to an explicit API
contract.

### 3) Reward UX
Reward eligibility plumbing exists, but full user-facing claim UX still needs iterative polish.

### 4) Field map drift
If Drupal LMS content model differs from current defaults, update settings (not code) first.

---

## Recommended post-migration verification checklist
1. Trigger a lesson completion event and confirm:
   - lesson counter increments
   - completed item IDs update (`al:completed_items`)
2. Complete all items in a section and confirm section milestone behavior.
3. Complete all items in a course and confirm course count milestone behavior.
4. Verify title projection updates in forum UI.
5. Verify student and parent completion emails with template replacements.
6. Verify forum topic/reply milestones for student role and staff exclusion.

---

## Handoff notes for future developer work
- Prefer configuration-based model alignment before adding new hardcoded assumptions.
- Keep achievements unlock logic idempotent.
- Keep migration and achievements concerns separated:
  - migration module handles content transformation
  - `achievements_learning` handles learning event reactions and recognition logic
