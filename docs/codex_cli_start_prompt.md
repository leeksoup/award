# Codex CLI continuation prompt

Copy the prompt below into Codex CLI from the repository root. Replace the
bracketed section with the latest staging output.

```text
Continue the Anu LMS to Drupal LMS migration in this repository.

Before doing anything:
1. Read AGENTS.md.
2. Read docs/codex_anu_to_lms_context.md.
3. Read the course section of docs/anu_to_lms_real_database_runbook.md.
4. Inspect git status and the latest commits. Do not assume patches from an
   earlier chat are present unless they are in the worktree/history.
5. Inspect the installed Group 3.3.x and Drupal LMS 1.1.18 code before changing
   role or access behavior.

Current gate: update 10010 now assigns the lms_teacher user role and
synchronized lms_course-teacher Group role to migrated course owners, and the
programmatic owner access audit passes. Finish browser/listing validation while
keeping Group query access enforcement enabled.

Latest staging output:
[PASTE drush updb, role audit, access audit, and browser results here]
 -------------------- ----------- --------------- -------------------------
  Module               Update ID   Type            Description
 -------------------- ----------- --------------- -------------------------
  anu_to_lms_migrate   10010       hook_update_n   10010 - Installs and
                                                   assigns the LMS teacher
                                                   role to migrated course
                                                   owners.
 -------------------- ----------- --------------- -------------------------


 // Do you wish to run the specified pending updates?: yes.

>  [notice] Update started: anu_to_lms_migrate_update_10010
>  [notice] Update completed: anu_to_lms_migrate_update_10010
 [success] Finished performing updates.
 [success] Cache rebuild complete.

$ drush config:get user.role.lms_teacher status
drush config:get group.role.lms_course-teacher status
drush config:get group.role.lms_course-teacher global_role
drush config:get group.role.lms_course-teacher permissions
'user.role.lms_teacher:status': true

'group.role.lms_course-teacher:status': true

'group.role.lms_course-teacher:global_role': lms_teacher

'group.role.lms_course-teacher:permissions':
  - 'administer members'
  - 'delete group'
  - 'delete group revisions'
  - 'edit group'
  - 'grade students'
  - 'leave group'
  - 'revert group revisions'
  - 'take course'
  - 'update own group_membership relationship'
  - 'view all group revisions'
  - 'view any unpublished group'
  - 'view group'
  - 'view group revisions'
  - 'view group_membership relationship'
  - 'view latest group version'
  - 'view own unpublished group'

Verify owner authorization
course:10 owner:1 member:yes user_teacher:yes group_roles:[lms_course-teacher] view:yes take:yes update:yes
course:11 owner:59 member:yes user_teacher:yes group_roles:[lms_course-teacher] view:yes take:yes update:yes
course:12 owner:59 member:yes user_teacher:yes group_roles:[lms_course-teacher] view:yes take:yes update:yes


Tasks:
- Validate `/admin/group`, `/admin/lms/courses`, `/group/COURSE_ID`, and
  `/course/COURSE_ID/start` with course owners and an unrelated unauthorized
  account.
- If browser or listing validation fails, identify the actual cause from code
  and config before editing. Do not disable Group access or SQL query
  rewriting.
- Make the smallest robust fix for existing migrated courses and future
  imports. Use a new monotonic update hook only if needed.
- Add or update copyable staging audits and rollback/recovery guidance.
- Run all locally available syntax, YAML, whitespace, Composer, and test-suite
  checks. Clearly separate environment limitations from passing tests.
- Update docs/codex_anu_to_lms_context.md with the verified result.
- Commit completed changes on the current branch with a focused message.

Acceptance criteria:
- Every migrated course owner is a Group member.
- Every owner receives an authorized insider role with view, take, and update
  access to that course.
- /admin/group and /admin/lms/courses show only courses allowed by normal Group
  access filtering.
- Owners can open /group/COURSE_ID and /course/COURSE_ID/start.
- An unrelated unauthorized account is denied.
- Course lesson counts and ordering remain 11, 15, and 13 with no duplicates
  or stubs.
```

After the access gate passes, start a new Codex turn with:

```text
Read AGENTS.md and docs/codex_anu_to_lms_context.md. The browser-level course
access gate now passes; update the context and migration plan to record the
evidence, then identify and implement the next smallest runnable slice from the
remaining content-parity or achievements work. Preserve the validated
migrations and Group access behavior. Show the proposed acceptance criteria
before making a broad architectural change.
```
