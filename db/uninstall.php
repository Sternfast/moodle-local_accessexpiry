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
 * Uninstall hook for local_accessexpiry.
 *
 * IMPORTANT lifecycle contract. This runs BEFORE core drops the plugin's tables,
 * so the audit log is still readable here. It is deliberately conservative:
 *
 *  - It does NOT drop the plugin's own tables — core's drop_plugin_tables() does
 *    that from install.xml (dropping them here would double-drop).
 *  - It does NOT remove config, capabilities, scheduled/adhoc tasks, calendar
 *    events or files — core auto-cleans all of those by component.
 *  - It does NOT revert any {user_enrolments}.timeend/.status or {cohort_members}
 *    rows. Those are real learner records this plugin does not own; reverting them
 *    during an unconfirmed, un-undoable uninstall would be dangerous and
 *    surprising. To restore prior access, run the "Revert changes" tool BEFORE
 *    uninstalling. Uninstall removes the plugin, not its effects.
 *
 * It only tidies the plugin's own stray footprint in other components' tables and
 * warns the admin (the output is captured and shown on the uninstall results page).
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Conservative uninstall cleanup. Its return value is ignored by core and it
 * cannot abort the uninstall, so it must be idempotent and defensive.
 *
 * @return bool
 */
function xmldb_local_accessexpiry_uninstall() {
    global $DB;
    $dbman = $DB->get_manager();

    // Count the changes we recorded, to warn the admin that uninstall does not undo them.
    $changes = 0;
    if ($dbman->table_exists('local_accessexpiry_log')) {
        $changes = (int)$DB->count_records('local_accessexpiry_log');
    }

    // Remove any per-user preferences this plugin created (none at present; defensive + idempotent).
    try {
        $DB->delete_records_select('user_preferences',
            $DB->sql_like('name', ':n'), ['n' => 'local_accessexpiry\_%']);
    } catch (\Throwable $e) {
        // Never let cleanup abort an uninstall.
        debugging('local_accessexpiry uninstall: user_preferences cleanup skipped: ' . $e->getMessage());
    }

    if ($changes > 0) {
        mtrace('');
        mtrace('======================================================================');
        mtrace('local_accessexpiry: ' . $changes . ' recorded access change(s) will NOT be reverted.');
        mtrace('Uninstalling removes the plugin and its audit log — not its effects on');
        mtrace('enrolment end dates / suspensions / cohort membership. If you need those');
        mtrace('restored, cancel is no longer possible: you should have run "Revert');
        mtrace('changes" (and exported the audit log) BEFORE uninstalling.');
        mtrace('======================================================================');
        mtrace('');
    }

    return true;
}
