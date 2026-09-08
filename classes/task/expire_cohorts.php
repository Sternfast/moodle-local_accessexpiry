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
 * Scheduled task: expire cohort memberships per policy.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_accessexpiry\task;

use local_accessexpiry\local\engine;
use local_accessexpiry\local\directengine;
use local_accessexpiry\local\audit;

/**
 * Nightly task that enforces access windows: it removes expired cohort
 * memberships (cohort sync ignores timeend, so we remove the member) and
 * applies/enforces access-duration rules on direct enrolments. No-overlap is
 * handled by the task framework's own lock.
 */
class expire_cohorts extends \core\task\scheduled_task {

    /**
     * @return string
     */
    public function get_name() {
        return get_string('taskexpirecohorts', 'local_accessexpiry');
    }

    /**
     * Run the expiry pass.
     */
    public function execute() {
        if (!get_config('local_accessexpiry', 'enabled')) {
            mtrace('local_accessexpiry: disabled — enable it under Site administration > Users > '
                . 'Access Expiry & Enrolment Duration > Settings. STATUS: PAUSED');
            return;
        }
        // Dry-run unless explicitly turned off via the Go-Live page (unset = safe/dry-run).
        $dryrun  = (get_config('local_accessexpiry', 'dryrun') !== '0');
        $maxrows = (int)get_config('local_accessexpiry', 'maxrows');
        if ($maxrows <= 0) {
            $maxrows = 200;
        }
        // One run id groups everything this pass changes (for audit + revert).
        audit::start_run();
        // Cohort scope (removal, because cohort sync ignores timeend).
        engine::run($dryrun, $maxrows);
        // Direct enrolments (manual, self, ...): set/enforce the access window.
        directengine::apply($dryrun, $maxrows);
    }
}
