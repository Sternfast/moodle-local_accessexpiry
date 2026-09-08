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
 * Staff-first "extend a learner's access" page, scoped to one course.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_accessexpiry\local\extension;
use local_accessexpiry\form\extend_form;
use local_accessexpiry\form\learner_jump_form;

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/accessexpiry:grantextension', $context);

$url = new moodle_url('/local/accessexpiry/extend.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('extend_title', 'local_accessexpiry'));
$PAGE->set_heading($course->fullname);

$action = optional_param('action', '', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);

// Revoke an extension (must belong to this course).
if ($action === 'revoke') {
    $extid = required_param('extid', PARAM_INT);
    require_sesskey();
    $ext = $DB->get_record(extension::TABLE, ['id' => $extid], '*', MUST_EXIST);
    if ((int)$ext->targettype === extension::TARGET_COURSE && (int)$ext->targetid === $courseid) {
        extension::revoke($extid, $USER->id);
        redirect($url, get_string('extend_revoked', 'local_accessexpiry'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect($url);
}

$datefmt = get_string('strftimedatefullshort', 'langconfig');

// Grant form for one learner.
if ($userid) {
    $u = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
    // Only ever extend someone actually enrolled in this course (active or suspended).
    if (!is_enrolled($context, $userid, '', false)) {
        redirect($url, get_string('extend_notenrolled', 'local_accessexpiry'), null,
            \core\output\notification::NOTIFY_ERROR);
    }
    $curend = extension::current_course_end($userid, $courseid);
    $curlabel = $curend ? userdate($curend, $datefmt) : get_string('extend_noexpiry', 'local_accessexpiry');
    $form = new extend_form(
        new moodle_url($url, ['userid' => $userid]),
        ['username' => fullname($u), 'currentend' => $curlabel]);
    $form->set_data(['courseid' => $courseid, 'userid' => $userid]);

    if ($form->is_cancelled()) {
        redirect($url);
    } else if ($d = $form->get_data()) {
        $mode = (int)$d->grantmode;
        $enddate = ($mode === extension::MODE_SET) ? (int)$d->enddate : null;
        $days = ($mode === extension::MODE_ADD) ? (int)$d->days : null;
        extension::grant_course($userid, $courseid, $mode, $enddate, $days, trim((string)$d->reason), $USER->id);
        redirect($url, get_string('extend_granted', 'local_accessexpiry', fullname($u)), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('extend_forheading', 'local_accessexpiry', fullname($u)));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

// List enrolled learners with their current access + any active extension.
$users = get_enrolled_users($context, '', 0, 'u.*', null, 0, 0, false); // include suspended.

// "Find a learner" autocomplete (bounded to this course's enrolled users).
$jumpoptions = [];
foreach ($users as $u) {
    $jumpoptions[$u->id] = fullname($u);
}
$jump = new learner_jump_form(new moodle_url($url), ['users' => $jumpoptions]);
if ($jd = $jump->get_data()) {
    if (!empty($jd->userid)) {
        redirect(new moodle_url($url, ['userid' => (int)$jd->userid]));
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('extend_title', 'local_accessexpiry'));
echo html_writer::div(get_string('extend_intro', 'local_accessexpiry'), 'alert alert-info');

if (empty($users)) {
    echo html_writer::div(get_string('extend_noone', 'local_accessexpiry'), 'alert alert-secondary');
    echo $OUTPUT->footer();
    exit;
}

$jump->display();

$table = new html_table();
$table->head = [
    get_string('fullname'),
    get_string('extend_access', 'local_accessexpiry'),
    get_string('extend_activeext', 'local_accessexpiry'),
    '',
];
$table->attributes['class'] = 'generaltable';
$table->data = [];

foreach ($users as $u) {
    $end = extension::current_course_end($u->id, $courseid);
    $endlabel = $end
        ? userdate($end, $datefmt) . ($end < time() ? ' ' . html_writer::span(
            get_string('extend_expired', 'local_accessexpiry'), 'badge badge-danger') : '')
        : html_writer::span(get_string('extend_noexpiry', 'local_accessexpiry'), 'text-muted');

    $ext = extension::active_for($u->id, extension::TARGET_COURSE, $courseid);
    if ($ext) {
        if ((int)$ext->grantmode === extension::MODE_UNLIMITED) {
            $extlabel = get_string('extend_unlimited', 'local_accessexpiry');
        } else {
            $extlabel = userdate((int)$ext->enddate, $datefmt);
        }
        $extlabel = html_writer::span($extlabel, 'badge badge-info')
            . ' ' . html_writer::link(
                new moodle_url($url, ['action' => 'revoke', 'extid' => $ext->id, 'sesskey' => sesskey()]),
                get_string('extend_revoke', 'local_accessexpiry'),
                ['class' => 'small', 'onclick' => "return confirm('"
                    . get_string('extend_confirmrevoke', 'local_accessexpiry') . "');"]);
        if (trim((string)$ext->reason) !== '') {
            $extlabel .= html_writer::div(s($ext->reason), 'small text-muted');
        }
    } else {
        $extlabel = html_writer::span('&mdash;', 'text-muted');
    }

    $extendbtn = html_writer::link(new moodle_url($url, ['userid' => $u->id]),
        get_string('extend_action', 'local_accessexpiry'), ['class' => 'btn btn-sm btn-secondary']);

    $table->data[] = [fullname($u), $endlabel, $extlabel, $extendbtn];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
