# Free text with model response: recovery handoff — 2026-11-10

## Starting point

Repository: `leeksoup/award`. Branch: `model-response-after-submit`.
The worktree was clean before writing this document. Feature commits currently
present, newest first:

- `55962d2` Show three rubric checks with an add-more button
- `2fe5cb5` Add rubric coaching and weighted model-response scoring
- `ac311c0` Reveal model responses after saving learner answers
- `8839c3e` Updated composer.* to match deployed versions
- `08c537e` Require teacher grading for model response answers

The user reports having applied all delivered feature patches. This checkout
has already been rebased to reconcile the patch-equivalent GitHub history.
Do not assume older local commit hashes identify missing work. No fresh fetch
or remote comparison was performed for this handoff.

The current rubric implementation supersedes the older unconditional
teacher-grading change: automatic grading is now the default, with a
per-activity Require teacher review checkbox.

No plugin implementation changes are requested at this point. The latest work
was read-only research into `lms_ai`; do not install or integrate it without a
new request. The user wanted this recovery document saved before continuing.

## Read first when resuming

1. Read root `AGENTS.md`, inspect Git status and recent commits.
2. Read [the module README](../web/modules/custom/lms_free_text_model/README.md).
   It contains the full settings, deployment instructions, and staging checklist.
3. Read the model-response sections in
   [the context document](codex_anu_to_lms_context.md) and
   [the database runbook](anu_to_lms_real_database_runbook.md).
4. Inspect the current module and applicable LMS source before changing code.

Some context-document prose describes an earlier implementation that removed
Check Answer altogether. The current implementation restores Check Answer for
rubric coaching only. Prefer current code, the module README, and this handoff
when interpreting that historical prose.

The lock file currently specifies Drupal core 10.6.21, LMS 1.2.5, and Single
Content Sync 1.4.19. The implementation was checked against vendored LMS 1.2.4
APIs. Root project instructions contain an older LMS 1.1.18 reference: verify
actual versions rather than assuming all those sources match. Consult the
Drupal MCP as required by `AGENTS.md` before new Drupal API work.

## Current authoring and learner behavior

Module: `web/modules/custom/lms_free_text_model`.
ActivityAnswer plugin ID: `free_text_model_response`.

- Existing Question, Minimum characters, Model response heading, and Model
  response settings remain. The model is raw HTML stored in a string field,
  rendered through Drupal's admin HTML filter; learner text is escaped.
- Rubric checks has at most five items. Each has an author-only label, phrase
  or regex mode, pattern, positive relative points weight, and plain-text nudge.
  Blank patterns are skipped. Phrase alternatives use `|`, case-insensitive
  literal substring matching, matching the built-in Free Text with Feedback
  syntax. Regex mode accepts a body without delimiters, validated on save.
- New activities start with three editable rows, each weighted 1:

  | Label | Mode | Pattern | Failure nudge |
  | --- | --- | --- | --- |
  | Verse reference | Regex | `\b([1-3]\s?)?[A-Z][a-z]+\.?\s\d+:\d+` | Try backing this up with a verse from the Compiled Evidence page. |
  | Key word | Phrases | blank | Make sure you address the objection directly. |
  | Question back | Regex | `\?` | Good apologetics often ends with a question back to the person. Can you add one? |

- Add another check reveals a fourth and then a fifth row, preserving entered
  values. The button disappears at five. Existing empty rubrics show three
  blank rows; existing four/five-item rubrics show all saved rows.
- Check Answer enforces minimum length, then shows only failed-check nudges or
  “Looks good. Submit when ready.” It does not save, advance progress, expose
  model HTML, show check labels/patterns, or show a score.
- Students may submit without checking or without passing all checks, provided
  they meet the minimum length. Submit answer saves through LMS and then opens
  the saved-answer comparison page. The model appears only after submission.
- Continue preserves LMS's original next-activity, next-lesson, or completion
  destination. It falls back to the course page if private tempstore expires.
- Submitted learner answers are read-only. Validation also rejects a stale form
  submitted from another tab after the answer has been saved.

