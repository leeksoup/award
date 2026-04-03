# Anu LMS → Drupal LMS Migration Plan

## Document purpose
This document converts current discovery into a practical migration plan from **Anu LMS 2.11.2** on Drupal 10.6.5 to the **Drupal LMS (`lms`)** module, with a field-level worksheet and phased implementation approach.

It is written for a first migration where:
- content has not gone live yet (no historical progress migration required)
- courses are mostly text/media/checklists
- quiz usage is still limited
- enrollment is expected to move to Drupal Commerce
- achievement behavior should remain aligned with `achievements_learning`

---

## Scope and assumptions

### In scope
1. Migrate core learning content structures:
   - `course`
   - `module_lesson`
   - `module_assessment`
   - relevant paragraph trees
2. Preserve sequencing and completion semantics.
3. Implement checklist behavior using LMS `no_answer` activities.
4. Evaluate and prefer LMS Activity plugins for assessments where feasible.
5. Integrate with existing/ported achievements behavior.
6. Plan Commerce-based enrollment automation.

### Out of scope for first cut
- migration of historical completion/progress attempts
- legacy analytics backfill
- advanced randomized exam logic unless explicitly required

### Confirmed source structures
- Course bundle: `course`
  - key field: `field_course_module` (ERR paragraphs, `course_modules`)
- Lesson bundle: `module_lesson`
  - key field: `field_module_lesson_content` (ERR paragraphs, `lesson_section` “Page”)
- Assessment bundle: `module_assessment`
  - key field: `field_module_assessment_items` (ERR paragraphs/question blocks)
- Paragraph bundles observed include:
  - `course_modules`
  - `lesson_section`
  - `lesson_checklist`
  - `checklist_item`
  - content block bundles (`lesson_text`, `lesson_heading`, etc.)

---

## Strategy decisions

Integration note:
- Treat Drupal LMS `TrainingManager` as the canonical service for learning-path
  status/progress checks where available; avoid direct status-entity mutation.

## 1) Checklist migration behavior
Use LMS **`no_answer`** activity plugin for checklist progression in v1.

Rationale:
- desired UX is “consume content, click Next,” which maps directly
- reduces migration complexity and avoids custom checklist plugin work in first pass

Tradeoff:
- per-item checklist state is reduced to activity-level progression unless custom plugin work is added later

## 2) Assessment approach
Default to **LMS Activity plugins** first; defer Quiz module unless required by concrete gaps.

Why:
- simpler integration with LMS-native progress/completion
- fewer dependencies and less dual-system complexity
- current assessment usage is light

Use Quiz module later only if needed for features like:
- large randomized banks
- complex attempt policies/reporting beyond LMS activity capabilities

---

## Field-level migration worksheet (copy/paste)

## A. Course (`node.course`) mapping

| Source entity | Source field | Source type | Target entity | Target field/plugin | Transform rule | Priority |
|---|---|---|---|---|---|---|
| course | `title` | Title | LMS Course | title/name | Copy as-is | P0 |
| course | `field_course_description` | Text (formatted, long) | LMS Course | description | Copy value; map text format safely | P0 |
| course | `field_course_image` | Image | LMS Course | image/thumbnail | Copy file + alt/title metadata | P1 |
| course | `field_course_category` | Term ref | LMS Course | taxonomy/category | Map term by UUID/name; create if missing | P1 |
| course | `field_course_label` | Term ref | LMS Course | taxonomy/label | Map term by UUID/name | P2 |
| course | `field_course_topics` | Term ref | LMS Course | taxonomy/topics | Map term by UUID/name | P2 |
| course | `field_course_linear_progress` | Boolean | LMS Course settings | linear/sequential | Copy boolean | P0 |
| course | `field_course_finish_button` | Link | LMS Course settings | finish CTA/redirect | Map URL/title where supported; fallback metadata | P2 |
| course | `field_weight` | Weight | LMS listing/order | sort weight | Copy integer | P2 |
| course | `field_course_module` | ERR (`course_modules`) | LMS Course structure | module/lesson sequence | Expand paragraph refs by delta order | P0 |

## B. Course module paragraph (`paragraph.course_modules`) mapping

