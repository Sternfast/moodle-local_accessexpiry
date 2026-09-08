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
 * Scheduled task: send staged access-expiry reminders (optional, off by default).
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_accessexpiry\task;

use local_accessexpiry\local\notifier;
use local_accessexpiry\local\rule;
use local_accessexpiry\local\directengine;

/**
 * Emits "access ending soon" reminders (and optionally an "ended" notice) for
 * direct enrolments, staged by configurable thresholds and deduped per stage.
 */
class send_notifications extends \core\task\scheduled_task {

    /**
     * @return string
     */
    public function get_name() {
        return get_string('tasksendnotifications', 'local_accessexpiry');
    }

    /**
     * Run the notification pass.
     */
    public function execute() {
        global $DB, $CFG;
        require_once($CFG->libdir . '/enrollib.php');

        if (!get_config('local_accessexpiry', 'enabled') || !get_config('local_accessexpiry', 'remindersenabled')) {
            mtrace('local_accessexpiry: reminders are off. Nothing to send.');
            return;
        }
        $thresholds = self::thresholds();
        if (empty($thresholds)) {
            mtrace('local_accessexpiry: no reminder thresholds configured.');
            return;
        }
        $max = max($thresholds);
        $now = time();
        $notifyexpired = (bool)get_config('local_accessexpiry', 'remindernotifyexpired');
        list($msql, $mparams) = $DB->get_in_or_equal(directengine::methods(), SQL_PARAMS_NAMED, 'm');
        $sent = 0;

        // --- Expiring soon: one reminder per threshold band, per effective end. ---
        $rs = $DB->get_recordset_sql(
            "SELECT ue.id, ue.userid, ue.timeend, e.courseid, u.email
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {user} u ON u.id = ue.userid
              WHERE e.enrol $msql AND e.status = 0 AND ue.status = 0 AND u.deleted = 0
                AND ue.timeend > :n AND ue.timeend <= :nmax",
            $mparams + ['n' => $now, 'nmax' => $now + $max * DAYSECS]);
        foreach ($rs as $row) {
            if (directengine::is_protected((int)$row->userid, (string)$row->email)) {
                continue;
            }
            $end = (int)$row->timeend;
            $daysleft = max(1, (int)ceil(($end - $now) / DAYSECS));
            $stage = null;
            foreach ($thresholds as $t) {
                if ($daysleft <= $t) {
                    $stage = $t;
                    break;
                }
            }
            if ($stage === null) {
                continue;
            }
            if (!notifier::mark_notified((int)$row->userid, rule::SCOPE_COURSE, (int)$row->courseid, 'exp' . $stage, $end)) {
                continue;
            }
            $recipient = \core_user::get_user((int)$row->userid);
            $course = get_course((int)$row->courseid);
            if ($recipient && $course) {
                notifier::notify_learner($recipient, $course, $end, 'expiring');
                $sent++;
            }
        }
        $rs->close();

        // --- Optional "access ended" notice, once per effective end. ---
        if ($notifyexpired) {
            $rs = $DB->get_recordset_sql(
                "SELECT ue.id, ue.userid, ue.timeend, e.courseid, u.email
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                   JOIN {user} u ON u.id = ue.userid
                  WHERE e.enrol $msql AND e.status = 0 AND u.deleted = 0
                    AND ue.timeend > :lo AND ue.timeend <= :hi",
                $mparams + ['lo' => $now - $max * DAYSECS, 'hi' => $now]);
            foreach ($rs as $row) {
                if (directengine::is_protected((int)$row->userid, (string)$row->email)) {
                    continue;
                }
                $end = (int)$row->timeend;
                if (!notifier::mark_notified((int)$row->userid, rule::SCOPE_COURSE, (int)$row->courseid, 'expired', $end)) {
                    continue;
                }
                $recipient = \core_user::get_user((int)$row->userid);
                $course = get_course((int)$row->courseid);
                if ($recipient && $course) {
                    notifier::notify_learner($recipient, $course, $end, 'expired');
                    $sent++;
                }
            }
            $rs->close();
        }

        set_config('lastnotifyrun', $now, 'local_accessexpiry');
        set_config('lastnotifycount', $sent, 'local_accessexpiry');
        mtrace("local_accessexpiry: notifications sent = {$sent}");
    }

    /**
     * Parse the configured reminder thresholds (days), ascending, unique, positive.
     *
     * @return int[]
     */
    public static function thresholds(): array {
        $raw = (string)get_config('local_accessexpiry', 'reminderdays');
        $days = array_filter(array_map('intval', preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY)),
            function($n) {
                return $n > 0;
            });
        $days = array_values(array_unique($days));
        sort($days);
        return $days;
    }
}
