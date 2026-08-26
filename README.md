# PlagiarismCheck.org plugin for Moodle

This is the official Moodle plagiarism plugin for
[PlagiarismCheck.org](https://plagiarismcheck.org/). It sends supported Moodle
submissions to the PlagiarismCheck.org SaaS service and displays similarity and
AI-detection scores in Moodle, with links to detailed reports.

The plugin can check:

- Assignment file submissions and online text
- Forum posts, when enabled by an administrator
- Essay responses in Quizzes, when enabled by an administrator

## Features

- Similarity checking against internet sources and the databases available in
  your PlagiarismCheck.org plan
- Optional AI-content detection
- Detailed reports with highlighted matches and links to detected sources
- Per-activity controls for excluding self-plagiarism, references, quotes, and
  sources below a chosen similarity threshold
- Separate controls for whether students can see scores and access reports
- Automatic processing through Moodle scheduled tasks
- Moodle Privacy API support

Available features and search sources may depend on your PlagiarismCheck.org
subscription.

## Requirements

- Moodle 3.9, 3.10, 3.11, 4.0, 4.1, 4.2, 4.3, 4.4, 4.5, 5.0, 5.1, or 5.2
- PHP 7.2 through 8.4, within whichever range your Moodle release supports
- A PlagiarismCheck.org institutional account and API token
- Moodle cron configured and running regularly
- Outbound HTTPS access to `plagiarismcheck.org`

The integration plugin is free to install. Use of the PlagiarismCheck.org SaaS
service requires an appropriate subscription. See
[PlagiarismCheck.org pricing](https://plagiarismcheck.org/pricing/) or contact
[support@plagiarismcheck.org](mailto:support@plagiarismcheck.org) for account
and token assistance.

## Installation

### Install from a ZIP package

1. Download the plugin ZIP from the
   [Moodle Plugins directory](https://marketplace.moodle.com/plugins/plagiarism_pchkorg).
2. In Moodle, go to **Site administration > Plugins > Install plugins**.
3. Upload the ZIP package and complete Moodle's validation and database upgrade
   steps.

### Install manually

Place the plugin source in:

```text
<moodle-root>/plagiarism/pchkorg
```

Then sign in as an administrator and visit **Site administration >
Notifications** to complete the installation.

## Configuration

1. Sign in to PlagiarismCheck.org and obtain the API token from **Profile >
   Integrations > Moodle > Connect**. If the integration is not available for
   your account, contact support.
2. In Moodle, open the **PlagiarismCheck.org plugin** settings under **Site
   administration > Plugins > Plagiarism**. On some Moodle versions, open
   **Manage plagiarism plugins** first and then follow the plugin's settings
   link.
3. Set **Enable plugin** to **Yes**, enter the API token, choose the global
   defaults, and save the changes.
4. Confirm that Moodle cron runs regularly. Submission upload and report updates
   are handled by scheduled tasks.

Administrators can also enable support for Forums and Quizzes, choose whether
new activities use the plugin by default, set a minimum source-similarity
threshold, and configure teacher auto-registration where supported by the
institutional account.

## Using the plugin

When creating or editing a supported activity, expand the
**PlagiarismCheck.org plugin** section and enable the plugin for that activity.
The activity settings let instructors configure:

- The minimum similarity percentage for sources included in the result
- Whether to exclude self-plagiarism
- Whether references and quotes are included
- Whether students can see similarity scores and open reports
- Whether AI detection is enabled

After a student submits work, Moodle queues it for processing. A similarity
score, an AI score when enabled, and a report link appear after the scheduled
tasks receive the result from PlagiarismCheck.org.

### Ignored activity templates

An administrator can enable **activity template exclusion**, which requires an
institutional API token. Instructors can then attach template material — the task
description, a rubric, a coversheet, a question sheet — to an activity, either as
uploaded files or as pasted text, while creating it or later in its settings.

Text in a student submission that matches a template is forced to count as
original and as not AI generated, so wording every student is expected to repeat
does not raise their scores. The submission itself is never altered.

Adding or deleting a template also applies to the submissions already in the
activity. Their finished checks are queued and their scores recalculated under
the templates as they now stand, over the next few runs of the scheduled task;
until then those submissions show as waiting for a result. No document is
uploaded a second time and none of the institution's allowance is used.

Managing templates requires the `plagiarism/pchkorg:manageignoretemplates`
capability, which by default is held by editing teachers, managers and course
creators, plus the custom teaching roles this plugin recognises. Administrators
can grant it to any other role, or withhold it from a role that otherwise edits
activities. Note that the section itself lives in the activity settings form,
which Moodle gates on `moodle/course:manageactivities`, so a role needs both to
change templates through the form.

Supported uploaded files are Microsoft Word (`.doc`, `.docx`), Rich Text Format
(`.rtf`), OpenDocument Text (`.odt`), plain text (`.txt`), PDF (`.pdf`),
Microsoft PowerPoint (`.ppt`, `.pptx`) and OpenDocument Presentation (`.odp`),
up to 25 MB per file.

For illustrated instructions, see the official
[administrator's guide](https://plagiarismcheck.org/blog/how-to-set-up-an-integration-in-moodle/)
and [instructor's guide](https://plagiarismcheck.org/blog/how-to-use-an-integration-in-moodle/).

## Contributing

Development documentation, including how to run the automated tests and the
static checks, is in [TESTING.md](TESTING.md).

The test suite does not contact PlagiarismCheck.org and does not need an API
token. GitHub Actions runs it against every supported Moodle release before a
change is merged.

## Privacy

Submissions enabled for checking are transmitted to PlagiarismCheck.org for
processing. Before enabling the plugin, administrators should review the
[PlagiarismCheck.org Privacy Policy](https://plagiarismcheck.org/privacy-policy/)
and [Terms and Conditions](https://plagiarismcheck.org/terms-of-service/) and
ensure that their Moodle privacy disclosures and institutional policies are
appropriate.

## Support

- Product and account support: [support@plagiarismcheck.org](mailto:support@plagiarismcheck.org)
- Moodle plugin page: [plagiarism_pchkorg](https://marketplace.moodle.com/plugins/plagiarism_pchkorg)
- Service website: [plagiarismcheck.org](https://plagiarismcheck.org/)

## License

This plugin is licensed under the [GNU General Public License v3 or
later](LICENSE).
