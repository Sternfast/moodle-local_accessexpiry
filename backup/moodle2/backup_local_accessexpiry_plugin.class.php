<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Backup handler for local_accessexpiry course-scoped data.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Sternfast
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Adds this plugin's course-scoped access-expiry rules and per-user course
 * extensions to a course backup, so a restored or duplicated course keeps
 * its expiry configuration and learner overrides.
 *
 * The append-only audit log (local_accessexpiry_log) is intentionally NOT
 * backed up: its rows reference the source course's enrolment IDs, which do
 * not exist in the restored course, and it is not configuration.
 */
class backup_local_accessexpiry_plugin extends backup_local_plugin {

    /**
     * Define the course-level structure contributed by this plugin.
     *
     * @return backup_plugin_element
     */
    protected function define_course_plugin_structure() {
        // SCOPE_COURSE / TARGET_COURSE both equal 30 (see classes/local/rule.php).
        $coursescope = 30;

        $plugin = $this->get_plugin_element();

        $pluginwrapper = new backup_nested_element($this->get_recommended_name());

        $rules = new backup_nested_element('accessexpiry_rules');
        $rule = new backup_nested_element('rule', ['id'], [
            'scopelevel', 'scopeid', 'enrolmethod', 'durationtype', 'durationdays',
            'enddate', 'onexpiry', 'enabled', 'timecreated', 'timemodified',
        ]);

        $extensions = new backup_nested_element('accessexpiry_extensions');
        $extension = new backup_nested_element('extension', ['id'], [
            'userid', 'targettype', 'targetid', 'grantmode', 'enddate', 'days',
            'reason', 'grantedby', 'revoked', 'revokedby', 'timecreated', 'timerevoked',
        ]);

        $plugin->add_child($pluginwrapper);
        $pluginwrapper->add_child($rules);
        $rules->add_child($rule);
        $pluginwrapper->add_child($extensions);
        $extensions->add_child($extension);

        // Only this course's course-scoped rows.
        $rule->set_source_table('local_accessexpiry_rule', [
            'scopelevel' => backup_helper::is_sqlparam($coursescope),
            'scopeid'    => backup::VAR_COURSEID,
        ]);
        $extension->set_source_table('local_accessexpiry_extension', [
            'targettype' => backup_helper::is_sqlparam($coursescope),
            'targetid'   => backup::VAR_COURSEID,
        ]);

        // Extensions reference users; annotate so they can be mapped on restore.
        $extension->annotate_ids('user', 'userid');
        $extension->annotate_ids('user', 'grantedby');
        $extension->annotate_ids('user', 'revokedby');

        return $plugin;
    }
}
