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
 * Per-user access extensions — the staff-first "extend this learner" override.
 *
 * An extension always wins over rule-resolved windows. Granting a course
 * extension is renew-safe: it re-activates a suspended enrolment (grades are
 * intact under suspend) as well as pushing out the end date. A cohort extension
 * simply shields the member from cohort removal.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_accessexpiry\local;

/**
 * Reads, writes and applies per-user extensions.
 */
class extension {

    /** @var string The extension table. */
    const TABLE = 'local_accessexpiry_extension';

    /** @var int Extension applies to a course enrolment. */
    const TARGET_COURSE = rule::SCOPE_COURSE;   // 30
    /** @var int Extension applies to a cohort membership. */
    const TARGET_COHORT = rule::SCOPE_COHORT;   // 60

    /** @var int Set a fixed end date. */
    const MODE_SET = 0;
    /** @var int Add days (to the current end, or now). */
    const MODE_ADD = 1;
    /** @var int Never expire. */
    const MODE_UNLIMITED = 2;

    /**
     * The active (not revoked) extension for a target, most recent first, or null.
     *
     * @param int $userid
     * @param int $targettype
     * @param int $targetid
     * @return \stdClass|null
     */
    public static function active_for(int $userid, int $targettype, int $targetid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records(self::TABLE,
            ['userid' => $userid, 'targettype' => $targettype, 'targetid' => $targetid, 'revoked' => 0],
            'timecreated DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    /**
     * The end date an active extension imposes: 0 = unlimited/never, a ts otherwise.
     * Null when there is no active extension.
     *
     * @param int $userid
     * @param int $targettype
     * @param int $targetid
     * @return int|null
     */
    public static function effective_end(int $userid, int $targettype, int $targetid): ?int {
        $ext = self::active_for($userid, $targettype, $targetid);
        if (!$ext) {
            return null;
        }
        if ((int)$ext->grantmode === self::MODE_UNLIMITED) {
            return 0;
        }
        return (int)$ext->enddate;
    }

    /**
     * The latest end date across a user's active direct enrolments in a course (0 if none/unlimited).
     *
     * @param int $userid
     * @param int $courseid
     * @return int
     */
    public static function current_course_end(int $userid, int $courseid): ?int {
        global $DB;
        $ends = $DB->get_fieldset_sql(
            "SELECT ue.timeend
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :c AND ue.userid = :u", ['c' => $courseid, 'u' => $userid]);
        $max = 0;
        foreach ($ends as $t) {
            if ((int)$t === 0) {
                return null; // An unlimited enrolment exists — signalled distinctly from "no end / none".
            }
            $max = max($max, (int)$t);
        }
        return $max; // 0 = no enrolments; otherwise the latest finite end.
    }

    /**
     * Grant a course extension and apply it to the learner's enrolments now.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $grantmode MODE_SET|MODE_ADD|MODE_UNLIMITED
     * @param int|null $enddate for MODE_SET
     * @param int|null $days for MODE_ADD
     * @param string $reason
     * @param int $grantedby
     * @return int extension id
     */
    public static function grant_course(int $userid, int $courseid, int $grantmode,
            ?int $enddate, ?int $days, string $reason, int $grantedby): int {
        global $DB;

        $unlimited = ($grantmode === self::MODE_UNLIMITED);
        if ($unlimited) {
            $finalend = 0;
        } else if ($grantmode === self::MODE_ADD) {
            $base = self::current_course_end($userid, $courseid);
            if ($base === null) {
                // Learner already has unlimited access — "add days" must not downgrade it.
                $unlimited = true;
                $finalend = 0;
            } else {
                $base = $base ?: time();
                $finalend = $base + (int)$days * DAYSECS;
            }
        } else {
            $finalend = (int)$enddate;
        }
        $storemode = $unlimited ? self::MODE_UNLIMITED : $grantmode;

        $id = (int)$DB->insert_record(self::TABLE, (object)[
            'userid'      => $userid,
            'targettype'  => self::TARGET_COURSE,
            'targetid'    => $courseid,
            'grantmode'   => $storemode,
            'enddate'     => $unlimited ? null : $finalend,
            'days'        => ($grantmode === self::MODE_ADD && !$unlimited) ? (int)$days : null,
            'reason'      => $reason,
            'grantedby'   => $grantedby,
            'revoked'     => 0,
            'timecreated' => time(),
        ]);

        self::apply_course_extension($userid, $courseid, $unlimited ? 0 : $finalend, $grantedby, $id, $reason, $storemode);
        return $id;
    }

    /**
     * Grant a cohort extension (shields the member from removal). No enrolment write.
     *
     * @param int $userid
     * @param int $cohortid
     * @param int $grantmode
     * @param int|null $enddate
     * @param int|null $days
     * @param string $reason
     * @param int $grantedby
     * @return int extension id
     */
    public static function grant_cohort(int $userid, int $cohortid, int $grantmode,
            ?int $enddate, ?int $days, string $reason, int $grantedby): int {
        global $DB;
        $unlimited = ($grantmode === self::MODE_UNLIMITED);
        $finalend = $unlimited ? 0 : (($grantmode === self::MODE_ADD) ? (time() + (int)$days * DAYSECS) : (int)$enddate);
        $id = (int)$DB->insert_record(self::TABLE, (object)[
            'userid'      => $userid,
            'targettype'  => self::TARGET_COHORT,
            'targetid'    => $cohortid,
            'grantmode'   => $grantmode,
            'enddate'     => $unlimited ? null : $finalend,
            'days'        => ($grantmode === self::MODE_ADD) ? (int)$days : null,
            'reason'      => $reason,
            'grantedby'   => $grantedby,
            'revoked'     => 0,
            'timecreated' => time(),
        ]);
        audit::log([
            'action'   => audit::EXTEND,
            'userid'   => $userid,
            'cohortid' => $cohortid,
            'actorid'  => $grantedby,
            'detail'   => 'cohort extension #' . $id . ($reason !== '' ? ': ' . $reason : ''),
        ]);
        return $id;
    }

    /**
     * Push the end date onto a learner's direct enrolments in a course, re-activating
     * a suspended enrolment (renew-safe). Logs one EXTEND audit row per enrolment.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $end 0 = never
     * @param int $actorid
     * @param int $extid
     * @param string $reason
     */
    public static function apply_course_extension(int $userid, int $courseid, int $end,
            int $actorid, int $extid, string $reason, int $mode = self::MODE_SET): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/enrollib.php');
        // Only touch the enrolment methods this plugin manages (never cohort/other).
        list($msql, $mparams) = $DB->get_in_or_equal(directengine::methods(), SQL_PARAMS_NAMED, 'm');
        $rows = $DB->get_records_sql(
            "SELECT ue.id, ue.status, ue.timestart, ue.timeend, e.id AS instanceid, e.enrol
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :c AND ue.userid = :u AND e.enrol $msql",
            $mparams + ['c' => $courseid, 'u' => $userid]);
        $plugins = [];
        $now = time();
        foreach ($rows as $row) {
            $cur = (int)$row->timeend;
            // Never let an "extension" shorten access.
            if ($end === 0) {
                $newend = 0;                         // Unlimited grant.
            } else if ($mode === self::MODE_SET) {
                $newend = $end;                      // Explicit date: allowed to move either way.
            } else {
                if ($cur === 0) {
                    continue;                        // Adding days must not cap an unlimited enrolment.
                }
                $newend = max($cur, $end);           // Adding days only extends.
            }
            // Renew-safe: only lift a suspension that looks like an expiry-suspension we caused.
            $oldstatus = (int)$row->status;
            $newstatus = ($oldstatus === ENROL_USER_SUSPENDED && $cur > 0 && $cur <= $now)
                ? ENROL_USER_ACTIVE : $oldstatus;
            if ($newend === $cur && $newstatus === $oldstatus) {
                continue;                            // Nothing to change.
            }
            if (!isset($plugins[$row->enrol])) {
                $plugins[$row->enrol] = enrol_get_plugin($row->enrol);
            }
            $plugin = $plugins[$row->enrol];
            $instance = $DB->get_record('enrol', ['id' => $row->instanceid]);
            if (!$plugin || !$instance) {
                continue;
            }
            $plugin->update_user_enrol($instance, $userid, $newstatus, (int)$row->timestart, $newend);
            audit::log([
                'action'     => audit::EXTEND,
                'ueid'       => (int)$row->id,
                'userid'     => $userid,
                'courseid'   => $courseid,
                'enrolid'    => (int)$row->instanceid,
                'enroltype'  => (string)$row->enrol,
                'oldtimeend' => $cur,
                'oldstatus'  => $oldstatus,
                'newtimeend' => $newend,
                'newstatus'  => $newstatus,
                'actorid'    => $actorid,
                'detail'     => 'extension #' . $extid . ($reason !== '' ? ': ' . $reason : ''),
            ]);
        }
    }

    /**
     * Revoke an extension. The next run recomputes the rule-based window.
     *
     * @param int $id
     * @param int $revokedby
     */
    public static function revoke(int $id, int $revokedby): void {
        global $DB;
        $ext = $DB->get_record(self::TABLE, ['id' => $id]);
        if (!$ext || $ext->revoked) {
            return;
        }
        $DB->update_record(self::TABLE, (object)[
            'id' => $id, 'revoked' => 1, 'revokedby' => $revokedby, 'timerevoked' => time(),
        ]);
        audit::log([
            'action'     => audit::REVOKE,
            'userid'     => (int)$ext->userid,
            'courseid'   => ($ext->targettype == self::TARGET_COURSE) ? (int)$ext->targetid : 0,
            'cohortid'   => ($ext->targettype == self::TARGET_COHORT) ? (int)$ext->targetid : 0,
            'actorid'    => $revokedby,
            'detail'     => 'revoked extension #' . $id,
        ]);
    }

    /**
     * All extensions for a user (active + revoked), newest first.
     *
     * @param int $userid
     * @return array
     */
    public static function list_for_user(int $userid): array {
        global $DB;
        return $DB->get_records(self::TABLE, ['userid' => $userid], 'timecreated DESC');
    }
}
