# LMS Forum Prompt

Adds an LMS `forum_prompt` activity type that displays prompt text and sends
learners to a linked Drupal Forum topic.

## Behavior

- Forum Prompt activities use the LMS `no_answer` plugin.
- The default max score is 10 points when the activity is added to a lesson.
- Each LMS course can choose a `Default forum`.
- When a Forum Prompt activity is saved or a course containing it is saved, the
  module creates one forum topic if the activity does not already reference one.
- Topic title comes from the Forum topic field, falling back to the activity
  name, and topic body comes from the prompt.
- If the Forum topic title matches an existing forum topic exactly, that topic
  is linked. Otherwise, a new topic is created when course context is available.
- The stored topic reference field is internal; authors edit the forum topic
  title field instead.
- Learners use the activity's `Go to discussion` link. Opening that link marks
  the activity complete, advances the LMS course status, and redirects to the
  forum topic.
- The forum topic URL receives a safe `return` query parameter pointing back to
  `/course/{group}/start`.
- Linked forum topic pages render a return-to-course link directly. The
  optional block can also be placed where block-based layout control is needed.

Place the `Forum Prompt return link` block on forum topic pages to show:

```text
When finished, click here to return to the lesson
```

## Troubleshooting

After changing a lesson's activity list, existing LMS course progress for test
users can still point at the previous activity snapshot. If course playback
throws a `current activity` or `EntityViewBuilder::view()` error after editing
a lesson, reset the affected test progress:

```bash
drush lms:reset-course COURSE_ID USER_ID
```
