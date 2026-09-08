# Access Expiry & Enrolment Duration (local_accessexpiry)

Set how long learners keep access, and manage it all from one place.

Access Expiry lets you define an access window by site, category, course, role, enrolment method or cohort; give cohort-synced members an end date; extend an individual learner in a couple of clicks; and send reminders before access ends. It is built to be careful with a live site: it ships switched off, previews every change in dry-run first, records what it does, and can undo it.

## Features

- **Access-duration rules across scopes.** Set a window — a number of days, or a fixed date — at site, category, course, role, enrolment method or cohort level. The most specific rule applies, so a course rule overrides a category rule, which overrides the site default.
- **Access windows for cohorts.** Give cohort-synced members an end date, so cohort enrolment and time-limited access work together.
- **Durations for direct enrolments.** Applies the window to manual and self enrolments. When access ends you choose what happens: suspend (keeps grades — the default), unenrol, suspend and remove roles, or report only.
- **Per-learner extensions.** Extend one learner by a number of days or to a date, or make their access unlimited — with a reason recorded against your name. If the learner was already suspended, extending them brings them straight back with their grades and work intact. You can grant staff just this permission, without full enrolment control.
- **Reminders, if you want them.** Off by default. When you turn them on, staged “your access is ending soon” messages go out on your own schedule (for example 30, 14, 7 and 1 days before), with an editable subject and body and a renew link. They are delivered through Moodle’s notification system, so they follow each person’s notification preferences and your site’s mail settings.
- **A safe rollout.** The plugin ships disabled and in dry-run. The Go-Live page shows exactly how many enrolments a real run will change and asks you to type that number to confirm. Site administrators and protected roles are never affected, each run is capped, every change is written to an audit log, and a Revert tool can restore prior access.

## Requirements

- Moodle 4.5 (LTS) or later.
- No external services. Works on MySQL/MariaDB and PostgreSQL.

## Installation

1. Copy the plugin to `local/accessexpiry`, or install the ZIP from *Site administration → Plugins → Install plugins*. The folder must be named `accessexpiry`.
2. Visit *Site administration → Notifications* to complete the upgrade.
3. Open it at *Site administration → Users → Access Expiry & Enrolment Duration*.

## Getting started

1. **Settings** — choose the site default (for example, expire after 365 days) and what happens when access ends. Suspend is recommended, because it keeps grades.
2. **Access rules** — add a rule for a specific course, category, role or enrolment method where you need a different window. **Dashboard** — set a policy per cohort.
3. **Enable** the plugin. It begins in dry-run (Observe), which previews the impact without changing anything.
4. **Go live** when you are ready. The Go-Live page shows the exact numbers and asks you to confirm. Testing on one cohort or course first is a good idea.
5. To extend a learner, open the course and use **Access & extensions**.

## Capabilities

- `local/accessexpiry:configure` — global defaults and safety settings.
- `local/accessexpiry:manage` — manage rules, the dashboard, going live, and revert.
- `local/accessexpiry:grantextension` — grant or revoke a learner’s extension in a course.
- `local/accessexpiry:viewreports` — view reports.

## Modes and the kill switch

The plugin has three modes: **Disabled** (does nothing), **Observe** (dry-run — previews, changes nothing), and **Live** (makes changes, in capped batches, and records every one). Disable is an instant kill switch: it stops all future changes while keeping the audit log, so you can review or undo.

## Uninstalling

Uninstalling removes the plugin, not the access windows it has already set (those are ordinary enrolment data). If you want prior access restored, do it before uninstalling: **Disable → Revert changes → Uninstall.**

## Privacy

Implements the Privacy API. The rule table holds no personal data; the extension, audit-log and notification records hold per-user data, with export and deletion support. Notifications are sent through Moodle’s messaging.

## Version

v1.0.0 — access-duration rules across scopes, cohort and direct-enrolment durations, per-learner extensions, optional reminders, and a safe, auditable rollout.

## License

GNU GPL v3 or later. © 2026 Vbounds LLC.
