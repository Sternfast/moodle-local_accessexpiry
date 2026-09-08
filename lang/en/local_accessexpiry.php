<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Language strings for local_accessexpiry.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Access Expiry & Enrolment Duration';

// Capabilities.
$string['accessexpiry:configure'] = 'Configure global defaults and safety guardrails';
$string['accessexpiry:manage'] = 'Manage access-duration rules and run expiry';
$string['accessexpiry:grantextension'] = 'Grant or revoke a learner access extension';
$string['accessexpiry:viewreports'] = 'View the expiring-soon dashboard, reports and audit log';

// Scheduled task.
$string['taskexpirecohorts'] = 'Expire access (cohort memberships)';

$string['tasksendnotifications'] = 'Send access-expiry reminders';

// Message providers (notification preferences).
$string['messageprovider:expiring'] = 'Your course access is ending soon';
$string['messageprovider:expired'] = 'Your course access has ended';

// Notifications settings + templates.
$string['remindersheading'] = 'Reminders (optional)';
$string['remindersheading_desc'] = 'Off by default. When on, learners are warned before their access ends, through Moodle\'s own notification system — so it respects each person\'s notification preferences and your site\'s email setup.';
$string['remindersenabled'] = 'Send reminders';
$string['remindersenabled_desc'] = 'Turn on staged "your access is ending soon" reminders. Nothing is sent while this is off.';
$string['reminderdays'] = 'Remind this many days before';
$string['reminderdays_desc'] = 'Comma-separated days before expiry to remind, e.g. 30,14,7,1. Each learner gets each stage once (again only if their end date changes).';
$string['remindernotifyexpired'] = 'Also notify when access has ended';
$string['remindernotifyexpired_desc'] = 'Send a one-off "your access has ended" notice after the window passes.';
$string['remindersubject'] = 'Reminder subject';
$string['reminderbody'] = 'Reminder message';
$string['remindertokens'] = 'You can use: {fullname}, {coursename}, {enddate}, {days}, {renewurl}, {sitename}.';
$string['remindersubject_default'] = 'Your access to {coursename} ends in {days} day(s)';
$string['reminderbody_default'] = 'Hi {fullname},

Your access to {coursename} ends on {enddate} ({days} day(s) from now).

To keep your access, renew here: {renewurl}

{sitename}';
$string['expiredsubject_default'] = 'Your access to {coursename} has ended';
$string['expiredbody_default'] = 'Hi {fullname},

Your access to {coursename} ended on {enddate}.

To regain access, renew here: {renewurl}

{sitename}';
$string['renewaccess'] = 'Renew access';
$string['reminderhealth_label'] = 'Delivery';
$string['reminderhealth'] = 'Last reminder run: {$a->when} — {$a->count} sent.';
$string['reminderhealth_never'] = 'The reminder task has not run yet.';

// Privacy.
$string['privacy:extensions'] = 'Access extensions';
$string['privacy:log'] = 'Access-expiry audit log';
$string['privacy:notified'] = 'Access-expiry notifications';
$string['privacy:metadata:extension'] = 'Per-user access extensions and overrides granted through this plugin (e.g. after a purchase or renewal).';
$string['privacy:metadata:extension:userid'] = 'The user whose access was extended.';
$string['privacy:metadata:extension:targettype'] = 'Whether the extension applies to a course or a cohort.';
$string['privacy:metadata:extension:targetid'] = 'The course or cohort the extension applies to.';
$string['privacy:metadata:extension:grantmode'] = 'How the extension is applied (set a date, add days, or unlimited).';
$string['privacy:metadata:extension:enddate'] = 'The new access end date, when one is set.';
$string['privacy:metadata:extension:days'] = 'The number of days added, when adding days.';
$string['privacy:metadata:extension:reason'] = 'The reason recorded for the extension.';
$string['privacy:metadata:extension:grantedby'] = 'The staff member who granted the extension.';
$string['privacy:metadata:extension:revokedby'] = 'The staff member who revoked the extension.';
$string['privacy:metadata:extension:timecreated'] = 'When the extension was granted.';
$string['privacy:metadata:log'] = 'An audit trail of access-expiry and extension actions, with before/after values.';
$string['privacy:metadata:log:userid'] = 'The user the logged action affected.';
$string['privacy:metadata:log:action'] = 'The action taken (for example expired or extended).';
$string['privacy:metadata:log:courseid'] = 'The course the action related to.';
$string['privacy:metadata:log:oldtimeend'] = 'The access end date before the change.';
$string['privacy:metadata:log:newtimeend'] = 'The access end date after the change.';
$string['privacy:metadata:log:detail'] = 'Additional detail about the action.';
$string['privacy:metadata:log:actorid'] = 'The user who performed the action (or the system, for scheduled runs).';
$string['privacy:metadata:log:timecreated'] = 'When the action was logged.';
$string['privacy:metadata:notified'] = 'A record of which expiry notifications a user has already been sent, to avoid duplicates.';
$string['privacy:metadata:notified:userid'] = 'The user who was notified.';
$string['privacy:metadata:notified:notiftype'] = 'The kind of notification (for example expiring or expired).';
$string['privacy:metadata:notified:effectiveend'] = 'The access end date the notification was about.';
$string['privacy:metadata:notified:timecreated'] = 'When the notification was sent.';
$string['privacy:metadata:core_message'] = 'Expiry reminders sent to the user through the Moodle messaging system.';

