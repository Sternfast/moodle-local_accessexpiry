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
 * Eligibility computation and expiry execution for local_accessexpiry.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_accessexpiry\local;

/**
 * The heart of the plugin: turns per-cohort policies + guardrails into a
 * cross-DB-safe eligibility query, and expires memberships via the cohort API.
 */
class engine {

    /**
     * Build the eligibility WHERE clause over {cohort_members} cm.
     *
     * @param int|null $now evaluation time (defaults to now)
     * @return array|null [sqlwhere, params], or null if nothing is eligible by policy
     */
    public static function build_where(?int $now = null): ?array {
        global $DB;
        $now = $now ?? time();

        // 1) Group per-cohort time conditions so bind-param count scales with the number
        //    of explicit policies, not the total number of cohorts (Postgres-safe at scale).
        $policies = rule::cohort_policies();
        $defaultpolicy = get_config('local_accessexpiry', 'defaultpolicy');
        $defaultdays = (int)get_config('local_accessexpiry', 'defaultdays');

        $relativegroups = []; // days => [cohortids] (explicit relative policies).
        $absoluteexpired = []; // cohortids whose fixed expiry date has passed.
        $explicitids = []; // every cohort with an explicit policy row (any type).
        foreach ($policies as $cid => $row) {
            $cid = (int)$cid;
            $explicitids[] = $cid;
            $ptype = (int)$row->policytype;
            if ($ptype === rule::RELATIVE && !empty($row->days) && (int)$row->days > 0) {
                $relativegroups[(int)$row->days][] = $cid;
            } else if ($ptype === rule::ABSOLUTE && !empty($row->expirydate) && $now >= (int)$row->expirydate) {
                $absoluteexpired[] = $cid;
            }
        }

        $timeconds = [];
        $params = [];
        $pi = 0;
        foreach ($relativegroups as $days => $cids) {
            list($insql, $inparams) = $DB->get_in_or_equal($cids, SQL_PARAMS_NAMED, "rel{$pi}");
            $timeconds[] = "(cm.cohortid $insql AND cm.timeadded < :relcut{$pi})";
            $params = array_merge($params, $inparams);
            $params["relcut{$pi}"] = $now - ((int)$days * DAYSECS);
            $pi++;
        }
        if (!empty($absoluteexpired)) {
            list($insql, $inparams) = $DB->get_in_or_equal($absoluteexpired, SQL_PARAMS_NAMED, 'absx');
            $timeconds[] = "cm.cohortid $insql";
            $params = array_merge($params, $inparams);
        }
        // Site default applies to cohorts WITHOUT an explicit policy row.
        if ($defaultpolicy === 'relative' && $defaultdays > 0) {
            $params['defcut'] = $now - ($defaultdays * DAYSECS);
            if (!empty($explicitids)) {
                list($notinsql, $notinparams) = $DB->get_in_or_equal($explicitids, SQL_PARAMS_NAMED, 'defx', false);
                $timeconds[] = "(cm.cohortid $notinsql AND cm.timeadded < :defcut)";
                $params = array_merge($params, $notinparams);
            } else {
                $timeconds[] = '(cm.timeadded < :defcut)';
            }
        }

        if (empty($timeconds)) {
            return null;
        }
        $wherebits = ['(' . implode(' OR ', $timeconds) . ')'];

        // 2) Guardrail: only touch cohorts wired to the student role via a cohort-sync method.
        $studentroleid = (int)get_config('local_accessexpiry', 'studentroleid');
        if ($studentroleid > 0) {
            $wherebits[] = "EXISTS (SELECT 1 FROM {enrol} e
                                     WHERE e.enrol = 'cohort' AND e.status = 0
                                       AND e.customint1 = cm.cohortid
                                       AND e.roleid = :studentrole)";
            $params['studentrole'] = $studentroleid;
        }

