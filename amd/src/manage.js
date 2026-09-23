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
 * Progressive enhancement for the cohort access-expiry management grid:
 * show only the value input relevant to each row's policy, wire the
 * "reset to default" links, and filter the grid.
 *
 * @module     local_accessexpiry/manage
 * @copyright  2026 Sternfast
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Show the days or date input that matches the selected policy for a row.
 *
 * @param {HTMLSelectElement} select The policy select element.
 */
const toggleRow = (select) => {
    const value = select.value;
    const row = select.closest('tr');
    if (!row) {
        return;
    }
    row.querySelectorAll('.ce-days').forEach((el) => {
        el.style.display = (value === 'relative') ? '' : 'none';
    });
    row.querySelectorAll('.ce-date').forEach((el) => {
        el.style.display = (value === 'absolute') ? '' : 'none';
    });
};

/**
 * Initialise the management grid behaviour.
 */
export const init = () => {
    document.querySelectorAll('.ce-policy').forEach((select) => {
        toggleRow(select);
        select.addEventListener('change', () => toggleRow(select));
    });

    document.querySelectorAll('.ce-reset').forEach((link) => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const id = link.getAttribute('data-cohortid');
            const target = document.querySelector('.ce-policy[data-cohortid="' + id + '"]');
            if (target) {
                target.value = 'usedefault';
                target.dispatchEvent(new Event('change'));
            }
        });
    });

    const filter = document.getElementById('ae-filter');
    if (filter) {
        filter.addEventListener('input', () => {
            const query = filter.value.toLowerCase();
            document.querySelectorAll('#ae-cohort-grid tbody tr').forEach((tr) => {
                tr.style.display = (tr.textContent.toLowerCase().indexOf(query) !== -1) ? '' : 'none';
            });
        });
    }
};
