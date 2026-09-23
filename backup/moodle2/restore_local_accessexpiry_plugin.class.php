<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Restore handler for local_accessexpiry course-scoped data.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Sternfast
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Restores course-scoped access-expiry rules and per-user course extensions,
 * remapping the course ID (and user IDs on extensions) to the restored course.
 */
class restore_local_accessexpiry_plugin extends restore_local_plugin {

    /**
     * Define the paths this plugin restores from a course backup.
     *
     * @return restore_path_element[]
     */
    protected function define_course_plugin_structure() {
        $paths = [];
        $paths[] = new restore_path_element(
            'accessexpiry_rule', $this->get_pathfor('/accessexpiry_rules/rule'));
        $paths[] = new restore_path_element(
            'accessexpiry_extension', $this->get_pathfor('/accessexpiry_extensions/extension'));
        return $paths;
    }

    /**
     * Restore one course-scoped rule into the target course.
     *
     * @param array|object $data
     */
    public function process_accessexpiry_rule($data) {
        global $DB;
        $data = (object) $data;
        $data->scopeid = $this->task->get_courseid();

        // Respect the unique index (scopelevel, scopeid, enrolmethod): don't duplicate.
        if ($DB->record_exists('local_accessexpiry_rule', [
            'scopelevel'  => $data->scopelevel,
            'scopeid'     => $data->scopeid,
            'enrolmethod' => $data->enrolmethod,
        ])) {
            return;
        }
        unset($data->id);
        $DB->insert_record('local_accessexpiry_rule', $data);
    }

    /**
     * Restore one course-scoped per-user extension into the target course.
     *
     * @param array|object $data
     */
    public function process_accessexpiry_extension($data) {
        global $DB;
        $data = (object) $data;
        $data->targetid = $this->task->get_courseid();

        // Map the learner; skip the row if that user was not restored.
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (empty($data->userid)) {
            return;
        }

        // Map staff references where possible; fall back safely.
        if (!empty($data->grantedby)) {
            $mapped = $this->get_mappingid('user', $data->grantedby);
            $data->grantedby = $mapped ?: 0;
        }
        if (!empty($data->revokedby)) {
            $mapped = $this->get_mappingid('user', $data->revokedby);
            $data->revokedby = $mapped ?: null;
        }

        unset($data->id);
        $DB->insert_record('local_accessexpiry_extension', $data);
    }
}
