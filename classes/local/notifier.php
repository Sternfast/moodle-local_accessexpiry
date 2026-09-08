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
 * Builds and sends access-expiry notifications through Moodle's message system.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_accessexpiry\local;

/**
 * The notification sender. Never sends raw email — everything goes through
 * message_send() so it respects the site mailer and each user's preferences.
 */
class notifier {

    /**
     * The placeholder tokens an admin may use in the subject/body templates.
     *
     * @return string[]
     */
    public static function tokens(): array {
        return ['{fullname}', '{coursename}', '{enddate}', '{days}', '{renewurl}', '{sitename}'];
    }

    /**
     * Substitute tokens in a template string.
     *
     * @param string $template
     * @param array $values token => value
     * @return string
     */
    protected static function render(string $template, array $values): string {
        return str_replace(array_keys($values), array_values($values), $template);
    }

    /**
     * Send one notification to a learner.
     *
     * @param \stdClass $recipient full user record
     * @param \stdClass $course course record (id, fullname)
     * @param int $end effective end date (unix ts)
     * @param string $providername 'expiring' or 'expired'
     * @return int|false message id or false
     */
    public static function notify_learner(\stdClass $recipient, \stdClass $course, int $end, string $providername) {
        global $CFG, $SITE;

        $oldlang = force_current_language($recipient->lang ?? current_language());
        $datefmt = get_string('strftimedatefullshort', 'langconfig');
        $daysleft = max(0, (int)ceil(($end - time()) / DAYSECS));
        $renewurl = (new \moodle_url('/enrol/index.php', ['id' => $course->id]))->out(false);

        $values = [
            '{fullname}'   => format_string(fullname($recipient)),
            '{coursename}' => format_string($course->fullname),
            '{enddate}'    => $end ? userdate($end, $datefmt) : '-',
            '{days}'       => $daysleft,
            '{renewurl}'   => $renewurl,
            '{sitename}'   => format_string($SITE->fullname),
        ];

        if ($providername === 'expired') {
            $subjecttpl = get_config('local_accessexpiry', 'expiredsubject') ?: get_string('expiredsubject_default', 'local_accessexpiry');
            $bodytpl = get_config('local_accessexpiry', 'expiredbody') ?: get_string('expiredbody_default', 'local_accessexpiry');
        } else {
            $subjecttpl = get_config('local_accessexpiry', 'remindersubject') ?: get_string('remindersubject_default', 'local_accessexpiry');
            $bodytpl = get_config('local_accessexpiry', 'reminderbody') ?: get_string('reminderbody_default', 'local_accessexpiry');
        }

        $subject = self::render($subjecttpl, $values);
        $bodytext = self::render($bodytpl, $values);
        $html = text_to_html($bodytext, false, false, true);

        $message = new \core\message\message();
        $message->component = 'local_accessexpiry';
        $message->name = $providername;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $recipient;
        $message->subject = $subject;
        $message->fullmessage = $bodytext;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = $html;
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->courseid = (int)$course->id;
        $message->contexturl = $renewurl;
        $message->contexturlname = get_string('renewaccess', 'local_accessexpiry');
        $message->customdata = ['notiftype' => $providername, 'courseid' => (int)$course->id, 'effectiveend' => $end];

        $result = message_send($message);
        force_current_language($oldlang);
        return $result;
    }

    /**
     * Record that a notification was sent (dedupe), returning false if already recorded.
     *
     * @param int $userid
     * @param int $targettype
     * @param int $targetid
     * @param string $notiftype dedupe key incl. stage
     * @param int $effectiveend
     * @return bool true if newly recorded, false if it already existed
     */
    public static function mark_notified(int $userid, int $targettype, int $targetid,
            string $notiftype, int $effectiveend): bool {
        global $DB;
        $params = ['userid' => $userid, 'targettype' => $targettype, 'targetid' => $targetid,
            'notiftype' => $notiftype, 'effectiveend' => $effectiveend];
        if ($DB->record_exists('local_accessexpiry_notified', $params)) {
            return false;
        }
        $rec = (object)($params + ['timecreated' => time()]);
        try {
            $DB->insert_record('local_accessexpiry_notified', $rec);
        } catch (\dml_exception $e) {
            return false; // Unique-constraint race: already notified.
        }
        return true;
    }
}
