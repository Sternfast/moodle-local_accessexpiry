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
 * Access-expiry policy management — the cohort policies grid.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_accessexpiry\local\rule;
use local_accessexpiry\local\engine;

admin_externalpage_setup('local_accessexpiry_manage');
$context = context_system::instance();
require_capability('local/accessexpiry:manage', $context);

$PAGE->set_url(new moodle_url('/local/accessexpiry/manage.php'));
$PAGE->set_title(get_string('managecohorts', 'local_accessexpiry'));
$PAGE->set_heading(get_string('managecohorts', 'local_accessexpiry'));

$action = optional_param('action', '', PARAM_ALPHA);

// -- Handle save --
if ($action === 'save' && confirm_sesskey()) {
    $cohortids = $DB->get_fieldset_select('cohort', 'id', '');
    $existing = rule::cohort_policies(); // cohortid => row; only clear rows that actually exist.
    $dayerrors = 0;
    $dateerrors = 0;
    $todaymidnight = strtotime('today');
    foreach ($cohortids as $cid) {
        $cid = (int)$cid;
        $ptype = optional_param('policytype_' . $cid, 'usedefault', PARAM_ALPHA);
        if ($ptype === 'usedefault') {
            if (isset($existing[$cid])) {
                rule::clear_cohort($cid);
            }
        } else if ($ptype === 'never') {
            rule::set_cohort($cid, rule::NEVER, null, null);
        } else if ($ptype === 'relative') {
            $days = optional_param('days_' . $cid, 0, PARAM_INT);
            if ($days < 1) {
                $dayerrors++;
                continue;
            }
            rule::set_cohort($cid, rule::RELATIVE, $days, null);
        } else if ($ptype === 'absolute') {
            $datestr = trim(optional_param('date_' . $cid, '', PARAM_RAW));
            $ts = ($datestr !== '') ? strtotime($datestr) : false;
            if ($ts === false || (int)$ts < $todaymidnight) {
                $dateerrors++;
                continue;
            }
            rule::set_cohort($cid, rule::ABSOLUTE, null, (int)$ts);
        }
    }
    $msg = get_string('changessaved', 'local_accessexpiry');
    $notifytype = \core\output\notification::NOTIFY_SUCCESS;
    if ($dayerrors > 0 || $dateerrors > 0) {
        if ($dayerrors > 0) {
            $msg .= ' ' . get_string('invaliddays', 'local_accessexpiry');
        }
        if ($dateerrors > 0) {
            $msg .= ' ' . get_string('invaliddate', 'local_accessexpiry');
        }
        $notifytype = \core\output\notification::NOTIFY_WARNING;
    }
    redirect($PAGE->url, $msg, null, $notifytype);
}

// -- Gather data for the grid --
$cohorts = $DB->get_records('cohort', null, 'name ASC', 'id, name, idnumber');
$policies = rule::cohort_policies();
$eligible = engine::counts_by_cohort();
$eligiblesoon = engine::counts_by_cohort(time() + 7 * DAYSECS); // Cumulative: eligible now + within 7 days.

// Total members per cohort.
$membercounts = [];
foreach ($DB->get_records_sql('SELECT cohortid, COUNT(*) AS n FROM {cohort_members} GROUP BY cohortid') as $r) {
    $membercounts[(int)$r->cohortid] = (int)$r->n;
}

// Which cohorts are wired to the configured student role via an active cohort-sync method.
$studentroleid = (int)get_config('local_accessexpiry', 'studentroleid');
$wired = [];
if ($studentroleid > 0) {
    $rows = $DB->get_records_sql(
        "SELECT DISTINCT customint1 AS cohortid FROM {enrol}
          WHERE enrol = 'cohort' AND status = 0 AND roleid = :r",
        ['r' => $studentroleid]);
    foreach ($rows as $r) {
        $wired[(int)$r->cohortid] = true;
    }
}

// Effective-policy label helper for the site default.
$defaultdays = (int)get_config('local_accessexpiry', 'defaultdays');
$defaultpolicy = get_config('local_accessexpiry', 'defaultpolicy');
$defaultlabel = ($defaultpolicy === 'relative' && $defaultdays > 0)
    ? get_string('effective_relative', 'local_accessexpiry', $defaultdays)
    : get_string('effective_never', 'local_accessexpiry');

$enabled = (bool)get_config('local_accessexpiry', 'enabled');
$dryrun = (get_config('local_accessexpiry', 'dryrun') !== '0');
$settingsurl = (new moodle_url('/admin/settings.php', ['section' => 'local_accessexpiry_settings']))->out();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managecohorts', 'local_accessexpiry'));

