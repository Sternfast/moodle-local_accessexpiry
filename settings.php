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
 * Admin settings for local_accessexpiry.
 *
 * Progressive disclosure: everyday controls live on the "Settings" page; the
 * safety guardrails live one click away on a separate "Advanced" page. The
 * interactive dashboard is a capability-gated external page reachable by
 * managers without full site-config access.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_accessexpiry\local\rule;

// Category under "Users" so a holder of local/accessexpiry:manage (e.g. a Manager)
// can reach the dashboard even without full site-config access. Access to each
// child page is gated by its own capability.
$ADMIN->add('users', new admin_category('local_accessexpiry',
    get_string('pluginname', 'local_accessexpiry')));

// Interactive dashboard — capability-gated (reachable by managers). Listed first
// so it is the natural landing page for day-to-day work.
$ADMIN->add('local_accessexpiry', new admin_externalpage('local_accessexpiry_manage',
    get_string('dashboard', 'local_accessexpiry'),
    new moodle_url('/local/accessexpiry/manage.php'),
    'local/accessexpiry:manage'));

// Access-duration rules across scopes (category / course / role / enrolment method).
$ADMIN->add('local_accessexpiry', new admin_externalpage('local_accessexpiry_rules',
    get_string('rules', 'local_accessexpiry'),
    new moodle_url('/local/accessexpiry/rules.php'),
    'local/accessexpiry:manage'));

// Go live: review impact and switch out of dry-run behind a confirmation.
$ADMIN->add('local_accessexpiry', new admin_externalpage('local_accessexpiry_golive',
    get_string('golive', 'local_accessexpiry'),
    new moodle_url('/local/accessexpiry/golive.php'),
    'local/accessexpiry:manage'));

// Revert changes (undo from the audit log) — run before disabling/uninstalling.
$ADMIN->add('local_accessexpiry', new admin_externalpage('local_accessexpiry_revert',
    get_string('revert', 'local_accessexpiry'),
    new moodle_url('/local/accessexpiry/revert.php'),
    'local/accessexpiry:manage'));

// ---------------------------------------------------------------------------
// "Settings" — the everyday page. Only the controls most sites ever touch.
// ---------------------------------------------------------------------------
$settingspage = new admin_settingpage('local_accessexpiry_settings',
    get_string('settings', 'local_accessexpiry'), 'moodle/site:config');

if ($ADMIN->fulltree) {
    $settingspage->add(new admin_setting_heading('local_accessexpiry/general',
        get_string('generalheading', 'local_accessexpiry'),
        get_string('generalheading_desc', 'local_accessexpiry')));

    $settingspage->add(new admin_setting_configcheckbox('local_accessexpiry/enabled',
        get_string('enabled', 'local_accessexpiry'),
        get_string('enabled_desc', 'local_accessexpiry'), 0));

    // Live vs dry-run is not a plain toggle — it is managed on the Go-Live page,
    // which shows the impact and requires confirmation before any real change.
    $mode = get_config('local_accessexpiry', 'enabled')
        ? ((get_config('local_accessexpiry', 'dryrun') !== '0')
            ? get_string('mode_observe', 'local_accessexpiry')
            : get_string('mode_live', 'local_accessexpiry'))
        : get_string('mode_disabled', 'local_accessexpiry');
    $goliveurl = (new moodle_url('/local/accessexpiry/golive.php'))->out();
    $settingspage->add(new admin_setting_description('local_accessexpiry/modeinfo',
        get_string('mode', 'local_accessexpiry'),
        get_string('mode_desc', 'local_accessexpiry', (object)['mode' => $mode, 'url' => $goliveurl])));

    $settingspage->add(new admin_setting_configselect('local_accessexpiry/defaultpolicy',
        get_string('defaultpolicy', 'local_accessexpiry'),
        get_string('defaultpolicy_desc', 'local_accessexpiry'), 'never', [
            'never'    => get_string('defaultpolicy_never', 'local_accessexpiry'),
            'relative' => get_string('defaultpolicy_relative', 'local_accessexpiry'),
        ]));

    $settingspage->add(new admin_setting_configtext('local_accessexpiry/defaultdays',
        get_string('defaultdays', 'local_accessexpiry'),
        get_string('defaultdays_desc', 'local_accessexpiry'), 365, PARAM_INT));
    // Only relevant when the default policy expires after a number of days.
    $settingspage->hide_if('local_accessexpiry/defaultdays',
        'local_accessexpiry/defaultpolicy', 'neq', 'relative');

    $settingspage->add(new admin_setting_configselect('local_accessexpiry/defaultonexpiry',
        get_string('defaultonexpiry', 'local_accessexpiry'),
        get_string('defaultonexpiry_desc', 'local_accessexpiry'), rule::EXPIRY_SUSPEND, [
            rule::EXPIRY_UNENROL        => get_string('onexpiry_unenrol', 'local_accessexpiry'),
            rule::EXPIRY_SUSPEND        => get_string('onexpiry_suspend', 'local_accessexpiry'),
            rule::EXPIRY_SUSPENDNOROLES => get_string('onexpiry_suspendnoroles', 'local_accessexpiry'),
            rule::EXPIRY_KEEP           => get_string('onexpiry_keep', 'local_accessexpiry'),
        ]));
    // The on-expiry action only applies to direct enrolments (cohort members are
    // removed from the cohort), so hide it when the default is "never expire".
    $settingspage->hide_if('local_accessexpiry/defaultonexpiry',
        'local_accessexpiry/defaultpolicy', 'eq', 'never');
}
$ADMIN->add('local_accessexpiry', $settingspage);

