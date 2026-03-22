# Achievements Learning Implementation Plan

## Project context
- **Project:** Student Learning Encouragement
- **Drupal version:** 10.6.5
- **Module:** `achievements`
- **Custom sub-module:** `achievements_learning`
- **Install path:** `web/modules/custom/achievements_learning`

## Purpose of this document
This plan turns the approved product design into an implementation roadmap for the first twelve feature areas previously defined for `achievements_learning`.

The goal of v1 is to provide a serious, non-gimmicky learning encouragement system that integrates with:
- Anu LMS lesson completion
- Achievements milestone awarding
- forum topic/reply participation
- title projection to `field_learning_current_title`
- lesson completion emails to students and parents
- major milestone reward choices

This plan assumes the following approved defaults:
- parent email source: `field_parent_email` on the user
- forum counting: published student-created forum topics and `comment_forum` replies only
- section completion: all lessons/quizzes in the relevant module paragraph completed
- reward scope: reward choice applies only to major course-completion identity milestones in v1
- title priority: the approved thirteen-title priority list governs the displayed title

---

## 1. Module purpose and v1 boundaries

### Objective
Deliver a Drupal module that recognizes student progress, reinforces identity-focused milestones, and avoids game-store mechanics.

### Included in v1
1. Lesson count achievements.
2. Lesson-specific achievements.
3. Section/module-paragraph achievements.
4. Course-specific and course-count achievements.
5. Forum topic/reply achievements.
6. Title mappings and projection to `field_learning_current_title`.
7. Lesson completion emails to student and parent.
8. Reward choices for selected major milestones.
9. Points as recognition only.
10. Simple administrator configuration for rules and priorities.

### Explicitly excluded from v1
- spendable point store
- badge image/media system
- user-selectable current title
- physical reward fulfillment
- leaderboard emphasis
- heavy low-code rule builder
- full config entity UI for milestone rules

### Definition of done for this feature area
The module can be installed, configured, and used to react to the supported learning and forum events without requiring code changes for initial title and milestone mappings.

---

## 2. Achievement recognition subsystem

### Goal
Track and unlock achievements for lesson progress, section completion, course completion, and forum participation.

### Implementation tasks
1. Create a central `LearningAchievementManager` service.
2. Define a normalized rule model in configuration for the following trigger types:
   - `lesson_complete`
   - `section_complete`
   - `course_complete`
   - `lesson_count`
   - `course_count`
   - `forum_topic_count`
   - `forum_reply_count`
3. Build methods to process the following contexts:
   - lesson completion event
   - derived section completion event
   - derived course completion event
   - forum topic creation event
   - forum reply creation event
4. Invoke `achievements_unlocked()` when a rule threshold or target condition is satisfied.
5. Keep count-based progress in module-managed storage if the Achievements API does not already provide a stable storage abstraction for the needed counters.
6. Log duplicate-suppression and unlock attempts for debugging.

### Data needed
- achievement ID
- trigger type
- target entity ID or threshold
- enabled/disabled state
- optional point metadata for future admin visibility

### Risks / open implementation considerations
- Achievements module behavior must be verified for idempotent unlocking.
- Count-based forum and course metrics may require custom storage tables or state wrappers if not fully covered by Achievements helpers.

---

## 3. Title subsystem

### Goal
Automatically determine a student’s current title from unlocked title achievements and write it to `field_learning_current_title`.

### Implementation tasks
1. Create a `LearningTitleManager` service.
2. Mark which milestone rules are title-bearing rules.
3. Store a title string and numeric priority in rule configuration.
4. Implement logic to gather unlocked title achievements for a user.
5. Sort unlocked titles by configured priority.
6. Persist the highest-priority title into `field_learning_current_title`.
7. Subscribe to both achievement unlock and achievement lock/reset flows.
8. Ensure empty-state behavior clears the projected field if no titles remain.

### Dependencies
- Achievements unlock state source of truth
- user field `field_learning_current_title`
- admin-configured title priority ordering

### Acceptance criteria
- Unlocking a higher-priority title updates the displayed title automatically.
- Removing a higher-priority title falls back to the next eligible title.
- No hardcoded title-to-content mapping is required.

---

## 4. Lesson completion and milestone trigger flow

