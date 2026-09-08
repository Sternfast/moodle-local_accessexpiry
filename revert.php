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
 * Revert changes the plugin made, from the audit log. Run this before disabling
 * or uninstalling if you want prior access restored.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_accessexpiry\local\reverter;

admin_externalpage_setup('local_accessexpiry_revert');
require_capability('local/accessexpiry:manage', context_system::instance());

$url = new moodle_url('/local/accessexpiry/revert.php');
$PAGE->set_url($url);
$PAGE->set_title(get_string('revert', 'local_accessexpiry'));
$PAGE->set_heading(get_string('revert', 'local_accessexpiry'));

$action = optional_param('action', '', PARAM_ALPHA);
$maxrows = (int)get_config('local_accessexpiry', 'maxrows');
if ($maxrows <= 0) {
    $maxrows = 200;
}

if ($action === 'revert' && confirm_sesskey()) {
    $r = reverter::process(false, $maxrows, $USER->id);
    redirect($url, get_string('revertdone', 'local_accessexpiry', (object)$r), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('revert', 'local_accessexpiry'));
echo html_writer::div(get_string('revert_intro', 'local_accessexpiry'), 'alert alert-info');

$pending = reverter::pending_count();
if ($pending === 0) {
    echo html_writer::div(get_string('revertnothing', 'local_accessexpiry'), 'alert alert-secondary');
    echo $OUTPUT->footer();
    exit;
}

// Dry-run preview of the next pass (changes nothing).
$preview = reverter::process(true, $maxrows, $USER->id);

echo html_writer::tag('p', get_string('revertpending', 'local_accessexpiry', $pending));

$table = new html_table();
$table->head = [
    get_string('revert_willrestore', 'local_accessexpiry'),
    get_string('revert_conflict', 'local_accessexpiry'),
    get_string('revert_gone', 'local_accessexpiry'),
    get_string('revert_irreversible', 'local_accessexpiry'),
];
$table->attributes['class'] = 'generaltable';
$table->data = [[
    html_writer::span($preview['ok'], 'badge badge-success'),
    html_writer::span($preview['conflict'], 'badge badge-warning'),
    html_writer::span($preview['gone'], 'badge badge-secondary'),
    html_writer::span($preview['irreversible'], 'badge badge-danger'),
]];
echo html_writer::table($table);

echo html_writer::div(get_string('revert_batchnote', 'local_accessexpiry',
    (object)['max' => $maxrows]), 'text-muted small mb-2');

echo $OUTPUT->single_button(
    new moodle_url($url, ['action' => 'revert', 'sesskey' => sesskey()]),
    get_string('revertnow', 'local_accessexpiry'), 'post');

echo $OUTPUT->footer();
