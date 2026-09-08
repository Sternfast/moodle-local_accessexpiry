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
 * Access-duration rule storage and scope resolution.
 *
 * @package    local_accessexpiry
 * @copyright  2026 Vbounds LLC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_accessexpiry\local;

/**
 * Reads, writes and resolves access-duration rules.
 *
 * A rule lives at a scope (site, category, course, role, enrolment method or
 * cohort). Resolution walks from the most specific scope to the broadest,
 * falling back to the site-default settings. Per-user extensions (handled
 * elsewhere) are layered on top and always win.
 */
class rule {

    // Scope levels — a larger number is MORE specific and wins resolution.
    /** @var int Whole site (the settings-page default). */
    const SCOPE_SITE = 10;
    /** @var int A course category (and its descendants). */
    const SCOPE_CATEGORY = 20;
    /** @var int A single course. */
    const SCOPE_COURSE = 30;
    /** @var int A role granted by the enrolment. */
    const SCOPE_ROLE = 40;
    /** @var int A specific enrolment method site-wide. */
    const SCOPE_ENROLMETHOD = 50;
    /** @var int A cohort. */
    const SCOPE_COHORT = 60;

    // Duration types.
    /** @var int Never expire. */
    const NEVER = 0;
    /** @var int Expire a number of days after access began. */
    const RELATIVE = 1;
    /** @var int Expire once a fixed calendar date passes. */
    const ABSOLUTE = 2;

    // On-expiry actions (direct enrolment; the cohort path always removes membership).
    /** @var int Unenrol the user. */
    const EXPIRY_UNENROL = 0;
    /** @var int Keep the enrolment (report only). */
    const EXPIRY_KEEP = 1;
    /** @var int Suspend the enrolment. */
    const EXPIRY_SUSPEND = 2;
    /** @var int Suspend and remove the user's roles. */
    const EXPIRY_SUSPENDNOROLES = 3;

    /** @var string The rule table. */
    const TABLE = 'local_accessexpiry_rule';

    /**
     * All rules at a scope level, keyed by id.
     *
     * @param int $scopelevel one of the SCOPE_* constants
     * @param bool $enabledonly restrict to enabled rules
     * @return array id => record
     */
    public static function get_by_level(int $scopelevel, bool $enabledonly = true): array {
        global $DB;
        $conditions = ['scopelevel' => $scopelevel];
        if ($enabledonly) {
            $conditions['enabled'] = 1;
        }
        return $DB->get_records(self::TABLE, $conditions);
    }

    /**
     * Fetch the single rule for an exact scope tuple, or null.
     *
     * @param int $scopelevel
     * @param int $scopeid
     * @param string $enrolmethod
     * @return \stdClass|null
     */
    public static function get(int $scopelevel, int $scopeid, string $enrolmethod = ''): ?\stdClass {
        global $DB;
        $row = $DB->get_record(self::TABLE, [
            'scopelevel' => $scopelevel, 'scopeid' => $scopeid, 'enrolmethod' => $enrolmethod]);
        return $row ?: null;
    }

    /**
     * Create or update a rule for a scope tuple.
     *
     * @param int $scopelevel
     * @param int $scopeid
     * @param string $enrolmethod enrolment plugin name, or '' for any
     * @param int $durationtype NEVER|RELATIVE|ABSOLUTE
     * @param int|null $days days for RELATIVE
     * @param int|null $enddate unix ts for ABSOLUTE
     * @param int $onexpiry EXPIRY_* action for direct enrolment
     * @param int $enabled 1|0
     * @return int the rule id
     */
    public static function set(int $scopelevel, int $scopeid, string $enrolmethod,
            int $durationtype, ?int $days, ?int $enddate,
            int $onexpiry = self::EXPIRY_UNENROL, int $enabled = 1): int {
        global $DB;
        $now = time();
        $rec = (object)[
            'scopelevel'   => $scopelevel,
            'scopeid'      => $scopeid,
            'enrolmethod'  => $enrolmethod,
            'durationtype' => $durationtype,
            'durationdays' => ($durationtype === self::RELATIVE) ? $days : null,
            'enddate'      => ($durationtype === self::ABSOLUTE) ? $enddate : null,
            'onexpiry'     => $onexpiry,
            'enabled'      => $enabled,
            'timemodified' => $now,
        ];
        $existing = $DB->get_record(self::TABLE, [
            'scopelevel' => $scopelevel, 'scopeid' => $scopeid, 'enrolmethod' => $enrolmethod]);
        if ($existing) {
            $rec->id = $existing->id;
            $DB->update_record(self::TABLE, $rec);
            return (int)$existing->id;
        }
        $rec->timecreated = $now;
        return (int)$DB->insert_record(self::TABLE, $rec);
    }

