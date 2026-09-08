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
 * Reverts changes the plugin made, using the audit log.
 *
 * Newest-first, with strict guards so a third party's later value is never
 * clobbered and a deleted enrolment is never resurrected. Restores go through
 * the enrolment API so events/gradebook stay consistent.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_accessexpiry\local;

/**
 * The "revert all plugin changes" engine.
 */
class reverter {

    /**
     * Number of change rows still awaiting revert.
     *
     * @return int
     */
    public static function pending_count(): int {
        return audit::unreverted_count();
    }

    /**
     * Process pending reverts newest-first.
     *
     * @param bool $dryrun preview only (no changes, nothing marked)
     * @param int $maxrows cap per pass
     * @param int $actorid who triggered this
     * @return array counts: ok, conflict, gone, irreversible, scanned
     */
    public static function process(bool $dryrun, int $maxrows, int $actorid = 0): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/enrollib.php');
        require_once($CFG->dirroot . '/cohort/lib.php');

        $counts = ['ok' => 0, 'conflict' => 0, 'gone' => 0, 'irreversible' => 0, 'scanned' => 0];
        list($insql, $params) = $DB->get_in_or_equal(audit::change_actions(), SQL_PARAMS_NAMED, 'a');
        $rows = $DB->get_records_select(audit::TABLE, "reverted = 0 AND action $insql",
            $params, 'timecreated DESC, id DESC', '*', 0, $maxrows);

        $plugins = [];
        foreach ($rows as $r) {
            $counts['scanned']++;
            $outcome = self::revert_row($r, $dryrun, $plugins, $actorid);
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * Attempt to revert one audit row. Returns the outcome key.
     *
     * @param \stdClass $r audit row
     * @param bool $dryrun
     * @param array $plugins enrol-plugin cache
     * @param int $actorid
     * @return string ok|conflict|gone|irreversible
     */
    protected static function revert_row(\stdClass $r, bool $dryrun, array &$plugins, int $actorid): string {
        global $DB;

        // Unenrol purges grades/attempts/groups — not byte-revertible; do not resurrect.
        if ($r->action === audit::UNENROL) {
            if (!$dryrun) {
                self::mark($r, 'irreversible', $actorid);
            }
            return 'irreversible';
        }

        // Cohort membership removal → re-add if the cohort and user still exist.
        if ($r->action === audit::COHORTREMOVE) {
            if (!$DB->record_exists('cohort', ['id' => $r->cohortid])
                    || !$DB->record_exists('user', ['id' => $r->userid, 'deleted' => 0])) {
                if (!$dryrun) {
                    self::mark($r, 'gone', $actorid);
                }
                return 'gone';
            }
            if ($DB->record_exists('cohort_members', ['cohortid' => $r->cohortid, 'userid' => $r->userid])) {
                // Already a member again — nothing to restore.
                if (!$dryrun) {
                    self::mark($r, 'ok', $actorid);
                }
                return 'ok';
            }
            if (!$dryrun) {
                cohort_add_member((int)$r->cohortid, (int)$r->userid);
                self::mark($r, 'ok', $actorid);
            }
            return 'ok';
        }

        // SETEND / SUSPEND / SUSPENDNOROLES need the enrolment + instance.
        $ue = $r->ueid ? $DB->get_record('user_enrolments', ['id' => $r->ueid]) : false;
        $instance = $r->enrolid ? $DB->get_record('enrol', ['id' => $r->enrolid]) : false;
        if (!$ue || !$instance || !$DB->record_exists('user', ['id' => $r->userid, 'deleted' => 0])) {
            if (!$dryrun) {
                self::mark($r, 'gone', $actorid);
            }
            return 'gone';
        }

        // Conflict guard: the value must still be exactly what we set.
        if ($r->action === audit::SETEND) {
            if ((int)$ue->timeend !== (int)$r->newtimeend) {
                if (!$dryrun) {
                    self::mark($r, 'conflict', $actorid);
                }
                return 'conflict';
            }
        } else {
            if ((int)$ue->status !== (int)$r->newstatus) {
                if (!$dryrun) {
                    self::mark($r, 'conflict', $actorid);
                }
                return 'conflict';
            }
        }

        if ($dryrun) {
            return 'ok';
        }

        if (!isset($plugins[$r->enroltype])) {
            $plugins[$r->enroltype] = enrol_get_plugin($r->enroltype);
        }
        $plugin = $plugins[$r->enroltype];
        if (!$plugin) {
            self::mark($r, 'gone', $actorid);
            return 'gone';
        }
        // Restore only the field this audit row actually changed, so we never clobber
        // the other field if a third party altered it since.
        if ($r->action === audit::SETEND) {
            $oldtimeend = ($r->oldtimeend === null) ? (int)$ue->timeend : (int)$r->oldtimeend;
            $plugin->update_user_enrol($instance, (int)$r->userid, null, null, $oldtimeend);
        } else {
            $oldstatus = ($r->oldstatus === null) ? (int)$ue->status : (int)$r->oldstatus;
            $plugin->update_user_enrol($instance, (int)$r->userid, $oldstatus, null, null);
        }
        self::mark($r, 'ok', $actorid);
        return 'ok';
    }

    /**
     * Mark an audit row processed and record the revert as its own audit event.
     *
     * @param \stdClass $r
     * @param string $outcome
     * @param int $actorid
     */
    protected static function mark(\stdClass $r, string $outcome, int $actorid): void {
        global $DB;
        $DB->update_record(audit::TABLE, (object)[
            'id' => $r->id, 'reverted' => 1, 'timereverted' => time(),
        ]);
        audit::log([
            'action'     => audit::REVERT,
            'ueid'       => $r->ueid,
            'userid'     => (int)$r->userid,
            'courseid'   => (int)$r->courseid,
            'enrolid'    => (int)$r->enrolid,
            'enroltype'  => (string)$r->enroltype,
            'cohortid'   => (int)$r->cohortid,
            'oldtimeend' => $r->newtimeend,
            'oldstatus'  => $r->newstatus,
            'newtimeend' => $r->oldtimeend,
            'newstatus'  => $r->oldstatus,
            'actorid'    => $actorid,
            'detail'     => 'revert:' . $outcome . ' of #' . $r->id,
        ]);
    }
}
