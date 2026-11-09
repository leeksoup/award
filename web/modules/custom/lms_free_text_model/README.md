# LMS Free Text Model Response

Adds `free_text_model_response` for learner answers with optional rubric coaching,
weighted automatic scoring, and a model response revealed only after submission.
Built-in Evidence Check / Free Text with Feedback activities are unchanged.

## Author settings

Keep the existing Question, Minimum characters, Model response heading, and Model
response fields. Model response remains raw HTML in a textarea, rendered through
Drupal's admin HTML filter. No formatted-field conversion is included.

Rubric checks is a repeatable field with a maximum of **five** items. Each has:

- Label: authors only; never sent as coaching.
- Pattern type: Phrases or Regular expression.
- Pattern: case-insensitive; a blank pattern disables that item.
- Points weight: a positive number, including decimals, relative to other active
  checks. Equal weights divide the maximum score evenly.
- Nudge text: plain text shown only when this check fails.

Phrase mode uses literal substring alternatives separated by `|`, matching the
built-in phrase syntax. It has no word boundaries: `trust` matches `distrust`.
Regex mode accepts a PCRE body **without delimiters**, such as `\?`. Regexes are
validated when the activity form is saved. Invalid regexes are form errors.
PCRE execution has match/depth limits; an invalid imported regex or an execution
limit fails that check rather than granting credit.

New activities have three editable defaults, with weight 1 each:

| Label | Type | Pattern | Nudge |
|---|---|---|---|
| Verse reference | Regex | `\b([1-3]\s?)?[A-Z][a-z]+\.?\s\d+:\d+` | Try backing this up with a verse from the Compiled Evidence page. |
| Key word | Phrases | blank | Make sure you address the objection directly. |
| Question back | Regex | `\?` | Good apologetics often ends with a question back to the person. Can you add one? |

The blank Key word item is skipped until filled in. The other two defaults split
credit equally. Patterns check textual features, not whether the answer is true
or demonstrates understanding. Use teacher review when that distinction matters.

The authoring form initially shows three rows. **Add another check** reveals a
fourth and then a fifth row; the button disappears at five. New activities have
the three defaults above. Existing empty rubrics show three blank rows, and
editing activities with four or five saved checks shows all their saved rows.
This widget-only change needs `drush cr`, with no additional database update.

Require teacher review defaults **off**. When off, LMS automatically evaluates
new submissions. When on, LMS keeps the answer unevaluated for the standard
course-results **Details > Evaluate** workflow, with the rubric score as its
starting grade. Use a positive activity maximum score: LMS automatically marks
zero-score activities evaluated regardless of this checkbox.

## Student flow and scoring

1. Student types an answer and can press **Check Answer**. Below the minimum,
   the existing length validation message appears. Otherwise, only failed-check
   nudges are shown. When all checks pass, the message is "Looks good. Submit
   when ready." No model HTML, check labels/patterns, or score appears.
2. Student may revise/check again, or submit without passing the rubric. The
   minimum length is still required on submission. Check Answer never saves an
   answer and never changes progress.
3. **Submit answer** saves the answer and then shows the configured comparison
   heading, escaped learner answer, and filtered model HTML. **Continue** uses
   LMS's original next-activity, next-lesson, or course-completion destination.

Score fraction = passed active weights / total active weights. LMS multiplies
that fraction by the activity's maximum score and rounds to an integer. A
response below minimum length scores zero. With no active checks, meeting the
minimum earns full credit, preserving behavior for existing activities including
467, 468, and 469. Updating the module does not add checks to saved activities,
rescore old answers, change entity/revision IDs, or rewrite learner progress.

After seeing the model, submitted answers are read-only in the learner form.
Validation also rejects an old form submitted from another tab after the answer
has already been saved. Teacher grading remains editable. Review URLs require
the answer owner, matching course, this plugin, and normal Group take access;
they are not cached. Continuation is stored server-side in private tempstore.
If it expires, Continue falls back to the course page.

## Install and upgrade

Fresh installation:

```bash
drush en lms_free_text_model -y
drush cr
```

Create an activity type at `/admin/lms/activity_type/add` and select **Free text
with model response**. LMS installs its bundle fields when the type is created.
Choose a positive default maximum score. Existing activity types that use another
plugin must be replaced deliberately; do not change an existing activity bundle
or migrate its attempt history as part of this feature.

Existing installation, after a database backup and code deployment:

```bash
drush updb -y
drush cr
```

Update `10001` refreshes the model-response field hint. Update `10002` adds the
rubric and teacher-review fields and authoring widgets to every bundle using
this plugin, and hides both fields in activity view displays. It is idempotent
and leaves saved activities without check values. The three defaults apply to
new activities only. The update preserves existing field IDs and reuses sync
UUIDs when creating new configuration. Export configuration from updated staging
and review it before production import; do not blindly import old configuration
that removes the new fields.

The implementation targets LMS 1.2.4's answer form APIs, checked against the
vendored source and Drupal MCP. The supplied lock currently contains LMS 1.2.5.
It uses a custom field/widget/constraint, plugin callbacks, and a presave hook
scoped to the initial teacher-review submission. Contributed code is unchanged.
The existing post-submit GET review route remains; no new HTML-posting route is
introduced. Hosting ModSecurity can still reject activity authoring requests.

Reset only an editor's own test progress when content changes leave an old
attempt's activity list stale. The editor needs course membership and update
access to see **Reset for updates**. Never reset students merely to deploy.

## Verification

From a full checkout with dev dependencies:

```bash
vendor/bin/phpunit -c web/core web/modules/custom/lms_free_text_model/tests/src/Unit
vendor/bin/phpunit -c web/core web/modules/custom/lms_free_text_model/tests/src/Kernel
```

Kernel coverage includes the default field values, weighted scoring, teacher
review selection, blank-check compatibility, regex/cardinality validation,
idempotent upgrade preservation of activities 467-469, plain-text nudges, and
model visibility before versus after saving. Unit coverage includes regex and
phrase matching, length-only scoring, saved-answer access, navigation, and a
one-time suggested teacher grade. Kernel tests still need a bootstrapped test
environment; syntax checks alone do not establish a runtime pass.

On staging:

1. Confirm built-in Evidence Checks retain their normal behavior.
2. Create a new Questions activity: only three default rows, editable weights,
   and teacher review unchecked. Add another check must reveal one row at a
   time, preserve entered values, and disappear after the fifth row. Save and
   reopen four/five-check activities to confirm all saved rows remain visible.
   Invalid regexes must prevent saving.
3. Check short, partially matching, fully matching, and blank-pattern answers.
   Confirm only plain-text nudges/readiness messages appear before submission.
   Inspect the AJAX response too: no model, labels, patterns, or score.
4. Submit directly without checking and verify length validation still runs.
   Submit a partial match and verify the rounded weighted score is saved.
5. Enable teacher review and submit a fresh attempt. Confirm the suggested score
   is stored unevaluated and can be changed through Details > Evaluate. Saving
   a teacher grade must not recalculate it.
6. Check the HTML model appears only after saving, and Continue works within a
   lesson, across lessons, and for the final activity. Refresh must not resubmit.
7. Test another account/course against the review URL, stale form resubmission,
   editor reset, and anonymous-session isolation if anonymous attempts are used.
8. Confirm existing activities 467-469 retain empty rubrics and full credit at
   minimum length. Old grading flags must remain unchanged.

Rollback requires the pre-deployment database backup and matching module code.
Do not simply remove the new custom field-type implementation while its field
storage/configuration remains installed. Keep the separate formatted-field
experiment stashed; it is not part of this feature.
