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
 * Go-Live: review the real impact and switch out of dry-run behind a
 * type-the-count confirmation. Returning to dry-run (pause) is ungated.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_accessexpiry\local\engine;
use local_accessexpiry\local\directengine;
use local_accessexpiry\local\audit;

admin_externalpage_setup('local_accessexpiry_golive');
require_capability('local/accessexpiry:manage', context_system::instance());

$url = new moodle_url('/local/accessexpiry/golive.php');
$PAGE->set_url($url);
$PAGE->set_title(get_string('golive', 'local_accessexpiry'));
$PAGE->set_heading(get_string('golive', 'local_accessexpiry'));

$enabled = (bool)get_config('local_accessexpiry', 'enabled');
$dryrun = (get_config('local_accessexpiry', 'dryrun') !== '0');
$action = optional_param('action', '', PARAM_ALPHA);

// Pause (Live -> Observe) is always allowed, no gate.
if ($action === 'pause' && confirm_sesskey()) {
    set_config('dryrun', 1, 'local_accessexpiry');
    redirect($url, get_string('golive_paused', 'local_accessexpiry'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// Compute the live blast radius (only meaningful while enabled).
$cohort = $enabled ? engine::count_eligible() : 0;
$direct = $enabled ? directengine::preview() : ['windows' => 0, 'expire' => 0, 'protected' => 0];
$affected = $cohort + $direct['windows'] + $direct['expire'];

$error = '';
if ($action === 'golive' && confirm_sesskey()) {
    $typed = (string)optional_param('confirmcount', '', PARAM_RAW_TRIMMED);
    $ack = optional_param('ack', 0, PARAM_INT);
    if (!$ack) {
        $error = get_string('golive_needack', 'local_accessexpiry');
    } else if ($typed !== (string)$affected) {
        // Stale or wrong number — recomputed $affected is shown again below.
        $error = get_string('golive_countmismatch', 'local_accessexpiry', $affected);
    } else {
        set_config('dryrun', 0, 'local_accessexpiry');
        audit::start_run();
        audit::log(['action' => audit::GOLIVE, 'actorid' => $USER->id,
            'detail' => 'affected~' . $affected]);
        redirect(new moodle_url('/local/accessexpiry/manage.php'),
            get_string('golive_now', 'local_accessexpiry'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('golive', 'local_accessexpiry'));

// Not enabled yet — nothing to go live.
if (!$enabled) {
    $surl = (new moodle_url('/admin/settings.php', ['section' => 'local_accessexpiry_settings']))->out();
    echo html_writer::div(get_string('golive_notenabled', 'local_accessexpiry', $surl), 'alert alert-warning');
    echo $OUTPUT->footer();
    exit;
}

// Already live — offer the pause.
if (!$dryrun) {
    echo html_writer::div(
        html_writer::tag('strong', get_string('mode_live', 'local_accessexpiry')) . ' '
        . get_string('golive_islive', 'local_accessexpiry'),
        'alert alert-danger');
    echo $OUTPUT->single_button(new moodle_url($url, ['action' => 'pause', 'sesskey' => sesskey()]),
        get_string('golive_pause', 'local_accessexpiry'), 'post');
    echo $OUTPUT->footer();
    exit;
}

// Observe mode — show impact and the arming form.
echo html_writer::div(get_string('golive_intro', 'local_accessexpiry'), 'alert alert-info');
if ($error !== '') {
    echo html_writer::div($error, 'alert alert-danger');
}

$table = new html_table();
$table->head = [
    get_string('golive_change', 'local_accessexpiry'),
    get_string('golive_count', 'local_accessexpiry'),
];
$table->attributes['class'] = 'generaltable';
$table->data = [
    [get_string('golive_cohortremovals', 'local_accessexpiry'),
        html_writer::span($cohort, 'badge ' . ($cohort ? 'badge-danger' : 'badge-light'))],
    [get_string('golive_windows', 'local_accessexpiry'),
        html_writer::span($direct['windows'], 'badge ' . ($direct['windows'] ? 'badge-info' : 'badge-light'))],
    [get_string('golive_expire', 'local_accessexpiry'),
        html_writer::span($direct['expire'], 'badge ' . ($direct['expire'] ? 'badge-danger' : 'badge-light'))],
    [get_string('golive_protected', 'local_accessexpiry'),
        html_writer::span($direct['protected'], 'badge badge-secondary')],
];
echo html_writer::table($table);

echo html_writer::div(get_string('golive_total', 'local_accessexpiry', $affected), 'lead');
echo html_writer::div(get_string('golive_capnote', 'local_accessexpiry',
    (object)['max' => (int)get_config('local_accessexpiry', 'maxrows')]), 'text-muted small mb-3');

// Arming form: acknowledge + type the affected count.
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'golive']);
echo html_writer::start_div('form-check mb-2');
echo html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'ack', 'value' => 1,
    'id' => 'ae-ack', 'class' => 'form-check-input']);
echo html_writer::tag('label', get_string('golive_ack', 'local_accessexpiry'),
    ['for' => 'ae-ack', 'class' => 'form-check-label']);
echo html_writer::end_div();
echo html_writer::start_div('form-inline mb-3');
echo html_writer::tag('label', get_string('golive_type', 'local_accessexpiry', $affected),
    ['for' => 'ae-count', 'class' => 'mr-2']);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'confirmcount', 'id' => 'ae-count',
    'class' => 'form-control ml-2', 'autocomplete' => 'off', 'style' => 'width:8rem']);
echo html_writer::end_div();
echo html_writer::empty_tag('input', ['type' => 'submit',
    'value' => get_string('golive_confirm', 'local_accessexpiry'), 'class' => 'btn btn-danger']);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
