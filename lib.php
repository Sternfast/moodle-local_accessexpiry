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
 * Library callbacks for local_accessexpiry.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add an "Access & extensions" link to a course for staff who may grant extensions.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 */
function local_accessexpiry_extend_navigation_course(navigation_node $navigation, stdClass $course,
        context_course $context) {
    if (has_capability('local/accessexpiry:grantextension', $context)) {
        $navigation->add(
            get_string('extend_title', 'local_accessexpiry'),
            new moodle_url('/local/accessexpiry/extend.php', ['courseid' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_accessexpiry_extend',
            new pix_icon('t/hide', '')
        );
    }
}
