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
 * Set-up steps 6 to 8. Voices: "Create missing voices" makes every missing phrase voice, one after another.
 *
 * @module     mod_ailanguageteacher/scenes
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {loadStrings, fmt} from 'mod_ailanguageteacher/ui';

/**
 * Initialises the page.
 *
 * @param {string} selector
 */
export const init = async(selector) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    const all = root.querySelector('[data-action="voiceall"]');
    if (!all) {
        return;
    }
    const S = await loadStrings(['confirm_title', 'confirm_create', 'voices_confirm', 'voices_progress',
        'discard_confirm']);
    const confirm = (question) => new Promise((resolve) => {
        Notification.saveCancel(S.confirm_title, question, S.confirm_create, () => resolve(true), () => resolve(false));
    });
    all.addEventListener('click', async() => {
        const ids = all.dataset.phrases.split(',').map((v) => parseInt(v, 10)).filter((v) => v > 0);
        const voice = root.querySelector('[data-region="voice"]').value;
        // Every paid request is confirmed first; the credits are named.
        const credits = ids.length * parseInt(all.dataset.credits, 10);
        if (!ids.length || !voice || !await confirm(fmt(S.voices_confirm, {count: ids.length, credits}))) {
            return;
        }
        const label = all.querySelector('span');
        const original = label.textContent;
        all.disabled = true;
        let made = 0;
        try {
            // One after another; the run stops at the first voice that is not delivered (nothing is retried).
            for (const phraseid of ids) {
                label.textContent = fmt(S.voices_progress, {done: made + 1, count: ids.length});
                const args = {phraseid, voice, variant: 'normal'};
                try {
                    await Ajax.call([{methodname: 'mod_ailanguageteacher_create_audio', args}], true, true, false, 120000)[0];
                } catch (err) {
                    if (err && err.errorcode === 'operationconflict' && await confirm(S.discard_confirm)) {
                        await Ajax.call([{methodname: 'mod_ailanguageteacher_create_audio', args: {...args, discard: true}}],
                            true, true, false, 120000)[0];
                    } else {
                        throw err;
                    }
                }
                made++;
            }
        } catch (err) {
            all.disabled = false;
            label.textContent = original;
            Notification.exception(err);
            return;
        }
        window.location.reload();
    });
};