// Settings.
$string['settings'] = 'Settings';
$string['advancedsettings'] = 'Advanced';
$string['dashboard'] = 'Dashboard';
$string['generalheading'] = 'General';
$string['generalheading_desc'] = 'The everyday controls. Safety guardrails live on the <strong>Advanced</strong> page.';
$string['safetyheading'] = 'Safety limits';
$string['safetyheading_desc'] = 'The defaults here are safe — change them only if you know why. They decide who can never be expired and how fast a backlog drains.';
$string['defaultonexpiry'] = 'When access expires';
$string['defaultonexpiry_desc'] = 'What happens to a directly-enrolled learner once their access window ends. Cohort members are always removed from the cohort (which unenrols them from the linked courses).';
$string['defaultonexpiry_help'] = 'Suspend keeps grades and restores instantly (recommended). Unenrol removes the enrolment (grades recoverable on re-enrol). Keep only reports.';
$string['onexpiry_unenrol'] = 'Unenrol from the course';
$string['onexpiry_suspend'] = 'Suspend the enrolment (keep grades, block access)';
$string['onexpiry_suspendnoroles'] = 'Suspend and remove roles';
$string['onexpiry_keep'] = 'Keep access (report only)';
$string['enabled'] = 'Enable expiry';
$string['enabled_desc'] = 'Master switch and kill switch. Off = nothing happens. Turn on after setting your policies.';
$string['dryrun'] = 'Dry run';
$string['dryrun_desc'] = 'When on, the nightly task logs exactly what it would remove but changes nothing. Ideal for the first few nights.';
$string['maxrows'] = 'Maximum removals per run';
$string['maxrows_desc'] = 'Cap per run, so a big backlog drains gradually (oldest first).';
$string['defaultpolicy'] = 'Default policy';
$string['defaultpolicy_desc'] = 'Applied to any cohort that does not have its own policy on the "Manage cohorts" page.';
$string['defaultpolicy_never'] = 'Never expire';
$string['defaultpolicy_relative'] = 'Expire after a number of days';
$string['defaultdays'] = 'Default number of days';
$string['defaultdays_desc'] = 'Used when the default policy is "Expire after a number of days" (e.g. 365 for a one-year access window).';
$string['studentroleid'] = 'Student role';
$string['studentroleid_desc'] = 'Only cohorts granting this role via cohort sync are expired. Protects cohorts wired to other roles.';
$string['studentrole_any'] = 'Any role (no restriction — not recommended)';
$string['elevatedroleids'] = 'Protected roles';
$string['elevatedroleids_desc'] = 'Anyone holding these roles anywhere is never expired (protects staff and QA in student cohorts).';
$string['staffdomains'] = 'Protected email domains';
$string['staffdomains_desc'] = 'One domain per line (e.g. example.com). Users whose email ends in one of these are never expired. Belt-and-suspenders for staff who only hold the student role.';
$string['excludesiteadmins'] = 'Never expire site administrators';
$string['excludesiteadmins_desc'] = 'Strongly recommended.';

