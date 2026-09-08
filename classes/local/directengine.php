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
 * Direct-enrolment access-duration engine.
 *
 * Resolves the effective access window for a direct enrolment (manual, self,
 * ...) by walking the scope ladder, writes it to {user_enrolments}.timeend via
 * the enrolment API, and applies the on-expiry action once the window passes.
 * The cohort path is handled separately by {@see engine} because cohort sync
 * ignores timeend.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_accessexpiry\local;

/**
 * The direct-enrolment side of the plugin.
 */
class directengine {

    /** @var string[] Enrolment methods that honour a per-user end date. */
    const DIRECT_METHODS = ['manual', 'self'];

    /**
     * Enrolment methods this engine manages, as configured (falls back to the default list).
     *
     * @return string[]
     */
    public static function methods(): array {
        $raw = trim((string)get_config('local_accessexpiry', 'directmethods'));
        if ($raw === '') {
            return self::DIRECT_METHODS;
        }
        $out = array_values(array_filter(array_map('trim', explode(',', $raw))));
        return $out ?: self::DIRECT_METHODS;
    }

    /**
     * Build an index of enabled rules for fast resolution.
     *
     * @return array {course:[courseid][method]=>row, category:[catid]=>row, role:[roleid]=>row, method:[name]=>row}
     */
    public static function rule_index(): array {
        global $DB;
        $index = ['course' => [], 'category' => [], 'role' => [], 'method' => []];
        $rows = $DB->get_records_select(rule::TABLE, 'enabled = 1 AND scopelevel <> :cohort',
            ['cohort' => rule::SCOPE_COHORT]);
        foreach ($rows as $r) {
            switch ((int)$r->scopelevel) {
                case rule::SCOPE_COURSE:
                    $index['course'][(int)$r->scopeid][(string)$r->enrolmethod] = $r;
                    break;
                case rule::SCOPE_CATEGORY:
                    $index['category'][(int)$r->scopeid] = $r;
                    break;
                case rule::SCOPE_ROLE:
                    $index['role'][(int)$r->scopeid] = $r;
                    break;
                case rule::SCOPE_ENROLMETHOD:
                    $index['method'][(string)$r->enrolmethod] = $r;
                    break;
            }
        }
        return $index;
    }

    /**
     * Category ancestry (nearest first) for every course, from the course_categories path.
     *
     * @return array courseid => int[] category ids, nearest ancestor first
     */
    public static function course_category_paths(): array {
        global $DB;
        // catid => [ancestor ids nearest-first] from the "/1/3/7" path.
        $catpaths = [];
        foreach ($DB->get_records('course_categories', null, '', 'id, path') as $cat) {
            $ids = array_values(array_filter(array_map('intval', explode('/', trim((string)$cat->path, '/')))));
            $catpaths[(int)$cat->id] = array_reverse($ids); // nearest (self) first, up to root.
        }
        $out = [];
        foreach ($DB->get_records('course', null, '', 'id, category') as $c) {
            $out[(int)$c->id] = $catpaths[(int)$c->category] ?? [];
        }
        return $out;
    }

    /**
     * Category ancestry (nearest first) for a single course — cheap, for the observer path.
     *
     * @param int $courseid
     * @return int[]
     */
    public static function category_path_for_course(int $courseid): array {
        global $DB;
        $catid = (int)$DB->get_field('course', 'category', ['id' => $courseid]);
        if ($catid <= 0) {
            return [];
        }
        $path = (string)$DB->get_field('course_categories', 'path', ['id' => $catid]);
        $ids = array_values(array_filter(array_map('intval', explode('/', trim($path, '/')))));
        return array_reverse($ids);
    }

