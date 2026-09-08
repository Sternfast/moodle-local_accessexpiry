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
 * Append-only audit log writer.
 *
 * Every real mutation the plugin makes to enrolment access is recorded here with
 * its before/after values, so it can be reviewed and precisely reverted. Dry-run
 * previews are NOT logged (they change nothing); the dashboard computes preview
 * impact live instead, which keeps the log lean and revert-focused.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_accessexpiry\local;

/**
 * Writes and groups audit records.
 */
class audit {

    /** @var string The audit table. */
    const TABLE = 'local_accessexpiry_log';

    // Actions (also the revert dispatch keys).
    /** @var string Set/changed an enrolment end date. */
    const SETEND = 'SETEND';
    /** @var string Suspended an enrolment on expiry. */
    const SUSPEND = 'SUSPEND';
    /** @var string Suspended and removed roles on expiry. */
    const SUSPENDNOROLES = 'SUSPENDNOROLES';
    /** @var string Unenrolled on expiry (not byte-revertible). */
    const UNENROL = 'UNENROL';
    /** @var string Removed a cohort membership on expiry. */
    const COHORTREMOVE = 'COHORTREMOVE';
    /** @var string Granted a per-user extension. */
    const EXTEND = 'EXTEND';
    /** @var string Revoked a per-user extension. */
    const REVOKE = 'REVOKE';
    /** @var string Went live (config transition, not a data change). */
    const GOLIVE = 'GOLIVE';
    /** @var string Reverted a prior change. */
    const REVERT = 'REVERT';

    /** @var string Current run id, grouping one pass of changes. */
    protected static $runid = '';

    /**
     * Begin a new run (a batch of related changes) and return its id.
     *
     * @return string
     */
    public static function start_run(): string {
        self::$runid = uniqid('run', true);
        return self::$runid;
    }

    /**
     * The current run id, starting one if needed.
     *
     * @return string
     */
    public static function current_run(): string {
        if (self::$runid === '') {
            self::start_run();
        }
        return self::$runid;
    }

    /**
     * Write one audit record. Missing fields take safe defaults.
     *
     * @param array $data column => value
     * @return int the new row id
     */
    public static function log(array $data): int {
        global $DB;
        $rec = (object)array_merge([
            'runid'        => self::current_run(),
            'action'       => '',
            'ueid'         => null,
            'userid'       => 0,
            'courseid'     => 0,
            'enrolid'      => 0,
            'enroltype'    => '',
            'cohortid'     => 0,
            'oldtimeend'   => null,
            'oldstatus'    => null,
            'newtimeend'   => null,
            'newstatus'    => null,
            'dryrun'       => 0,
            'actorid'      => 0,
            'reverted'     => 0,
            'timereverted' => null,
            'detail'       => '',
            'timecreated'  => time(),
        ], $data);
        return (int)$DB->insert_record(self::TABLE, $rec);
    }

    /**
     * The action types that represent a real, revertible data change.
     *
     * @return string[]
     */
    public static function change_actions(): array {
        return [self::SETEND, self::SUSPEND, self::SUSPENDNOROLES, self::UNENROL, self::COHORTREMOVE];
    }

    /**
     * Count real (non-reverted) changes recorded — for dashboard/uninstall warnings.
     *
     * @return int
     */
    public static function unreverted_count(): int {
        global $DB;
        list($insql, $params) = $DB->get_in_or_equal(self::change_actions(), SQL_PARAMS_NAMED, 'a');
        return (int)$DB->count_records_select(self::TABLE, "reverted = 0 AND action $insql", $params);
    }
}
