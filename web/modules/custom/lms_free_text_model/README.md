# LMS Free Text Model Response

Adds the activity answer plugin `free_text_model_response` ("Free text with
model response") for questions that cannot be graded by phrase matching.

Evidence Check activities stay on the LMS `free_text_feedback` plugin. This
module does not change that plugin, its fields, or its feedback headings.

## Behavior

- The student types one answer. The only check is the activity's minimum
  character count, using the same counting rules as Free Text with Feedback.
  Set minimum characters to 0 to accept any non-empty answer.
- Check Answer does not look for phrases. When the answer is long enough, the
  activity always shows the Model response field.
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
- Check Answer uses the minimum length to reveal the model response. Submitted
  answers require teacher grading. LMS initially stores the activity's maximum
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
hidden until Check Answer.

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

This plugin is written for the LMS code in this repository. `lms.info.yml`
reports 1.1.18. `composer.lock` records `drupal/lms` 1.2.3. The plugin API it
uses is in both of those tags: the `ActivityAnswer` attribute,
`ActivityAnswerBase::getScore()` returning a fraction from 0 to 1,
`WithFeedbackPluginTrait`, and installation of
`config/activity_answer/{plugin_id}` when a new activity type is saved.

It depends on `lms_answer_plugins` for the Check Answer form. A later LMS
release that changes the private `buildFeedbackRenderArray()` or
`addAnswerClassesToForm()` methods on that trait, or stops installing plugin
config from that directory, needs a matching update here. The contrib LMS
module is not patched.

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

### Manual check

1. Leave an existing Evidence Check on Free Text with Feedback. A matching
   phrase still shows the overridden "Well done!" heading, and a missing
   phrase still shows "Take another look".
2. Open a new Questions activity as a student. The model response is not
   visible, and Submit stays hidden until Check Answer succeeds.
3. Check an answer shorter than the minimum. The form reports the length and
   does not reveal the model response.
4. Check a longer answer that contains none of the old catch-all words. The
   page shows the configured heading and the model HTML, in the normal text
   color, without `correct-answer` or `wrong-answer`.
5. Submit an activity with a positive maximum score. The answer remains
   unevaluated pending teacher grading, and the lesson can continue. Its initial
   score equals the activity max score provisionally.
6. Clear the heading and check again. The model response remains and no
   heading is rendered.
7. Open the answer in the teacher evaluation view. The student text is
   escaped, and the model response is shown with the same heading.