| Source entity | Source field | Source type | Target entity | Target field/plugin | Transform rule | Priority |
|---|---|---|---|---|---|---|
| course_modules | parent course | Paragraph parent | LMS Course sequence | section/module grouping | Create sequence container per paragraph | P0 |
| course_modules | `field_module_lessons` | Node refs (`module_lesson`) | LMS Course sequence | lesson entries | Resolve lesson map; attach in source order | P0 |
| course_modules | `field_module_assessment` (optional) | Node ref (`module_assessment`) | LMS Course sequence | assessment entry | Attach at configured position (typically after lesson group) | P1 |
| course_modules | paragraph delta | implicit | LMS Course sequence | module order | Preserve exact order | P0 |

## C. Lesson (`node.module_lesson`) mapping

| Source entity | Source field | Source type | Target entity | Target field/plugin | Transform rule | Priority |
|---|---|---|---|---|---|---|
| module_lesson | `title` | Title | LMS Lesson | title | Copy as-is | P0 |
| module_lesson | `field_module_lesson_content` | ERR (`lesson_section`) | LMS Lesson content | pages/activities | Expand section paragraphs in order | P0 |
| module_lesson | `field_completion_email_enabled` | Boolean | `achievements_learning` config/field | lesson completion email flag | Keep behavior, migrate value | P1 |
| module_lesson | `field_completion_email_subject` | Text (plain) | `achievements_learning` config/field | student subject | Copy as-is | P1 |
| module_lesson | `field_completion_email_body` | Text (formatted, long) | `achievements_learning` config/field | student body | Copy + normalize text format | P1 |
| module_lesson | `field_parent_completion_email_subject` | Text (plain) | `achievements_learning` config/field | parent subject | Copy as-is | P1 |
| module_lesson | `field_parent_completion_email_body` | Text (formatted, long) | `achievements_learning` config/field | parent body | Copy + normalize text format | P1 |

## D. Lesson section/page paragraph (`paragraph.lesson_section`) mapping

| Source entity | Source field | Source type | Target entity | Target field/plugin | Transform rule | Priority |
|---|---|---|---|---|---|---|
| lesson_section | parent lesson | Paragraph parent | LMS Lesson | section/page container | Create one page/section per source paragraph | P0 |
| lesson_section | child blocks | Paragraph refs | LMS Lesson page | block/activity list | Transform each child by bundle map below | P0 |
| lesson_section | delta order | implicit | LMS Lesson page | block order | Preserve order exactly | P0 |

## E. Paragraph bundle → LMS plugin mapping

| Source paragraph bundle | Source meaning | Target LMS plugin/type | Transform rule | Priority |
|---|---|---|---|---|
| `lesson_text` | rich text | content block | Copy formatted text safely | P0 |
| `lesson_heading` | heading | heading block | Map heading text/level | P1 |
| `lesson_image`, `lesson_image_wide`, `lesson_image_thumbnail` | image blocks | image/media block | Copy media + caption/alt | P1 |
| `lesson_embedded_video` | video embed | embed/video block | Normalize provider URL/embed | P1 |
| `lesson_audio` | audio block | audio/media block | Copy audio file refs | P2 |
| `lesson_divider` | visual divider | divider block | Simple structural map | P3 |
| `lesson_footnotes` | footnotes | text block | Flatten if no native footnote type | P3 |
| `lesson_highlight`, `lesson_highlight_marker` | callout/highlight | callout block | Preserve style intent where possible | P2 |
| `lesson_list`, `lesson_img_list`, `lesson_img_list_item` | list content | list/content block | Preserve ordering and item content | P2 |
| `lesson_resource` | downloadable resource | resource/download block | Copy file and label metadata | P1 |
| `lesson_table` | tabular content | table/HTML block | Preserve valid markup | P2 |
| `lesson_checklist` | checklist container | **`no_answer` activity** | Convert to instructional/progression activity | P0 |
| `checklist_item` | checklist line item | content inside `no_answer` activity | Flatten items into activity body/instructions | P0 |

## F. Assessment (`node.module_assessment`) mapping

| Source entity | Source field | Source type | Target entity | Target field/plugin | Transform rule | Priority |
|---|---|---|---|---|---|---|
| module_assessment | `title` | Title | LMS Activity set | title | Copy as-is | P0 |
| module_assessment | `field_no_multiple_submissions` | Boolean | LMS activity settings | attempt policy | Map to LMS attempt constraint if available | P1 |
| module_assessment | `field_hide_correct_answers` | Boolean | LMS activity settings | answer reveal | Map to LMS feedback/review option if available | P1 |
| module_assessment | `field_module_assessment_items` | ERR question paragraphs | LMS activities | plugin-specific conversion | Transform question paragraphs by type | P0 |

