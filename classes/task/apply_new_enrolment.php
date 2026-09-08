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
 * Ad-hoc task: apply the resolved access window to a new enrolment.
 *
 * Queued from the user_enrolment_created observer. Running it a moment later
 * (rather than inside the event) means the enrolment's role assignment has
 * committed, so the elevated-role guardrail sees the real role.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_accessexpiry\task;

use local_accessexpiry\local\directengine;

/**
 * Applies an access window to a single newly-created enrolment.
 */
class apply_new_enrolment extends \core\task\adhoc_task {

    /**
     * Apply the window for the enrolment carried in the custom data.
     */
    public function execute() {
        $data = $this->get_custom_data();
        if (empty($data->ueid)) {
            return;
        }
        // Re-check the master switch at run time; nothing to do if disabled or in dry-run.
        if (!get_config('local_accessexpiry', 'enabled') || get_config('local_accessexpiry', 'dryrun')) {
            return;
        }
        directengine::apply_to_new_enrolment((int)$data->ueid);
    }
}
