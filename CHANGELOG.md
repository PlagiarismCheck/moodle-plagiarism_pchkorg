# Changelog

All notable changes to `plagiarism_pchkorg`, most recent first.

## v3.20.0 — 11 September 2026

- Submissions now carry the **course fullname** as well as the course ID, so
  PlagiarismCheck.org group reports name the course instead of showing a bare
  number. Both send paths carry it, institutional and personal tokens alike.
- The name is read from Moodle's course cache and looked up once per course per
  run. A course whose name cannot be read is sent without one, and the
  submission is checked exactly as before.
- **Requires the matching service-side release**, which adds the `course_name`
  field. Sending it to an older service is harmless — the field is ignored —
  and an older plugin against the newer service simply records no course name.

## v3.19.0 — 10 September 2026

- With **"Limit report access to a teacher's own courses"** turned on, people
  are now registered with PlagiarismCheck.org under their Moodle username,
  namespaced per site, rather than their email address. A teacher who already
  has a personal PlagiarismCheck.org account, and two people sharing one
  address, no longer collide with one another.
- The address is still sent, in a new `additional_email` field, and is where
  PlagiarismCheck.org writes to the person. Nothing is looked up by it.
- Anyone already registered under their email address goes on being recognised
  by it, so turning the setting on does not register existing teachers a second
  time or orphan their reports. Turning it off again is not symmetric.
- A teacher whose Moodle account holds an unusable email address is now
  registerable on such a site; they simply get no delivery address.
- Nothing changes on a site that leaves the setting off. **Requires the matching
  service-side release**, which adds the `additional_email` field.

## v3.18.1 — 9 September 2026

- **Fixed:** a valid personal (non-institutional) API token was reported as
  invalid whenever plugin settings were saved. The check behind that message
  only recognises institutional tokens, so personal ones are no longer sent to
  it.
- The settings page now names the PlagiarismCheck.org account the site's token
  belongs to — the institution's name and ID, whether it is active, members or
  pages remaining, and any expiry date — so a token pasted into the wrong site
  is visible there rather than only showing up as submissions failing later. A
  disabled account or a subscription that has already expired is highlighted,
  both being reasons checks stop working while the token itself is still
  correct.
- The details are read each time the page is opened, so they reflect the
  account as it is now. The page still loads normally when
  PlagiarismCheck.org cannot be reached; it says so in place of the details.
- No service-side change is required: this uses the token validation endpoint
  the plugin already called.

## v3.18.0 — 9 September 2026

- **New site-wide setting "Limit report access to a teacher's own courses"**
  under *Site administration ▸ Plugins ▸ Plagiarism ▸ PlagiarismCheck*. Ships as
  No, so an untouched site behaves exactly as before.
- PlagiarismCheck.org stores one role per person for a whole institution, so a
  teacher can open every report the institution holds — a problem where the same
  person teaches one course and studies in another. With the new setting on, a
  teacher is instead given access one course at a time, as they open reports in
  it, and can no longer open reports from courses they do not teach.
- Access to a course lasts 48 hours and is renewed on every report opened from
  it, so somebody who stops teaching a course loses it within two days without
  an administrator having to do anything.
- The setting changes how people are registered with PlagiarismCheck.org **from
  then on**. Anyone already registered as a teacher keeps institution-wide
  access until PlagiarismCheck.org support changes their role, so a long-running
  site sees little change until its membership turns over.
- Reports from submissions made before the site recorded course information, and
  reports that did not come from Moodle, stay governed by the institution-wide
  role.
- Requires a PlagiarismCheck.org service release that supports per-course access.

## v3.17.0 — 2 September 2026

- **"Enable AI Detector" now stops the AI check from being run**, instead of only
  hiding its result. The setting is sent with the submission, and
  PlagiarismCheck.org skips the AI check for an activity where it is off. What
  Moodle displays is unchanged.
- **New site-wide setting "Enable AI Detector by default"** under *Site
  administration ▸ Plugins ▸ Plagiarism ▸ PlagiarismCheck*. Ships as Yes, so an
  untouched site behaves as before. An activity's own setting always wins.
- Known rough edge: an activity created before v3.16 may have no stored setting,
  so its AI score stays hidden until the activity is opened and saved once.

## v3.16.6 — 27 August 2026