    /**
     * Resolve the effective policy for a direct enrolment.
     *
     * Precedence, most specific first:
     *   course+method -> course(any) -> category(nearest ancestor) -> role -> enrolment method -> site default.
     *
     * @param int $courseid
     * @param string $method enrolment plugin name
     * @param int $roleid the role the enrolment grants (0 if none)
     * @param array $catpath category ancestry, nearest first
     * @param array $index rule index from rule_index()
     * @return \stdClass ->type,->days,->enddate,->onexpiry,->source,->scopelevel,->scopeid
     */
    public static function resolve(int $courseid, string $method, int $roleid, array $catpath, array $index): \stdClass {
        // 1) course + this method.
        if (isset($index['course'][$courseid][$method])) {
            return self::shape($index['course'][$courseid][$method], 'course', rule::SCOPE_COURSE, $courseid);
        }
        // 2) course, any method.
        if (isset($index['course'][$courseid][''])) {
            return self::shape($index['course'][$courseid][''], 'course', rule::SCOPE_COURSE, $courseid);
        }
        // 3) nearest ancestor category with a rule.
        foreach ($catpath as $catid) {
            if (isset($index['category'][$catid])) {
                return self::shape($index['category'][$catid], 'category', rule::SCOPE_CATEGORY, $catid);
            }
        }
        // 4) role.
        if ($roleid > 0 && isset($index['role'][$roleid])) {
            return self::shape($index['role'][$roleid], 'role', rule::SCOPE_ROLE, $roleid);
        }
        // 5) enrolment method (site-wide).
        if (isset($index['method'][$method])) {
            return self::shape($index['method'][$method], 'method', rule::SCOPE_ENROLMETHOD, 0);
        }
        // 6) site default (settings).
        $eff = rule::site_default();
        $eff->onexpiry = (int)get_config('local_accessexpiry', 'defaultonexpiry');
        $eff->scopelevel = rule::SCOPE_SITE;
        $eff->scopeid = 0;
        return $eff;
    }

    /**
     * Turn a rule row into the resolved shape.
     *
     * @param \stdClass $r
     * @param string $source
     * @param int $scopelevel
     * @param int $scopeid
     * @return \stdClass
     */
    protected static function shape(\stdClass $r, string $source, int $scopelevel, int $scopeid): \stdClass {
        return (object)[
            'type'       => (int)$r->durationtype,
            'days'       => ($r->durationdays !== null) ? (int)$r->durationdays : null,
            'enddate'    => ($r->enddate !== null) ? (int)$r->enddate : null,
            'onexpiry'   => (int)$r->onexpiry,
            'source'     => $source,
            'scopelevel' => $scopelevel,
            'scopeid'    => $scopeid,
        ];
    }

    /**
     * The end date a resolved policy implies for an enrolment starting at $timestart.
     *
     * @param \stdClass $resolved
     * @param int $timestart
     * @return int unix ts, or 0 for "no expiry"
     */
    public static function compute_timeend(\stdClass $resolved, int $timestart): int {
        if ($resolved->type === rule::RELATIVE && !empty($resolved->days)) {
            $base = $timestart > 0 ? $timestart : time();
            return $base + ((int)$resolved->days * DAYSECS);
        }
        if ($resolved->type === rule::ABSOLUTE && !empty($resolved->enddate)) {
            return (int)$resolved->enddate;
        }
        return 0; // Never.
    }

    /**
     * The effective end date for a user in a course: the rule-resolved window,
     * overridden by any active per-user course extension (which always wins).
     *
     * @param int $userid
     * @param int $courseid
     * @param \stdClass $resolved
     * @param int $timestart
     * @return int
     */
    public static function effective_window(int $userid, int $courseid, \stdClass $resolved, int $timestart): int {
        $end = self::compute_timeend($resolved, $timestart);
        $ext = extension::effective_end($userid, extension::TARGET_COURSE, $courseid);
        if ($ext !== null) {
            $end = $ext; // 0 = never (unlimited extension).
        }
        return $end;
    }

