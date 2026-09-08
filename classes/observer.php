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
 * Event observers for local_accessexpiry.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_accessexpiry;

use local_accessexpiry\local\rule;

/**
 * Keeps rules and extensions tidy when the objects they scope to are deleted.
 */
class observer {

    /**
     * Drop a cohort's rule and any extensions targeting it.
     *
     * @param \core\event\cohort_deleted $event
     */
    public static function cohort_deleted(\core\event\cohort_deleted $event): void {
        global $DB;
        $cohortid = (int)$event->objectid;
        $DB->delete_records(rule::TABLE, ['scopelevel' => rule::SCOPE_COHORT, 'scopeid' => $cohortid]);
        $DB->delete_records('local_accessexpiry_extension',
            ['targettype' => rule::SCOPE_COHORT, 'targetid' => $cohortid]);
    }

    /**
     * Drop a course's rule and any extensions targeting it.
     *
     * @param \core\event\course_deleted $event
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;
        $courseid = (int)$event->objectid;
        $DB->delete_records(rule::TABLE, ['scopelevel' => rule::SCOPE_COURSE, 'scopeid' => $courseid]);
        $DB->delete_records('local_accessexpiry_extension',
            ['targettype' => rule::SCOPE_COURSE, 'targetid' => $courseid]);
    }

    /**
     * Drop a category's rule when the category is deleted.
     *
     * @param \core\event\course_category_deleted $event
     */
    public static function category_deleted(\core\event\course_category_deleted $event): void {
        global $DB;
        $DB->delete_records(rule::TABLE,
            ['scopelevel' => rule::SCOPE_CATEGORY, 'scopeid' => (int)$event->objectid]);
    }

    /**
     * Drop a role's rule when the role is deleted.
     *
     * @param \core\event\role_deleted $event
     */
    public static function role_deleted(\core\event\role_deleted $event): void {
        global $DB;
        $DB->delete_records(rule::TABLE,
            ['scopelevel' => rule::SCOPE_ROLE, 'scopeid' => (int)$event->objectid]);
    }

    /**
     * Queue application of the access window for a newly-created direct enrolment.
     *
     * Deferred to an ad-hoc task so the enrolment's role assignment has committed
     * before the elevated-role guardrail is evaluated (the event fires first).
     * Gated by the master switch; the task re-checks dry-run at run time.
     *
     * @param \core\event\user_enrolment_created $event
     */
    public static function enrolment_created(\core\event\user_enrolment_created $event): void {
        if (!get_config('local_accessexpiry', 'enabled') || get_config('local_accessexpiry', 'dryrun')) {
            return;
        }
        $task = new \local_accessexpiry\task\apply_new_enrolment();
        $task->set_custom_data(['ueid' => (int)$event->objectid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
