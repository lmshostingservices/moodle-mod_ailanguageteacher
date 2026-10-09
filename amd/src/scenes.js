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
 * Blocks Back, Next and the other set-up links while voices are being made (leaving would stop the run), and asks
 * before the page is closed. The links look disabled, and clicks on them do nothing.
 *
 * @param {boolean} on
 * @param {string} busytext
 */
const lockNav = (on, busytext = '') => {
    document.querySelectorAll('.lt-setupnav a, .lt-setupnav button').forEach((link) => {
        link.classList.toggle('is-busy', on);
        if (on) {
            link.setAttribute('aria-disabled', 'true');
            link.setAttribute('tabindex', '-1');
            link.dataset.ltTitle = link.getAttribute('title') || '';
            link.setAttribute('title', busytext);
        } else {
            link.removeAttribute('aria-disabled');
            link.removeAttribute('tabindex');
            link.setAttribute('title', link.dataset.ltTitle || '');
        }
    });
    window.onbeforeunload = on ? () => busytext : null;
};
document.addEventListener('click', (e) => {
    if (e.target.closest('.lt-setupnav [aria-disabled="true"]')) {
        e.preventDefault();
        e.stopPropagation();
    }
}, true);

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
        'discard_confirm', 'voices_quoting', 'voices_confirm_head', 'voices_confirm_headone', 'voices_confirm_new',
        'voices_confirm_newone', 'voices_confirm_free', 'voices_confirm_freeone', 'voices_confirm_note', 'voices_busy']);
    const confirm = (question) => new Promise((resolve) => {
        Notification.saveCancel(S.confirm_title, question, S.confirm_create, () => resolve(true), () => resolve(false));
    });
    all.addEventListener('click', async() => {
        const ids = all.dataset.phrases.split(',').map((v) => parseInt(v, 10)).filter((v) => v > 0);
        const voice = root.querySelector('[data-region="voice"]').value;
        // Every paid request is confirmed first; the credits are named.
        const each = parseInt(all.dataset.credits, 10);
        // With free remakes, each voice's current price comes from LMS Labs first (free) and is its ceiling.
        const prices = new Map();
        let question = fmt(S.voices_confirm, {count: ids.length, credits: ids.length * each});
        if (all.dataset.remakes === '1' && ids.length && voice) {
            const label = all.querySelector('span');
            const before = label.textContent;
            all.disabled = true;
            label.textContent = S.voices_quoting;
            try {
                const quotes = await Ajax.call([{methodname: 'mod_ailanguageteacher_quote_audio',
                    args: {phraseids: ids, variant: 'normal'}}], true, true, false, 120000)[0];
                quotes.forEach((q) => prices.set(q.phraseid, q.credits));
            } catch (err) {
                Notification.exception(err);
                return;
            } finally {
                all.disabled = false;
                label.textContent = before;
            }
            const paid = ids.filter((id) => prices.get(id) !== 0).length;
            const free = ids.length - paid;
            const parts = [];
            if (paid) {
                parts.push(fmt(paid === 1 ? S.voices_confirm_newone : S.voices_confirm_new, {paid, each, credits: paid * each}));
            }
            if (free) {
                parts.push(fmt(free === 1 ? S.voices_confirm_freeone : S.voices_confirm_free, free));
            }
            question = fmt(ids.length === 1 ? S.voices_confirm_headone : S.voices_confirm_head, ids.length) + ' '
                + parts.join(' ') + ' ' + S.voices_confirm_note;
        }
        if (!ids.length || !voice || !await confirm(question)) {
            return;
        }
        const label = all.querySelector('span');
        const original = label.textContent;
        all.disabled = true;
        lockNav(true, S.voices_busy);
        let made = 0;
        try {
            // One after another; the run stops at the first voice that is not delivered (nothing is retried).
            for (const phraseid of ids) {
                label.textContent = fmt(S.voices_progress, {done: made + 1, count: ids.length});
                const args = {phraseid, voice, variant: 'normal', maxcredits: prices.get(phraseid) === 0 ? 0 : each};
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
            lockNav(false);
            all.disabled = false;
            label.textContent = original;
            Notification.exception(err);
            return;
        }
        lockNav(false);
        window.location.reload();
    });
};