// ---------------------------------------------------------------------------
// "Advanced" — safety guardrails, one click away. Defaults are safe.
// ---------------------------------------------------------------------------
$advancedpage = new admin_settingpage('local_accessexpiry_advanced',
    get_string('advancedsettings', 'local_accessexpiry'), 'moodle/site:config');

if ($ADMIN->fulltree) {
    // Role option lists + sensible archetype-based defaults (only built when rendering).
    $roleoptions = [];
    foreach (role_get_names(\context_system::instance()) as $role) {
        $roleoptions[$role->id] = $role->localname;
    }
    $studentdefault = 0;
    if ($studentroles = get_archetype_roles('student')) {
        $studentdefault = (int)reset($studentroles)->id;
    }
    $elevateddefault = [];
    foreach (['manager', 'coursecreator', 'editingteacher', 'teacher'] as $arch) {
        foreach (get_archetype_roles($arch) as $r) {
            $elevateddefault[$r->id] = $r->id;
        }
    }
    $elevateddefault = array_values($elevateddefault);

    $advancedpage->add(new admin_setting_heading('local_accessexpiry/safety',
        get_string('safetyheading', 'local_accessexpiry'),
        get_string('safetyheading_desc', 'local_accessexpiry')));

    $advancedpage->add(new admin_setting_configtext('local_accessexpiry/maxrows',
        get_string('maxrows', 'local_accessexpiry'),
        get_string('maxrows_desc', 'local_accessexpiry'), 200, PARAM_INT));

    if (!empty($roleoptions)) {
        $advancedpage->add(new admin_setting_configselect('local_accessexpiry/studentroleid',
            get_string('studentroleid', 'local_accessexpiry'),
            get_string('studentroleid_desc', 'local_accessexpiry'), $studentdefault,
            [0 => get_string('studentrole_any', 'local_accessexpiry')] + $roleoptions));

        $advancedpage->add(new admin_setting_configmultiselect('local_accessexpiry/elevatedroleids',
            get_string('elevatedroleids', 'local_accessexpiry'),
            get_string('elevatedroleids_desc', 'local_accessexpiry'), $elevateddefault, $roleoptions));
    }

    $advancedpage->add(new admin_setting_configcheckbox('local_accessexpiry/excludesiteadmins',
        get_string('excludesiteadmins', 'local_accessexpiry'),
        get_string('excludesiteadmins_desc', 'local_accessexpiry'), 1));

    $advancedpage->add(new admin_setting_configtextarea('local_accessexpiry/staffdomains',
        get_string('staffdomains', 'local_accessexpiry'),
        get_string('staffdomains_desc', 'local_accessexpiry'), '', PARAM_TEXT));

    // --- Notifications (optional, OFF by default) ---
    $advancedpage->add(new admin_setting_heading('local_accessexpiry/remindersheading',
        get_string('remindersheading', 'local_accessexpiry'),
        get_string('remindersheading_desc', 'local_accessexpiry')));

    $advancedpage->add(new admin_setting_configcheckbox('local_accessexpiry/remindersenabled',
        get_string('remindersenabled', 'local_accessexpiry'),
        get_string('remindersenabled_desc', 'local_accessexpiry'), 0));

    $advancedpage->add(new admin_setting_configtext('local_accessexpiry/reminderdays',
        get_string('reminderdays', 'local_accessexpiry'),
        get_string('reminderdays_desc', 'local_accessexpiry'), '30,14,7,1', PARAM_TEXT));
    $advancedpage->hide_if('local_accessexpiry/reminderdays', 'local_accessexpiry/remindersenabled', 'notchecked');

    $advancedpage->add(new admin_setting_configcheckbox('local_accessexpiry/remindernotifyexpired',
        get_string('remindernotifyexpired', 'local_accessexpiry'),
        get_string('remindernotifyexpired_desc', 'local_accessexpiry'), 0));
    $advancedpage->hide_if('local_accessexpiry/remindernotifyexpired', 'local_accessexpiry/remindersenabled', 'notchecked');

    $advancedpage->add(new admin_setting_configtext('local_accessexpiry/remindersubject',
        get_string('remindersubject', 'local_accessexpiry'),
        get_string('remindertokens', 'local_accessexpiry'),
        get_string('remindersubject_default', 'local_accessexpiry'), PARAM_TEXT));
    $advancedpage->hide_if('local_accessexpiry/remindersubject', 'local_accessexpiry/remindersenabled', 'notchecked');

    $advancedpage->add(new admin_setting_configtextarea('local_accessexpiry/reminderbody',
        get_string('reminderbody', 'local_accessexpiry'),
        get_string('remindertokens', 'local_accessexpiry'),
        get_string('reminderbody_default', 'local_accessexpiry'), PARAM_RAW));
    $advancedpage->hide_if('local_accessexpiry/reminderbody', 'local_accessexpiry/remindersenabled', 'notchecked');

    $lastrun = (int)get_config('local_accessexpiry', 'lastnotifyrun');
    $healthinfo = $lastrun
        ? get_string('reminderhealth', 'local_accessexpiry',
            (object)['when' => userdate($lastrun), 'count' => (int)get_config('local_accessexpiry', 'lastnotifycount')])
        : get_string('reminderhealth_never', 'local_accessexpiry');
    $advancedpage->add(new admin_setting_description('local_accessexpiry/reminderhealthinfo',
        get_string('reminderhealth_label', 'local_accessexpiry'), $healthinfo));
}
$ADMIN->add('local_accessexpiry', $advancedpage);

// We built the tree ourselves; prevent Moodle adding a default duplicate node.
$settings = null;