// -- Status banner: make the current operating mode unmistakable. --
if (!$enabled) {
    echo html_writer::div(get_string('disabledwarning', 'local_accessexpiry', $settingsurl),
        'alert alert-warning');
} else if ($dryrun) {
    echo html_writer::div(get_string('dryrunwarning', 'local_accessexpiry'),
        'alert alert-warning');
} else {
    echo html_writer::div(
        html_writer::tag('strong', get_string('live_title', 'local_accessexpiry')) . ' '
        . get_string('live_desc', 'local_accessexpiry'),
        'alert alert-danger');
}

// Un-reverted changes: surface the safe Disable -> Revert -> Uninstall order (core gives no hard block).
$unreverted = \local_accessexpiry\local\audit::unreverted_count();
if ($unreverted > 0) {
    $reverturl = (new moodle_url('/local/accessexpiry/revert.php'))->out();
    echo html_writer::div(
        get_string('unreverted_banner', 'local_accessexpiry', (object)['n' => $unreverted, 'url' => $reverturl]),
        'alert alert-warning');
}

echo html_writer::div(get_string('manage_intro', 'local_accessexpiry'), 'text-muted mb-3');

// -- Teaching empty state (no cohorts yet). --
if (empty($cohorts)) {
    $body = html_writer::tag('h4', get_string('empty_title', 'local_accessexpiry'))
        . html_writer::tag('p', get_string('empty_body', 'local_accessexpiry'))
        . html_writer::div(
            html_writer::link(new moodle_url('/cohort/index.php'),
                get_string('empty_cta', 'local_accessexpiry'), ['class' => 'btn btn-primary']),
            'mt-2');
    echo html_writer::div($body, 'card card-body bg-light');
    echo $OUTPUT->footer();
    exit;
}

// -- Impact preview: how many cohorts diverge, and the blast radius at the next run. --
$overrides = count($policies);
$totalcohorts = count($cohorts);
$now = array_sum($eligible);
$soon = max(0, array_sum($eligiblesoon) - $now);
$impact = html_writer::tag('strong',
    get_string('impact_overrides', 'local_accessexpiry', (object)['overrides' => $overrides, 'total' => $totalcohorts]));
if ($now > 0) {
    $impact .= ' ' . get_string('impact_now', 'local_accessexpiry', $now);
    if ($soon > 0) {
        $impact .= ' ' . get_string('impact_soon', 'local_accessexpiry', $soon);
    }
} else {
    $impact .= ' ' . get_string('impact_none', 'local_accessexpiry');
}
echo html_writer::div($impact, 'alert alert-secondary');

// Client-side filter for long cohort lists (name or ID number).
if (count($cohorts) > 8) {
    echo html_writer::div(
        html_writer::empty_tag('input', ['type' => 'text', 'id' => 'ae-filter',
            'class' => 'form-control', 'autocomplete' => 'off', 'style' => 'max-width:24rem',
            'placeholder' => get_string('filtercohorts', 'local_accessexpiry')]),
        'mb-2');
}

$typeoptions = [
    'usedefault' => get_string('policy_usedefault', 'local_accessexpiry'),
    'never'      => get_string('policy_never', 'local_accessexpiry'),
    'relative'   => get_string('policy_relative', 'local_accessexpiry'),
    'absolute'   => get_string('policy_absolute', 'local_accessexpiry'),
];

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);

$table = new html_table();
$table->head = [
    get_string('col_cohort', 'local_accessexpiry'),
    get_string('col_idnumber', 'local_accessexpiry'),
    get_string('col_members', 'local_accessexpiry'),
    get_string('col_wired', 'local_accessexpiry'),
    get_string('col_policy', 'local_accessexpiry'),
    get_string('col_value', 'local_accessexpiry'),
    get_string('col_eligible', 'local_accessexpiry'),
];
$table->attributes['class'] = 'generaltable';
$table->id = 'ae-cohort-grid';
$table->data = [];