Score fraction is passed active weights / total active weights; LMS multiplies
by the activity maximum and rounds. Below minimum length scores zero. With no
active checks, meeting the minimum earns full credit. Existing activities
467–469 keep empty rubrics and the earlier length-only scoring behavior.
This feature does not rescore old answers or rewrite learner progress.

Require teacher review defaults off. When enabled, the answer remains
unevaluated with the rubric score as a suggested starting grade for the normal
course-results Details > Evaluate workflow. The initial score override is
scoped to that submission, so subsequent teacher edits are not recalculated.
Use a positive maximum score: LMS marks zero-score activities evaluated.

These checks measure textual features, not understanding or factual accuracy.
No copy-detection or semantic assessment has been implemented.

## Implementation map and boundaries

Paths below are relative to the module directory:

| File | Responsibility |
| --- | --- |
| `src/Plugin/ActivityAnswer/FreeTextModelResponse.php` | Learner form, coaching AJAX, grading selection, scoring, saved-answer/model display |
| `src/RubricEvaluator.php` | Weighted checks, phrase/regex matching, nudges, regex execution limits |
| `src/ModelResponseEvaluator.php` | Minimum length, scoring delegation, filtered model rendering |
| `src/ModelResponseSubmission.php` | Duplicate-submit protection, private continuation, post-save redirect, one-time suggested teacher score |
| `src/Controller/ModelResponseController.php` | Saved-answer comparison page and access checks |
| `lms_free_text_model.module` | Scoped LMS answer-form hooks and initial review-score presave hook |
| `src/Plugin/Field/FieldType/RubricCheck.php` | Structured rubric field; maximum cardinality five |
| `src/Plugin/Field/FieldWidget/RubricCheckWidget.php` | Three initial rows and AJAX add-more behavior |
| `src/Plugin/Validation/Constraint/` | Rubric validation, including invalid regex rejection |
| `lms_free_text_model.install` | Updates 10001 and 10002 |
| `tests/src/Unit/`, `tests/src/Kernel/` | Evaluation, form/display, access, submission, and upgrade coverage |

The plugin no longer uses `WithFeedbackPluginTrait`. Do not reintroduce its
pre-submit model-feedback behavior. The review GET route is
`/course/{group}/model-response/{lms_answer}`. It requires the answer owner,
matching course, this plugin, and normal Group take access; responses are not
cached. Continuation is stored server-side, not accepted as an arbitrary URL.

Answer activity references use LMS's `activity_revision` field
(`Answer::ACTIVITY_FIELD`), not a field named `activity`. Test answer data should
use `setData()` rather than manually double-encoding JSON.

No contrib/core modifications are part of this feature. No content-sync code
changes were made for it. The new rubric and teacher-review fields require the
destination module/configuration to be current; do not claim a rubric-field
export/import round trip passed without actually testing one.

## Deployment and verification status

User confirmation that patches were applied establishes delivery, not that the
entire browser/kernel checklist passed. No new live-site validation was run
while preparing this handoff. Use the module README's staging checklist to
establish the remaining runtime status.

Existing-site deployment instructions already documented in the README are:
back up the database, deploy code, run `drush updb -y`, then `drush cr`.
Update 10001 refreshes the model-response hint. Update 10002 adds rubric/review
storage, bundle fields, widgets, and hidden view-display components while
preserving existing IDs and leaving old activities without rubric values.
The subsequent three-row/add-more widget change needs only a cache rebuild.
Review configuration exports; do not import old configuration that deletes the
new fields. Rollback requires a matching database backup and module code:
removing the custom field implementation while its storage remains is unsafe.

Previously recorded repository verification:

- PHP syntax, YAML parsing via Python, Composer validation, and whitespace
  checks passed for the feature changes. Ruby was unavailable.
- Standalone evaluator unit tests passed: RubricEvaluatorTest, 2 tests / 19
  assertions; ModelResponseEvaluatorTest, 7 tests / 49 assertions.
- Full Drupal-dependent unit and kernel suites were not run. This checkout
  lacks `vendor/` and `web/core/`; PHPCS was unavailable. Existing test source
  is coverage to run, not evidence of a runtime pass.

