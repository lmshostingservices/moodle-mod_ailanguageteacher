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
 * Lesson builder: the four-step wizard, then drafting, reviewing and creating the scenes.
 *
 * Without JavaScript every step of the wizard is shown on one page and the form still works.
 *
 * @module     mod_ailanguageteacher/builder
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {init as initCopy} from 'mod_ailanguageteacher/copy';
import {render, loadStrings, fmt} from 'mod_ailanguageteacher/ui';

/**
 * The wizard (steps 1 to 4).
 *
 * @param {string} selector
 * @param {number} start the step to open (1 to 4), e.g. 4 when coming back from step 5
 */
export const initWizard = async(selector, start = 1) => {
    const form = document.querySelector(selector);
    if (!form) {
        return;
    }
    const steps = Array.from(form.querySelectorAll('[data-step]'));
    const dots = Array.from(form.querySelectorAll('[data-stepdot]'));
    const prev = form.querySelector('[data-action="prev"]');
    const next = form.querySelector('[data-action="nextstep"]');
    const save = form.querySelector('[data-action="save"]');
    let current = 0;
    const progress = document.querySelector('[data-region="setupprogress"]');
    const P = await loadStrings(['setup_progress']);
    form.classList.add('is-enhanced');

    const target = () => (form.querySelector('input[name="targetlang"]:checked') || {}).value;
    const syncVariety = () => {
        form.querySelectorAll('[data-variety]').forEach((v) => {
            v.hidden = v.dataset.variety !== target();
        });
        form.querySelectorAll('[data-region="ielts"]').forEach((i) => {
            i.hidden = target() !== 'en';
        });
    };
    const syncPack = () => {
        const chosen = form.querySelector('input[name="supportlang"]:checked');
        const haspack = chosen && chosen.dataset.haspack === '1';
        form.querySelector('[data-region="forcelang"]').hidden = !haspack;
        form.querySelector('[data-region="nopack"]').hidden = haspack;
    };
    const situationsChosen = () => form.querySelectorAll('input[name="situations[]"]:checked').length > 0 ||
        Array.from(form.querySelectorAll('input[name="custom[]"]')).some((i) => i.value.trim() !== '');
    const show = (i) => {
        current = Math.max(0, Math.min(steps.length - 1, i));
        steps.forEach((s, k) => {
            s.hidden = k !== current;
        });
        if (progress) {
            progress.textContent = fmt(P.setup_progress, {current: current + 1, total: 9});
        }
        dots.forEach((d, k) => {
            if (k === current) {
                d.setAttribute('aria-current', 'step');
            } else {
                d.removeAttribute('aria-current');
            }
            d.classList.toggle('is-active', k === current);
            d.classList.toggle('is-done', k < current);
        });
        prev.hidden = current === 0;
        next.hidden = current === steps.length - 1;
        save.hidden = current !== steps.length - 1;
        const first = steps[current].querySelector('input:checked, input, select');
        if (first) {
            first.focus({preventScroll: true});
        }
        form.scrollIntoView({behavior: 'smooth', block: 'start'});
    };
    const valid = () => {
        if (current === 1 && !situationsChosen()) {
            form.querySelector('[data-region="siterror"]').hidden = false;
            return false;
        }
        form.querySelector('[data-region="siterror"]').hidden = true;
        return true;
    };

    form.addEventListener('change', (e) => {
        if (e.target.name === 'targetlang') {
            syncVariety();
        } else if (e.target.name === 'supportlang') {
            syncPack();
        }
    });
    prev.addEventListener('click', () => show(current - 1));
    next.addEventListener('click', () => {
        if (valid()) {
            show(current + 1);
        }
    });
    form.addEventListener('submit', (e) => {
        if (!situationsChosen()) {
            e.preventDefault();
            show(1);
            valid();
        }
    });
    form.querySelector('[data-action="addcustom"]').addEventListener('click', () => {
        const hidden = form.querySelector('input[name="custom[]"][hidden]');
        if (hidden) {
            hidden.hidden = false;
            hidden.focus();
        }
    });
    syncVariety();
    syncPack();
    show(start - 1);
};

/**
 * Step 5: pick a way to create the scenes, review the draft, and create it. Both ways are charged: an LMS Labs draft
 * when it is delivered (its scenes are then created free, once), scenes from the teacher's own AI assistant when they
 * are created. Every charge is confirmed first, with the credits named.
 *
 * @param {string} selector
 */
