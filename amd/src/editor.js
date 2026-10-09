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
 * Scene editor: click the picture once per phrase to place it (a queue, like Label Diagram), drag pins to adjust,
 * edit meanings, notes, speaking cues and accepted answers, record your own voice. Changes save automatically.
 *
 * @module     mod_ailanguageteacher/editor
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import * as Speech from 'mod_ailanguageteacher/speech';
import {loadStrings, fmt, el, render, renderAll, readable} from 'mod_ailanguageteacher/ui';

const KEYS = ['saved', 'saving', 'unsaved', 'clicktoplace', 'placingof', 'skip', 'stop', 'newphrase', 'limitreached',
    'emptyeditor', 'recording', 'recordstop', 'placeon', 'audiounavailable', 'confirmdeletephrase', 'deletephrase',
    'speechcatalogunavailable', 'confirm_title', 'confirm_create', 'voice_confirm', 'voice_confirm_free',
    'discard_confirm', 'limit_count',
    'limit_over'];

let S = {};

/**
 * Parses one Quick add line: "Phrase", "Phrase | Meaning" or "Phrase | Meaning | When to use it".
 *
 * @param {string} line
 * @returns {object}
 */
const parseLine = (line) => {
    const text = String(line).replace(/^\s*(\d+[.)]|[-*•])\s*/, '').trim();
    const parts = text.includes('|') ? text.split('|').map((p) => p.trim()) : [text];
    return {text: parts[0] || '', translation: parts[1] || '', usagenote: parts.slice(2).join(' | ')};
};

/**
 * The editor.
 */
/**
 * Adds a live "120 / 300" counter under fields with data-lt-limit, so the text fits neatly on the learner's page.
 *
 * @param {HTMLElement} root
 */
const limits = (root) => {
    root.querySelectorAll('[data-lt-limit]').forEach((field) => {
        if (field.dataset.ltCounted) {
            return;
        }
        field.dataset.ltCounted = '1';
        const max = parseInt(field.dataset.ltLimit, 10);
        const counter = document.createElement('span');
        counter.className = 'lt-limit lt-mini';
        counter.id = 'lt-limit-' + Math.random().toString(36).slice(2);
        field.setAttribute('aria-describedby', counter.id);
        field.insertAdjacentElement('afterend', counter);
        const update = () => {
            const length = Array.from(field.value.trim()).length;
            counter.textContent = fmt(length > max ? S.limit_over : S.limit_count, {length, max});
            counter.classList.toggle('is-over', length > max);
            // Announced only when the text goes over the limit, not on every key.
            counter.setAttribute('role', length > max ? 'status' : 'note');
        };
        field.addEventListener('input', update);
        update();
    });
};

class Editor {
    /**
     * Constructor.
     *
     * @param {HTMLElement} root
     * @param {object} cfg
     */
    constructor(root, cfg) {
        this.root = root;
        this.cfg = cfg;
        this.seq = 0;
        this.phrases = cfg.phrases.map((p) => ({...p, key: this.key()}));
        this.selected = null;
        this.placing = null;
        this.skipped = new Set();
        this.dirty = false;
        this.saving = null;
    }

    /**
     * Unique client key.
     *
     * @returns {string}
     */
    key() {
        this.seq++;
        return `k${this.seq}`;
    }

