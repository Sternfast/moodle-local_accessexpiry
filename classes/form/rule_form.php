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
 * Add/edit form for an access-duration rule.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_accessexpiry\form;

use local_accessexpiry\local\rule;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * The rule add/edit moodleform. Conditional fields keep it simple: the target
 * picker and duration fields reveal only when relevant.
 */
class rule_form extends \moodleform {

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        // Which scope this rule targets.
        $scopes = [
            rule::SCOPE_COURSE      => get_string('scope_course', 'local_accessexpiry'),
            rule::SCOPE_CATEGORY    => get_string('scope_category', 'local_accessexpiry'),
            rule::SCOPE_ROLE        => get_string('scope_role', 'local_accessexpiry'),
            rule::SCOPE_ENROLMETHOD => get_string('scope_method', 'local_accessexpiry'),
        ];
        $mform->addElement('select', 'scopelevel', get_string('scopetype', 'local_accessexpiry'), $scopes);
        $mform->addHelpButton('scopelevel', 'scopetype', 'local_accessexpiry');

        // Course target (autocomplete).
        $mform->addElement('course', 'courseid', get_string('scope_course', 'local_accessexpiry'));
        $mform->hideIf('courseid', 'scopelevel', 'neq', rule::SCOPE_COURSE);

        // Category target.
        $catoptions = \core_course_category::make_categories_list();
        $mform->addElement('autocomplete', 'catid', get_string('scope_category', 'local_accessexpiry'), $catoptions);
        $mform->hideIf('catid', 'scopelevel', 'neq', rule::SCOPE_CATEGORY);

        // Role target.
        $roleoptions = [];
        foreach (role_get_names(\context_system::instance()) as $r) {
            $roleoptions[$r->id] = $r->localname;
        }
        $mform->addElement('select', 'roleid', get_string('scope_role', 'local_accessexpiry'), $roleoptions);
        $mform->hideIf('roleid', 'scopelevel', 'neq', rule::SCOPE_ROLE);

        // Enrolment method: required for a method-scope rule, an optional refinement for a course-scope rule.
        $methods = ['' => get_string('anymethod', 'local_accessexpiry')];
        foreach (enrol_get_plugins(true) as $name => $plugin) {
            $methods[$name] = get_string('pluginname', 'enrol_' . $name);
        }
        $mform->addElement('select', 'enrolmethod', get_string('enrolmethod', 'local_accessexpiry'), $methods);
        $mform->addHelpButton('enrolmethod', 'enrolmethod', 'local_accessexpiry');
        $mform->hideIf('enrolmethod', 'scopelevel', 'eq', rule::SCOPE_CATEGORY);
        $mform->hideIf('enrolmethod', 'scopelevel', 'eq', rule::SCOPE_ROLE);

        // Duration.
        $mform->addElement('select', 'durationtype', get_string('col_policy', 'local_accessexpiry'), [
            rule::NEVER    => get_string('policy_never', 'local_accessexpiry'),
            rule::RELATIVE => get_string('policy_relative', 'local_accessexpiry'),
            rule::ABSOLUTE => get_string('policy_absolute', 'local_accessexpiry'),
        ]);

        $mform->addElement('text', 'durationdays', get_string('field_days', 'local_accessexpiry'), ['size' => 6]);
        $mform->setType('durationdays', PARAM_INT);
        $mform->hideIf('durationdays', 'durationtype', 'neq', rule::RELATIVE);

        $mform->addElement('date_selector', 'enddate', get_string('field_date', 'local_accessexpiry'));
        $mform->hideIf('enddate', 'durationtype', 'neq', rule::ABSOLUTE);

        // On-expiry action (direct enrolments only).
        $mform->addElement('select', 'onexpiry', get_string('defaultonexpiry', 'local_accessexpiry'), [
            rule::EXPIRY_SUSPEND        => get_string('onexpiry_suspend', 'local_accessexpiry'),
            rule::EXPIRY_UNENROL        => get_string('onexpiry_unenrol', 'local_accessexpiry'),
            rule::EXPIRY_SUSPENDNOROLES => get_string('onexpiry_suspendnoroles', 'local_accessexpiry'),
            rule::EXPIRY_KEEP           => get_string('onexpiry_keep', 'local_accessexpiry'),
        ]);
        $mform->setDefault('onexpiry', rule::EXPIRY_SUSPEND);
        $mform->addHelpButton('onexpiry', 'defaultonexpiry', 'local_accessexpiry');
        $mform->hideIf('onexpiry', 'durationtype', 'eq', rule::NEVER);

        $mform->addElement('advcheckbox', 'enabled', get_string('enable', 'local_accessexpiry'));
        $mform->setDefault('enabled', 1);

        $this->add_action_buttons();
    }

    /**
     * Validate the submitted rule.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $type = (int)$data['durationtype'];
        if ($type === rule::RELATIVE && (empty($data['durationdays']) || (int)$data['durationdays'] < 1)) {
            $errors['durationdays'] = get_string('invaliddays', 'local_accessexpiry');
        }
        if ($type === rule::ABSOLUTE && (empty($data['enddate']) || (int)$data['enddate'] < strtotime('today'))) {
            $errors['enddate'] = get_string('invaliddate', 'local_accessexpiry');
        }
        if ((int)$data['scopelevel'] === rule::SCOPE_ENROLMETHOD && empty($data['enrolmethod'])) {
            $errors['enrolmethod'] = get_string('methodrequired', 'local_accessexpiry');
        }
        if ((int)$data['scopelevel'] === rule::SCOPE_COURSE && empty($data['courseid'])) {
            $errors['courseid'] = get_string('required');
        }
        return $errors;
    }
}