export const initBuild = async(selector) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    const S = await loadStrings(['lessoninvalid', 'generating', 'creating', 'lessonempty', 'confirm_title',
        'confirm_create', 'draft_confirm', 'import_confirm', 'discard_confirm', 'import_done']);
    initCopy('.lt-copy');
    const cmid = parseInt(root.dataset.cmid, 10);
    const perscene = parseInt(root.dataset.importcredits, 10);
    const review = root.querySelector('[data-region="review"]');
    let another = false;

    const confirm = (question) => new Promise((resolve) => {
        Notification.saveCancel(S.confirm_title, question, S.confirm_create, () => resolve(true), () => resolve(false));
    });
    // An unfinished earlier request with different content: only abandoned when the teacher says so.
    const call = async(methodname, args) => {
        try {
            return await Ajax.call([{methodname, args}], true, true, false, 120000)[0];
        } catch (err) {
            if (err && err.errorcode === 'operationconflict' && await confirm(S.discard_confirm)) {
                return Ajax.call([{methodname, args: {...args, discard: true}}], true, true, false, 120000)[0];
            }
            throw err;
        }
    };

    // Two ways to create scenes: show only the one the teacher picks.
    root.querySelectorAll('input[name="lt-path"]').forEach((radio) => radio.addEventListener('change', () => {
        root.querySelectorAll('[data-path]').forEach((path) => {
            path.hidden = path.dataset.path !== radio.value;
        });
        review.hidden = true;
    }));

    const showDraft = async(data, draftid) => {
        let phrases = 0;
        data.scenes.forEach((s) => {
            phrases += s.phrases.length;
        });
        const node = await render('builder_review', {count: data.scenes.length, phrases,
            scenes: data.scenes.map((s, index) => ({...s, index}))});
        review.replaceChildren(node);
        review.hidden = false;
        review.scrollIntoView({behavior: 'smooth', block: 'start'});
        node.querySelector('[data-action="create"]').addEventListener('click', async(e) => {
            const btn = e.currentTarget;
            const keep = Array.from(node.querySelectorAll('[data-scene]')).filter((c) => c.checked)
                .map((c) => data.scenes[parseInt(c.dataset.scene, 10)]);
            if (!keep.length) {
                Notification.alert('', S.lessonempty);
                return;
            }
            // The teacher's own AI assistant: charged per scene like LMS Labs AI, confirmed first. An LMS Labs draft is paid for.
            if (!draftid && !await confirm(fmt(S.import_confirm, {count: keep.length, credits: keep.length * perscene}))) {
                return;
            }
            const original = btn.textContent;
            btn.disabled = true;
            btn.textContent = S.creating;
            try {
                const res = await call('mod_ailanguageteacher_import_lesson', {cmid, draftid,
                    // Escape "<" so the JSON passes PARAM_TEXT; the server decodes it and strips any markup itself.
                    draft: JSON.stringify({scenes: keep}).replace(/</g, '\\u003c')});
                if (res.charged > 0) {
                    Notification.addNotification({message: fmt(S.import_done, res), type: 'success'});
                }
                window.location.href = root.dataset.nexturl;
            } catch (err) {
                btn.disabled = false;
                btn.textContent = original;
                Notification.exception(err);
            }
        });
    };

    /**
     * Reads pasted AI output (tolerates code fences and text around the JSON).
     *
     * @param {string} raw
     * @returns {object|null}
     */
    const parse = (raw) => {
        const text = raw.trim().replace(/^```[a-z]*\s*/i, '').replace(/```\s*$/, '');
        const start = text.indexOf('{');
        const end = text.lastIndexOf('}');
        if (start < 0 || end <= start) {
            return null;
        }
        try {
            const data = JSON.parse(text.slice(start, end + 1));
            if (!data || !Array.isArray(data.scenes)) {
                return null;
            }
            data.scenes = data.scenes.filter((s) => s && s.title && Array.isArray(s.phrases) && s.phrases.length)
                .map((s) => ({
                    situation: String(s.situation || ''),
                    title: String(s.title),
                    context: String(s.context || ''),
                    imageprompt: String(s.imageprompt || ''),
                    phrases: s.phrases.filter((p) => p && p.text).map((p) => ({...p, text: String(p.text)})),
                    distractors: (s.distractors || []).map((d) => (typeof d === 'string' ? {text: d} : d))
                        .filter((d) => d && d.text),
                }));
            return data.scenes.length ? data : null;
        } catch (e) {
            return null;
        }
    };

    const preview = root.querySelector('[data-action="preview"]');
    preview?.addEventListener('click', () => {
        const data = parse(root.querySelector('[data-region="json"]').value);
        if (!data) {
            Notification.alert('', S.lessoninvalid);
            return;
        }
        showDraft(data, 0);
    });

    const gen = root.querySelector('[data-action="generate"]');
    if (gen) {
        const label = gen.querySelector('span');
        const original = label.textContent;
        gen.addEventListener('click', async() => {
            if (!await confirm(S.draft_confirm)) {
                return;
            }
            gen.disabled = true;
            label.textContent = S.generating;
            try {
                // One intentional request; an unanswered one is asked again with the same key, never a new charge.
                const res = await call('mod_ailanguageteacher_generate_lesson', {cmid, newdraft: another});
                await showDraft(JSON.parse(res.draft), res.draftid);
                another = true;
            } catch (err) {
                Notification.exception(err);
            } finally {
                gen.disabled = false;
                label.textContent = original;
            }
        });
    }
};