// Manage page.
$string['managecohorts'] = 'Manage cohorts';
$string['manage_intro'] = 'Set how long each cohort keeps access. Members past their window are removed, and Moodle unenrols them from the linked courses.';
$string['col_cohort'] = 'Cohort';
$string['col_idnumber'] = 'ID number';
$string['col_members'] = 'Members';
$string['col_wired'] = 'Student-enrolled';
$string['col_policy'] = 'Expiry policy';
$string['col_value'] = 'Days / date';
$string['col_eligible'] = 'Expire now';
$string['policy_usedefault'] = 'Use site default';
$string['policy_never'] = 'Never expire';
$string['policy_relative'] = 'After a number of days';
$string['policy_absolute'] = 'On a fixed date';
$string['field_days'] = 'Days';
$string['field_date'] = 'Expiry date';
$string['effective_never'] = 'Never';
$string['effective_relative'] = 'After {$a} days';
$string['effective_absolute'] = 'On {$a}';
$string['effective_default'] = 'Site default ({$a})';
$string['wired_yes'] = 'Yes';
$string['wired_no'] = 'No';
$string['wired_no_help'] = 'This cohort is not linked to any course via a student "Cohort sync" enrolment method, so its memberships are never expired regardless of policy.';
$string['savechanges'] = 'Save policies';
$string['changessaved'] = 'Cohort expiry policies saved.';
$string['nocohorts'] = 'No cohorts exist yet. Create cohorts under Site administration > Users > Cohorts first.';
$string['disabledwarning'] = 'The nightly expiry task is currently <strong>disabled</strong>. Policies are saved but nothing will be removed until you enable it in <a href="{$a}">the settings</a>.';
$string['dryrunwarning'] = 'Dry-run mode is on: the nightly task will log what it would remove but change nothing.';
$string['status_none'] = 'None';
$string['invaliddate'] = 'Please choose an expiry date of today or later.';
$string['invaliddays'] = 'Please enter a number of days of 1 or more.';

// Status / danger zone.
$string['live_title'] = 'Live mode.';
$string['live_desc'] = 'The nightly task is enabled and dry-run is off, so eligible memberships will be removed at the next run.';
$string['unreverted_banner'] = '<strong>{$a->n} change(s) can be undone.</strong> Before uninstalling: Disable → <a href="{$a->url}">Revert</a> → Uninstall.';

// Impact preview.
$string['impact_overrides'] = '{$a->overrides} of {$a->total} cohorts have their own policy; the rest use the site default.';
$string['impact_now'] = '{$a} membership(s) are eligible to expire now.';
$string['impact_soon'] = '{$a} more become eligible within 7 days.';
$string['impact_none'] = 'Nothing is eligible to expire right now.';

// Per-row override affordances.
$string['badge_default'] = 'Site default';
$string['badge_custom'] = 'Custom policy';
$string['resetdefault'] = '↺ Reset to default';

// Teaching empty state.
$string['empty_title'] = 'No cohorts yet';
$string['empty_body'] = 'Safe by default: nothing is removed until you enable it, and dry-run previews first. Create a cohort to set your first policy.';
$string['empty_cta'] = 'Create a cohort';

// Rules page (scope-based access-duration rules).
$string['rules'] = 'Access rules';
$string['rules_intro'] = 'Duration rules for direct enrolments. Most specific wins: course → category → role → method → site default. Per-learner extensions override these; cohort policies are on the Dashboard.';
$string['addrule'] = 'Add rule';
$string['editrule'] = 'Edit rule';
$string['norules'] = 'No scope rules yet. New enrolments fall back to the site default. Add a rule to give a specific course, category, role or enrolment method its own access window.';
$string['ruledeleted'] = 'Rule deleted.';
$string['rulesaved'] = 'Rule saved.';
$string['confirmdeleterule'] = 'Delete this rule? Enrolments will fall back to the next-most-specific rule (or the site default) on the next run.';
$string['col_scope'] = 'Scope';
$string['col_inscope'] = 'Enrolments in scope';
$string['scopetype'] = 'Rule applies to';
$string['scopetype_help'] = 'Choose what this rule targets. A course rule can optionally be narrowed to a single enrolment method. A more specific rule always beats a broader one.';
$string['scope_course'] = 'Course';
$string['scope_category'] = 'Category';
$string['scope_role'] = 'Role';
$string['scope_method'] = 'Enrolment method (site-wide)';
$string['enrolmethod'] = 'Enrolment method';
$string['enrolmethod_help'] = 'Leave as "Any method" to apply the rule to every direct enrolment in the scope, or pick one method (e.g. Self enrolment) to target just that.';
$string['anymethod'] = 'Any method';
$string['methodrequired'] = 'Choose an enrolment method for a method-scope rule.';
$string['enable'] = 'Enabled';
$string['sitedefault_is'] = 'The site default is: <strong>{$a}</strong>.';
$string['changeit'] = 'Change it';