    /**
     * Delete a rule for a scope tuple (no-op if it does not exist).
     *
     * @param int $scopelevel
     * @param int $scopeid
     * @param string $enrolmethod
     */
    public static function clear(int $scopelevel, int $scopeid, string $enrolmethod = ''): void {
        global $DB;
        $DB->delete_records(self::TABLE, [
            'scopelevel' => $scopelevel, 'scopeid' => $scopeid, 'enrolmethod' => $enrolmethod]);
    }

    // ------------------------------------------------------------------
    // Cohort-scope convenience (the cohort engine + management grid).
    // ------------------------------------------------------------------

    /**
     * Enabled COHORT-scope rules as a cohortid => policy-shaped object map.
     *
     * The shape (->policytype/->days/->expirydate) matches what the cohort
     * engine and management grid consume.
     *
     * @return array cohortid => \stdClass{policytype, days, expirydate}
     */
    public static function cohort_policies(): array {
        global $DB;
        $out = [];
        $rows = $DB->get_records(self::TABLE, ['scopelevel' => self::SCOPE_COHORT, 'enabled' => 1]);
        foreach ($rows as $r) {
            $out[(int)$r->scopeid] = (object)[
                'policytype' => (int)$r->durationtype,
                'days'       => ($r->durationdays !== null) ? (int)$r->durationdays : null,
                'expirydate' => ($r->enddate !== null) ? (int)$r->enddate : null,
            ];
        }
        return $out;
    }

    /**
     * Set a cohort's rule.
     *
     * @param int $cohortid
     * @param int $type NEVER|RELATIVE|ABSOLUTE
     * @param int|null $days
     * @param int|null $enddate
     * @return int rule id
     */
    public static function set_cohort(int $cohortid, int $type, ?int $days, ?int $enddate): int {
        return self::set(self::SCOPE_COHORT, $cohortid, '', $type, $days, $enddate);
    }

    /**
     * Remove a cohort's rule (reverts it to the site default).
     *
     * @param int $cohortid
     */
    public static function clear_cohort(int $cohortid): void {
        self::clear(self::SCOPE_COHORT, $cohortid, '');
    }

    /**
     * Effective policy for a cohort: its explicit rule, else the site default.
     *
     * @param int $cohortid
     * @param array|null $cohortpolicies pre-fetched map from cohort_policies()
     * @return \stdClass ->type, ->days, ->expirydate, ->source ('cohort'|'default')
     */
    public static function resolve_cohort(int $cohortid, ?array $cohortpolicies = null): \stdClass {
        if ($cohortpolicies === null) {
            $cohortpolicies = self::cohort_policies();
        }
        $row = $cohortpolicies[$cohortid] ?? null;
        if ($row) {
            $eff = new \stdClass();
            $eff->type = (int)$row->policytype;
            $eff->days = ($row->days !== null) ? (int)$row->days : null;
            $eff->expirydate = ($row->expirydate !== null) ? (int)$row->expirydate : null;
            $eff->source = 'cohort';
            return $eff;
        }
        return self::site_default();
    }

    /**
     * The site-default policy, read from the plugin settings.
     *
     * @return \stdClass ->type, ->days, ->expirydate, ->source ('default')
     */
    public static function site_default(): \stdClass {
        $eff = new \stdClass();
        $defaultpolicy = get_config('local_accessexpiry', 'defaultpolicy');
        $defaultdays = (int)get_config('local_accessexpiry', 'defaultdays');
        if ($defaultpolicy === 'relative' && $defaultdays > 0) {
            $eff->type = self::RELATIVE;
            $eff->days = $defaultdays;
            $eff->expirydate = null;
        } else {
            $eff->type = self::NEVER;
            $eff->days = null;
            $eff->expirydate = null;
        }
        $eff->source = 'default';
        return $eff;
    }
}