- **Fixed: upgrading to v3.16.4 or v3.16.5 could stop with "Capability
  'plagiarism/pchkorg:manageignoretemplates' was not found!"** The upgrade step
  granted the capability before Moodle had installed it. Only sites holding one
  of the custom teaching roles were affected. Install v3.16.6 over the failed
  copy and re-run the upgrade; nothing needs cleaning up first.

## v3.16.5 — 26 August 2026

- **Fixed: on Moodle 3.9 to 4.1, attaching an ignored template reported "That
  template is already attached to this activity".** The plugin answered two form
  hooks, so every save ran twice and posted the file a second time. Pasted text
  and the five-template limit were affected the same way. A template attached
  under that error needs no cleanup — it is attached and in use. Moodle 4.2 and
  later were never affected.

## v3.16.4 — 26 August 2026

- **Fixed: changing an activity's ignored templates left its existing reports
  alone.** Adding or deleting a template now also re-checks the submissions
  already in the activity, over the next few runs of the scheduled task; until
  then they show as waiting for a result. Nothing is uploaded again and no
  allowance is used. A save that changed no templates queues nothing. Needs the
  matching service-side fix.
- **New capability `plagiarism/pchkorg:manageignoretemplates`** for adding,
  deleting and downloading templates, previously gated on
  `moodle/course:manageactivities`. Allowed by default to editing teachers,
  managers and course creators, and granted on upgrade to the custom teaching
  roles this plugin recognises by name. Deliberately not granted to non-editing
  teachers; sites that want it there can grant it.
- **Submissions now say which Moodle and which plugin version sent them**, so
  support decisions are not a guess. Neither value identifies a person or a site,
  neither is stored in Moodle, and both are listed in the plugin's privacy
  registry.

## v3.16.3 — 19 August 2026

- **Fixed: lowering or clearing "Exclude sources below X% similarity" had no
  effect.** The threshold is now stored and sent as given. **Empty** defers to
  the site-wide threshold; **0** turns source filtering off for that activity.
  An affected activity corrects itself on its next submission. Reports already
  returned keep the filtering they were produced with.
- **Fixed: saving an activity could silently discard its threshold** when the
  user's role is not allowed to edit the field.

## v3.16.2 — 18 August 2026

- **New: "Refresh results" checkbox** in the activity settings form. Tick it and
  save, and every finished check in the activity is re-read from the service by
  the scheduled task. Nothing is uploaded or checked again and no allowance is
  used. The box is never saved, so later saves do not refresh a second time.

## v3.16.1 — 6 August 2026

- **Security — upgrade recommended.** The API token was written into the page
  source of every submission widget. Reports now open through a server-side proxy
  that re-checks permissions. Sites on an institutional token should treat the
  old token as exposed.
- **New: ignored activity templates.** Attach a task description, rubric,
  coversheet or question sheet to an activity, as files or pasted text; matching
  text in submissions is excluded from the similarity and AI scores. Off by
  default; requires an institutional (`G-`) token and backend support. Up to five
  per activity, at least 20 words each. No template data is stored in Moodle, and
  existing reports keep their scores.
- **Fixed: a service outage could permanently disable teacher auto-registration.**
  The plugin now tells "the service said no" apart from "the service could not be
  asked"; an unreachable response is not cached and the task fails visibly.
- **Fixed: a long error message could lose the submission record** on a database
  in strict mode. Messages are now trimmed on character boundaries before every
  write.
- **Privacy.** Data export and deletion now work for this plugin. A table holding
  email addresses and the `message` column are now declared in the privacy
  registry. After an erasure, the teacher's similarity widget for that submission
  is empty; the copy held by PlagiarismCheck.org is not deleted.
- Wording says "activity" rather than "assignment". Internally: a 120-test
  PHPUnit suite, CI across Moodle 3.9–5.2, and the official Moodle coding style.

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
  `v3.15.10`–`v3.15.17` series.
- `v3.16.4` adds the `plagiarism/pchkorg:manageignoretemplates` capability. That
  upgrade step failed with a "Capability ... was not found" error in `v3.16.4`
  and `v3.16.5`; upgrade straight to `v3.16.6` and re-run the upgrade if one has
  already stopped that way.
- Ignored activity templates are **off by default** and need no schema change, so
  the plugin is safe to deploy before the backend that serves them.