    /**
     * Builds the layout.
     */
    async start() {
        const c = this.cfg;
        const nodes = await renderAll('editor_layout', {...c, ratio: c.width / c.height,
            situations: c.situations.map((s) => ({...s, selected: s.id === c.situationid}))});
        this.root.replaceChildren(...nodes);
        limits(this.root);
        const q = (r) => this.root.querySelector(`[data-region="${r}"]`);
        this.figure = q('figure');
        this.cursor = q('cursor');
        this.banner = q('banner');
        this.list = q('list');
        this.countEl = q('count');
        this.stateEl = q('state');
        this.root.querySelectorAll('[data-field]').forEach((f) => {
            if (!f.closest('.lt-lrow')) {
                f.addEventListener('input', () => this.touch());
                f.addEventListener('change', () => this.touch());
            }
        });
        this.root.querySelector('[data-action="save"]').addEventListener('click', () => this.save());
        this.root.querySelector('[data-action="quick"]').addEventListener('click', () => this.quickAdd());
        this.root.querySelector('[data-action="add"]').addEventListener('click', () => {
            if (this.pinCount() >= c.maxphrases) {
                Notification.alert('', fmt(S.limitreached, c.maxphrases));
                return;
            }
            const p = this.blank(S.newphrase);
            this.phrases.push(p);
            this.touch();
            this.select(p);
            this.startPlacing(p);
        });
        this.figure.addEventListener('pointermove', (e) => {
            if (!this.placing) {
                return;
            }
            const p = this.pct(e);
            this.cursor.style.left = `${p.x}%`;
            this.cursor.style.top = `${p.y}%`;
        });
        this.figure.addEventListener('click', (e) => {
            if (e.target.closest('.lt-pin') || this.suppress) {
                return;
            }
            this.placeAt(this.pct(e));
        });
        window.addEventListener('beforeunload', (e) => {
            if (this.dirty) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
        document.addEventListener('keydown', (e) => this.onKey(e));
        this.drawPins();
        await this.drawList();
        this.nextToPlace();
    }

    /**
     * A new, empty phrase.
     *
     * @param {string} text
     * @returns {object}
     */
    blank(text) {
        return {id: 0, key: this.key(), text, romanisation: '', translation: '', usagenote: '', example: '',
            exampletrans: '', prompt: '', alternatives: '', anchor: '', x: 0, y: 0, placed: 0, color: this.nextColor(),
            distractor: 0, recording: ''};
    }

    /**
     * Placed phrases in pin order (top to bottom).
     *
     * @returns {Array}
     */
    ordered() {
        return this.phrases.filter((p) => !p.distractor && p.placed).sort((a, b) => (a.y - b.y) || (a.x - b.x));
    }

    /**
     * Number of phrases that belong on the picture.
     *
     * @returns {number}
     */
    pinCount() {
        return this.phrases.filter((p) => !p.distractor).length;
    }

    /**
     * Next unused palette colour.
     *
     * @returns {string}
     */
    nextColor() {
        const used = new Set(this.phrases.map((p) => p.color));
        return this.cfg.palette.find((c) => !used.has(c)) || this.cfg.palette[this.phrases.length % this.cfg.palette.length];
    }

    /**
     * Pointer position as percentages of the picture.
     *
     * @param {MouseEvent} e
     * @returns {{x: number, y: number}}
     */
    pct(e) {
        const r = this.figure.getBoundingClientRect();
        const x = Math.max(0, Math.min(100, ((e.clientX - r.left) / r.width) * 100));
        const y = Math.max(0, Math.min(100, ((e.clientY - r.top) / r.height) * 100));
        return {x: Math.round(x * 100) / 100, y: Math.round(y * 100) / 100};
    }

    /**
     * Quick add: one phrase per line, then place them.
     */
    quickAdd() {
        const box = this.root.querySelector('[data-region="quick"]');
        const items = box.value.split(/\r?\n/).map(parseLine).filter((i) => i.text);
        if (!items.length) {
            box.focus();
            return;
        }
        let room = this.cfg.maxphrases - this.pinCount();
        const rest = [];
        items.forEach((item) => {
            if (room <= 0) {
                rest.push(item);
                return;
            }
            room--;
            this.phrases.push({...this.blank(item.text), translation: item.translation, usagenote: item.usagenote});
        });
        box.value = rest.map((i) => [i.text, i.translation, i.usagenote].filter(Boolean).join(' | ')).join('\n');
        if (rest.length) {
            Notification.alert('', fmt(S.limitreached, this.cfg.maxphrases));
        }
        this.touch();
        this.skipped.clear();
        this.drawList();
        this.nextToPlace();
    }

    /**
     * Starts placing the next unplaced phrase, if any.
     */
    nextToPlace() {
        const next = this.phrases.find((p) => !p.distractor && !p.placed && !this.skipped.has(p.key));
        if (next) {
            this.startPlacing(next);
        } else {
            this.stopPlacing();
        }
    }

    /**
     * Waits for a click on the picture for a phrase.
     *
     * @param {object} phrase
     */
    startPlacing(phrase) {
        this.placing = phrase;
        this.figure.classList.add('is-placing');
        const todo = this.phrases.filter((p) => !p.distractor && !p.placed);
        const index = todo.indexOf(phrase);
        this.banner.replaceChildren();
        const info = el('div', 'lt-qlabel');
        info.append(el('span', '', {text: S.clicktoplace}), el('strong', '', {text: phrase.text}));
        if (phrase.anchor) {
            info.appendChild(el('span', '', {text: fmt(S.placeon, phrase.anchor)}));
        }
        if (!phrase.placed && index >= 0) {
            info.appendChild(el('span', '', {text: fmt(S.placingof, {current: index + 1, total: todo.length})}));
        }
        this.banner.appendChild(info);
        if (!phrase.placed) {
            const skip = el('button', 'lt-btn lt-btn-ghost lt-btn-sm', {type: 'button', text: S.skip});
            skip.addEventListener('click', () => {
                this.skipped.add(phrase.key);
                this.nextToPlace();
            });
            this.banner.appendChild(skip);
        }
        const stop = el('button', 'lt-btn lt-btn-ghost lt-btn-sm', {type: 'button', text: S.stop});
        stop.addEventListener('click', () => this.stopPlacing());
        this.banner.appendChild(stop);
        this.banner.hidden = false;
        this.figure.scrollIntoView({behavior: 'smooth', block: 'center'});
    }

    /**
     * Stops placing.
     */
    stopPlacing() {
        this.placing = null;
        this.figure.classList.remove('is-placing');
        this.banner.hidden = true;
    }

    /**
     * Places the current phrase where the picture was clicked.
     *
     * @param {{x: number, y: number}} p
     */
    placeAt(p) {
        if (!this.placing) {
            this.select(null);
            return;
        }
        const phrase = this.placing;
        phrase.x = p.x;
        phrase.y = p.y;
        phrase.placed = 1;
        this.touch();
        this.drawPins(phrase);
        this.drawList();
        this.nextToPlace();
        if (!this.placing) {
            this.select(phrase);
        }
    }

    /**
     * Draws the pins.
     *
     * @param {object|null} fresh newly placed phrase to animate
     */
    drawPins(fresh = null) {
        this.figure.querySelectorAll('.lt-pin').forEach((p) => p.remove());
        this.ordered().forEach((phrase, i) => {
            const pin = el('button', `lt-pin is-filled${phrase === this.selected ? ' is-sel' : ''}${
                phrase === fresh ? ' is-new' : ''}`, {type: 'button', text: String(i + 1), 'aria-label': phrase.text});
            pin.style.left = `${phrase.x}%`;
            pin.style.top = `${phrase.y}%`;
            pin.style.setProperty('--c', phrase.color);
            const tag = el('span', 'lt-pin-tag', {text: phrase.text, dir: 'auto'});
            tag.style.setProperty('--c', readable(phrase.color));
            pin.appendChild(tag);
            pin.addEventListener('pointerdown', (e) => this.dragPin(e, phrase, pin));
            pin.addEventListener('click', (e) => {
                e.stopPropagation();
                if (!this.suppress) {
                    this.select(phrase);
                }
            });
            this.figure.appendChild(pin);
        });
    }

    /**
     * Drags a pin.
     *
     * @param {PointerEvent} e
     * @param {object} phrase
     * @param {HTMLElement} pin
     */
    dragPin(e, phrase, pin) {
        if (e.button > 0) {
            return;
        }
        e.preventDefault();
        const sx = e.clientX;
        const sy = e.clientY;
        let moved = false;
        pin.setPointerCapture(e.pointerId);
        const move = (ev) => {
            if (!moved && Math.hypot(ev.clientX - sx, ev.clientY - sy) < 3) {
                return;
            }
            moved = true;
            const p = this.pct(ev);
            phrase.x = p.x;
            phrase.y = p.y;
            pin.style.left = `${p.x}%`;
            pin.style.top = `${p.y}%`;
        };
        const up = () => {
            pin.removeEventListener('pointermove', move);
            pin.removeEventListener('pointerup', up);
            pin.removeEventListener('pointercancel', up);
            if (moved) {
                this.suppress = true;
                window.setTimeout(() => {
                    this.suppress = false;
                }, 60);
                this.touch();
                this.selected = phrase;
                this.drawPins();
                this.drawList();
            }
        };
        pin.addEventListener('pointermove', move);
        pin.addEventListener('pointerup', up);
        pin.addEventListener('pointercancel', up);
    }

    /**
     * Selects a phrase.
     *
     * @param {object|null} phrase
     */
    async select(phrase) {
        this.selected = phrase;
        this.drawPins();
        await this.drawList();
        if (phrase) {
            const row = this.list.querySelector(`[data-key="${phrase.key}"]`);
            if (row) {
                row.scrollIntoView({block: 'nearest', behavior: 'smooth'});
            }
        }
    }

    /**
     * Player number of a phrase.
     *
     * @param {object} phrase
     * @returns {string}
     */
    number(phrase) {
        if (phrase.distractor || !phrase.placed) {
            return '–';
        }
        return String(this.ordered().indexOf(phrase) + 1);
    }

    /**
     * Renders the phrase list.
     */
    async drawList() {
        const c = this.cfg;
        const pins = this.pinCount();
        this.countEl.textContent = `${pins}/${c.maxphrases}`;
        this.countEl.classList.toggle('is-full', pins >= c.maxphrases);
        if (!this.phrases.length) {
            this.list.replaceChildren(el('p', 'lt-mini', {text: S.emptyeditor}));
            return;
        }
        const token = {};
        this.drawToken = token;
        const sorted = [...this.ordered(), ...this.phrases.filter((p) => !p.distractor && !p.placed),
            ...this.phrases.filter((p) => p.distractor)];
        const rows = [];
        for (const phrase of sorted) {
            rows.push(await render('editor_phrase', {
                ...phrase, number: this.number(phrase), selected: phrase === this.selected,
                unplaced: !phrase.distractor && !phrase.placed, distractor: !!phrase.distractor,
                romanise: c.romanise, lang: c.locale, hasrecording: !!phrase.recording,
                palette: c.palette.map((color) => ({color, on: color === phrase.color})),
                targetname: c.targetname, supportname: c.supportname,
                voiceauto: !phrase.voicegender, voicef: phrase.voicegender === 'f', voicem: phrase.voicegender === 'm',
            }));
        }
        if (this.drawToken !== token) {
            return;
        }
        this.list.replaceChildren(...rows);
        rows.forEach((row, i) => this.wireRow(row, sorted[i]));
        rows.forEach((row) => limits(row));
    }

    /**
     * Wires one phrase row.
     *
     * @param {HTMLElement} row
     * @param {object} phrase
     */
    wireRow(row, phrase) {
        const act = (a) => row.querySelector(`[data-action="${a}"]`);
        row.querySelectorAll('[data-field]').forEach((f) => {
            const field = f.dataset.field;
            if (field === 'distractor') {
                f.addEventListener('change', () => {
                    const distractors = this.phrases.filter((p) => p.distractor).length;
                    if (f.checked && distractors >= this.cfg.maxdistractors) {
                        f.checked = false;
                        Notification.alert('', fmt(S.limitreached, this.cfg.maxdistractors));
                        return;
                    }
                    if (!f.checked && this.pinCount() >= this.cfg.maxphrases) {
                        f.checked = true;
                        Notification.alert('', fmt(S.limitreached, this.cfg.maxphrases));
                        return;
                    }
                    phrase.distractor = f.checked ? 1 : 0;
                    this.touch();
                    this.drawPins();
                    this.drawList();
                });
                return;
            }
            f.addEventListener('focus', () => {
                if (this.selected !== phrase) {
                    this.selected = phrase;
                    this.drawPins();
                    this.list.querySelectorAll('.lt-lrow').forEach((r) => r.classList.toggle('is-sel',
                        r.dataset.key === phrase.key));
                }
            });
            f.addEventListener('input', () => {
                phrase[field] = f.value;
                this.touch();
                if (field === 'text') {
                    const tag = this.figure.querySelector('.lt-pin.is-sel .lt-pin-tag');
                    if (tag) {
                        tag.textContent = f.value;
                    }
                }
            });
        });
        act('select').addEventListener('click', () => this.select(phrase));
        act('listen').addEventListener('click', () => this.listen(phrase));
        act('createaudio').addEventListener('click', async(e) => {
            if (!phrase.id || this.dirty) {
                await this.save();
            }
            if (!phrase.id) {
                return;
            }
            const btn = e.currentTarget;
            btn.disabled = true;
            try {
                const catalog = await Ajax.call([{methodname: 'mod_ailanguageteacher_voice_choices',
                    args: {cmid: this.cfg.cmid}}])[0];
                if (!catalog.voices.length) {
                    Notification.alert('', S.speechcatalogunavailable);
                    return;
                }
                // The activity's voice (chosen in the Voices step), or the first voice LMS Labs offers.
                const voice = catalog.voices.includes(this.cfg.voice) ? this.cfg.voice : catalog.voices[0];
                // With free remakes, the voice's current price comes first (free) and is its ceiling.
                let maxcredits = this.cfg.voicecredits;
                if (this.cfg.remakes) {
                    const quote = await Ajax.call([{methodname: 'mod_ailanguageteacher_quote_audio',
                        args: {phraseids: [phrase.id], variant: 'normal'}}])[0];
                    maxcredits = quote.length && quote[0].credits === 0 ? 0 : this.cfg.voicecredits;
                }
                const go = await new Promise((resolve) => Notification.saveCancel(S.confirm_title,
                    maxcredits === 0 ? S.voice_confirm_free : S.voice_confirm,
                    S.confirm_create, () => resolve(true), () => resolve(false)));
                if (!go) {
                    return;
                }
                const args = {phraseid: phrase.id, voice, variant: 'normal', maxcredits};
                let res;
                try {
                    res = await Ajax.call([{methodname: 'mod_ailanguageteacher_create_audio', args}])[0];
                } catch (err) {
                    if (err && err.errorcode === 'operationconflict' && await new Promise((resolve) =>
                            Notification.saveCancel(S.confirm_title, S.discard_confirm, S.confirm_create,
                                () => resolve(true), () => resolve(false)))) {
                        res = await Ajax.call([{methodname: 'mod_ailanguageteacher_create_audio',
                            args: {...args, discard: true}}])[0];
                    } else {
                        throw err;
                    }
                }
                await Speech.play({url: res.url, text: phrase.text, locale: this.cfg.locale});
            } catch (err) {
                Notification.exception(err);
            } finally {
                btn.disabled = false;
            }
        });
        row.querySelectorAll('[data-color]').forEach((b) => b.addEventListener('click', () => {
            phrase.color = b.dataset.color;
            this.touch();
            this.drawPins();
            this.drawList();
        }));
        const move = act('move');
        if (move) {
            move.addEventListener('click', () => this.startPlacing(phrase));
        }
        act('delete').addEventListener('click', () => this.remove(phrase));
        act('record').addEventListener('click', (e) => this.record(phrase, e.currentTarget));
        const del = act('deleterecording');
        if (del) {
            del.addEventListener('click', async() => {
                try {
                    await Ajax.call([{methodname: 'mod_ailanguageteacher_delete_phrase_audio',
                        args: {phraseid: phrase.id}}])[0];
                    phrase.recording = '';
                    this.drawList();
                } catch (err) {
                    Notification.exception(err);
                }
            });
        }
    }

    /**
     * Plays a phrase: the teacher's recording, the site voice, or the browser's voice.
     *
     * @param {object} phrase
     */
    async listen(phrase) {
        let url = phrase.recording || '';
        if (!url && this.cfg.hasservicetts && phrase.id && !this.dirty) {
            try {
                url = (await Ajax.call([{methodname: 'mod_ailanguageteacher_get_audio', args: {cmid: this.cfg.cmid,
                    ref: `p${phrase.id}`}}])[0]).url;
            } catch (err) {
                url = '';
            }
        }
        try {
            await Speech.play({url, text: phrase.text, locale: this.cfg.locale});
        } catch (err) {
            Notification.alert('', S.audiounavailable);
        }
    }

    /**
     * Records the teacher saying a phrase.
     *
     * @param {object} phrase
     * @param {HTMLElement} btn
     */
    async record(phrase, btn) {
        if (this.recording) {
            this.recording.stop();
            return;
        }
        if (!phrase.id || this.dirty) {
            await this.save();
        }
        if (!phrase.id) {
            return;
        }
        const label = btn.querySelector('span');
        const signal = {};
        this.recording = {stop: () => signal.stop && signal.stop()};
        label.textContent = S.recordstop;
        btn.classList.add('is-recording');
        try {
            const blob = await Speech.recordClip(signal, 10000);
            const audio = await Speech.toBase64(blob);
            const res = await Ajax.call([{methodname: 'mod_ailanguageteacher_save_phrase_audio',
                args: {phraseid: phrase.id, audio}}])[0];
            phrase.recording = res.url;
        } catch (err) {
            Notification.exception(err);
        } finally {
            this.recording = null;
            this.drawList();
        }
    }

    /**
     * Deletes a phrase.
     *
     * @param {object} phrase
     */
    async remove(phrase) {
        const ok = await Notification.deleteCancelPromise(S.deletephrase, fmt(S.confirmdeletephrase, phrase.text),
            S.deletephrase).then(() => true).catch(() => false);
        if (!ok) {
            return;
        }
        this.phrases = this.phrases.filter((p) => p !== phrase);
        if (this.selected === phrase) {
            this.selected = null;
        }
        if (this.placing === phrase) {
            this.nextToPlace();
        }
        this.touch();
        this.drawPins();
        this.drawList();
    }

    /**
     * Keyboard: Ctrl/Cmd+S saves, arrows nudge the selected pin, Esc stops placing.
     *
     * @param {KeyboardEvent} e
     */
    onKey(e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
            e.preventDefault();
            this.save();
            return;
        }
        if (e.key === 'Escape' && this.placing) {
            this.stopPlacing();
            return;
        }
        const tag = (e.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || !this.selected || !this.selected.placed) {
            return;
        }
        const step = e.shiftKey ? 2 : 0.5;
        const d = {ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step]}[e.key];
        if (d) {
            e.preventDefault();
            this.selected.x = Math.max(0, Math.min(100, this.selected.x + d[0]));
            this.selected.y = Math.max(0, Math.min(100, this.selected.y + d[1]));
            this.touch();
            this.drawPins();
            const pin = this.figure.querySelector('.lt-pin.is-sel');
            if (pin) {
                pin.focus();
            }
        }
    }

