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
 * Privacy provider for local_accessexpiry.
 *
 * The plugin's rule table holds no personal data, but the extension, log and
 * notification tables record data about individual users, so this is a full
 * provider. All such data is keyed to the affected (or acting) user and is
 * exported/erased in that user's own context.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_accessexpiry\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use context;
use context_user;

/**
 * Privacy Subsystem implementation for local_accessexpiry.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\core_userlist_provider,
        \core_privacy\local\request\plugin\provider {

    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_accessexpiry_extension', [
            'userid'      => 'privacy:metadata:extension:userid',
            'targettype'  => 'privacy:metadata:extension:targettype',
            'targetid'    => 'privacy:metadata:extension:targetid',
            'grantmode'   => 'privacy:metadata:extension:grantmode',
            'enddate'     => 'privacy:metadata:extension:enddate',
            'days'        => 'privacy:metadata:extension:days',
            'reason'      => 'privacy:metadata:extension:reason',
            'grantedby'   => 'privacy:metadata:extension:grantedby',
            'revokedby'   => 'privacy:metadata:extension:revokedby',
            'timecreated' => 'privacy:metadata:extension:timecreated',
        ], 'privacy:metadata:extension');

        $collection->add_database_table('local_accessexpiry_log', [
            'userid'      => 'privacy:metadata:log:userid',
            'action'      => 'privacy:metadata:log:action',
            'courseid'    => 'privacy:metadata:log:courseid',
            'oldtimeend'  => 'privacy:metadata:log:oldtimeend',
            'newtimeend'  => 'privacy:metadata:log:newtimeend',
            'detail'      => 'privacy:metadata:log:detail',
            'actorid'     => 'privacy:metadata:log:actorid',
            'timecreated' => 'privacy:metadata:log:timecreated',
        ], 'privacy:metadata:log');

        $collection->add_database_table('local_accessexpiry_notified', [
            'userid'       => 'privacy:metadata:notified:userid',
            'notiftype'    => 'privacy:metadata:notified:notiftype',
            'effectiveend' => 'privacy:metadata:notified:effectiveend',
            'timecreated'  => 'privacy:metadata:notified:timecreated',
        ], 'privacy:metadata:notified');

        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:core_message');

        return $collection;
    }

    /**
     * The contexts (the user's own) that hold data for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.contextlevel = :ctxuser
                   AND ctx.instanceid = :userid
                   AND (EXISTS (SELECT 1 FROM {local_accessexpiry_extension} e
                                 WHERE e.userid = :u1 OR e.grantedby = :u2 OR e.revokedby = :u3)
                     OR EXISTS (SELECT 1 FROM {local_accessexpiry_log} l
                                 WHERE l.userid = :u4 OR l.actorid = :u5)
                     OR EXISTS (SELECT 1 FROM {local_accessexpiry_notified} n
                                 WHERE n.userid = :u6))";
        $contextlist->add_from_sql($sql, [
            'ctxuser' => CONTEXT_USER, 'userid' => $userid,
            'u1' => $userid, 'u2' => $userid, 'u3' => $userid,
            'u4' => $userid, 'u5' => $userid, 'u6' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * The users who have data in a (user) context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof context_user) {
            return;
        }
        $userid = $context->instanceid;
        $has = $DB->record_exists_select('local_accessexpiry_extension',
                    'userid = ? OR grantedby = ? OR revokedby = ?', [$userid, $userid, $userid])
            || $DB->record_exists_select('local_accessexpiry_log',
                    'userid = ? OR actorid = ?', [$userid, $userid])
            || $DB->record_exists('local_accessexpiry_notified', ['userid' => $userid]);
        if ($has) {
            $userlist->add_user($userid);
        }
    }

    /**
     * Export all personal data for the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_user) {
                continue;
            }
            $userid = $context->instanceid;

            $extensions = $DB->get_records_select('local_accessexpiry_extension',
                'userid = ? OR grantedby = ? OR revokedby = ?', [$userid, $userid, $userid], 'timecreated ASC');
            if ($extensions) {
                $rows = array_map(function($r) {
                    return [
                        'userid'      => $r->userid,
                        'targettype'  => $r->targettype,
                        'targetid'    => $r->targetid,
                        'grantmode'   => $r->grantmode,
                        'enddate'     => $r->enddate ? transform::datetime($r->enddate) : null,
                        'days'        => $r->days,
                        'reason'      => $r->reason,
                        'grantedby'   => $r->grantedby,
                        'revoked'     => transform::yesno($r->revoked),
                        'revokedby'   => $r->revokedby,
                        'timecreated' => transform::datetime($r->timecreated),
                        'timerevoked' => $r->timerevoked ? transform::datetime($r->timerevoked) : null,
                    ];
                }, array_values($extensions));
                writer::with_context($context)->export_data(
                    [get_string('privacy:extensions', 'local_accessexpiry')], (object)['extensions' => $rows]);
            }

            $logs = $DB->get_records_select('local_accessexpiry_log',
                'userid = ? OR actorid = ?', [$userid, $userid], 'timecreated ASC');
            if ($logs) {
                $rows = array_map(function($r) {
                    return [
                        'userid'      => $r->userid,
                        'action'      => $r->action,
                        'courseid'    => $r->courseid,
                        'enroltype'   => $r->enroltype,
                        'oldtimeend'  => $r->oldtimeend ? transform::datetime($r->oldtimeend) : null,
                        'newtimeend'  => $r->newtimeend ? transform::datetime($r->newtimeend) : null,
                        'detail'      => $r->detail,
                        'actorid'     => $r->actorid,
                        'timecreated' => transform::datetime($r->timecreated),
                    ];
                }, array_values($logs));
                writer::with_context($context)->export_data(
                    [get_string('privacy:log', 'local_accessexpiry')], (object)['log' => $rows]);
            }

            $notified = $DB->get_records('local_accessexpiry_notified', ['userid' => $userid], 'timecreated ASC');
            if ($notified) {
                $rows = array_map(function($r) {
                    return [
                        'notiftype'    => $r->notiftype,
                        'targettype'   => $r->targettype,
                        'targetid'     => $r->targetid,
                        'effectiveend' => $r->effectiveend ? transform::datetime($r->effectiveend) : null,
                        'timecreated'  => transform::datetime($r->timecreated),
                    ];
                }, array_values($notified));
                writer::with_context($context)->export_data(
                    [get_string('privacy:notified', 'local_accessexpiry')], (object)['notified' => $rows]);
            }
        }
    }

    /**
     * Delete all data for all users in a (user) context.
     *
     * @param context $context
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        if (!$context instanceof context_user) {
            return;
        }
        self::delete_for_userid($context->instanceid);
    }

    /**
     * Delete a user's data in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_user && $context->instanceid == $userid) {
                self::delete_for_userid($userid);
            }
        }
    }

    /**
     * Delete data for the approved users in a (user) context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_user) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            if ($userid == $context->instanceid) {
                self::delete_for_userid($userid);
            }
        }
    }

    /**
     * Erase a user's personal data: delete rows where they are the subject and
     * anonymise references where they were merely the actor (keeps audit rows).
     *
     * @param int $userid
     */
    protected static function delete_for_userid(int $userid): void {
        global $DB;
        $DB->delete_records('local_accessexpiry_extension', ['userid' => $userid]);
        $DB->delete_records('local_accessexpiry_log', ['userid' => $userid]);
        $DB->delete_records('local_accessexpiry_notified', ['userid' => $userid]);
        $DB->set_field('local_accessexpiry_extension', 'grantedby', 0, ['grantedby' => $userid]);
        $DB->set_field('local_accessexpiry_extension', 'revokedby', 0, ['revokedby' => $userid]);
        $DB->set_field('local_accessexpiry_log', 'actorid', 0, ['actorid' => $userid]);
    }
}
