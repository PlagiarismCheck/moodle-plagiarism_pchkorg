# Changelog

All notable changes to `plagiarism_pchkorg`, most recent first.

## v3.17.0 — 2 September 2026

**Changed: "Enable AI Detector" now stops the AI check from being run, instead
of only hiding its result.** The activity setting has always been honoured in
Moodle — an activity with it switched off showed a similarity score alone — but
the setting never left the site. PlagiarismCheck.org was told nothing about it,
ran AI detection on every submission regardless, and the plugin quietly dropped
the answer it got back. Teachers turning it off were paying, in processing time,
for a result nobody was ever shown.

The setting now travels with the submission, like the source threshold and the
quotes, references and self-plagiarism filters already do, and
PlagiarismCheck.org skips creating the AI check for an activity where it is off.
Nothing about the plugin's own display changed: an activity with it on still
shows the AI score beside the similarity score, and an activity with it off
still shows the similarity score alone.

**Added: "Enable AI Detector by default", a site-wide setting** under *Site
administration ▸ Plugins ▸ Plagiarism ▸ PlagiarismCheck*. It is what a newly
created activity is offered, and what an activity that has never stored a choice
of its own is checked with. It ships set to Yes, so an untouched site behaves
exactly as it did. An activity that sets AI detection itself always overrides
it.

*Expect this:* nothing changes until someone switches AI detection off. Every
activity carries the setting it already had, and every one of them defaults to
on. On an activity where a teacher had already turned it off, submissions
checked from now on will have no AI result at PlagiarismCheck.org at all, where
before there was one that Moodle did not display; results already stored are
untouched.

*One rough edge:* the widget decides whether to show an AI score from the
activity's own stored setting, and an activity created before v3.16 may have
none stored. Such an activity has AI detection run for it, as it always has, but
its AI score stays hidden until the activity is opened and saved once, which
stores the setting. Saving changes nothing else.

## v3.16.6 — 27 August 2026

**Fixed: upgrading to v3.16.4 or v3.16.5 could stop with "Capability
'plagiarism/pchkorg:manageignoretemplates' was not found! This has to be fixed
in code."** The upgrade aborted there and the site stayed on the plugin version
it already had.

The capability introduced in v3.16.4 comes with an upgrade step that grants it
to the custom teaching roles this plugin recognises by name, so an assistant who
could manage templates before that release still can. Moodle registers a
plugin's capabilities from `db/access.php` in `upgrade_component_updated()`,
which runs *after* the plugin's own upgrade steps have finished — so the step
was handing out a capability Moodle had not installed yet, and
`assign_capability()` refuses by design to record a permission for a capability
it cannot find. The step now registers this plugin's capability definitions
before it grants anything.

Only sites holding at least one of those custom roles were affected. Everywhere
else the step had no role to grant anything to and never reached the refusal.
This is how every supported Moodle release orders an upgrade, 3.9 through 5.2;
the Moodle version in use made no difference to whether it happened.

*Expect this:* on an affected site, install v3.16.6 over the failed copy and run
the upgrade again. Nothing needs cleaning up first — the upgrade stopped before
it had changed anything, so the site is exactly where it was, and the grant goes
through this time. Sites that took v3.16.4 or v3.16.5 without an error are
already correct, and this release changes nothing for them.

## v3.16.5 — 26 August 2026

**Fixed: on Moodle 3.9 to 4.1, attaching an ignored template reported "That
template is already attached to this activity".** It said so for the very first
template of an activity, with nothing attached to duplicate — and the template
was in fact attached, so reopening the settings showed it there under an error
saying it had been refused.

Those Moodle versions apply a saved activity form through two hooks, an older
one Moodle 4.2 removed and the current one, and the plugin answered both. Every
save therefore ran twice. Nothing that stores a posted setting minds being
asked to store it again, which is why this never showed up anywhere else, but
the uploaded templates are not a setting: the second run posted the same file a
second time and PlagiarismCheck.org quite correctly refused it as a duplicate
of the one the first run had just attached. The plugin now answers only the
current hook, as Moodle has asked plugins to since 3.9, so a save is applied
once on every supported version.