    /**
     * Marks changes and schedules an autosave.
     */
    touch() {
        this.dirty = true;
        this.stateEl.className = 'lt-savestate is-dirty';
        this.stateEl.textContent = S.unsaved;
        window.clearTimeout(this.autosave);
        this.autosave = window.setTimeout(() => this.save(), 1500);
    }

    /**
     * Saves through the web service (one save at a time).
     *
     * @returns {Promise}
     */
    save() {
        window.clearTimeout(this.autosave);
        if (this.saving) {
            this.again = true;
            return this.saving;
        }
        this.stateEl.className = 'lt-savestate';
        this.stateEl.textContent = S.saving;
        const field = (name) => this.root.querySelector(`.lt-ed-main [data-field="${name}"]`);
        const sent = this.phrases.filter((p) => p.text.trim() !== '');
        const payload = sent.map((p) => ({id: p.id || 0, text: p.text.trim(), romanisation: p.romanisation || '',
            translation: p.translation || '', usagenote: p.usagenote || '', example: p.example || '',
            exampletrans: p.exampletrans || '', prompt: p.prompt || '', alternatives: p.alternatives || '',
            anchor: p.anchor || '', voicegender: p.voicegender || '', x: p.x, y: p.y, placed: p.placed ? 1 : 0, color: p.color,
            distractor: p.distractor ? 1 : 0}));
        this.dirty = false;
        this.saving = Promise.resolve(Ajax.call([{methodname: 'mod_ailanguageteacher_save_scene', args: {
            sceneid: this.cfg.sceneid, title: field('title').value, context: field('context').value,
            imageprompt: field('imageprompt').value, situationid: parseInt(field('situationid').value || '0', 10),
            phrases: payload,
        }}])[0]).then((res) => {
            res.ids.forEach((id, i) => {
                if (sent[i]) {
                    sent[i].id = id;
                }
            });
            if (!this.dirty) {
                this.stateEl.className = 'lt-savestate is-saved';
                this.stateEl.textContent = S.saved;
            }
            return res;
        }).catch((err) => {
            this.dirty = true;
            this.stateEl.className = 'lt-savestate is-dirty';
            this.stateEl.textContent = S.unsaved;
            Notification.exception(err);
        }).finally(() => {
            this.saving = null;
            if (this.again) {
                this.again = false;
                this.save();
            }
        });
        return this.saving;
    }
}

/**
 * Entry point.
 *
 * @param {string} selector
 */
export const init = async(selector) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    try {
        S = await loadStrings(KEYS);
        const editor = new Editor(root, JSON.parse(root.dataset.config));
        await editor.start();
    } catch (err) {
        Notification.exception(err);
    }
};