    /**
     * Is this user protected from expiry by a guardrail?
     *
     * @param int $userid
     * @param string $email
     * @return bool
     */
    public static function is_protected(int $userid, string $email): bool {
        global $DB;
        // Site admins.
        if (get_config('local_accessexpiry', 'excludesiteadmins') && is_siteadmin($userid)) {
            return true;
        }
        // Elevated roles held anywhere.
        $elevated = engine::config_intlist('elevatedroleids');
        if (!empty($elevated)) {
            list($insql, $params) = $DB->get_in_or_equal($elevated, SQL_PARAMS_NAMED, 'er');
            $params['uid'] = $userid;
            if ($DB->record_exists_select('role_assignments', "userid = :uid AND roleid $insql", $params)) {
                return true;
            }
        }
        // Staff email domains.
        $domains = preg_split('/[\s,]+/', (string)get_config('local_accessexpiry', 'staffdomains'), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($domains as $dom) {
            $dom = ltrim(trim($dom), '@');
            if ($dom !== '' && $email !== '' && str_ends_with(strtolower($email), '@' . strtolower($dom))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Apply access windows to direct enrolments: set/refresh timeend to the
     * resolved policy, and act on any that have already expired.
     *
     * Safe alongside core: the writes are idempotent, and suspend/unenrol on an
     * already-suspended/unenrolled user is a no-op.
     *
     * @param bool $dryrun log only, change nothing
     * @param int $maxrows cap on rows changed this pass
     * @param int|null $now
     * @param int $actorid who triggered this (0 = cron/system)
     * @return array [windowsset, expiredacted, scanned]
     */
    public static function apply(bool $dryrun, int $maxrows, ?int $now = null, int $actorid = 0): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/enrollib.php');
        $now = $now ?? time();

        $index = self::rule_index();
        $catpaths = self::course_category_paths();
        $sitedefault = rule::site_default();
        $haswork = !empty($index['course']) || !empty($index['category'])
            || !empty($index['role']) || !empty($index['method'])
            || $sitedefault->type !== rule::NEVER;
        if (!$haswork) {
            mtrace('local_accessexpiry (direct): no direct-enrolment rules and site default = never. Nothing to do.');
            return [0, 0, 0];
        }

        list($msql, $mparams) = $DB->get_in_or_equal(self::methods(), SQL_PARAMS_NAMED, 'm');
        $sql = "SELECT ue.id, ue.userid, ue.timestart, ue.timecreated, ue.timeend, ue.status AS uestatus,
                       e.id AS instanceid, e.enrol, e.courseid, e.roleid, u.email
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {user} u ON u.id = ue.userid
                 WHERE e.enrol $msql
                   AND e.status = 0
                   AND u.deleted = 0
              ORDER BY ue.timestart ASC, ue.id ASC";
        $rs = $DB->get_recordset_sql($sql, $mparams);

        $plugins = [];
        $windowsset = 0;
        $expiredacted = 0;
        $scanned = 0;
        foreach ($rs as $row) {
            if ($windowsset + $expiredacted >= $maxrows) {
                break;
            }
            $scanned++;
            if (self::is_protected((int)$row->userid, (string)$row->email)) {
                continue;
            }
            $resolved = self::resolve((int)$row->courseid, (string)$row->enrol, (int)$row->roleid,
                $catpaths[(int)$row->courseid] ?? [], $index);
            $base = (int)$row->timestart > 0 ? (int)$row->timestart : (int)$row->timecreated;
            $desiredend = self::effective_window((int)$row->userid, (int)$row->courseid, $resolved, $base);
            $currentend = (int)$row->timeend;

            // (a) Keep the access window in sync with the policy.
            if ($desiredend !== $currentend) {
                $line = sprintf('uid=%d course=%d %s: timeend %s -> %s (%s)',
                    $row->userid, $row->courseid, $row->enrol,
                    $currentend ? userdate($currentend, '%Y-%m-%d') : 'none',
                    $desiredend ? userdate($desiredend, '%Y-%m-%d') : 'none', $resolved->source);
                if ($dryrun) {
                    mtrace('  [dry-run][window] ' . $line);
                } else {
                    if (!isset($plugins[$row->enrol])) {
                        $plugins[$row->enrol] = enrol_get_plugin($row->enrol);
                    }
                    $instance = $DB->get_record('enrol', ['id' => $row->instanceid]);
                    if ($plugins[$row->enrol] && $instance) {
                        $plugins[$row->enrol]->update_user_enrol($instance, (int)$row->userid,
                            (int)$row->uestatus, (int)$row->timestart, $desiredend);
                        audit::log([
                            'action'     => audit::SETEND,
                            'ueid'       => (int)$row->id,
                            'userid'     => (int)$row->userid,
                            'courseid'   => (int)$row->courseid,
                            'enrolid'    => (int)$row->instanceid,
                            'enroltype'  => (string)$row->enrol,
                            'oldtimeend' => $currentend,
                            'oldstatus'  => (int)$row->uestatus,
                            'newtimeend' => $desiredend,
                            'newstatus'  => (int)$row->uestatus,
                            'actorid'    => $actorid,
                        ]);
                        mtrace('  [window] ' . $line);
                    }
                }
                $windowsset++;
                $currentend = $desiredend; // Reflect the change for the expiry check below.
            }

            // (b) Act on an already-expired window (idempotent; skips "keep").
            if ($currentend > 0 && $currentend <= $now && (int)$row->uestatus == ENROL_USER_ACTIVE
                    && $resolved->onexpiry !== rule::EXPIRY_KEEP) {
                if ($windowsset + $expiredacted >= $maxrows) {
                    break;
                }
                $acted = self::act_on_expiry($dryrun, $row, $resolved->onexpiry, $plugins, $actorid);
                if ($acted) {
                    $expiredacted++;
                }
            }
        }
        $rs->close();

        mtrace(sprintf('local_accessexpiry (direct): scanned=%d windows-set=%d expired-acted=%d dry-run=%s',
            $scanned, $windowsset, $expiredacted, $dryrun ? 'YES' : 'no'));
        return [$windowsset, $expiredacted, $scanned];
    }

    /**
     * Perform the on-expiry action for one enrolment.
     *
     * @param bool $dryrun
     * @param \stdClass $row the joined enrolment row
     * @param int $onexpiry EXPIRY_* action
     * @param array $plugins enrol-plugin cache by name
     * @param int $actorid who triggered this (0 = cron/system)
     * @return bool whether an action was taken (or would be, in dry-run)
     */
    protected static function act_on_expiry(bool $dryrun, \stdClass $row, int $onexpiry, array &$plugins,
            int $actorid = 0): bool {
        global $DB;
        $labels = [
            rule::EXPIRY_UNENROL        => 'unenrol',
            rule::EXPIRY_SUSPEND        => 'suspend',
            rule::EXPIRY_SUSPENDNOROLES => 'suspend+noroles',
        ];
        $actions = [
            rule::EXPIRY_UNENROL        => audit::UNENROL,
            rule::EXPIRY_SUSPEND        => audit::SUSPEND,
            rule::EXPIRY_SUSPENDNOROLES => audit::SUSPENDNOROLES,
        ];
        $label = $labels[$onexpiry] ?? 'noop';
        $line = sprintf('uid=%d course=%d %s: %s (window ended)', $row->userid, $row->courseid, $row->enrol, $label);
        if ($dryrun) {
            mtrace('  [dry-run][expire] ' . $line);
            return true;
        }
        if (!isset($plugins[$row->enrol])) {
            $plugins[$row->enrol] = enrol_get_plugin($row->enrol);
        }
        $plugin = $plugins[$row->enrol];
        $instance = $DB->get_record('enrol', ['id' => $row->instanceid]);
        if (!$plugin || !$instance) {
            return false;
        }
        $newstatus = ENROL_USER_SUSPENDED;
        if ($onexpiry === rule::EXPIRY_UNENROL) {
            $plugin->unenrol_user($instance, (int)$row->userid);
            $newstatus = null; // Enrolment removed.
        } else {
            // Suspend (optionally strip roles).
            $plugin->update_user_enrol($instance, (int)$row->userid, ENROL_USER_SUSPENDED);
            if ($onexpiry === rule::EXPIRY_SUSPENDNOROLES) {
                $coursecontext = \context_course::instance((int)$row->courseid);
                role_unassign_all(['userid' => (int)$row->userid, 'contextid' => $coursecontext->id,
                    'component' => 'enrol_' . $row->enrol, 'itemid' => (int)$row->instanceid]);
            }
        }
        audit::log([
            'action'     => $actions[$onexpiry] ?? audit::SUSPEND,
            'ueid'       => (int)$row->id,
            'userid'     => (int)$row->userid,
            'courseid'   => (int)$row->courseid,
            'enrolid'    => (int)$row->instanceid,
            'enroltype'  => (string)$row->enrol,
            'oldtimeend' => (int)$row->timeend,
            'oldstatus'  => ENROL_USER_ACTIVE,
            'newtimeend' => (int)$row->timeend,
            'newstatus'  => $newstatus,
            'actorid'    => $actorid,
        ]);
        mtrace('  [expire] ' . $line);
        return true;
    }

    /**
     * Count what a real apply() would change right now — no mutation, no output.
     * Used by the go-live preview.
     *
     * @param int|null $now
     * @return array ['windows' => int, 'expire' => int, 'protected' => int]
     */
    public static function preview(?int $now = null): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/enrollib.php');
        $now = $now ?? time();

        $index = self::rule_index();
        $sitedefault = rule::site_default();
        $haswork = !empty($index['course']) || !empty($index['category'])
            || !empty($index['role']) || !empty($index['method'])
            || $sitedefault->type !== rule::NEVER;
        if (!$haswork) {
            return ['windows' => 0, 'expire' => 0, 'protected' => 0];
        }
        $catpaths = self::course_category_paths();
        list($msql, $mparams) = $DB->get_in_or_equal(self::methods(), SQL_PARAMS_NAMED, 'm');
        $rs = $DB->get_recordset_sql(
            "SELECT ue.id, ue.userid, ue.timestart, ue.timecreated, ue.timeend, ue.status AS uestatus,
                    e.courseid, e.roleid, e.enrol, u.email
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {user} u ON u.id = ue.userid
              WHERE e.enrol $msql AND e.status = 0 AND u.deleted = 0", $mparams);
        $windows = 0;
        $expire = 0;
        $protected = 0;
        foreach ($rs as $row) {
            if (self::is_protected((int)$row->userid, (string)$row->email)) {
                $protected++;
                continue;
            }
            $resolved = self::resolve((int)$row->courseid, (string)$row->enrol, (int)$row->roleid,
                $catpaths[(int)$row->courseid] ?? [], $index);
            $base = (int)$row->timestart > 0 ? (int)$row->timestart : (int)$row->timecreated;
            $desired = self::effective_window((int)$row->userid, (int)$row->courseid, $resolved, $base);
            if ($desired !== (int)$row->timeend) {
                $windows++;
            }
            $end = $desired ?: (int)$row->timeend;
            if ($end > 0 && $end <= $now && (int)$row->uestatus == ENROL_USER_ACTIVE
                    && $resolved->onexpiry !== rule::EXPIRY_KEEP) {
                $expire++;
            }
        }
        $rs->close();
        return ['windows' => $windows, 'expire' => $expire, 'protected' => $protected];
    }

    /**
     * Set the access window on a single, newly-created enrolment (from the observer).
     *
     * Only writes when a policy actually applies and the enrolment has no end date yet,
     * so it never shortens an end date an admin set by hand.
     *
     * @param int $ueid user_enrolment id
     */
    public static function apply_to_new_enrolment(int $ueid): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/enrollib.php');
        $row = $DB->get_record_sql(
            "SELECT ue.id, ue.userid, ue.timestart, ue.timecreated, ue.timeend, ue.status AS uestatus,
                    e.id AS instanceid, e.enrol, e.courseid, e.roleid, u.email, u.deleted
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {user} u ON u.id = ue.userid
              WHERE ue.id = :id", ['id' => $ueid]);
        if (!$row || $row->deleted) {
            return;
        }
        if (!in_array($row->enrol, self::methods(), true)) {
            return;
        }
        if ((int)$row->timeend !== 0) {
            return; // Respect an explicit end date.
        }
        if (self::is_protected((int)$row->userid, (string)$row->email)) {
            return;
        }
        $resolved = self::resolve((int)$row->courseid, (string)$row->enrol, (int)$row->roleid,
            self::category_path_for_course((int)$row->courseid), self::rule_index());
        $base = (int)$row->timestart > 0 ? (int)$row->timestart : (int)$row->timecreated;
        $end = self::effective_window((int)$row->userid, (int)$row->courseid, $resolved, $base);
        if ($end <= 0) {
            return;
        }
        $plugin = enrol_get_plugin($row->enrol);
        $instance = $DB->get_record('enrol', ['id' => $row->instanceid]);
        if ($plugin && $instance) {
            $plugin->update_user_enrol($instance, (int)$row->userid, (int)$row->uestatus, (int)$row->timestart, $end);
            audit::log([
                'action'     => audit::SETEND,
                'ueid'       => (int)$row->id,
                'userid'     => (int)$row->userid,
                'courseid'   => (int)$row->courseid,
                'enrolid'    => (int)$row->instanceid,
                'enroltype'  => (string)$row->enrol,
                'oldtimeend' => 0,
                'oldstatus'  => (int)$row->uestatus,
                'newtimeend' => $end,
                'newstatus'  => (int)$row->uestatus,
                'detail'     => 'new enrolment',
            ]);
        }
    }
}