foreach ($cohorts as $c) {
    $cid = (int)$c->id;
    $row = $policies[$cid] ?? null;

    // Current selection + prefilled values.
    if ($row === null) {
        $selected = 'usedefault';
    } else if ((int)$row->policytype === rule::NEVER) {
        $selected = 'never';
    } else if ((int)$row->policytype === rule::RELATIVE) {
        $selected = 'relative';
    } else {
        $selected = 'absolute';
    }
    $daysval = ($row && $row->days) ? (int)$row->days : ($defaultdays ?: 365);
    $dateval = ($row && $row->expirydate) ? date('Y-m-d', (int)$row->expirydate) : '';

    // Policy select.
    $select = html_writer::select($typeoptions, 'policytype_' . $cid, $selected, false,
        ['class' => 'ce-policy custom-select', 'data-cohortid' => $cid]);

    // Days + date inputs (JS shows the relevant one).
    $daysinput = html_writer::span(
        get_string('field_days', 'local_accessexpiry') . ' ' .
        html_writer::empty_tag('input', [
            'type' => 'number', 'min' => '1', 'name' => 'days_' . $cid,
            'value' => $daysval, 'class' => 'form-control d-inline-block',
        ]), 'ce-days');
    $dateinput = html_writer::span(
        get_string('field_date', 'local_accessexpiry') . ' ' .
        html_writer::empty_tag('input', [
            'type' => 'date', 'name' => 'date_' . $cid,
            'value' => $dateval, 'class' => 'form-control d-inline-block',
        ]), 'ce-date');
    $valuecell = $daysinput . $dateinput;

    // Effective-policy label (what actually applies) + override affordances.
    if ($selected === 'usedefault') {
        $eff = get_string('effective_default', 'local_accessexpiry', $defaultlabel);
        $badge = html_writer::span(get_string('badge_default', 'local_accessexpiry'), 'badge badge-light');
        $reset = '';
    } else {
        if ($selected === 'never') {
            $eff = get_string('effective_never', 'local_accessexpiry');
        } else if ($selected === 'relative') {
            $eff = get_string('effective_relative', 'local_accessexpiry', $daysval);
        } else {
            // Server-timezone date, to match the date input's prefill (date('Y-m-d', ...)).
            $eff = get_string('effective_absolute', 'local_accessexpiry', date('j F Y', (int)$row->expirydate));
        }
        $badge = html_writer::span(get_string('badge_custom', 'local_accessexpiry'), 'badge badge-info');
        // JS "reset to default" affordance for divergent rows.
        $reset = ' ' . html_writer::link('#', get_string('resetdefault', 'local_accessexpiry'),
            ['class' => 'ce-reset small', 'data-cohortid' => $cid]);
    }

    // Student-wired indicator.
    if ($studentroleid == 0) {
        $wiredcell = html_writer::span('&mdash;', 'text-muted');
    } else if (!empty($wired[$cid])) {
        $wiredcell = html_writer::span(get_string('wired_yes', 'local_accessexpiry'), 'badge badge-success');
    } else {
        $wiredcell = html_writer::span(get_string('wired_no', 'local_accessexpiry'), 'badge badge-secondary')
            . ' ' . $OUTPUT->help_icon('wired_no', 'local_accessexpiry');
    }

    // Eligible-now count.
    $n = $eligible[$cid] ?? 0;
    if ($n > 0) {
        $eligiblecell = html_writer::span($n, 'badge badge-danger');
    } else {
        $eligiblecell = html_writer::span(get_string('status_none', 'local_accessexpiry'), 'text-muted');
    }

    $namecell = html_writer::div(s($c->name) . ' ' . $badge, 'font-weight-bold')
        . html_writer::div($eff . $reset, 'small text-muted ce-eff');

    $table->data[] = [
        $namecell,
        s($c->idnumber),
        $membercounts[$cid] ?? 0,
        $wiredcell,
        $select,
        $valuecell,
        $eligiblecell,
    ];
}

echo html_writer::table($table);
echo html_writer::div(
    html_writer::empty_tag('input', [
        'type' => 'submit', 'value' => get_string('savechanges', 'local_accessexpiry'),
        'class' => 'btn btn-primary',
    ]), 'mt-2');
echo html_writer::end_tag('form');

// Progressive enhancement: show only the relevant value input per row, and wire "reset to default".
$PAGE->requires->js_amd_inline("
require(['jquery'], function($) {
    function toggle(el) {
        var v = $(el).val();
        var row = $(el).closest('tr');
        row.find('.ce-days').css('display', v === 'relative' ? '' : 'none');
        row.find('.ce-date').css('display', v === 'absolute' ? '' : 'none');
    }
    $('.ce-policy').each(function() { toggle(this); });
    $('.ce-policy').on('change', function() { toggle(this); });
    $('.ce-reset').on('click', function(e) {
        e.preventDefault();
        var id = $(this).data('cohortid');
        $('.ce-policy[data-cohortid=\"' + id + '\"]').val('usedefault').trigger('change');
    });
    $('#ae-filter').on('input', function() {
        var q = $(this).val().toLowerCase();
        $('#ae-cohort-grid tbody tr').each(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(q) !== -1);
        });
    });
});
");

echo $OUTPUT->footer();
