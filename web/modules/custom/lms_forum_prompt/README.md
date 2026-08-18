# LMS Forum Prompt

Adds an LMS `forum_prompt` activity type that displays prompt text and sends
learners to a linked Drupal Forum topic.

## Behavior

- Forum Prompt activities use the LMS `no_answer` plugin.
- The default max score is 10 points when the activity is added to a lesson.
- Each LMS course can choose a `Default forum`.
- When a Forum Prompt activity is saved or a course containing it is saved, the
  module creates one forum topic if the activity does not already reference one.
- Topic title comes from the activity name, topic body comes from the prompt,
  and topic owner comes from the activity author.
- Learners use the activity's `Go to discussion` link. Opening that link marks
  the activity complete, advances the LMS course status, and redirects to the
  forum topic.
- The forum topic URL receives a safe `return` query parameter pointing back to
  `/course/{group}/start`.

Place the `Forum Prompt return link` block on forum topic pages to show:

```text
When finished, click here to return to the lesson
```
