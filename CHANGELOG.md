# Changelog

All notable changes to `plagiarism_pchkorg`, most recent first.

## v3.16.3 — 19 August 2026

**Fixed: lowering or clearing "Exclude sources below X% similarity" had no
effect.** Setting the threshold to 0 to stop filtering left the previous value
in force, and every later submission to that activity was still filtered by it.
The plugin treated 0 as "nothing configured", discarded the setting instead of
storing it, and then left the value out of the submission entirely — and
PlagiarismCheck.org keeps the last threshold it was told for an activity, so it
went on applying the old one. The threshold is now stored and sent as given,
including 0, and an activity that had already got into this state corrects
itself on its next submission with no cleanup needed.

An empty field and 0 now mean different things: **empty** defers to the
site-wide threshold, as before, while **0** turns source filtering off for that
activity whatever the site-wide setting says. Raising or lowering the threshold
between two non-zero values was never affected.

Note that a threshold applies when a submission is checked, so changing it
affects work submitted afterwards. Reports already returned keep the filtering
they were produced with; **Refresh results** re-reads the stored scores and does
not re-filter them.

**Fixed: saving an activity could silently discard its threshold.** On a site
where a role is denied *Allow changing "Exclude sources below X% similarity"*,
the field is shown disabled and so is not submitted — which the plugin read as 0
and deleted the stored value. Saving an activity now leaves a threshold that
user cannot edit exactly as it was.

## v3.16.2 — 18 August 2026

**New: refreshing results for submissions already checked.** PlagiarismCheck.org
can revise a report after Moodle has stored its scores, and Moodle was never
told, because the poll stops asking about a text once its report arrives. The
plugin's section of the activity settings form now has a **Refresh results**
checkbox: tick it, save the activity, and every finished check in it is queued
to be read from the service again, so the scheduled task picks up the current
similarity and AI scores. Nothing is uploaded or checked a second time and none
of the institution's allowance is used. Available to anyone who can already
configure the plugin for the activity. Beside the box is a count of how many
submissions have a finished report, so it is clear in advance whether ticking it
will do anything.

The box is deliberately not saved with the other settings: it is unticked again
whenever the form is reopened, so later saves of the activity do not refresh
anything a second time. While a submission waits to be re-read it shows as
waiting for a result rather than showing its previous score.

## v3.16.1 — 6 August 2026

**Fixed: a service outage could permanently disable teacher auto-registration.**
`is-group-member` requests were resolved to `false`/"disabled" whenever the
service could not be reached, indistinguishable from a genuine negative answer.
The scheduled task then wrote that guess into the administrator's saved setting
on the first user it checked, and it stayed off after the outage cleared. The
plugin now tells "the service said no" apart from "the service could not be
asked": an unreachable or unparseable response no longer disables the feature,
is no longer cached, and the scheduled task now throws instead of recording a
successful run, so the failure is visible in Moodle's task log. The same
ambiguity affected `is_group_member()`, used to gate the similarity widget and
report access for group-token sites; a submission made during an outage is now
queued for a retry instead of being permanently marked as failed.

**Security — upgrade recommended.** The API token was written into the page
source of every submission widget, including for students who are not allowed to
open reports and for anyone viewing a grading table. Reports now open through a
server-side proxy that re-checks permissions before contacting the service.
Sites on an institutional token should treat the old token as exposed.

**New: ignored activity templates.** Teachers can attach a task description,
rubric, coversheet or question sheet to an activity, as files or pasted text.
Matching text in student submissions is then excluded from the similarity and AI
scores. Off by default; requires an institutional (`G-`) token and a backend that
supports the feature. Works for assignments, quizzes and forums, and can be set
while creating an activity. Up to five templates each, at least 20 words. No
template data is stored in Moodle. Existing reports keep their scores; templates
apply to submissions checked from then on.

**Privacy.** Data export and deletion requests previously did nothing for this
plugin — both now work. A table holding email addresses that was never declared
in the privacy registry is now declared, and is removed with the user's data. The
`message` column, which holds the reason a check failed and can name the
submitter's email address, is now declared too and appears in an export as
`error_message`. Erasure already removed it, because the whole row is deleted.
*Expect this:* after an erasure request the teacher's similarity widget for that
submission is empty, because the score was only ever stored there. The copy of a
submission held by PlagiarismCheck.org is not deleted; users can remove it from
their own cabinet on the service.

**Fixed: a long error message could lose the submission record.** Only the
message returned by the service was trimmed to the width of the `message` column
(130 characters); the messages the plugin builds itself were not. On a database
in strict mode an over-length value aborts the insert, so nothing about the
submission was recorded. Every write now goes through one helper, which also cuts
on character boundaries rather than bytes so a non-Latin message cannot be stored
as invalid UTF-8. A truncated message is expected and is preferable to a failed
insert.

**Also.** User-facing wording now says "activity" rather than "assignment", since
the plugin covers quizzes and forums too — no language keys were removed, so
translations keep working. Internally: a 120-test PHPUnit suite, CI across Moodle
3.9–5.2, fixes for PHP 7.2 parse errors and a wrong MIME type, and the codebase
moved to the official Moodle coding style.

**Upgrading.** No database change. Ignored templates stay off until an
administrator enables them, so the plugin is safe to deploy before the backend
that serves them.

## v3.15.21 — 12 June 2026

- More custom roles recognised for teacher auto-registration.

## v3.15.20 — 28 October 2025

- Timeouts on every curl request, so an unresponsive service cannot hang a cron
  run or a page load.

## v3.15.18 / v3.15.19 — 8 April 2025

- Group token and email are trimmed before use, so stray whitespace pasted into
  the settings form no longer breaks authentication.

## v3.15.10 – v3.15.17 — January to March 2025

- Forum replies are handled correctly.
- A forum report is hidden from students other than its author.
- Error messages are stored and shown when a text could not be checked. This
  added the `message` column to `plagiarism_pchkorg_files` (upgrade step
  `2025022717`).
- Quiz checking fixes.
- Work is no longer resent when the reason for the failure is already known.
- Scores are updated only for successful results.
- The disclosure warning message is corrected.
- An XMLDB schema fix: the `signature` field no longer declares a literal
  `DEFAULT="NULL"`.

## v3.15.8 / v3.15.9 — 16 January 2025

- The queue no longer stalls once more than 20 documents have failed, for both
  personal and group accounts.

## v3.15.7 — 26 November 2024

- Fixed saving the **Enable AI Detector** activity setting.

## Upgrading

- Requires Moodle 3.9 or later (`$plugin->requires = 2020061501`) and
  `mod_assign`.
- The only schema change in this range is the `message` column added in the
  `v3.15.10`–`v3.15.17` series; ignored activity templates need **no** schema
  change, because no template data is stored in Moodle.
- Ignored activity templates are **off by default** on upgrade, and stay hidden
  until an administrator enables the setting and the site is on an institutional
  token.
- If the backend does not yet expose the ignore-template endpoints, deploy this
  plugin anyway: with the setting off it behaves exactly as before.
