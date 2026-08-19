# LMS Discussion Prompt

Adds an LMS `discussion_prompt` activity type that displays prompt text and
sends learners to a linked `discussion` node attached to the LMS Course group.

## Behavior

- Discussion Prompt activities use the LMS `no_answer` plugin.
- The module installs a reusable `discussion` node type with comments enabled.
- The module installs a Group node relationship type so `lms_course` groups can
  contain `discussion` nodes.
- When a Discussion Prompt activity is saved or a course containing it is
  saved, the module creates one discussion node if the activity does not
  already reference one.
- Learners use the activity's `Go to discussion` link. Opening that link marks
  the activity complete and redirects to the discussion node.
- The discussion URL receives a safe `return` query parameter pointing back to
  the course.
- Linked discussion pages render a return-to-course link directly. The optional
  `Discussion Prompt return link` block can also display the return link.