### Question paragraph conversion map

| Source bundle | Preferred LMS plugin | Rule | Priority |
|---|---|---|---|
| `question_single_choice` | `select` (or feedback variant) | map prompt/options/correct answer | P0 |
| `question_multi_choice` | `select` (multi if supported) | map prompt/options/correct set | P0 |
| `question_short_answer` | `free_text` (or feedback variant) | map prompt + expected guidance | P1 |
| `question_long_answer` | `free_text` | map prompt + rubric hints | P1 |
| `question_scale` / `question_likert_scale` | closest LMS plugin or custom plugin | implement transform after capability validation | P1 |
| `single_multi_choice_item` | option row | attach to parent question in order | P0 |

---

## Implementation plan

## Phase 0 — Discovery freeze (1–2 days)
1. Export source field config and sample content snapshots.
2. Confirm every paragraph bundle in real data (not only schema).
3. Identify any custom text formats/media edge cases.

Deliverable: frozen field map + migration acceptance criteria.

## Phase 1 — Migration module scaffolding (1–2 days)
1. Create custom migration module (example name: `anu_to_lms_migrate`).
2. Add migration groups and dependencies:
   - taxonomy/media prerequisites
   - lessons/assessments
   - course sequence
3. Add placeholder YAML + process plugin stubs.

Deliverable: runnable migration skeleton.

## Phase 2 — Core content migration (3–6 days)
1. Migrate `module_lesson` and section/block trees.
2. Implement checklist→`no_answer` transform.
3. Migrate `module_assessment` to LMS activity plugins.
4. Migrate `course` and sequence structure from `course_modules`.

Deliverable: all 3 courses render and sequence correctly in Drupal LMS.

## Phase 3 — Completion semantics + achievements adapter (3–6 days)
1. Rewire `achievements_learning` triggers to Drupal LMS completion events/services.
2. Validate lesson/section/course milestone parity.
3. Confirm title projection and reward-choice eligibility still work.

Deliverable: same student-facing encouragement behavior under new LMS.

## Phase 4 — Enrollment via Commerce (3–8 days)
1. Model purchaser(parent) vs learner(student) relationship.
2. Hook purchase/renewal/cancel events to class enrollment sync.
3. Add fallback reconciliation job (cron/queue).

Deliverable: paid subscriptions control LMS access automatically.

## Phase 5 — Validation and cutover (2–4 days)
1. Dry-run migration in staging.
2. UAT script:
   - course navigation
   - checklist progression
   - activity completion
   - achievements/title updates
   - parent/student emails
3. Final cutover and rollback plan.

Deliverable: production-ready migration package.

---

## Testing plan

### Functional validation
- Course count, lesson count, and order parity between source and target.
- Checklist pages are completable through `no_answer` activities.
- Assessment items map correctly to chosen LMS plugins.
- Course completion marks correctly from final required item.
- `achievements_learning` milestones still trigger correctly.

### Data validation
- Spot-check 100% of courses (3/3), 100% of assessments, and sample 20–30 lessons.
- Verify media/file references are valid and accessible.
- Confirm taxonomy references resolved without orphan terms.

### Performance/operations
- Migrations are idempotent or safely resettable in staging.
- Rollback or remap procedures documented for failed transforms.

---

## Risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| LMS plugin mismatch for certain question types | medium | define fallback plugin or custom plugin extension path |
| Checklist flattening loses per-item interaction detail | low/medium | accepted in v1; document behavior change |
| Hidden content edge cases in paragraph trees | medium | add discovery fixtures from real content before final run |
| Commerce enrollment complexity | high | treat as dedicated stream with clear event contract |
| Achievement trigger drift after LMS swap | medium | add parity test matrix before go-live |

---

## Decision log (current)
- checklists should be implemented as LMS `no_answer` activities in v1
- LMS Activity plugins are preferred over Quiz module for current scope
- historical progress migration is intentionally excluded
- achievements behavior should remain consistent with current `achievements_learning` product plan

---

## Next actions
1. Approve this plan as v1 migration baseline.
2. Create migration module scaffold and seed migration group files.
3. Implement lesson/section/checklist transform first (highest value path).
4. Run first staging dry-run on one course and iterate.
