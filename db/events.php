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
 * Event observer registrations for local_accessexpiry.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\cohort_deleted',
        'callback'  => '\local_accessexpiry\observer::cohort_deleted',
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback'  => '\local_accessexpiry\observer::course_deleted',
    ],
    [
        'eventname' => '\core\event\course_category_deleted',
        'callback'  => '\local_accessexpiry\observer::category_deleted',
    ],
    [
        'eventname' => '\core\event\role_deleted',
        'callback'  => '\local_accessexpiry\observer::role_deleted',
    ],
    [
        'eventname' => '\core\event\user_enrolment_created',
        'callback'  => '\local_accessexpiry\observer::enrolment_created',
    ],
];