Pasted template text was refused the same way. An activity already holding five
templates could also refuse a legitimate save as exceeding the limit, counting
the same upload twice against it. Moodle 4.2 and later, which is where this was
developed, were never affected.

*Expect this:* a template that was attached under an error message needs no
cleanup. It is attached, it is listed, and the submissions in the activity were
queued to be checked against it as they should have been.

## v3.16.4 — 26 August 2026

**Fixed: changing an activity's ignored templates left its existing reports
alone.** Templates were only ever applied at the moment a submission was
checked, so attaching a rubric to an activity that had already been marked did
nothing for the work already in it: those reports went on counting the shared
wording against students, and the only way out was to have every student submit
again.

Adding or deleting a template now also re-checks the submissions already in the
activity. Their finished checks are queued and their similarity and AI scores
recalculated under the templates as they now stand, over the next few runs of
the scheduled task; until then those submissions show as waiting for a result
instead of showing their previous score. No document is uploaded a second time
and none of the institution's allowance is used.

A save that changed no templates queues nothing, so saving an activity for some
unrelated reason does not disturb its reports. A save PlagiarismCheck.org
refused queues nothing either: the templates did not change, so the stored
reports still match the ones in force. Ticking **Fetch updated results** on the
same save as a template change is reported once rather than twice.

This needs the matching service-side fix to be deployed. Without it a re-check
of unchanged text can be answered from the earlier report, and the newly added
template is silently not applied.

**New: a capability of its own for managing ignored templates.** Adding,
deleting and downloading an activity's templates was gated on Moodle's
`moodle/course:manageactivities`, which stock Moodle grants only to editing
teachers and managers, and which no site can adjust for templates alone without
also changing who may edit activities. There is now
`plagiarism/pchkorg:manageignoretemplates`, allowed by default to **editing
teachers, managers and course creators**. A site can grant it to any other role,
or withhold it from a role that still edits activities in every other respect.

Deliberately not granted to the non-editing teacher role: a template decides
what stops counting against every student in the activity, which is a change to
how the work is marked rather than part of marking it. Sites that want it there
can grant it.

On upgrade the capability is also granted to the custom teaching roles this
plugin already recognises by name — `ta`, `hod`, `cl`, `cce`, `lib`, `led`,
`id`, `ca`, `l`, `adt1`, `adt1tii` — so an assistant who could manage templates
before this release still can. Roles built on no stock archetype are invisible
to Moodle's capability defaults, which is why they are named explicitly. A role
that already had an answer recorded for this capability keeps it, including a
deliberate refusal, and a custom role created *after* this upgrade needs the
capability granted in the usual way.

*Expect this:* the templates section is still reached through the activity
settings form, which Moodle itself gates on `moodle/course:manageactivities`. A
role granted the new capability but not that one can download templates, but
cannot open the form to change them.

**Also: submissions now say which Moodle and which plugin version sent them.**
The plugin supports Moodle 3.9 through 5.2, and until now nobody knew which of
those versions institutions were actually running — so a decision to stop
supporting an old one was a guess, and risked stranding sites. Each submission
now carries the site's major Moodle version (`5.0`, `4.5`) and this plugin's
release (`v3.16.4`) alongside it.

Neither identifies a person or a site, neither is stored in Moodle, and neither
changes how work is checked: a site that cannot report its version sends nothing
and is checked exactly as before. Both are listed in the plugin's privacy
summary, along with everything else it sends, so a data protection officer can
see them at **Site administration ▸ Users ▸ Privacy and policies ▸ Plugin
privacy registry**.

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
- `v3.16.4` adds the `plagiarism/pchkorg:manageignoretemplates` capability. Its
  upgrade step assigns it to existing custom teaching roles by shortname and
  changes no data; it is safe to re-run and does not override a permission a
  site has already set. That step failed with a "Capability ... was not found"
  coding error in `v3.16.4` and `v3.16.5`; upgrade straight to `v3.16.6`, which
  fixes it, and re-run the upgrade if one has already stopped that way.
- If the backend does not yet expose the ignore-template endpoints, deploy this
  plugin anyway: with the setting off it behaves exactly as before.
