# LMS Free Text Model Response

Adds the activity answer plugin `free_text_model_response` ("Free text with
model response") for questions that cannot be graded by phrase matching.

Evidence Check activities stay on the LMS `free_text_feedback` plugin. This
module does not change that plugin, its fields, or its feedback headings.

## Behavior

- The student types one answer. The only check is the activity's minimum
  character count, using the same counting rules as Free Text with Feedback.
  Set minimum characters to 0 to accept any non-empty answer.
- There is no Check Answer button. Submit answer validates the minimum length,
  saves the answer, and displays a read-only review with the model response.
  Continue follows the normal LMS next-activity, next-lesson, or completion link.
- Submitted answers are read-only on learner revisits, preventing replacement
  after seeing the model. Teacher grading remains available. Resetting test
  progress permits a fresh submission.
- The heading above that response comes from Model response heading. New
  activities default to `Compare your response:`. Clear the field to show no
  heading.
- The heading is plain text. The model response is HTML entered in a textarea
  and rendered in a `div` through Drupal's admin HTML filter
  (`Xss::filterAdmin()`), the same filter an `html_tag` element applies to
  phrase feedback. Authors can keep markup such as
  `<p><strong>Model response:</strong> ...</p>`.
- The feedback uses the `model-response-feedback` classes and the normal text
  color. It does not use `correct-answer`, `wrong-answer`, or the matched and
  unmatched phrase sections.
- Model feedback is only built for saved answers. Submitted answers require
  teacher grading. LMS initially stores the activity's maximum
  score as a provisional value so the learner can continue, but marks the
  answer unevaluated. Set a positive maximum score: LMS always treats zero-score
  activities as evaluated, regardless of the plugin setting.
- Deploying this change does not rewrite previously submitted answers or their
  evaluation flags. Subsequent submissions follow the teacher-grading behavior.
- The site-wide string overrides for "You seem to have a good understanding
  of:" and "You could have another look at:" do not apply here. Those source
  strings are not used.

## Enable

The module depends on LMS and LMS Core Answer plugins.

```bash
drush en lms_free_text_model -y
drush cr
```

Or enable "LMS Free Text Model Response" at Extend.

Enabling the module does not create an activity type. LMS installs this
plugin's fields when an activity type that uses it is saved for the first
time.

## Create a Questions activity type

1. Open `/admin/lms/activity_type/add`.
2. Name: `Questions`.
3. Machine name: `questions`, unless that name is already in use.
4. Description: `Students answer in their own words and then compare their answer with a model response.`
5. Activity - answer plugin: `Free text with model response`.
6. Default maximum score: use the same value as the current Questions
   activity type. Check it with:

   ```bash
   drush config:get lms.lms_activity_type.free_text_with_feedback defaultMaxScore
   ```

   The machine name above is the one in use for phrase-feedback activities on
   this site. Use the actual type that the current Questions activities use
   if it differs.
7. Save. LMS creates the Question, Minimum characters, Model response heading,
   and Model response fields for that bundle. Question and Minimum characters
   reuse the existing shared field storage when another activity type already
   created it.

Then add activities of that type. Put the objection in Question, the comparison
heading in Model response heading, and the model answer HTML in Model
response. Minimum characters defaults to 50.

The question is visible to students. The heading and model response stay
hidden until a successful submission.

## Recreate existing Questions activities

An LMS activity bundle cannot be changed after the activity exists, and an
activity type's answer plugin cannot be changed either. A Questions activity
that currently uses Free Text with Feedback has to be replaced.

For each current activity:

1. Create a new activity of the Questions type.
2. Copy the name, question, and minimum characters.
3. Copy the feedback HTML that was entered in the present and absent phrase
   rows into Model response. Do not copy the catch-all phrase such as
   `the|The`.
4. Set Model response heading to `Compare your response:`, or clear it. Do
   not reuse "Well done!" or "Take another look".
5. Edit each lesson that lists the old activity. Replace that reference with
   the new activity in the same position, and keep the same max score.
6. Unpublish the old activity after no lesson still references it.

Answers already stored on the old activity stay there. This module does not
copy attempt history. If LMS is set to lock courses to the current revision,
learners who already started the course keep the previous lesson revision
until that progress is reset. Reset only the progress that should be
discarded:

```bash
drush lms:reset-course COURSE_ID USER_ID
```

## Compatibility

This change targets the LMS API present in the vendored reference code and
checked through the Drupal MCP. The current Composer lock records LMS 1.2.5.
It uses the normal LMS answer form to save, grade provisionally, and advance
progress. A form-alter callback runs after LMS submission to insert the review
page while retaining the original continuation in user-private tempstore. It
does not use `WithFeedbackPluginTrait` or modify contributed code.

The review route requires the answer owner, the matching course, this plugin,
and course `take` access. Review pages are not cached. Unsaved answers cannot
reveal model feedback. Expired continuation tempstore falls back to the course
page; refreshing a review does not save the answer or advance progress again.
Existing saved answers are also read-only when revisited.

Deploy the code, run `drush updb -y` (update `10001` refreshes the authoring
hint), then `drush cr` to register the new service and route. No field conversion
or response migration is included. The formatted-field experiment is separate.

The Question and Minimum characters fields reuse the shared
`lms_activity.question` and `lms_activity.minimum_characters` storages. Saving
the activity type does not replace those storages when they already exist. If
either storage has been changed to a different field type, creating the
activity type fails.

Uninstalling this module removes the model-response fields. Do that only after
the Questions activities that use them are gone.

## Tests

`ModelResponseEvaluatorTest` covers the score fraction, the minimum-length
count, and the neutral feedback render array. It does not boot Drupal. From a
site checkout that has PHPUnit installed:

```bash
vendor/bin/phpunit -c web/core \
  web/modules/custom/lms_free_text_model/tests/src/Unit/ModelResponseEvaluatorTest.php
```

### Manual check (staging only)

1. Confirm an existing Evidence Check still has its normal Check Answer behavior.
2. Open a new Questions activity as a learner. Submit is available; Check Answer
   is absent. Inspect the page source and confirm the model response is absent.
3. Submit a response shorter than the minimum. Confirm validation reports the
   length, no answer is saved, and no model response appears.
4. Submit a valid response to an activity with positive maximum score. Confirm
   it is saved unevaluated for teacher grading, with its provisional score, and
   the review shows the escaped student response and filtered model HTML.
5. Use Continue and verify normal navigation within a lesson, across lessons,
   and after the last activity. Refresh/back must not submit or advance again.
6. Revisit the answered activity: response is read-only and cannot be resubmitted,
   including a stale form opened in another tab. Teacher grading still works.
7. Try the review URL as a different learner, with another course ID, and for an
   unsaved/nonexistent answer. All must be denied or not found. Repeat with a
   `destination` query parameter and confirm it cannot skip the review.
8. Clear the model heading and confirm only the model body appears. Test a blank
   model response as well. Verify a course editor can reset their own test
   progress and submit afresh.
9. If anonymous course attempts are enabled, repeat in separate private browser
   sessions and confirm one session cannot read another session's answer.

`ModelResponseSubmissionTest` additionally covers the unsaved-answer gate,
overtime/no-submission behavior, and preservation of next/completion routes:

```bash
vendor/bin/phpunit -c web/core web/modules/custom/lms_free_text_model/tests/src/Unit
```