Pending staging checks include progressive authoring rows and retained values,
invalid regex validation, no model leakage in coaching AJAX, weighted scores,
teacher review and subsequent teacher edits, all Continue destinations, review
access isolation, stale-tab rejection, and unchanged activities 467–469.
Also test anonymous-session behavior if anonymous attempts are used.

An earlier view error for course 15 / lesson 33 came from stale attempt activity
references, before this plugin rendered. Resetting the editor's own progress
resolved it. Course membership and an authorized synchronized teacher Group
role were needed for course edit access/reset; global `lms_teacher` alone was
insufficient. Do not reset student progress as a deployment step.

## Paused formatted-field experiment

The local stash currently contains:

```text
stash@{0}: On main: Paused formatted model-response field conversion
```

It is separate work, not included in these feature commits. The user paused it
while asking the LMS owners why built-in feedback uses raw HTML. Do not pop or
merge it automatically. Stash indexes can move; identify it by description.
A stash is local Git state and is not carried by normal patches or a fresh
clone. If that experiment needs recovery elsewhere, export it separately,
including any untracked files, before losing this checkout.

Hosting ModSecurity has blocked activity creation with longer HTML. Creating
with short text and then editing worked for the user. This is not evidence of
a Drupal sanitization bug; no WAF workaround was added to the module.

## Latest research: LMS AI (no changes made)

The user requested an MCP-first review of
[lms_ai](https://www.drupal.org/project/lms_ai), explicitly without code changes.
The Drupal MCP indexed it; the official beta3 release source was downloaded
under `/tmp` for read-only inspection. No dependency, code, or config was added.
Temporary downloads are disposable and should not be needed to resume.

Findings from that inspection, not a guarantee about future releases:

- Release reviewed: `1.0.0-beta3`; its Composer requirements include Drupal
  `^10.3 || ^11`, LMS `^1.1.16`, and `ai_providers_api`. It is beta software
  outside Drupal's security advisory coverage.
- The `lms_ai_answer` submodule provides `ai_answer` and `ai_answer_async`.
  The former can stream feedback on Check Answer; the latter supports multiple
  questions. Both queue grading after submission for eligible saved answers.
- Prompts use the activity question, a source-of-truth field, optional custom
  evaluation instructions, and the learner answer. Provider/model setup is
  separate from our plugin. Learner content is sent to the selected provider.
- The worker stores feedback/score, marks the answer evaluated, and updates
  status totals. It does not directly honor our rubric fields, post-submit
  fixed-model flow, or optional human-review semantics.
- Provider exceptions have retries, but empty/malformed scoring JSON can become
  score zero and evaluated rather than a retry. Inspect this before adopting.
- A possible streaming-access concern needs further review: the inspected
  hook checked course view access while accepting activity/revision IDs
  separately. The surrounding AI Providers API access layer was not fully
  audited; no confirmed exploit was established.

Relevant upstream beta3 files: `modules/lms_ai_answer/src/PromptBuilder.php`,
`src/Plugin/QueueWorker/AiAnswerScoringWorker.php`, and
`src/Hook/LmsAiAnswerHooks.php` within that submodule.

Suggested direction discussed, not approved implementation: retain our rubric
coaching and post-submit model response, and pilot AI after submission as
teacher assistance for assessing understanding. Validate against known answers
before using AI grades automatically. No copying detector was selected;
PHP `xdiff_string_diff` was discussed but not implemented.

## Delivery workflow

Commit completed work on the current branch, then generate `git format-patch`
outside the tracked worktree and provide a path plus copyable `git am` command.
The user applies patches in a separate checkout and pushes to GitHub.

For later history reconciliation, first preserve unrelated local changes and
inspect the worktree, then use the existing fetch/rebase workflow:

```bash
git status --short --branch
git fetch origin
git rebase origin/main
git status --short --branch
```

Rebase may skip commits whose patches already exist upstream. Preserve the
formatted-field stash. Do not use a destructive reset to reconcile history.
