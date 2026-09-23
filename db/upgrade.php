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
 * Upgrade steps for local_accessexpiry.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the plugin database.
 *
 * v1.0.0 is the first released version; the schema is created by install.xml,
 * so there are no upgrade steps yet. Future schema changes add guarded blocks
 * here with upgrade_plugin_savepoint().
 *
 * @param int $oldversion the version we are upgrading from.
 * @return bool
 */
function xmldb_local_accessexpiry_upgrade($oldversion) {
    global $CFG, $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026090702) {
        // The audit log was restructured to hold before/after values for precise
        // revert. It is append-only and empty at this pre-release stage, so the
        // cleanest migration is to recreate it from the new install.xml definition.
        $table = new xmldb_table('local_accessexpiry_log');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
        $dbman->install_one_table_from_xmldb_file(
            $CFG->dirroot . '/local/accessexpiry/db/install.xml', 'local_accessexpiry_log');
        upgrade_plugin_savepoint(true, 2026090702, 'local', 'accessexpiry');
    }

    // v1.0.1 (2026092300): install.xml no longer declares an empty-string DEFAULT on
    // its NOT NULL char columns. No schema upgrade is needed for existing sites —
    // Moodle already stored those columns with a NULL default at install time (it
    // rewrites '' defaults automatically), so the change only affects fresh installs.

    return true;
}
