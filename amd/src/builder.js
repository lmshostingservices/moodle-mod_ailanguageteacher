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
import {render, loadStrings} from 'mod_ailanguageteacher/ui';

/**
 * The wizard (steps 1 to 4).
 *
 * @param {string} selector
 */
export const initWizard = async(selector) => {
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
        dots.forEach((d, k) => {
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
    show(0);
};

/**
 * Step 5: draft, review and create.
 *
 * @param {string} selector
 */
export const initBuild = async(selector) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    const S = await loadStrings(['lessoninvalid', 'generating', 'creating', 'build_ai_go', 'lessonempty']);
    initCopy('.lt-copy');
    const cmid = parseInt(root.dataset.cmid, 10);
    const review = root.querySelector('[data-region="review"]');
    let draft = null;
    let another = false;

    const showDraft = async(data) => {
        draft = data;
        // Keep the editable original JSON alongside the checkbox review.
        root.querySelector('[data-region="json"]').value = JSON.stringify(data, null, 2);
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
                .map((c) => draft.scenes[parseInt(c.dataset.scene, 10)]);
            if (!keep.length) {
                Notification.alert('', S.lessonempty);
                return;
            }
            btn.disabled = true;
            btn.textContent = S.creating;
            try {
                await Ajax.call([{methodname: 'mod_ailanguageteacher_import_lesson', args: {cmid,
                    // Escape "<" so the JSON passes PARAM_TEXT; the server decodes it and strips any markup itself.
                    draft: JSON.stringify({scenes: keep}).replace(/</g, '\\u003c')}}])[0];
                window.location.href = root.dataset.scenesurl;
            } catch (err) {
                btn.disabled = false;
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

    root.querySelector('[data-action="preview"]').addEventListener('click', () => {
        const data = parse(root.querySelector('[data-region="json"]').value);
        if (!data) {
            Notification.alert('', S.lessoninvalid);
            return;
        }
        showDraft(data);
    });

    const gen = root.querySelector('[data-action="generate"]');
    if (gen) {
        gen.addEventListener('click', async() => {
            gen.disabled = true;
            gen.textContent = S.generating;
            try {
                const res = await Ajax.call([{methodname: 'mod_ailanguageteacher_generate_lesson',
                    args: {cmid, newdraft: another}}])[0];
                await showDraft(JSON.parse(res.draft));
                another = true;
            } catch (err) {
                Notification.exception(err);
            } finally {
                gen.disabled = false;
                gen.textContent = S.build_ai_go;
            }
        });
    }
};