### Goal
Use Anu LMS lesson completion as the primary event that drives lesson, section, course, title, and email outcomes.

### Implementation tasks
1. Subscribe to `anu_lms.lesson_completed`.
2. Extract the user ID and lesson node ID from the event.
3. Award lesson-count achievements.
4. Evaluate lesson-specific milestone rules.
5. Resolve the lesson’s containing section/module paragraph(s).
6. Check whether that section is now complete.
7. If the section is complete, evaluate section milestone rules.
8. Resolve the lesson’s parent course.
9. Check whether the course is now complete.
10. If the course is complete:
    - award course-count progress
    - evaluate course milestone rules
    - mark reward eligibility where configured
11. Trigger title recomputation if title-bearing achievements changed.
12. Trigger student/parent lesson completion emails.

### Acceptance criteria
A single lesson completion can cascade into the right lesson, section, course, title, reward, and notification outcomes in one deterministic flow.

---

## 5. Section completion subsystem

### Goal
Treat a section as complete when all lessons/quizzes in the relevant module paragraph are complete for the student.

### Implementation tasks
1. Create `LearningSectionManager`.
2. Inspect Anu LMS content structure to identify how module paragraphs reference lessons and assessments/quizzes.
3. Build helper methods for:
   - locating section paragraphs containing a lesson
   - extracting lesson and quiz references from a section
   - determining whether all required child items are complete for a user
4. Normalize completion checks so lessons and quizzes can be compared in one API.
5. Cache expensive content graph lookups where practical.

### Dependencies
- Anu LMS paragraph structure
- assessment completion data, if quizzes are stored separately from lessons

### Acceptance criteria
Section completion only becomes true when all configured lessons/quizzes in that section are completed by the same student.

---

## 6. Course completion subsystem

### Goal
Derive course completion reliably from Anu LMS course data and use it for achievements and reward eligibility.

### Implementation tasks
1. Create `LearningCourseManager`.
2. Determine the canonical relationship between lesson nodes and course nodes in Anu LMS.
3. Determine whether Anu LMS exposes a reusable course progress service/API.
4. Implement methods to:
   - find a course from a lesson
   - determine whether a course is complete for a user
   - enumerate lessons/sections that belong to a course when needed
5. Connect course completion outcomes to:
   - course-count achievements
   - course-specific milestones
   - selected major title/reward milestones

### Acceptance criteria
When a student completes the final required item in a course, course achievements and reward eligibility are evaluated exactly once.

---

## 7. Forum participation subsystem

### Goal
Award achievements for meaningful forum participation while excluding staff/admin activity.

### Approved rule set
- count published forum topics by eligible students
- count published `comment_forum` replies by eligible students
- count nested replies
- do not count edits as new activity
- exclude teacher/admin roles

### Implementation tasks
1. Create `LearningForumManager`.
2. Hook into topic creation for `node` bundle `forum`.
3. Hook into `comment` creation for bundle `comment_forum`.
4. Reject unpublished content.
5. Reject users with excluded roles from configuration.
6. Maintain forum topic and reply counters.
7. Evaluate the configured forum milestone rules after each eligible insert.
8. Recompute titles if a title-bearing forum milestone is unlocked.

### Acceptance criteria
Student-created published topics/replies increment the right counters and trigger the right milestones, while teacher/admin activity is ignored.

---

## 8. Notification subsystem

### Goal
Send lesson completion emails to both student and parent using lesson-level configurable content.

### Data model
For `module_lesson`:
- `field_completion_email_enabled`
- `field_completion_email_subject`
- `field_completion_email_body`
- `field_parent_completion_email_subject`
- `field_parent_completion_email_body`

For user:
- `field_parent_email`

### Implementation tasks
1. Create `LearningNotificationManager`.
2. Load the completed lesson and student account on lesson completion.
3. Check whether lesson completion emails are enabled.
4. Build token replacement strategy for lesson title, student name, course title, and section title.
5. Send the student email if student email content is configured.
6. Send the parent email if a parent email exists and parent email content is configured.
7. Add safe fallback behavior when optional email fields are empty.
8. Log mail-send failures without breaking achievement processing.

### Acceptance criteria
On eligible lesson completion, the student and parent receive the correct lesson-specific email content, and missing optional configuration fails gracefully.

---

## 9. Reward-choice subsystem

