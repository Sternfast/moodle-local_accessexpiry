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
 * Install hook for local_accessexpiry.
 *
 * Fail-closed: schema is created by install.xml; this only asserts the safe
 * initial state. It MUST NOT enable the plugin, enqueue work, or touch
 * {user_enrolments}, {cohort_members}, grades or roles.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Set the safe initial state on install.
 *
 * @return bool
 */
function xmldb_local_accessexpiry_install() {
    // Ships disabled and in dry-run: it does nothing to anyone until an admin
    // deliberately enables it and, separately, goes live from the Go-Live page.
    set_config('enabled', 0, 'local_accessexpiry');
    set_config('dryrun', 1, 'local_accessexpiry');
    return true;
}
