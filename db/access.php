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
 * Capability definitions for local_accessexpiry.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Configure global defaults and guardrails (the "Advanced" surface). Carries
    // config + data-loss risk because it governs automatic removals site-wide.
    'local/accessexpiry:configure' => [
        'riskbitmask'          => RISK_CONFIG | RISK_DATALOSS,
        'captype'              => 'write',
        'contextlevel'         => CONTEXT_SYSTEM,
        'archetypes'           => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/site:config',
    ],

    // Manage access-duration rules at any scope and run previews/expiry. Drives
    // unenrolment / cohort removal, so it carries a data-loss risk.
    'local/accessexpiry:manage' => [
        'riskbitmask'          => RISK_DATALOSS,
        'captype'              => 'write',
        'contextlevel'         => CONTEXT_SYSTEM,
        'archetypes'           => [
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/cohort:manage',
    ],

    // Grant or revoke a single learner's access extension in a course. Delegable
    // to trainers/support staff without full course or enrolment management.
    'local/accessexpiry:grantextension' => [
        'riskbitmask'          => RISK_DATALOSS,
        'captype'              => 'write',
        'contextlevel'         => CONTEXT_COURSE,
        'archetypes'           => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'enrol/manual:manage',
    ],

    // View the "expiring soon" dashboard, reports and audit log.
    'local/accessexpiry:viewreports' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
];
