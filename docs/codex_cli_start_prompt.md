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
5. Inspect the installed Group 2.3 and Drupal LMS 1.1.18 code before changing
   role or access behavior.

Current gate: migrated LMS course groups and owner memberships exist. Before
update 10010, owner memberships had no effective Group roles and owners could
not view or take their courses. The repository now intends to install the
lms_teacher user role and synchronized lms_course-teacher Group role, assign
the user role to migrated owners, and keep Group query access enforcement
enabled.

Latest staging output:
[PASTE drush updb, role audit, access audit, and browser results here]

Tasks:
- Determine whether update 10010 and the synchronized Group role are correct
  for the vendored Group/LMS versions.
- If staging output shows a failure, identify the actual cause from code and
  config before editing. Do not disable Group access or SQL query rewriting.
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
Read AGENTS.md and docs/codex_anu_to_lms_context.md. The course access gate now
passes; update the context and migration plan to record the evidence, then
identify and implement the next smallest runnable slice from the remaining
content-parity or achievements work. Preserve the validated migrations and
Group access behavior. Show the proposed acceptance criteria before making a
broad architectural change.
```