// Revert page.
$string['revert'] = 'Revert changes';
$string['revert_intro'] = 'Undo changes from the audit log. Only values still matching what the plugin set are reverted; unenrolments can\'t be. Run this before disabling or uninstalling.';
$string['revertnothing'] = 'There is nothing to revert — the plugin has made no changes, or they have all been reverted already.';
$string['revertpending'] = '{$a} recorded change(s) can be reverted.';
$string['revert_willrestore'] = 'Will restore';
$string['revert_conflict'] = 'Skip — changed since';
$string['revert_gone'] = 'Skip — enrolment gone';
$string['revert_irreversible'] = 'Skip — unenrol (not revertible)';
$string['revert_batchnote'] = 'Each run reverts up to {$a->max} changes (newest first); run again to continue.';
$string['revertnow'] = 'Revert now';
$string['revertdone'] = 'Reverted {$a->ok}; skipped {$a->conflict} changed-since, {$a->gone} gone, {$a->irreversible} unenrolments.';

// Mode indicator (Settings page).
$string['mode'] = 'Mode';
$string['mode_desc'] = 'Current mode: <strong>{$a->mode}</strong>. Live vs dry-run is set on the <a href="{$a->url}">Go-Live page</a>, which shows the impact and asks you to confirm — so nothing goes live by accident.';
$string['mode_disabled'] = 'Disabled — does nothing';
$string['mode_observe'] = 'Observe — dry-run, previews only, changes nothing';
$string['mode_live'] = 'Live — making real changes';

// Go-Live page.
$string['golive'] = 'Go live';
$string['golive_intro'] = 'Review what the next live run will change, then confirm. You\'re in dry-run now — nothing has changed. You can pause back to dry-run anytime.';
$string['golive_notenabled'] = 'The plugin is disabled, so there is nothing to go live. Enable it first on the <a href="{$a}">Settings page</a> — it starts in dry-run (Observe) mode.';
$string['golive_islive'] = 'The plugin is making real changes. To pause, switch it back to dry-run — existing changes are kept and can still be reverted.';
$string['golive_pause'] = 'Pause (return to dry-run)';
$string['golive_paused'] = 'Paused — back to dry-run. Existing changes were left as they are.';
$string['golive_now'] = 'You are now live. The next run will apply changes in capped batches; every change is logged and can be reverted.';
$string['golive_change'] = 'On the next live run';
$string['golive_count'] = 'Count';
$string['golive_cohortremovals'] = 'Cohort memberships removed';
$string['golive_windows'] = 'Access end dates set / changed';
$string['golive_expire'] = 'Enrolments suspended or unenrolled (already past their window)';
$string['golive_protected'] = 'Protected users excluded (site admins, protected roles, staff domains)';
$string['golive_total'] = 'About {$a} enrolment record(s) will change.';
$string['golive_capnote'] = 'Each run processes at most {$a->max}; a larger backlog drains over subsequent runs.';
$string['golive_ack'] = 'I have reviewed the impact above and understand this will make real changes.';
$string['golive_type'] = 'Type {$a} to confirm:';
$string['golive_confirm'] = 'Go live';
$string['golive_needack'] = 'Please tick the box to confirm you have reviewed the impact.';
$string['golive_countmismatch'] = 'That number did not match. The current impact is {$a} — type exactly that to confirm (it may have changed since you loaded the page).';

// Extend (staff-first per-learner page).
$string['extend_title'] = 'Access & extensions';
$string['extend_intro'] = 'Extend a learner\'s access. An extension overrides the automatic rules and re-activates a suspended learner — grades and work kept.';
$string['extend_noone'] = 'No one is enrolled in this course yet.';
$string['extend_access'] = 'Access ends';
$string['extend_activeext'] = 'Active extension';
$string['extend_noexpiry'] = 'No expiry';
$string['extend_expired'] = 'expired';
$string['extend_action'] = 'Extend';
$string['extend_findlearner'] = 'Find a learner…';
$string['filtercohorts'] = 'Filter cohorts by name or ID…';
$string['extend_for'] = 'Learner';
$string['extend_forheading'] = 'Extend access for {$a}';
$string['extend_current'] = 'Access ends now';
$string['extend_mode'] = 'Extend by';
$string['extend_add'] = 'Adding days';
$string['extend_set'] = 'A new end date';
$string['extend_unlimited'] = 'No expiry (unlimited)';
$string['extend_days'] = 'Days to add';
$string['extend_setdate'] = 'New access end date';
$string['extend_reason'] = 'Reason';
$string['extend_reason_help'] = 'A short note on why (e.g. "paid renewal", "illness", "manager approved"). It is recorded in the audit log with your name.';
$string['extend_grant'] = 'Extend access';
$string['extend_granted'] = 'Access extended for {$a}.';
$string['extend_revoke'] = 'Revoke';
$string['extend_revoked'] = 'Extension revoked. The learner returns to the automatic policy at the next run.';
$string['extend_confirmrevoke'] = 'Revoke this extension? The learner returns to the automatic access policy.';
$string['extend_notenrolled'] = 'That user is not enrolled in this course.';