        // 3) Guardrail: exclude users holding any elevated role assignment anywhere.
        $elevated = self::config_intlist('elevatedroleids');
        if (!empty($elevated)) {
            list($insql, $inparams) = $DB->get_in_or_equal($elevated, SQL_PARAMS_NAMED, 'erole');
            $wherebits[] = "NOT EXISTS (SELECT 1 FROM {role_assignments} ra
                                         WHERE ra.userid = cm.userid AND ra.roleid $insql)";
            $params = array_merge($params, $inparams);
        }

        // 4) Guardrail: exclude site admins.
        if (get_config('local_accessexpiry', 'excludesiteadmins')) {
            $siteadmins = trim((string)get_config('core', 'siteadmins'), " ,");
            if ($siteadmins !== '') {
                $adminids = array_filter(array_map('intval', explode(',', $siteadmins)));
                if (!empty($adminids)) {
                    list($insql, $inparams) = $DB->get_in_or_equal($adminids, SQL_PARAMS_NAMED, 'sadm', false);
                    $wherebits[] = "cm.userid $insql";
                    $params = array_merge($params, $inparams);
                }
            }
        }

        // 5) Guardrail: exclude configured staff email domains (cross-DB safe).
        $domains = preg_split('/[\s,]+/', (string)get_config('local_accessexpiry', 'staffdomains'), -1, PREG_SPLIT_NO_EMPTY);
        if (!empty($domains)) {
            $likes = [];
            foreach (array_values($domains) as $k => $dom) {
                $dom = ltrim(trim($dom), '@');
                if ($dom === '') {
                    continue;
                }
                $likes[] = $DB->sql_like('u.email', ":dom{$k}", false);
                $params["dom{$k}"] = '%@' . $DB->sql_like_escape($dom);
            }
            if (!empty($likes)) {
                $wherebits[] = "NOT EXISTS (SELECT 1 FROM {user} u
                                             WHERE u.id = cm.userid AND (" . implode(' OR ', $likes) . "))";
            }
        }

        // 6) Guardrail: never remove a member who has an active, still-current cohort
        //    extension — unlimited, or with an end date still in the future.
        $wherebits[] = "NOT EXISTS (SELECT 1 FROM {local_accessexpiry_extension} ex
                                     WHERE ex.userid = cm.userid AND ex.targettype = :extcohort
                                       AND ex.targetid = cm.cohortid AND ex.revoked = 0
                                       AND (ex.grantmode = :extunlim OR ex.enddate IS NULL OR ex.enddate > :extnow))";
        $params['extcohort'] = rule::SCOPE_COHORT;
        $params['extunlim'] = extension::MODE_UNLIMITED;
        $params['extnow'] = $now;

        return [implode(' AND ', $wherebits), $params];
    }

    /**
     * Parse a CSV/whitespace plugin setting into a list of ints.
     *
     * @param string $name setting name
     * @return int[]
     */
    public static function config_intlist(string $name): array {
        $raw = (string)get_config('local_accessexpiry', $name);
        if (trim($raw) === '') {
            return [];
        }
        $out = array_filter(array_map('intval', preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY)));
        return array_values(array_unique($out));
    }

    /**
     * Total memberships eligible for removal right now.
     *
     * @param int|null $now
     * @return int
     */
    public static function count_eligible(?int $now = null): int {
        global $DB;
        $built = self::build_where($now);
        if ($built === null) {
            return 0;
        }
        list($where, $params) = $built;
        return (int)$DB->count_records_sql("SELECT COUNT(*) FROM {cohort_members} cm WHERE $where", $params);
    }

    /**
     * Eligible counts grouped by cohort (one query, for the manage grid).
     *
     * @param int|null $now
     * @return array cohortid => count
     */
    public static function counts_by_cohort(?int $now = null): array {
        global $DB;
        $built = self::build_where($now);
        if ($built === null) {
            return [];
        }
        list($where, $params) = $built;
        $rows = $DB->get_records_sql(
            "SELECT cm.cohortid, COUNT(*) AS n FROM {cohort_members} cm WHERE $where GROUP BY cm.cohortid",
            $params);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->cohortid] = (int)$r->n;
        }
        return $out;
    }

    /**
     * The oldest candidate rows, capped.
     *
     * @param int $maxrows
     * @param int|null $now
     * @return array
     */
    public static function get_candidates(int $maxrows, ?int $now = null): array {
        global $DB;
        $built = self::build_where($now);
        if ($built === null) {
            return [];
        }
        list($where, $params) = $built;
        return $DB->get_records_sql(
            "SELECT cm.id, cm.userid, cm.cohortid, cm.timeadded
               FROM {cohort_members} cm
              WHERE $where
           ORDER BY cm.timeadded ASC",
            $params, 0, $maxrows);
    }

    /**
     * Expire up to $maxrows memberships (or report them, if $dryrun).
     *
     * Uses cohort_remove_member() so the cohort_member_removed event fires and
     * enrol_cohort (and tool_cohortroles, etc.) clean up enrolments/roles correctly.
     *
     * @param bool $dryrun
     * @param int $maxrows
     * @param int|null $now
     * @return array [processed, totaleligible]
     */
    public static function run(bool $dryrun, int $maxrows, ?int $now = null, int $actorid = 0): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $now = $now ?? time();

        // Build the eligibility clause once and reuse it for the count + the fetch.
        $built = self::build_where($now);
        $total = 0;
        if ($built !== null) {
            list($where, $params) = $built;
            $total = (int)$DB->count_records_sql("SELECT COUNT(*) FROM {cohort_members} cm WHERE $where", $params);
        }
        mtrace("local_accessexpiry: eligible = {$total} | dry-run: " . ($dryrun ? 'YES' : 'no') . " | max-rows: {$maxrows}");
        if ($total === 0) {
            mtrace('local_accessexpiry: nothing to remove. STATUS: SUCCESS 0 rows');
            return [0, 0];
        }

        $candidates = $DB->get_records_sql(
            "SELECT cm.id, cm.userid, cm.cohortid, cm.timeadded
               FROM {cohort_members} cm
              WHERE $where
           ORDER BY cm.timeadded ASC",
            $params, 0, $maxrows);

        // Preload user + cohort names for the batch (avoids per-row lookups when logging).
        $userids = array_values(array_unique(array_map(function($cm) {
            return $cm->userid;
        }, $candidates)));
        $cohortids = array_values(array_unique(array_map(function($cm) {
            return $cm->cohortid;
        }, $candidates)));
        $users = $userids ? $DB->get_records_list('user', 'id', $userids, '', 'id, firstname, lastname') : [];
        $cohortnames = $cohortids ? $DB->get_records_list('cohort', 'id', $cohortids, '', 'id, name, idnumber') : [];

        $processed = 0;
        foreach ($candidates as $cm) {
            $u = $users[$cm->userid] ?? null;
            $c = $cohortnames[$cm->cohortid] ?? null;
            $label = $c ? trim(($c->idnumber ?? '') !== '' ? $c->idnumber : ($c->name ?? '')) : '';
            $line = sprintf('uid=%d %s %s | cohort=%d (%s) | joined %s',
                $cm->userid, $u->firstname ?? '?', $u->lastname ?? '?', $cm->cohortid,
                $label, userdate($cm->timeadded, '%Y-%m-%d %H:%M'));
            if ($dryrun) {
                mtrace('  [dry-run] ' . $line);
            } else {
                cohort_remove_member($cm->cohortid, $cm->userid);
                audit::log([
                    'action'   => audit::COHORTREMOVE,
                    'userid'   => (int)$cm->userid,
                    'cohortid' => (int)$cm->cohortid,
                    'actorid'  => $actorid,
                    'detail'   => $label,
                ]);
                mtrace('  [removed] ' . $line);
            }
            $processed++;
        }
        $backlog = $total - $processed;
        if ($dryrun) {
            mtrace("local_accessexpiry: STATUS: DRY-RUN {$processed} rows would be removed (backlog after: {$backlog})");
        } else {
            mtrace("local_accessexpiry: STATUS: SUCCESS {$processed} rows (backlog after: {$backlog})");
        }
        return [$processed, $total];
    }
}