### Goal
Allow one digital reward choice for selected major milestones without introducing a spendable store.

### v1 scope
- reward choices only for major course-completion identity milestones
- one claim per eligible milestone
- digital goodies only

### Implementation tasks
1. Create `LearningRewardManager`.
2. Define configuration for:
   - reward rules keyed by achievement ID
   - reward groups
   - reward items with label, description, and file/URL
3. Create persistent storage for claims.
4. Mark reward eligibility when a configured milestone is unlocked.
5. Provide a simple claim UI or route for selecting one reward.
6. Prevent duplicate claims for the same user/achievement.
7. Keep the selected digital reward accessible after claim.

### Acceptance criteria
A student who unlocks a configured reward milestone can choose exactly one reward from its group, and the claim is stored permanently.

---

## 10. Configuration model and admin UX

### Goal
Support teacher-configurable milestone/title mappings in v1 using simple structured config and an admin form instead of hardcoded PHP rules.

### v1 design choice
Use a **basic admin config form + structured config**, not a full config entity UI.

### Implementation tasks
1. Define config schema for:
   - general settings
   - title priority order
   - milestone rules
   - reward rules
   - reward items
2. Replace freeform YAML where possible with structured repeatable form elements for the most common rule fields.
3. Keep an “advanced YAML” area only if necessary for faster early implementation.
4. Validate trigger types, thresholds, target IDs, achievement IDs, and title flags.
5. Add help text explaining supported trigger types and v1 limitations.
6. Expose excluded forum roles, parent email field name, and projected title field name.

### Acceptance criteria
An administrator can change mappings and priorities without editing PHP or redeploying code.

---

## 11. Data model and storage plan

### Goal
Document the persistent state required by the module and how it should be stored.

### Required storage
1. **Drupal config**
   - milestone rules
   - reward rules
   - reward items
   - title priority list
   - excluded forum roles
   - parent/title field settings
2. **User fields**
   - `field_learning_current_title`
   - `field_parent_email`
3. **Lesson fields on `module_lesson`**
   - lesson completion email fields listed above
4. **Reward claims table**
   - user ID
   - achievement ID
   - reward item ID
   - created timestamp
5. **Optional custom progress tables** if needed for efficient counters
   - lesson count by user
   - course count by user
   - forum topic count by user
   - forum reply count by user

### Implementation guidance
- Prefer reusing Achievements storage helpers where they fit the model.
- Add custom tables only where queryability or determinism makes them necessary.
- Keep stored IDs machine-readable and stable.

---

## 12. Delivery phases and execution order

### Phase 1 — foundation
- stabilize module scaffolding
- finalize install/update strategy
- finalize config schema
- create settings UI foundation
- define interfaces for core services

### Phase 2 — lesson/course milestone engine
- implement lesson completion subscriber
- implement lesson-specific and count-based milestone evaluation
- implement section resolution and section completion checks
- implement course resolution and course completion checks

### Phase 3 — title synchronization
- connect title-bearing rules to Achievements unlock state
- implement title recomputation and projection writing
- add reset/fallback behavior on achievement removal

### Phase 4 — forum milestones
- implement eligible forum topic/reply counting
- add forum milestone rule evaluation
- add title recomputation hooks for forum-earned titles

### Phase 5 — notifications
- implement lesson email field reading
- implement student and parent mail sending
- add token replacement and fallback handling

### Phase 6 — reward choices
- implement reward eligibility evaluation
- implement reward item retrieval and claim persistence
- add simple reward selection/access UX

### Phase 7 — polish, tests, and rollout readiness
- kernel/unit coverage for services and config validation
- integration tests for lesson completion and forum insert flows
- admin UX cleanup
- operational logging and failure handling review
- release notes and site-builder handoff documentation

---

## Cross-cutting technical notes

### Testing strategy
Minimum automated coverage for v1 should include:
- config schema validation
- settings form validation
- lesson completion subscriber behavior
- forum eligibility logic
- reward duplicate-claim protection
- title priority resolution

### Migration / deployment notes
- installation should create fields only when absent
- updates should avoid overwriting site-specific configuration
- default config should seed the approved title order and baseline milestone thresholds

### Recommended immediate next step
Before extending code further, align the existing scaffold to this document and implement Phase 2 from this plan rather than adding more placeholder services.
