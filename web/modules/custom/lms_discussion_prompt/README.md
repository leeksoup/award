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
- The module installs a course-level Discussions tab at
  `/group/{group}/course-discussions` that lists published `discussion` nodes
  attached through the course's `group_node:discussion` relationship.
- The tab uses Group relationship access. Course-viewer roles need both
  `view group_node:discussion relationship` and
  `view group_node:discussion entity` for rows to appear.
- Existing linked discussion nodes are attached to their containing course by
  update `10004` if the Group relationship is missing.
