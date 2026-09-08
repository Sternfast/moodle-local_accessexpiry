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
 * Grant-a-learner-extension form.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_accessexpiry\form;

use local_accessexpiry\local\extension;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Extend one learner's access in a course.
 */
class extend_form extends \moodleform {

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'courseid', 0);
        $mform->setType('courseid', PARAM_INT);
        $mform->addElement('hidden', 'userid', 0);
        $mform->setType('userid', PARAM_INT);

        if (!empty($this->_customdata['username'])) {
            $mform->addElement('static', 'who', get_string('extend_for', 'local_accessexpiry'),
                s($this->_customdata['username']));
        }
        if (!empty($this->_customdata['currentend'])) {
            $mform->addElement('static', 'current', get_string('extend_current', 'local_accessexpiry'),
                $this->_customdata['currentend']);
        }

        $mform->addElement('select', 'grantmode', get_string('extend_mode', 'local_accessexpiry'), [
            extension::MODE_ADD       => get_string('extend_add', 'local_accessexpiry'),
            extension::MODE_SET       => get_string('extend_set', 'local_accessexpiry'),
            extension::MODE_UNLIMITED => get_string('extend_unlimited', 'local_accessexpiry'),
        ]);
        $mform->setDefault('grantmode', extension::MODE_ADD);

        $mform->addElement('text', 'days', get_string('extend_days', 'local_accessexpiry'), ['size' => 6]);
        $mform->setType('days', PARAM_INT);
        $mform->setDefault('days', 30);
        $mform->hideIf('days', 'grantmode', 'neq', extension::MODE_ADD);

        $mform->addElement('date_selector', 'enddate', get_string('extend_setdate', 'local_accessexpiry'));
        $mform->hideIf('enddate', 'grantmode', 'neq', extension::MODE_SET);

        $mform->addElement('text', 'reason', get_string('extend_reason', 'local_accessexpiry'), ['size' => 50]);
        $mform->setType('reason', PARAM_TEXT);
        $mform->addRule('reason', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('reason', 'extend_reason', 'local_accessexpiry');

        $this->add_action_buttons(true, get_string('extend_grant', 'local_accessexpiry'));
    }

    /**
     * Validate.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $mode = (int)$data['grantmode'];
        if ($mode === extension::MODE_ADD && (empty($data['days']) || (int)$data['days'] < 1)) {
            $errors['days'] = get_string('invaliddays', 'local_accessexpiry');
        }
        if ($mode === extension::MODE_SET && (empty($data['enddate']) || (int)$data['enddate'] < strtotime('today'))) {
            $errors['enddate'] = get_string('invaliddate', 'local_accessexpiry');
        }
        if (trim((string)($data['reason'] ?? '')) === '') {
            $errors['reason'] = get_string('required');
        }
        return $errors;
    }
}
