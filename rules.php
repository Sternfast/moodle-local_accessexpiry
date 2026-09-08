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
 * Access-duration rules across scopes (category / course / role / enrolment method).
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_accessexpiry\local\rule;
use local_accessexpiry\form\rule_form;

admin_externalpage_setup('local_accessexpiry_rules');
$context = context_system::instance();
require_capability('local/accessexpiry:manage', $context);

$url = new moodle_url('/local/accessexpiry/rules.php');
$PAGE->set_url($url);
$PAGE->set_title(get_string('rules', 'local_accessexpiry'));
$PAGE->set_heading(get_string('rules', 'local_accessexpiry'));

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

// ---- Delete ----
if ($action === 'delete' && $id && confirm_sesskey()) {
    $DB->delete_records(rule::TABLE, ['id' => $id]);
    redirect($url, get_string('ruledeleted', 'local_accessexpiry'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// ---- Add / edit form ----
$mform = new rule_form($url->out(false));
if ($id && $action === 'edit') {
    $rec = $DB->get_record(rule::TABLE, ['id' => $id], '*', MUST_EXIST);
    $data = [
        'id'           => $rec->id,
        'scopelevel'   => (int)$rec->scopelevel,
        'enrolmethod'  => (string)$rec->enrolmethod,
        'durationtype' => (int)$rec->durationtype,
        'durationdays' => $rec->durationdays !== null ? (int)$rec->durationdays : null,
        'enddate'      => $rec->enddate !== null ? (int)$rec->enddate : null,
        'onexpiry'     => (int)$rec->onexpiry,
        'enabled'      => (int)$rec->enabled,
    ];
    switch ((int)$rec->scopelevel) {
        case rule::SCOPE_COURSE:   $data['courseid'] = (int)$rec->scopeid; break;
        case rule::SCOPE_CATEGORY: $data['catid'] = (int)$rec->scopeid; break;
        case rule::SCOPE_ROLE:     $data['roleid'] = (int)$rec->scopeid; break;
    }
    $mform->set_data($data);
}

if ($mform->is_cancelled()) {
    redirect($url);
} else if ($d = $mform->get_data()) {
    $scopelevel = (int)$d->scopelevel;
    $enrolmethod = '';
    $scopeid = 0;
    switch ($scopelevel) {
        case rule::SCOPE_COURSE:
            $scopeid = (int)$d->courseid;
            $enrolmethod = (string)$d->enrolmethod;
            break;
        case rule::SCOPE_CATEGORY:
            $scopeid = (int)$d->catid;
            break;
        case rule::SCOPE_ROLE:
            $scopeid = (int)$d->roleid;
            break;
        case rule::SCOPE_ENROLMETHOD:
            $enrolmethod = (string)$d->enrolmethod;
            break;
    }
    // On edit the scope tuple may have changed; drop the old row, then upsert the new tuple.
    if (!empty($d->id)) {
        $DB->delete_records(rule::TABLE, ['id' => (int)$d->id]);
    }
    $days = ((int)$d->durationtype === rule::RELATIVE) ? (int)$d->durationdays : null;
    $enddate = ((int)$d->durationtype === rule::ABSOLUTE) ? (int)$d->enddate : null;
    rule::set($scopelevel, $scopeid, $enrolmethod, (int)$d->durationtype, $days, $enddate,
        (int)$d->onexpiry, (int)$d->enabled);
    redirect($url, get_string('rulesaved', 'local_accessexpiry'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// ---- Render the form (add/edit) ----
if ($action === 'add' || $action === 'edit') {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string($action === 'add' ? 'addrule' : 'editrule', 'local_accessexpiry'));
    $mform->display();
    echo $OUTPUT->footer();
    exit;
}

// ---- List ----
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('rules', 'local_accessexpiry'));
echo html_writer::div(get_string('rules_intro', 'local_accessexpiry'), 'alert alert-info');

// Site default (read-only) — lives on the Settings page.
$sitedefault = rule::site_default();
$settingsurl = new moodle_url('/admin/settings.php', ['section' => 'local_accessexpiry_settings']);
echo html_writer::div(
    get_string('sitedefault_is', 'local_accessexpiry',
        local_accessexpiry_duration_label($sitedefault->type, $sitedefault->days, $sitedefault->expirydate))
    . ' ' . html_writer::link($settingsurl, get_string('changeit', 'local_accessexpiry')),
    'alert alert-secondary');

echo html_writer::div(
    html_writer::link(new moodle_url($url, ['action' => 'add']),
        get_string('addrule', 'local_accessexpiry'), ['class' => 'btn btn-primary']),
    'mb-3');

$rules = $DB->get_records_select(rule::TABLE, 'scopelevel <> :cohort',
    ['cohort' => rule::SCOPE_COHORT], 'scopelevel ASC, scopeid ASC');

if (empty($rules)) {
    echo html_writer::div(get_string('norules', 'local_accessexpiry'), 'alert alert-secondary');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('col_scope', 'local_accessexpiry'),
    get_string('enrolmethod', 'local_accessexpiry'),
    get_string('col_policy', 'local_accessexpiry'),
    get_string('defaultonexpiry', 'local_accessexpiry'),
    get_string('col_inscope', 'local_accessexpiry'),
    get_string('enable', 'local_accessexpiry'),
    '',
];
$table->attributes['class'] = 'generaltable';
$table->data = [];

foreach ($rules as $r) {
    $scopelabel = local_accessexpiry_scope_label($r);
    $methodlabel = ($r->enrolmethod !== '')
        ? get_string('pluginname', 'enrol_' . $r->enrolmethod)
        : html_writer::span(get_string('anymethod', 'local_accessexpiry'), 'text-muted');
    $durationlabel = local_accessexpiry_duration_label((int)$r->durationtype,
        $r->durationdays !== null ? (int)$r->durationdays : null,
        $r->enddate !== null ? (int)$r->enddate : null);
    $onexpirylabel = ((int)$r->durationtype === rule::NEVER)
        ? html_writer::span('&mdash;', 'text-muted')
        : local_accessexpiry_onexpiry_label((int)$r->onexpiry);
    $inscope = local_accessexpiry_count_in_scope($r);
    $inscopecell = $inscope > 0
        ? html_writer::span($inscope, 'badge badge-info')
        : html_writer::span('0', 'text-muted');
    $enabledcell = $r->enabled
        ? html_writer::span(get_string('yes'), 'badge badge-success')
        : html_writer::span(get_string('no'), 'badge badge-secondary');
    $actions = html_writer::link(new moodle_url($url, ['action' => 'edit', 'id' => $r->id]),
            $OUTPUT->pix_icon('t/edit', get_string('edit')))
        . ' ' . html_writer::link(
            new moodle_url($url, ['action' => 'delete', 'id' => $r->id, 'sesskey' => sesskey()]),
            $OUTPUT->pix_icon('t/delete', get_string('delete')),
            ['onclick' => "return confirm('" . get_string('confirmdeleterule', 'local_accessexpiry') . "');"]);

    $table->data[] = [$scopelabel, $methodlabel, $durationlabel, $onexpirylabel,
        $inscopecell, $enabledcell, $actions];
}

echo html_writer::table($table);
echo $OUTPUT->footer();

/**
 * Plain-language label for a rule's scope target.
 *
 * @param \stdClass $r rule row
 * @return string
 */
function local_accessexpiry_scope_label(\stdClass $r): string {
    global $DB;
    switch ((int)$r->scopelevel) {
        case rule::SCOPE_COURSE:
            $name = $DB->get_field('course', 'fullname', ['id' => $r->scopeid]);
            return get_string('scope_course', 'local_accessexpiry') . ': ' . s($name ?: ('#' . $r->scopeid));
        case rule::SCOPE_CATEGORY:
            $name = $DB->get_field('course_categories', 'name', ['id' => $r->scopeid]);
            return get_string('scope_category', 'local_accessexpiry') . ': ' . s($name ?: ('#' . $r->scopeid));
        case rule::SCOPE_ROLE:
            $roles = role_get_names(\context_system::instance());
            $name = isset($roles[$r->scopeid]) ? $roles[$r->scopeid]->localname : ('#' . $r->scopeid);
            return get_string('scope_role', 'local_accessexpiry') . ': ' . s($name);
        case rule::SCOPE_ENROLMETHOD:
            return get_string('scope_method', 'local_accessexpiry');
        default:
            return '#' . $r->scopeid;
    }
}

/**
 * Plain-language label for a duration policy.
 *
 * @param int $type
 * @param int|null $days
 * @param int|null $enddate
 * @return string
 */
function local_accessexpiry_duration_label(int $type, ?int $days, ?int $enddate): string {
    if ($type === rule::RELATIVE && $days) {
        return get_string('effective_relative', 'local_accessexpiry', $days);
    }
    if ($type === rule::ABSOLUTE && $enddate) {
        return get_string('effective_absolute', 'local_accessexpiry', userdate($enddate, get_string('strftimedate', 'langconfig')));
    }
    return get_string('effective_never', 'local_accessexpiry');
}

/**
 * Plain-language label for an on-expiry action.
 *
 * @param int $onexpiry
 * @return string
 */
function local_accessexpiry_onexpiry_label(int $onexpiry): string {
    $map = [
        rule::EXPIRY_UNENROL        => 'onexpiry_unenrol',
        rule::EXPIRY_SUSPEND        => 'onexpiry_suspend',
        rule::EXPIRY_SUSPENDNOROLES => 'onexpiry_suspendnoroles',
        rule::EXPIRY_KEEP           => 'onexpiry_keep',
    ];
    return get_string($map[$onexpiry] ?? 'onexpiry_suspend', 'local_accessexpiry');
}

/**
 * Rough count of active direct enrolments currently in a rule's scope (upper bound,
 * ignoring precedence). Gives an at-a-glance blast radius.
 *
 * @param \stdClass $r
 * @return int
 */
function local_accessexpiry_count_in_scope(\stdClass $r): int {
    global $DB;
    $methods = \local_accessexpiry\local\directengine::methods();
    list($msql, $params) = $DB->get_in_or_equal($methods, SQL_PARAMS_NAMED, 'm');
    $where = "e.enrol $msql AND e.status = 0 AND ue.status = 0";
    switch ((int)$r->scopelevel) {
        case rule::SCOPE_COURSE:
            $where .= " AND e.courseid = :cid";
            $params['cid'] = (int)$r->scopeid;
            if ($r->enrolmethod !== '') {
                $where .= " AND e.enrol = :em";
                $params['em'] = $r->enrolmethod;
            }
            break;
        case rule::SCOPE_CATEGORY:
            // Courses whose category path contains this category id.
            $like = $DB->sql_like('cc.path', ':p1') . ' OR ' . $DB->sql_like('cc.path', ':p2');
            $params['p1'] = '%/' . (int)$r->scopeid;
            $params['p2'] = '%/' . (int)$r->scopeid . '/%';
            return (int)$DB->count_records_sql(
                "SELECT COUNT(ue.id)
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                   JOIN {course} c ON c.id = e.courseid
                   JOIN {course_categories} cc ON cc.id = c.category
                  WHERE $where AND ($like)", $params);
        case rule::SCOPE_ROLE:
            $where .= " AND e.roleid = :rid";
            $params['rid'] = (int)$r->scopeid;
            break;
        case rule::SCOPE_ENROLMETHOD:
            $where .= " AND e.enrol = :em";
            $params['em'] = $r->enrolmethod;
            break;
    }
    return (int)$DB->count_records_sql(
        "SELECT COUNT(ue.id)
           FROM {user_enrolments} ue
           JOIN {enrol} e ON e.id = ue.enrolid
          WHERE $where", $params);
}
