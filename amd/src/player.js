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
 * AI Language Teacher player: Study, Practice and Test over a slideshow of picture scenes.
 *
 * Study: every phrase is on the picture; tap one to hear it, see what it means and try saying it.
 * Practice: drag each phrase to its place (or tap the phrase, then the place). A correct match opens the phrase
 * card: listen, then say it until it reaches the pass score the required number of times.
 * Test: match the phrases, then listen and tap the right place, then say each phrase from a cue in the learner's
 * own language. Everything is marked on the server.
 *
 * @module     mod_ailanguageteacher/player
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import * as Sound from 'mod_ailanguageteacher/sound';
import * as Speech from 'mod_ailanguageteacher/speech';
import {REDUCED, COARSE, EASE, SPRING, loadStrings, fmt, el, icon, button, setIcon, render, clock, flip,
    confetti, burst, readable} from 'mod_ailanguageteacher/ui';

const STRING_KEYS = [
    'exit', 'sceneof', 'hint', 'hintlevel', 'reset', 'submitscene', 'next', 'previous', 'finish', 'score', 'mastered',
    'time', 'mute', 'unmute', 'fullscreen', 'exitfullscreen', 'dropzoneempty', 'dropzonefilled', 'instructions_drag',
    'instructions_tap', 'instructions_study', 'correctfeedback', 'wrongfeedback', 'placedfeedback', 'selectedfeedback',
    'scenecomplete', 'close', 'returntotray', 'modestudy', 'modepractice', 'modetest', 'passed', 'notpassed', 'passmark',
    'youneed', 'excellent', 'greatjob', 'goodeffort', 'keeppractising', 'correctof', 'confirmsubmit', 'goback',
    'attemptsleft', 'phraseof', 'goal_times', 'goal_once', 'listening', 'checking', 'tapandsay', 'tapwhendone',
    'nothingheard', 'headline_excellent', 'headline_great', 'headline_nearly', 'headline_tryagain', 'msg_moretogo',
    'msg_mastered', 'msg_keepgoing', 'msg_reset', 'spokencheck', 'wordsrecognised', 'charsrecognised', 'unintelligible',
    'speechinprogress', 'intro_item_servicespeech', 'successfultries', 'matchscore', 'speaknotavailable',
    'micblocked', 'speechfailed_short', 'speechratelimit', 'recorded',
    'intro_expect', 'intro_requirements', 'intro_pass', 'intro_nopass',
    'intro_attempts', 'intro_unlimited', 'intro_time', 'intro_notime', 'intro_graded', 'intro_study_1', 'intro_study_2',
    'intro_study_3', 'intro_practice_1', 'intro_practice_2', 'intro_practice_3', 'intro_practice_4', 'intro_test_1',
    'intro_test_2', 'intro_test_3', 'intro_item_scenes', 'intro_item_listen', 'intro_item_speakgoal',
    'intro_item_nospeak', 'intro_item_hints', 'intro_item_nopressure', 'intro_item_stages', 'intro_item_testnohints',
    'intro_item_resume', 'intro_item_browserspeech', 'intro_item_selfspeech', 'stage_listen_step', 'stage_speak_step',
    'skip', 'playall', 'stopplaying', 'confirmreset', 'resetdone', 'masteredsummary', 'avgscore', 'hintsused',
    'needspracticecount', 'stage_match_help', 'selectplace', 'nomatchsaved', 'triesleft', 'tryagainshort',
    'hint1', 'hint2', 'hint3', 'hint4', 'audiounavailable', 'studyrecorded', 'listenfirst', 'results_slide',
];

let S = {};

/**
 * The player application.
 */
/**
 * Splits a text into sentences, so a scene's situation is shown one short paragraph at a time.
 * Works for scripts with a space after the full stop and for those without one (Chinese, Japanese).
 *
 * @param {string} text
 * @returns {string[]}
 */
const sentences = (text) => String(text || '').trim()
    // No lookbehind: older Safari (before 16.4) cannot parse it.
    .replace(/([.!?…])\s+(?=["“‘¿¡(\p{Lu}\p{Lo}\d])/gu, '$1\n')
    .replace(/([。！？])/gu, '$1\n')
    .split(/\n+/)
    .map((t) => t.trim()).filter((t) => t !== '')
    // A title such as "Mr. Smith" stays in one sentence.
    .reduce((out, t) => {
        if (out.length && /\b(Mr|Mrs|Ms|Dr|St|Prof|Sr|Jr|vs|etc|e\.g|i\.e)\.$/i.test(out[out.length - 1])) {
            out[out.length - 1] += ' ' + t;
        } else {
            out.push(t);
        }
        return out;
    }, []);

class Player {
    /**
     * Constructor.
     *
     * @param {HTMLElement} root
     * @param {object} config
     */
    constructor(root, config) {
        this.root = root;
        this.config = config;
        this.home = root.querySelector('[data-region="home"]');
        this.host = root.querySelector('[data-region="player"]');
        this.live = root.querySelector('[data-region="live"]');
        this.drag = null;
        this.selected = null;
        this.lesson = null;
        this.timerHandle = null;
        this.audioCache = new Map();
        this.studied = new Set();
        this.studySent = new Set();
        Sound.setAllowed(!!config.sounds);
        root.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-action="start"]');
            if (btn && !btn.disabled && this.home.contains(btn)) {
                this.showIntro(btn.dataset.mode);
            }
        });
        this.onResize = () => {
            if (!this.slides) {
                return;
            }
            this.slides.forEach((s) => this.drawLines(s));
            this.syncHeight();
        };
        window.addEventListener('resize', this.onResize);
        document.addEventListener('fullscreenchange', () => this.syncFullscreen());
        document.addEventListener('webkitfullscreenchange', () => this.syncFullscreen());
        document.addEventListener('keydown', (e) => this.onKey(e));
        window.addEventListener('beforeunload', () => this.flushStudy());
    }

    /**
     * Whether this device can take part in speaking with the site's scoring method.
     *
     * @returns {boolean}
     */
    canSpeak() {
        const mode = this.config.speakmode;
        if (mode === 'service') {
            return Speech.canRecord();
        }
        if (mode === 'browser') {
            return Speech.canRecognise();
        }
        return Speech.canRecord() && !!window.MediaRecorder;
    }

    /**
     * Announces a message to assistive technologies.
     *
     * @param {string} msg
     */
    say(msg) {
        this.live.textContent = '';
        window.setTimeout(() => {
            this.live.textContent = msg;
        }, 30);
    }

    /* ------------------------------------------------------------------ */
    /* Start screen                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Shows the start screen for a mode.
     *
     * @param {string} mode study|practice|test
     */
    async showIntro(mode) {
        Sound.play('select');
        const c = this.config;
        const items = [];
        const add = (ic, text, key = false) => items.push({text, key, icon: {[ic]: true}});
        const scenes = fmt(S.intro_item_scenes, {scenes: c.scenecount, phrases: c.phrasecount});
        const speakable = c.speaking && this.canSpeak();
        const goal = c.repetitions > 1 ? fmt(S.goal_times, {score: c.passscore, times: c.repetitions})
            : fmt(S.goal_once, c.passscore);
        let steps = [];
        if (mode === 'test') {
            add('target', c.passpercent > 0 ? fmt(S.intro_pass, c.passpercent) : S.intro_nopass, c.passpercent > 0);
            add('repeat', c.maxattempts ? fmt(S.intro_attempts, {left: c.attemptsleft, max: c.maxattempts})
                : S.intro_unlimited);
            add('clock', c.timelimit ? fmt(S.intro_time, c.timelimittext) : S.intro_notime);
            add('image', scenes);
            add('chat', S.intro_item_stages);
            add('info', S.intro_item_testnohints);
            if (c.graded) {
                add('trophy', S.intro_graded);
            }
            (c.completion || []).filter((r) => r.rule !== 'completionpassgrade' || !c.passpercent)
                .forEach((r) => add('flag', r.text));
            steps = [S.intro_test_1, S.intro_test_2, S.intro_test_3];
        } else if (mode === 'practice') {
            add('image', scenes);
            if (c.speaking) {
                add('mic', fmt(S.intro_item_speakgoal, goal), true);
                if (c.speakmode === 'service') {
                    add('info', S.intro_item_servicespeech);
                } else if (c.speakmode === 'browser') {
                    add('info', S.intro_item_browserspeech);
                } else if (c.speakmode === 'self') {
                    add('info', S.intro_item_selfspeech);
                }
                if (!speakable) {
                    add('info', S.speaknotavailable);
                }
            } else {
                add('sound', S.intro_item_nospeak);
            }
            add('hint', S.intro_item_hints);
            if (c.practicecounts && c.practicecounts.done > 0) {
                add('star', fmt(S.intro_item_resume, {done: c.practicecounts.done, total: c.practicecounts.total}));
            }
            steps = [S.intro_practice_1, S.intro_practice_2, c.speaking ? S.intro_practice_3 : S.intro_practice_4];
        } else {
            add('image', scenes);
            add('sound', S.intro_item_listen);
            add('star', S.intro_item_nopressure);
            steps = [S.intro_study_1, S.intro_study_2, S.intro_study_3];
        }
        const node = await render('player_intro', {
            mode,
            isstudy: mode === 'study',
            ispractice: mode === 'practice',
            istest: mode === 'test',
            heading: mode === 'test' ? S.intro_requirements : S.intro_expect,
            items,
            steps: steps.map((text, i) => ({number: i + 1, text})),
            canreset: mode === 'practice' && c.canattempt && c.practicecounts && c.practicecounts.done > 0,
        });
        this.home.hidden = true;
        this.host.hidden = false;
        this.host.replaceChildren(node);
        this.shell = node;
        node.querySelector('[data-action="back"]').addEventListener('click', () => this.exit());
        const reset = node.querySelector('[data-action="reset"]');
        if (reset) {
            reset.addEventListener('click', async() => {
                if (!await this.confirm(S.confirmreset, S.reset)) {
                    return;
                }
                try {
                    await Ajax.call([{methodname: 'mod_ailanguageteacher_reset_progress', args: {cmid: c.cmid}}])[0];
                    c.practicecounts = {total: c.practicecounts.total, mastered: 0, done: 0};
                    this.say(S.resetdone);
                    this.showIntro('practice');
                } catch (err) {
                    Notification.exception(err);
                }
            });
        }
        const go = node.querySelector('[data-action="go"]');
        go.addEventListener('click', () => {
            go.disabled = true;
            this.start(mode);
        });
        go.focus({preventScroll: true});
        this.scrollToShell();
    }

    /**
     * Starts a mode.
     *
     * @param {string} mode
     */
    async start(mode) {
        Sound.play('select');
        this.mode = mode;
        let data;
        try {
            if (mode === 'study') {
                data = this.config.study;
            } else {
                data = await Ajax.call([{
                    methodname: 'mod_ailanguageteacher_start_attempt',
                    args: {cmid: this.config.cmid, kind: mode, canlisten: this.config.hasservicetts || Speech.canSynthesise(),
                        canspeak: this.canSpeak()},
                }])[0];
            }
        } catch (err) {
            this.exit();
            Notification.exception(err);
            return;
        }
        this.data = data;
        this.attemptid = data.attemptid || 0;
        this.stages = data.stages || ['match'];
        await this.build();
    }

    /* ------------------------------------------------------------------ */
    /* Building                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Builds the player shell and every scene.
     */
    async build() {
        const mode = this.mode;
        this.index = 0;
        this.startedAt = Date.now();
        const shell = await render('player_shell', {mode, modetag: S[`mode${mode}`], showstats: mode !== 'study'});
        this.shell = shell;
        const q = (name) => shell.querySelector(`[data-region="${name}"]`);
        this.titleEl = q('title');
        this.situationEl = q('situation');
        this.counterEl = q('counter');
        this.dotsEl = q('dots');
        this.hintEl = q('hint');
        this.timerEl = q('timer');
        this.scoreEl = q('score');
        this.viewport = q('viewport');
        this.track = q('track');
        this.actions = q('actions');
        shell.querySelector('[data-action="exit"]').addEventListener('click', () => this.exit());
        this.muteBtn = shell.querySelector('[data-action="mute"]');
        this.muteBtn.addEventListener('click', () => {
            Sound.toggleMute();
            this.syncMute();
        });
        this.muteBtn.hidden = !this.config.sounds;
        this.fullBtn = shell.querySelector('[data-action="fullscreen"]');
        this.fullBtn.addEventListener('click', () => this.toggleFullscreen());

        this.data.scenes.forEach((s, i) => {
            const dot = el('button', 'lt-dot', {type: 'button', role: 'tab',
                'aria-label': fmt(S.sceneof, {current: i + 1, total: this.data.scenes.length})});
            dot.addEventListener('click', () => {
                if (this.canNavigateTo(i)) {
                    this.goTo(i);
                }
            });
            this.dotsEl.appendChild(dot);
        });
        this.dotsEl.hidden = this.data.scenes.length < 2;

        this.host.replaceChildren(shell);
        this.slides = [];
        for (let i = 0; i < this.data.scenes.length; i++) {
            this.slides.push(await this.buildSlide(this.data.scenes[i], i));
        }
        this.syncMute();
        this.syncFullscreen();
        this.goTo(0, true);
        this.startTimer();
        this.updateScore();
        if (window.ResizeObserver) {
            this.resizeObserver = new ResizeObserver(() => this.onResize());
            this.resizeObserver.observe(this.viewport);
        }
        shell.focus({preventScroll: true});
        window.setTimeout(() => {
            this.syncHeight();
            this.scrollToShell();
        }, 60);
    }

    /**
     * Builds one scene.
     *
     * @param {object} data
     * @param {number} index
     * @returns {Promise<object>} slide state
     */
    async buildSlide(data, index) {
        const mode = this.mode;
        const study = mode === 'study';
        const locale = this.config.locale;
        const n = data.pins.length;
        const context = {
            id: data.id, title: data.title, context: data.context, contextlines: sentences(data.context), image: data.image,
            width: data.width,
            height: data.height, ratio: data.width / data.height, n: Math.min(n, 8), compact: n > 5, study,
            hastray: !study, lang: locale,
            pins: data.pins.map((p) => ({...p, ink: p.color ? readable(p.color) : ''})),
            chips: (data.chips || []).map((c) => ({...c, ink: readable(c.color), translation: c.translation || ''})),
        };
        const section = await render('player_scene', context);
        section.setAttribute('aria-label', fmt(S.sceneof, {current: index + 1, total: this.data.scenes.length}));
        const slide = {data, index, el: section, slots: new Map(), chips: new Map(), answers: new Map(),
            details: new Map(), complete: false, stage: study ? 'done' : 'match', board: section.querySelector('.lt-board'),
            svg: section.querySelector('.lt-lines'), tray: section.querySelector('.lt-tray'),
            stageEl: section.querySelector('[data-region="stage"]'), choices: {}, speakDone: new Set()};
        (data.answers || []).forEach((a) => slide.answers.set(a.pin, a.chip));
        (data.details || []).forEach((d) => slide.details.set(d.token, d));
        const tapHint = COARSE ? S.instructions_tap : S.instructions_drag;
        slide.hint = study ? S.instructions_study : tapHint;

        const img = section.querySelector('.lt-image');
        img.addEventListener('load', () => {
            this.drawLines(slide);
            if (this.slides && this.slides[this.index] === slide) {
                this.syncHeight();
            }
        });
        const zones = section.querySelectorAll('.lt-slot-zone');
        const pins = section.querySelectorAll('.lt-pin');
        const SVGNS = 'http://www.w3.org/2000/svg';
        data.pins.forEach((pin, i) => {
            const path = document.createElementNS(SVGNS, 'path');
            path.setAttribute('class', 'lt-line');
            const dotA = document.createElementNS(SVGNS, 'circle');
            dotA.setAttribute('class', 'lt-line-end');
            dotA.setAttribute('r', '3');
            slide.svg.append(path, dotA);
            const zone = zones[i];
            const state = {token: pin.token, number: pin.number, zone, body: zone.querySelector('.lt-slot-body'),
                pinEl: pins[i], path, dotA, chip: null, locked: false, color: null, pin};
            slide.slots.set(pin.token, state);
            this.labelZone(slide, state);
            const activate = (e) => {
                e.preventDefault();
                this.onZoneActivate(slide, state);
            };
            zone.addEventListener('click', activate);
            state.pinEl.addEventListener('click', activate);
            zone.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    activate(e);
                }
            });
            zone.addEventListener('pointerdown', (e) => {
                if (state.chip && !state.locked) {
                    this.pointerDown(e, slide, slide.chips.get(state.chip));
                }
            });
            if (study) {
                state.color = pin.color;
                state.locked = true;
                zone.classList.add('is-filled');
                state.pinEl.classList.add('is-filled');
                state.pinEl.style.setProperty('--c', pin.color);
            }
        });
        if (slide.tray) {
            slide.tray.querySelectorAll('.lt-chip').forEach((chipEl) => {
                const token = chipEl.dataset.token;
                const info = (data.chips || []).find((c) => c.token === token);
                const chip = {token, el: chipEl, text: info.text, color: info.color, slot: null, tries: 0, hintlevel: 0};
                slide.chips.set(token, chip);
                chipEl.addEventListener('pointerdown', (e) => this.pointerDown(e, slide, chip));
                chipEl.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (this.suppressClick) {
                        e.preventDefault();
                        return;
                    }
                    this.toggleSelect(slide, chip);
                });
            });
            slide.tray.addEventListener('click', (e) => {
                if (e.target === slide.tray && this.selected && this.selected.chip.slot) {
                    this.unplace(slide, this.selected.chip, true);
                    this.clearSelection();
                }
            });
        }
        this.track.appendChild(section);
        if (mode === 'practice') {
            this.restoreProgress(slide);
        }
        requestAnimationFrame(() => this.drawLines(slide));
        return slide;
    }

    /**
     * Practice: places phrases the learner has already finished, so they carry on where they left off.
     *
     * @param {object} slide
     */
    restoreProgress(slide) {
        slide.answers.forEach((chipToken, pinToken) => {
            const detail = slide.details.get(chipToken);
            if (!detail || (detail.state !== 'mastered' && detail.state !== 'needspractice')) {
                return;
            }
            const chip = slide.chips.get(chipToken);
            const slot = slide.slots.get(pinToken);
            this.attach(slide, chip, slot, null, null, true);
            this.lockSlot(slide, slot, chip, detail.state);
        });
        if (this.allLocked(slide)) {
            slide.complete = true;
            this.fadeDistractors(slide);
        }
    }

    /**
     * Sets a zone's accessible label.
     *
     * @param {object} slide
     * @param {object} slot
     */
    labelZone(slide, slot) {
        const chip = slot.chip ? slide.chips.get(slot.chip) : null;
        const studytext = this.mode === 'study' ? slot.pin.text : null;
        const text = chip || studytext ? fmt(S.dropzonefilled, {number: slot.number, label: chip ? chip.text : studytext})
            : fmt(S.dropzoneempty, slot.number);
        slot.zone.setAttribute('aria-label', text);
        slot.pinEl.setAttribute('aria-label', text);
    }

    /**
     * Draws the leader lines from rail slots to pins.
     *
     * @param {object} slide
     */
    drawLines(slide) {
        const boardRect = slide.board.getBoundingClientRect();
        if (!boardRect.width) {
            return;
        }
        slide.svg.setAttribute('viewBox', `0 0 ${boardRect.width} ${boardRect.height}`);
        slide.svg.setAttribute('width', boardRect.width);
        slide.svg.setAttribute('height', boardRect.height);
        slide.slots.forEach((slot) => {
            const z = slot.zone.getBoundingClientRect();
            const p = slot.pinEl.getBoundingClientRect();
            const x1 = z.right - boardRect.left + 3;
            const y1 = z.top + z.height / 2 - boardRect.top;
            const x2 = p.left + p.width / 2 - boardRect.left;
            const y2 = p.top + p.height / 2 - boardRect.top;
            const dx = Math.max(24, (x2 - x1) * 0.45);
            slot.path.setAttribute('d', `M${x1},${y1} C${x1 + dx},${y1} ${x2 - dx},${y2} ${x2},${y2}`);
            slot.dotA.setAttribute('cx', x1);
            slot.dotA.setAttribute('cy', y1);
            const filled = !!(slot.chip || slot.color);
            slot.path.classList.toggle('is-filled', filled);
            slot.dotA.classList.toggle('is-filled', filled);
            if (filled && slot.color) {
                slot.path.style.setProperty('--line', slot.color);
                slot.dotA.style.setProperty('--line', slot.color);
            } else {
                slot.path.style.removeProperty('--line');
                slot.dotA.style.removeProperty('--line');
            }
        });
    }

    /**
     * Animates a leader line being drawn.
     *
     * @param {object} slide
     * @param {object} slot
     */
    animateLine(slide, slot) {
        this.drawLines(slide);
        if (REDUCED || !slot.path.getTotalLength) {
            return;
        }
        const len = slot.path.getTotalLength();
        slot.path.animate([
            {strokeDasharray: `${len}`, strokeDashoffset: `${len}`},
            {strokeDasharray: `${len}`, strokeDashoffset: '0'},
        ], {duration: 520, easing: EASE});
    }

    /* ------------------------------------------------------------------ */
    /* Navigation                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Whether the learner may jump to a scene.
     *
     * @param {number} i
     * @returns {boolean}
     */
    canNavigateTo(i) {
        if (this.mode === 'study') {
            return true;
        }
        if (this.mode === 'test') {
            return i <= this.index && this.slides[this.index].stage === 'match';
        }
        return i <= this.index || this.slides.slice(0, i).every((s) => s.complete);
    }

    /**
     * Moves the slideshow to a scene.
     *
     * @param {number} i
     * @param {boolean} instant
     */
    goTo(i, instant = false) {
        const total = this.slides.length;
        i = Math.max(0, Math.min(total - 1, i));
        const changed = i !== this.index;
        this.index = i;
        this.clearSelection();
        this.stopAutoplay();
        this.closeLesson(true);
        Speech.stop();
        this.track.style.transition = instant || REDUCED ? 'none' : `transform 640ms ${EASE}`;
        this.track.style.transform = `translate3d(${-100 * i}%, 0, 0)`;
        this.slides.forEach((s, k) => {
            const active = k === i;
            s.el.classList.toggle('is-active', active);
            s.el.setAttribute('aria-hidden', active ? 'false' : 'true');
            s.el.inert = !active;
        });
        Array.from(this.dotsEl.children).forEach((dot, k) => {
            const s = this.slides[k];
            dot.classList.toggle('is-active', k === i);
            dot.classList.toggle('is-done', !!s.complete && this.mode !== 'study');
            dot.setAttribute('aria-selected', k === i ? 'true' : 'false');
        });
        const slide = this.slides[i];
        if (this.mode === 'study') {
            this.visited = this.visited || new Set();
            this.visited.add(i);
            this.flushStudy();
        }
        this.titleEl.textContent = slide.data.title;
        this.situationEl.textContent = slide.data.situation || '';
        this.situationEl.hidden = !slide.data.situation;
        this.counterEl.textContent = fmt(S.sceneof, {current: i + 1, total});
        this.hintEl.textContent = slide.hint;
        if (changed && !instant) {
            Sound.play('whoosh');
        }
        this.renderActions();
        this.syncHeight();
        window.setTimeout(() => {
            this.drawLines(slide);
            this.syncHeight();
        }, instant ? 0 : 660);
        this.say(`${slide.data.title}. ${fmt(S.sceneof, {current: i + 1, total})}`);
    }

    /**
     * Sizes the viewport to the active scene.
     */
    syncHeight() {
        const slide = this.slides?.[this.index];
        if (!slide || !this.viewport) {
            return;
        }
        this.fit(slide);
        this.viewport.style.height = `${slide.el.offsetHeight}px`;
    }

    /**
     * Sizes the picture so the whole player fits on the screen.
     *
     * @param {object} slide
     */
    fit(slide) {
        const shell = this.shell;
        const figure = slide.board.querySelector('.lt-figure');
        if (!shell || !figure) {
            return;
        }
        shell.classList.toggle('lt-wide', shell.clientWidth >= 1000);
        const full = shell.classList.contains('is-full');
        const navbar = full ? 0 : (document.querySelector('nav.navbar.fixed-top')?.offsetHeight || 0);
        const available = window.innerHeight - navbar - (full ? 0 : 16);
        const board = slide.board;
        const cs = window.getComputedStyle(board);
        const boardChrome = parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom) +
            parseFloat(cs.borderTopWidth) + parseFloat(cs.borderBottomWidth);
        const scs = window.getComputedStyle(shell);
        const kids = Array.from(shell.children).filter((k) => k.offsetParent !== null && k !== this.viewport &&
            !k.classList.contains('lt-overlay') && !k.classList.contains('lt-confetti'));
        const gap = parseFloat(scs.rowGap) || 0;
        const shellChrome = kids.reduce((sum, k) => sum + k.offsetHeight, 0) + gap * kids.length +
            parseFloat(scs.paddingTop) + parseFloat(scs.paddingBottom) +
            parseFloat(scs.borderTopWidth) + parseFloat(scs.borderBottomWidth);
        const chrome = shellChrome + (slide.el.offsetHeight - board.offsetHeight) + boardChrome;
        const maxh = Math.max(240, Math.floor(available - chrome - 4));
        shell.style.setProperty('--maxh', `${maxh}px`);
        if (window.innerWidth > 700) {
            const n = Math.max(1, slide.data.pins.length);
            const railgap = maxh / n >= 52 ? 8 : 5;
            const slotH = Math.max(40, Math.min(64, Math.floor((maxh - railgap * (n - 1) - 8) / n)));
            let fs = '.9rem';
            if (slotH >= 58) {
                fs = '1.05rem';
            } else if (slotH >= 50) {
                fs = '.98rem';
            }
            board.style.setProperty('--slot-h', `${slotH}px`);
            board.style.setProperty('--chip-fs', fs);
            board.style.setProperty('--rail-gap', `${railgap}px`);
        } else {
            board.style.removeProperty('--slot-h');
            board.style.removeProperty('--chip-fs');
        }
    }

    /**
     * Scrolls the page so the player sits under Moodle's fixed header.
     */
    scrollToShell() {
        const navbar = document.querySelector('nav.navbar.fixed-top')?.offsetHeight || 0;
        const top = this.host.getBoundingClientRect().top + window.scrollY - navbar - 8;
        window.scrollTo({top, behavior: REDUCED ? 'auto' : 'smooth'});
    }

    /**
     * Renders the footer buttons for the current state.
     */
    renderActions() {
        const slide = this.slides[this.index];
        const last = this.index === this.slides.length - 1;
        const left = el('div', 'lt-actions-left');
        const right = el('div', 'lt-actions-right');
        this.actions.replaceChildren(left, right);
        if (this.index > 0 && this.canNavigateTo(this.index - 1)) {
            left.appendChild(button('lt-btn lt-btn-ghost', S.previous, 'prev', () => this.goTo(this.index - 1)));
        }
        if (this.mode === 'study') {
            this.playAllBtn = button('lt-btn lt-btn-ghost', this.autoplay ? S.stopplaying : S.playall,
                this.autoplay ? 'stop' : 'play', () => this.toggleAutoplay(slide));
            left.appendChild(this.playAllBtn);
            right.appendChild(button('lt-btn lt-btn-primary', last ? S.finish : S.next, 'next', () => {
                if (last) {
                    this.flushStudy(true);
                    this.exit(true);
                } else {
                    this.goTo(this.index + 1);
                }
            }, {after: true}));
            return;
        }
        if (this.mode === 'practice') {
            if (!slide.complete) {
                const level = this.nextHintLevel(slide);
                this.hintBtn = button('lt-btn lt-btn-ghost', level ? `${S.hint} ${level}/4` : S.hint, 'hint',
                    () => this.hint(slide), {disabled: !level});
                left.appendChild(this.hintBtn);
            }
            const b = button('lt-btn lt-btn-primary', last ? S.finish : S.next, 'next', () => {
                if (last) {
                    this.finish();
                } else {
                    this.goTo(this.index + 1);
                }
            }, {after: true, disabled: !slide.complete});
            if (slide.complete) {
                b.classList.add('lt-pulse');
            }
            right.appendChild(b);
            return;
        }
        // Test.
        if (slide.stage === 'match') {
            left.appendChild(button('lt-btn lt-btn-ghost', S.reset, 'reset', () => this.resetSlide(slide)));
            const b = button('lt-btn lt-btn-primary', S.submitscene, 'next', () => this.submitMatch(slide), {after: true});
            if (this.allPlaced(slide)) {
                b.classList.add('lt-pulse');
            }
            right.appendChild(b);
        } else if (slide.stage === 'listen') {
            right.appendChild(button('lt-btn lt-btn-ghost', S.skip, 'next', () => this.chooseListen(slide, ''),
                {after: true}));
        } else if (slide.stage === 'speak') {
            const item = slide.data.speak[slide.speakIndex];
            const done = item && slide.speakDone.has(item.token);
            const b = button(done ? 'lt-btn lt-btn-primary' : 'lt-btn lt-btn-ghost', done ? S.next : S.skip, 'next',
                () => this.nextSpeak(slide), {after: true});
            right.appendChild(b);
        } else if (last) {
            right.appendChild(button('lt-btn lt-btn-primary', S.finish, 'check', () => this.finish(), {after: true}));
        } else {
            right.appendChild(button('lt-btn lt-btn-primary', S.next, 'next', () => this.goTo(this.index + 1),
                {after: true}));
        }
    }

    /**
     * Whether every slot of a scene holds a phrase.
     *
     * @param {object} slide
     * @returns {boolean}
     */
    allPlaced(slide) {
        return Array.from(slide.slots.values()).every((s) => s.chip);
    }

    /**
     * Whether every slot of a scene is finished.
     *
     * @param {object} slide
     * @returns {boolean}
     */
    allLocked(slide) {
        return Array.from(slide.slots.values()).every((s) => s.locked);
    }

    /* ------------------------------------------------------------------ */
    /* Tap / keyboard selection                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Selects or deselects a phrase.
     *
     * @param {object} slide
     * @param {object} chip
     */
    toggleSelect(slide, chip) {
        if (this.mode === 'study' || this.lesson || slide.stage !== 'match') {
            return;
        }
        if (chip.slot && slide.slots.get(chip.slot).locked) {
            return;
        }
        if (this.selected && this.selected.chip === chip) {
            this.clearSelection();
            return;
        }
        this.clearSelection();
        this.selected = {slide, chip};
        chip.el.classList.add('is-selected');
        chip.el.setAttribute('aria-pressed', 'true');
        slide.board.classList.add('is-targeting');
        Sound.play('select');
        this.say(fmt(S.selectedfeedback, chip.text));
    }

    /**
     * Clears the current selection.
     */
    clearSelection() {
        if (!this.selected) {
            return;
        }
        this.selected.chip.el.classList.remove('is-selected');
        this.selected.chip.el.setAttribute('aria-pressed', 'false');
        this.selected.slide.board.classList.remove('is-targeting');
        this.selected = null;
    }

    /**
     * A zone or pin was clicked, tapped or activated by keyboard.
     *
     * @param {object} slide
     * @param {object} slot
     */
    onZoneActivate(slide, slot) {
        if (this.suppressClick || this.lesson) {
            return;
        }
        if (this.mode === 'study') {
            this.stopAutoplay();
            this.openStudyCard(slide, slot);
            return;
        }
        if (this.mode === 'practice' && slot.locked) {
            const chip = slide.chips.get(slot.chip);
            const detail = chip ? slide.details.get(chip.token) : null;
            if (detail) {
                this.openLesson(slide, slot, chip, detail, true);
            }
            return;
        }
        if (this.mode === 'test' && slide.stage === 'listen') {
            this.chooseListen(slide, slot.token);
            return;
        }
        if (slot.locked || slide.stage !== 'match') {
            return;
        }
        if (this.selected && this.selected.slide === slide) {
            const chip = this.selected.chip;
            const from = chip.el.getBoundingClientRect();
            this.clearSelection();
            this.place(slide, chip, slot, from);
            return;
        }
        if (slot.chip) {
            this.toggleSelect(slide, slide.chips.get(slot.chip));
        }
    }

    /* ------------------------------------------------------------------ */
    /* Drag and drop (pointer events: mouse, pen, touch)                  */
    /* ------------------------------------------------------------------ */

    /**
     * Pointer down on a phrase.
     *
     * @param {PointerEvent} e
     * @param {object} slide
     * @param {object} chip
     */
    pointerDown(e, slide, chip) {
        if (this.mode === 'study' || this.lesson || e.button > 0 || this.drag || slide.stage !== 'match') {
            return;
        }
        if (chip.slot && slide.slots.get(chip.slot).locked) {
            return;
        }
        this.suppressClick = false;
        this.drag = {slide, chip, startX: e.clientX, startY: e.clientY, id: e.pointerId, active: false, over: null,
            lastX: e.clientX};
        const move = (ev) => this.pointerMove(ev);
        const up = (ev) => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            window.removeEventListener('pointercancel', up);
            this.pointerUp(ev);
        };
        window.addEventListener('pointermove', move, {passive: false});
        window.addEventListener('pointerup', up);
        window.addEventListener('pointercancel', up);
    }

    /**
     * Pointer move.
     *
     * @param {PointerEvent} e
     */
    pointerMove(e) {
        const d = this.drag;
        if (!d || e.pointerId !== d.id) {
            return;
        }
        if (!d.active) {
            if (Math.hypot(e.clientX - d.startX, e.clientY - d.startY) < 6) {
                return;
            }
            this.beginDrag(e);
        }
        e.preventDefault();
        const vx = e.clientX - d.lastX;
        d.lastX = e.clientX;
        d.tilt = Math.max(-10, Math.min(10, (d.tilt || 0) * 0.7 + vx * 0.9));
        d.ghost.style.transform = `translate3d(${e.clientX - d.offX}px, ${e.clientY - d.offY}px, 0)
            rotate(${REDUCED ? 0 : d.tilt}deg) scale(1.06)`;
        this.updateOver(e.clientX, e.clientY);
    }

    /**
     * Starts the visual drag.
     *
     * @param {PointerEvent} e
     */
    beginDrag(e) {
        const d = this.drag;
        d.active = true;
        this.suppressClick = true;
        this.clearSelection();
        const r = d.chip.el.getBoundingClientRect();
        d.offX = e.clientX - r.left;
        d.offY = e.clientY - r.top;
        const ghost = d.chip.el.cloneNode(true);
        ghost.className = 'lt-chip lt-ghost';
        ghost.style.setProperty('--c', d.chip.color);
        ghost.style.setProperty('--ink', readable(d.chip.color));
        ghost.style.width = `${r.width}px`;
        ghost.style.height = `${r.height}px`;
        ghost.style.transform = `translate3d(${r.left}px, ${r.top}px, 0)`;
        const host = document.fullscreenElement || document.querySelector('.path-mod-ailanguageteacher') || document.body;
        host.appendChild(ghost);
        d.ghost = ghost;
        d.chip.el.classList.add('is-lifted');
        d.slide.board.classList.add('is-dragging');
        d.slide.tray.classList.add('is-dragging');
        document.body.classList.add('lt-noselect');
        Sound.play('pickup');
    }

    /**
     * Finds the drop target under or near the pointer and highlights it.
     *
     * @param {number} x
     * @param {number} y
     */
    updateOver(x, y) {
        const d = this.drag;
        let target = null;
        let best = Infinity;
        const magnet = COARSE ? 48 : 38;
        d.slide.slots.forEach((slot) => {
            if (slot.locked) {
                return;
            }
            const z = slot.zone.getBoundingClientRect();
            if (x >= z.left - 6 && x <= z.right + 6 && y >= z.top - 6 && y <= z.bottom + 6) {
                target = slot;
                best = -1;
                return;
            }
            const p = slot.pinEl.getBoundingClientRect();
            const dist = Math.hypot(x - (p.left + p.width / 2), y - (p.top + p.height / 2));
            if (dist < magnet && dist < best) {
                best = dist;
                target = slot;
            }
        });
        let overTray = false;
        if (!target) {
            const t = d.slide.tray.getBoundingClientRect();
            overTray = x >= t.left && x <= t.right && y >= t.top && y <= t.bottom;
        }
        if (target !== d.over) {
            if (d.over) {
                d.over.zone.classList.remove('is-over');
                d.over.pinEl.classList.remove('is-over');
                d.over.path.classList.remove('is-over');
            }
            if (target) {
                target.zone.classList.add('is-over');
                target.pinEl.classList.add('is-over');
                target.path.classList.add('is-over');
                target.path.style.setProperty('--c', d.chip.color);
                Sound.play('zone');
                if (navigator.vibrate && COARSE) {
                    navigator.vibrate(8);
                }
            }
            d.over = target;
        }
        d.slide.tray.classList.toggle('is-over', overTray);
        d.overTray = overTray;
    }

    /**
     * Pointer released.
     *
     * @param {PointerEvent} e
     */
    pointerUp(e) {
        const d = this.drag;
        this.drag = null;
        if (!d || !d.active) {
            return;
        }
        e.preventDefault();
        const ghostRect = d.ghost.getBoundingClientRect();
        d.ghost.remove();
        d.chip.el.classList.remove('is-lifted');
        d.slide.board.classList.remove('is-dragging');
        d.slide.tray.classList.remove('is-dragging', 'is-over');
        document.body.classList.remove('lt-noselect');
        if (d.over) {
            d.over.zone.classList.remove('is-over');
            d.over.pinEl.classList.remove('is-over');
            d.over.path.classList.remove('is-over');
            this.place(d.slide, d.chip, d.over, ghostRect);
        } else if (d.overTray && d.chip.slot) {
            this.unplace(d.slide, d.chip, true, ghostRect);
        } else {
            flip(d.chip.el, ghostRect, {duration: 460});
            Sound.play('back');
        }
        window.setTimeout(() => {
            this.suppressClick = false;
        }, 50);
    }

    /* ------------------------------------------------------------------ */
    /* Placement                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Places a phrase into a slot.
     *
     * @param {object} slide
     * @param {object} chip
     * @param {object} slot
     * @param {DOMRect} fromRect
     */
    place(slide, chip, slot, fromRect) {
        if (slot.locked) {
            return;
        }
        if (chip.slot === slot.token) {
            flip(chip.el, fromRect);
            return;
        }
        const prevSlot = chip.slot ? slide.slots.get(chip.slot) : null;
        if (this.mode === 'practice') {
            const answer = slide.chips.get(slide.answers.get(slot.token));
            // Two phrases with the same words are interchangeable.
            const correct = slide.answers.get(slot.token) === chip.token ||
                (!!answer && answer.text.trim().toLowerCase() === chip.text.trim().toLowerCase());
            this.attach(slide, chip, slot, fromRect, prevSlot);
            Sound.play('drop');
            chip.tries++;
            if (correct) {
                const token = slide.answers.get(slot.token);
                const real = chip;
                const detail = slide.details.get(token) || slide.details.get(chip.token);
                slot.locked = true;
                slot.zone.classList.add('is-correct');
                slot.pinEl.classList.add('is-correct');
                real.el.classList.add('is-locked');
                real.el.disabled = true;
                window.setTimeout(() => {
                    Sound.play('correct');
                    const b = slide.board.getBoundingClientRect();
                    const p = slot.pinEl.getBoundingClientRect();
                    burst(slide.board, p.left + p.width / 2 - b.left, p.top + p.height / 2 - b.top, chip.color);
                }, 140);
                this.say(fmt(S.correctfeedback, chip.text));
                this.event(detail ? detail.token : chip.token, 'matched', chip.tries, chip.hintlevel);
                window.setTimeout(async() => {
                    await this.openLesson(slide, slot, real, detail, false);
                    this.checkPracticeComplete(slide);
                }, 520);
                this.updateScore();
            } else {
                slot.zone.classList.add('is-wrong');
                slot.pinEl.classList.add('is-wrong');
                window.setTimeout(() => Sound.play('wrong'), 120);
                this.say(fmt(S.wrongfeedback, chip.text));
                window.setTimeout(() => {
                    slot.zone.classList.remove('is-wrong');
                    slot.pinEl.classList.remove('is-wrong');
                    this.unplace(slide, chip, false);
                    Sound.play('back');
                    if (chip.tries >= 2 && this.hintBtn) {
                        this.hintBtn.classList.add('lt-pulse');
                    }
                }, 650);
            }
            return;
        }
        // Test: free placement, swap if occupied.
        if (slot.chip) {
            const other = slide.chips.get(slot.chip);
            if (prevSlot) {
                const otherRect = other.el.getBoundingClientRect();
                this.detach(slide, other);
                this.attach(slide, other, prevSlot, otherRect, null);
            } else {
                this.unplace(slide, other, false);
            }
        }
        this.attach(slide, chip, slot, fromRect, prevSlot);
        Sound.play('drop');
        this.say(fmt(S.placedfeedback, {label: chip.text, number: slot.number}));
        this.updateScore();
        this.renderActions();
    }

    /**
     * Moves a phrase into a slot and animates it.
     *
     * @param {object} slide
     * @param {object} chip
     * @param {object} slot
     * @param {DOMRect|null} fromRect
     * @param {object|null} prevSlot
     * @param {boolean} quiet no animation
     */
    attach(slide, chip, slot, fromRect, prevSlot, quiet = false) {
        if (prevSlot && prevSlot.chip === chip.token) {
            prevSlot.chip = null;
            prevSlot.color = null;
            prevSlot.zone.classList.remove('is-filled');
            prevSlot.pinEl.classList.remove('is-filled');
            this.labelZone(slide, prevSlot);
        }
        chip.slot = slot.token;
        slot.chip = chip.token;
        slot.color = chip.color;
        slot.body.appendChild(chip.el);
        slot.zone.classList.add('is-filled');
        slot.pinEl.classList.add('is-filled');
        slot.pinEl.style.setProperty('--c', chip.color);
        this.labelZone(slide, slot);
        if (quiet) {
            return;
        }
        flip(chip.el, fromRect);
        this.animateLine(slide, slot);
        if (prevSlot) {
            this.drawLines(slide);
        }
        this.collapseTray(slide);
    }

    /**
     * Detaches a phrase from its slot (without moving the element).
     *
     * @param {object} slide
     * @param {object} chip
     */
    detach(slide, chip) {
        if (!chip.slot) {
            return;
        }
        const slot = slide.slots.get(chip.slot);
        slot.chip = null;
        slot.color = null;
        slot.zone.classList.remove('is-filled');
        slot.pinEl.classList.remove('is-filled');
        this.labelZone(slide, slot);
        chip.slot = null;
    }

    /**
     * Returns a phrase to the tray.
     *
     * @param {object} slide
     * @param {object} chip
     * @param {boolean} sound
     * @param {DOMRect|null} fromRect
     */
    unplace(slide, chip, sound, fromRect = null) {
        const from = fromRect || chip.el.getBoundingClientRect();
        this.detach(slide, chip);
        slide.tray.insertBefore(chip.el, slide.tray.querySelector('.lt-tray-done'));
        flip(chip.el, from, {duration: 520});
        this.drawLines(slide);
        this.collapseTray(slide);
        if (sound) {
            Sound.play('back');
            this.say(fmt(S.returntotray, chip.text));
        }
        this.updateScore();
        if (this.mode === 'test') {
            this.renderActions();
        }
    }

    /**
     * Marks the tray empty or not, for styling.
     *
     * @param {object} slide
     */
    collapseTray(slide) {
        slide.tray.classList.toggle('is-empty', !slide.tray.querySelector('.lt-chip:not(.is-faded)'));
        window.setTimeout(() => this.syncHeight(), 320);
    }

    /**
     * Locks a finished Practice slot and marks it mastered or "practise again".
     *
     * @param {object} slide
     * @param {object} slot
     * @param {object} chip
     * @param {string} state
     */
    lockSlot(slide, slot, chip, state) {
        slot.locked = true;
        slot.zone.classList.add('is-correct');
        slot.pinEl.classList.add('is-correct');
        chip.el.classList.add('is-locked');
        chip.el.disabled = true;
        slot.zone.querySelectorAll('.lt-check').forEach((m) => m.remove());
        const mark = el('span', `lt-check${state === 'mastered' ? ' is-star' : ' is-again'}`, {'aria-hidden': 'true'});
        mark.appendChild(icon(state === 'mastered' ? 'star' : 'repeat'));
        slot.zone.appendChild(mark);
    }

    /**
     * Hides the distractor phrases left in the tray once a scene is done.
     *
     * @param {object} slide
     */
    fadeDistractors(slide) {
        slide.chips.forEach((chip) => {
            if (!chip.slot) {
                chip.el.classList.add('is-faded');
                chip.el.disabled = true;
            }
        });
        this.collapseTray(slide);
    }

    /**
     * Practice: checks whether the scene is complete.
     *
     * @param {object} slide
     */
    checkPracticeComplete(slide) {
        if (!this.allLocked(slide) || slide.complete) {
            this.renderActions();
            return;
        }
        slide.complete = true;
        this.fadeDistractors(slide);
        window.setTimeout(() => {
            Sound.play('slide');
            this.say(S.scenecomplete);
            slide.board.classList.add('is-complete');
            const banner = el('div', 'lt-banner');
            banner.append(icon('star'), el('span', '', {text: S.scenecomplete}));
            slide.el.appendChild(banner);
            window.setTimeout(() => banner.classList.add('is-out'), 2200);
            window.setTimeout(() => banner.remove(), 2800);
            confetti(slide.el, 60);
        }, 200);
        this.renderActions();
        Array.from(this.dotsEl.children)[slide.index].classList.add('is-done');
    }

    /**
     * Sends a Practice step to the server (fire and forget; errors are shown).
     *
     * @param {string} token phrase token
     * @param {string} action matched|hint|moveon
     * @param {number} tries
     * @param {number} hintlevel
     * @returns {Promise}
     */
    event(token, action, tries = 1, hintlevel = 0) {
        return Promise.resolve(Ajax.call([{methodname: 'mod_ailanguageteacher_practice_event', args: {
            attemptid: this.attemptid, token, action, tries, hintlevel}}])[0]).catch(Notification.exception);
    }

    /* ------------------------------------------------------------------ */
    /* Hints (Practice): hear it, see its meaning, where roughly, exactly */
    /* ------------------------------------------------------------------ */

    /**
     * The phrase a hint is for: the selected one, else the first unplaced phrase that belongs to a place.
     *
     * @param {object} slide
     * @returns {object|null}
     */
    hintTarget(slide) {
        const answers = new Set(slide.answers.values());
        if (this.selected && this.selected.slide === slide && answers.has(this.selected.chip.token)) {
            return this.selected.chip;
        }
        return Array.from(slide.chips.values()).find((c) => !c.slot && answers.has(c.token)) || null;
    }

    /**
     * The next hint level available, 0 when none.
     *
     * @param {object} slide
     * @returns {number}
     */
    nextHintLevel(slide) {
        const chip = this.hintTarget(slide);
        return chip && chip.hintlevel < 4 ? chip.hintlevel + 1 : 0;
    }

    /**
     * Gives the next, stronger hint for a phrase.
     *
     * @param {object} slide
     */
    async hint(slide) {
        const chip = this.hintTarget(slide);
        if (!chip || chip.hintlevel >= 4) {
            return;
        }
        chip.hintlevel++;
        const level = chip.hintlevel;
        let pin = null;
        slide.answers.forEach((c, p) => {
            if (c === chip.token) {
                pin = p;
            }
        });
        const slot = slide.slots.get(pin);
        Sound.play('hint');
        chip.el.classList.add('lt-hinted');
        window.setTimeout(() => chip.el.classList.remove('lt-hinted'), 1800);
        if (level === 1) {
            this.say(S.hint1);
            this.listen(slide.details.get(chip.token), 'normal');
        } else if (level === 2) {
            const meaning = chip.el.querySelector('.lt-chip-meaning');
            if (meaning && meaning.textContent.trim()) {
                meaning.hidden = false;
            }
            this.say(S.hint2);
            this.syncHeight();
        } else if (level === 3 && slot) {
            const area = el('span', 'lt-area', {'aria-hidden': 'true'});
            const jitter = () => (Math.random() - 0.5) * 8;
            area.style.left = `${Math.max(8, Math.min(92, slot.pin.x + jitter()))}%`;
            area.style.top = `${Math.max(8, Math.min(92, slot.pin.y + jitter()))}%`;
            slide.board.querySelector('.lt-figure').appendChild(area);
            window.setTimeout(() => area.remove(), 2600);
            this.say(S.hint3);
        } else if (slot) {
            slot.zone.classList.add('lt-hinted');
            slot.pinEl.classList.add('lt-hinted');
            window.setTimeout(() => {
                slot.zone.classList.remove('lt-hinted');
                slot.pinEl.classList.remove('lt-hinted');
            }, 2200);
            this.say(S.hint4);
        }
        this.event(chip.token, 'hint', 0, level);
        this.renderActions();
    }

    /* ------------------------------------------------------------------ */
    /* Listening                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Plays a phrase (teacher recording, stored voice, or the browser's voice).
     *
     * @param {object} detail phrase details (phraseid, text, example, audio)
     * @param {string} variant normal|slow|example
     * @returns {Promise}
     */
    async listen(detail, variant = 'normal') {
        if (!detail) {
            return;
        }
        const key = `${detail.phraseid}:${variant}`;
        let url = variant === 'normal' ? detail.audio : '';
        if (!url && this.audioCache.has(key)) {
            url = this.audioCache.get(key);
        }
        if (!url && this.config.hasservicetts) {
            try {
                const res = await Ajax.call([{methodname: 'mod_ailanguageteacher_get_audio', args: {
                    cmid: this.config.cmid, ref: `p${detail.phraseid}`, variant}}])[0];
                url = res.url;
                this.audioCache.set(key, url);
            } catch (err) {
                url = '';
            }
        }
        // No separate slow recording: play the paid normal voice slowed down, rather than the browser's voice.
        let slowfile = !!url && variant === 'slow';
        if (!url && variant === 'slow') {
            slowfile = false;
            url = detail.audio || '';
            if (!url && this.config.hasservicetts) {
                const normal = `${detail.phraseid}:normal`;
                if (this.audioCache.has(normal)) {
                    url = this.audioCache.get(normal);
                } else {
                    try {
                        const res = await Ajax.call([{methodname: 'mod_ailanguageteacher_get_audio', args: {
                            cmid: this.config.cmid, ref: `p${detail.phraseid}`, variant: 'normal'}}])[0];
                        url = res.url;
                        this.audioCache.set(normal, url);
                    } catch (err) {
                        url = '';
                    }
                }
            }
        }
        const text = variant === 'example' ? detail.example : detail.text;
        try {
            await Speech.play({url, text, locale: this.config.locale, slow: variant === 'slow', slowfile});
        } catch (err) {
            this.say(S.audiounavailable);
        }
    }

    /* ------------------------------------------------------------------ */
    /* The phrase card                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Opens the Study phrase card.
     *
     * @param {object} slide
     * @param {object} slot
     * @returns {Promise}
     */
    openStudyCard(slide, slot) {
        this.studied.add(slot.pin.phraseid);
        return this.openLesson(slide, slot, null, slot.pin, true);
    }

    /**
     * Opens the phrase card over the picture. In Practice it stays open until the phrase is mastered or the learner
     * moves on.
     *
     * @param {object} slide
     * @param {object} slot
     * @param {object|null} chip
     * @param {object} detail
     * @param {boolean} revisit opened again from a finished slot (or Study): can be closed freely
     * @returns {Promise} resolves when the card closes
     */
    async openLesson(slide, slot, chip, detail, revisit) {
        this.closeLesson(true);
        if (!detail) {
            return;
        }
        const c = this.config;
        const practice = this.mode === 'practice';
        const color = slot.color || detail.color || '#6366F1';
        const successes = Math.min(detail.successes || 0, c.repetitions);
        const speaking = practice ? !!c.speaking : c.speakmode !== 'self' || Speech.canRecord();
        const goal = practice ? (c.repetitions > 1 ? fmt(S.goal_times, {score: c.passscore, times: c.repetitions})
            : fmt(S.goal_once, c.passscore)) : '';
        const node = await render('phrase_card', {
            number: slot.number, color, ink: readable(color),
            eyebrow: fmt(S.phraseof, {current: slot.number, total: slide.slots.size}),
            text: detail.text, romanisation: detail.romanisation, lang: c.locale, supportlang: c.supportlang,
            translation: detail.translation, usagenote: detail.usagenote, example: detail.example,
            exampletrans: detail.exampletrans, hasexample: detail.hasexample, speaking, goal,
            dots: Array.from({length: c.repetitions}, (v, i) => ({done: i < successes})),
            dotslabel: fmt(S.successfultries, {done: Math.min(successes, c.repetitions), total: c.repetitions}),
            practice, study: !practice, canclose: !practice || revisit,
            note: speaking && !this.canSpeak() ? S.speaknotavailable : '',
        });
        const wrap = el('div', 'lt-lesson-wrap');
        wrap.appendChild(node);
        if (window.matchMedia('(max-width: 700px)').matches) {
            // On phones the card is a bottom sheet over the whole page, outside the slide's stacking context.
            wrap.classList.add('lt-lesson-sheet');
            (document.fullscreenElement || document.body).appendChild(wrap);
        } else {
            slide.board.appendChild(wrap);
        }
        slot.pinEl.classList.add('is-focus');
        const lesson = {wrap, node, slide, slot, chip, detail, revisit, practice};
        this.lesson = lesson;
        const done = new Promise((resolve) => {
            lesson.resolve = resolve;
        });
        const q = (a) => node.querySelector(`[data-action="${a}"]`);
        // "Listen before speaking": in Practice the microphone opens once the learner has heard the phrase.
        let mustlisten = practice && !!c.mustlisten && !revisit;
        const statusEl = node.querySelector('[data-region="status"]');
        const heard = () => {
            if (!mustlisten) {
                return;
            }
            mustlisten = false;
            const m = q('speak');
            if (m) {
                m.disabled = !this.canSpeak();
            }
            if (statusEl && statusEl.textContent === S.listenfirst) {
                statusEl.textContent = '';
            }
        };
        // Only the latest playback counts: pressing Listen again stops the earlier one, which must not open the mic.
        let playid = 0;
        const play = (variant) => {
            const id = ++playid;
            return this.listen(detail, variant).then(() => {
                if (id === playid) {
                    heard();
                }
            });
        };
        q('listen').addEventListener('click', () => play('normal'));
        q('slow').addEventListener('click', () => play('slow'));
        const ex = q('example');
        if (ex) {
            ex.addEventListener('click', () => this.listen(detail, 'example'));
        }
        node.querySelectorAll('[data-action="close"]').forEach((b) => b.addEventListener('click', () => this.closeLesson()));
        const mic = q('speak');
        if (mic) {
            if (!this.canSpeak() || mustlisten) {
                mic.disabled = true;
            }
            if (mustlisten && statusEl) {
                statusEl.textContent = S.listenfirst;
            }
            mic.addEventListener('click', () => this.speakTurn(lesson));
        }
        if (practice) {
            const cont = q('continue');
            const moveon = q('moveon');
            const finishPhrase = (state) => {
                if (chip && state) {
                    detail.state = state;
                    this.lockSlot(slide, slot, chip, state);
                }
                this.closeLesson();
            };
            cont.addEventListener('click', () => finishPhrase(revisit ? null : 'mastered'));
            moveon.addEventListener('click', async() => {
                await this.event(detail.token, 'moveon');
                finishPhrase('needspractice');
            });
            lesson.cont = cont;
            lesson.moveon = moveon;
            if (revisit || !c.speaking || detail.state === 'mastered') {
                cont.disabled = false;
            }
            if (c.speaking && !revisit && (!this.canSpeak() || detail.speaktries >= c.maxspeaktries)) {
                moveon.hidden = false;
            }
        }
        requestAnimationFrame(() => wrap.classList.add('is-open'));
        Sound.play('card');
        node.focus({preventScroll: true});
        if (!revisit || this.mode === 'study') {
            window.setTimeout(() => {
                if (this.lesson === lesson) {
                    play('normal');
                }
            }, 350);
        }
        return done;
    }

    /**
     * Closes the phrase card.
     *
     * @param {boolean} instant
     */
    closeLesson(instant = false) {
        const lesson = this.lesson;
        if (!lesson) {
            return;
        }
        this.lesson = null;
        if (lesson.signal && lesson.signal.stop) {
            lesson.signal.stop();
        }
        Speech.stop();
        lesson.slot.pinEl.classList.remove('is-focus');
        if (instant || REDUCED) {
            lesson.wrap.remove();
        } else {
            lesson.wrap.classList.remove('is-open');
            window.setTimeout(() => lesson.wrap.remove(), 240);
        }
        if (lesson.resolve) {
            lesson.resolve();
        }
    }

    /**
     * One speaking turn on the phrase card: record, score, show feedback, update mastery.
     *
     * @param {object} lesson
     */
    async speakTurn(lesson) {
        const node = lesson.node;
        const mic = node.querySelector('[data-action="speak"]');
        const label = node.querySelector('[data-region="miclabel"]');
        const status = node.querySelector('[data-region="status"]');
        const result = node.querySelector('[data-region="result"]');
        if (lesson.busy) {
            if (lesson.signal && lesson.signal.stop) {
                lesson.signal.stop();
            }
            return;
        }
        lesson.busy = true;
        Speech.stop();
        const c = this.config;
        const kind = lesson.practice ? 'practice' : 'study';
        const ref = lesson.practice ? lesson.detail.token : `p${lesson.detail.phraseid}`;
        const setBusy = (text, listening) => {
            mic.classList.toggle('is-listening', listening);
            mic.classList.toggle('is-checking', !listening && !!text);
            label.textContent = text || S.tapandsay;
        };
        lesson.signal = {};
        let args = null;
        try {
            if (c.speakmode === 'service') {
                setBusy(S.tapwhendone, true);
                const rec = await Speech.recordWav({signal: lesson.signal, maxms: 8000,
                    onlevel: (v) => mic.style.setProperty('--level', v.toFixed(2))});
                if (!rec.heard) {
                    throw new Error('nothingheard');
                }
                setBusy(S.checking, false);
                args = {audio: await Speech.toBase64(rec.blob)};
            } else if (c.speakmode === 'browser') {
                setBusy(S.tapwhendone, true);
                const transcripts = await Speech.recognise(c.locale, lesson.signal);
                if (!transcripts.length) {
                    throw new Error('nothingheard');
                }
                setBusy(S.checking, false);
                args = {transcripts};
            } else {
                setBusy(S.tapwhendone, true);
                const blob = await Speech.recordClip(lesson.signal, 8000);
                setBusy('', false);
                lesson.busy = false;
                this.showSelfCheck(lesson, blob, result);
                return;
            }
            const res = await Ajax.call([{methodname: 'mod_ailanguageteacher_assess_speech', args: {
                cmid: c.cmid, kind, attemptid: lesson.practice ? this.attemptid : 0, ref, ...args}}])[0];
            await this.showResult(lesson, res, result);
            status.textContent = '';
        } catch (err) {
            if (err && err.message === 'nothingheard') {
                status.textContent = S.nothingheard;
            } else if (err && (err.name === 'NotAllowedError' || err.message === 'not-allowed')) {
                status.textContent = S.micblocked;
                if (lesson.moveon) {
                    lesson.moveon.hidden = false;
                }
            } else if (err && err.errorcode === 'speechratelimit') {
                status.textContent = S.speechratelimit;
            } else if (err && err.errorcode === 'speechinprogress') {
                status.textContent = S.speechinprogress;
            } else if (err && err.errorcode) {
                Notification.exception(err);
            } else {
                status.textContent = S.speechfailed_short;
            }
        } finally {
            mic.style.removeProperty('--level');
            setBusy('', false);
            lesson.busy = false;
        }
    }

    /**
     * "Listen back and compare" when no scoring service is available.
     *
     * @param {object} lesson
     * @param {Blob} blob
     * @param {HTMLElement} host
     */
    async showSelfCheck(lesson, blob, host) {
        const url = URL.createObjectURL(blob);
        const node = await render('speak_result', {hasscore: false, tone: 'neutral', headline: S.recorded,
            message: '', units: [], summary: '', showheard: false, selfcheck: true});
        host.replaceChildren(node);
        node.querySelector('[data-action="playmine"]').addEventListener('click', () => Speech.play({url}).catch(() => null));
        node.querySelector('[data-action="listen"]').addEventListener('click', () => this.listen(lesson.detail, 'normal'));
        node.querySelector('[data-action="selfok"]').addEventListener('click', async() => {
            URL.revokeObjectURL(url);
            if (!lesson.practice) {
                host.replaceChildren();
                return;
            }
            try {
                const res = await Ajax.call([{methodname: 'mod_ailanguageteacher_assess_speech', args: {
                    cmid: this.config.cmid, kind: 'practice', attemptid: this.attemptid, ref: lesson.detail.token,
                    selfcheck: true}}])[0];
                await this.showResult(lesson, res, host);
            } catch (err) {
                Notification.exception(err);
            }
        });
    }

    /**
     * Shows speaking feedback and updates the mastery dots.
     *
     * @param {object} lesson
     * @param {object} res assess_speech result
     * @param {HTMLElement} host
     */
    async showResult(lesson, res, host) {
        const c = this.config;
        const pass = res.passscore;
        if (!res.counted) {
            // Silence or unclear speech: no mark, and the try does not count.
            const node = await render('speak_result', {hasscore: false, tone: 'neutral', eyebrow: S.spokencheck,
                headline: res.status === 'unintelligible' ? S.unintelligible : S.nothingheard, message: '',
                units: [], summary: '', showheard: false, selfcheck: false});
            host.replaceChildren(node);
            this.say(node.textContent.trim());
            return;
        }
        let tone = 'bad';
        let headline = S.headline_tryagain;
        if (res.provider === 'self' || res.score >= Math.max(90, pass)) {
            tone = 'great';
            headline = S.headline_excellent;
        } else if (res.score >= pass) {
            tone = 'good';
            headline = S.headline_great;
        } else if (res.score >= pass * 0.75) {
            tone = 'ok';
            headline = S.headline_nearly;
        }
        let message = '';
        if (lesson.practice) {
            const left = Math.max(0, res.required - res.successes);
            if (res.mastered) {
                message = S.msg_mastered;
            } else if (res.passed) {
                message = fmt(S.msg_moretogo, left);
            } else if (c.consecutive && res.successes === 0) {
                message = S.msg_reset;
            } else {
                message = S.msg_keepgoing;
            }
        }
        const checked = res.provider !== 'self';
        const summary = checked && res.total > 0
            ? fmt(res.unit === 'char' ? S.charsrecognised : S.wordsrecognised, {found: res.found, total: res.total}) : '';
        const node = await render('speak_result', {
            score: res.score, hasscore: checked && res.score >= 0, tone, headline, message,
            eyebrow: checked ? S.spokencheck : '', summary, scorelabel: S.matchscore,
            units: (res.units || []).map((u) => ({text: u.text, tone: u.ok ? 'good' : 'miss'})),
            heard: res.recognised, showheard: checked && !!res.recognised, selfcheck: false,
        });
        host.replaceChildren(node);
        this.say(`${headline} ${summary} ${message}`);
        if (!lesson.practice) {
            return;
        }
        lesson.detail.successes = res.successes;
        lesson.detail.speaktries = (lesson.detail.speaktries || 0) + 1;
        lesson.node.querySelector('[data-region="dots"]')?.setAttribute('aria-label',
            fmt(S.successfultries, {done: Math.min(res.successes, res.required), total: res.required}));
        const dots = lesson.node.querySelectorAll('.lt-sdot');
        dots.forEach((d, i) => {
            const was = d.classList.contains('is-done');
            d.classList.toggle('is-done', i < res.successes);
            if (!was && i < res.successes && !REDUCED) {
                d.animate([{transform: 'scale(1.8)'}, {transform: 'scale(1)'}], {duration: 420, easing: SPRING});
            }
        });
        if (res.mastered) {
            lesson.detail.state = 'mastered';
            lesson.cont.disabled = false;
            lesson.cont.classList.add('lt-pulse');
            lesson.moveon.hidden = true;
            Sound.play('mastered');
            confetti(lesson.node, 50);
            lesson.cont.focus();
        } else if (res.canmoveon) {
            lesson.moveon.hidden = false;
        }
        this.updateScore();
    }

    /* ------------------------------------------------------------------ */
    /* Study: play the whole scene                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Starts or stops playing every phrase of the scene in turn.
     *
     * @param {object} slide
     */
    async toggleAutoplay(slide) {
        if (this.autoplay) {
            this.stopAutoplay();
            return;
        }
        const run = {stopped: false};
        this.autoplay = run;
        this.renderActions();
        for (const slot of slide.slots.values()) {
            if (run.stopped) {
                break;
            }
            this.studied.add(slot.pin.phraseid);
            slot.pinEl.classList.add('is-focus');
            slot.zone.classList.add('is-playing');
            await this.listen(slot.pin, 'normal');
            await new Promise((r) => window.setTimeout(r, 700));
            slot.pinEl.classList.remove('is-focus');
            slot.zone.classList.remove('is-playing');
        }
        if (this.autoplay === run) {
            this.autoplay = null;
            this.renderActions();
        }
    }

    /**
     * Stops "play all".
     */
    stopAutoplay() {
        if (!this.autoplay) {
            return;
        }
        this.autoplay.stopped = true;
        this.autoplay = null;
        Speech.stop();
        if (this.slides) {
            this.slides.forEach((s) => s.slots.forEach((slot) => {
                slot.pinEl.classList.remove('is-focus');
                slot.zone.classList.remove('is-playing');
            }));
            if (this.actions) {
                this.renderActions();
            }
        }
    }

    /**
     * Sends newly studied phrases, and finishes Study once every scene has been seen.
     *
     * @param {boolean} force send even when nothing new was opened
     */
    flushStudy(force = false) {
        if (this.mode !== 'study' || !this.config.canattempt || !this.slides) {
            return;
        }
        const fresh = Array.from(this.studied).filter((id) => !this.studySent.has(id));
        const complete = !!this.visited && this.visited.size === this.slides.length;
        if (!fresh.length && !(complete && !this.studyComplete) && !force) {
            return;
        }
        fresh.forEach((id) => this.studySent.add(id));
        if (complete) {
            this.studyComplete = true;
        }
        Promise.resolve(Ajax.call([{methodname: 'mod_ailanguageteacher_record_study', args: {
            cmid: this.config.cmid, phraseids: fresh, complete}}])[0]).catch(() => null);
    }

    /* ------------------------------------------------------------------ */
    /* Test                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Test: returns every placed phrase to the tray.
     *
     * @param {object} slide
     */
    resetSlide(slide) {
        slide.chips.forEach((chip) => {
            if (chip.slot) {
                this.unplace(slide, chip, false);
            }
        });
        Sound.play('back');
    }

    /**
     * Test: submits the matching stage of a scene.
     *
     * @param {object} slide
     */
    async submitMatch(slide) {
        if (slide.submitting) {
            return;
        }
        if (!this.allPlaced(slide) && !await this.confirm(S.confirmsubmit, S.submitscene)) {
            return;
        }
        slide.submitting = true;
        const answers = Array.from(slide.slots.values()).map((s) => ({item: s.token, answer: s.chip || ''}));
        try {
            await Ajax.call([{methodname: 'mod_ailanguageteacher_submit_scene', args: {attemptid: this.attemptid,
                sceneid: slide.data.id, stage: 'match', answers}}])[0];
        } catch (err) {
            slide.submitting = false;
            Notification.exception(err);
            return;
        }
        slide.submitting = false;
        slide.slots.forEach((s) => {
            s.locked = true;
        });
        slide.board.classList.add('is-submitted');
        slide.tray.hidden = true;
        Sound.play('slide');
        this.nextStage(slide);
    }

    /**
     * Test: moves a scene to its next stage.
     *
     * @param {object} slide
     */
    nextStage(slide) {
        if (slide.stage === 'match' && this.stages.includes('listen') && slide.data.listen.length) {
            this.startListen(slide);
        } else if (slide.stage !== 'speak' && this.stages.includes('speak') && slide.data.speak.length) {
            this.startSpeak(slide);
        } else {
            this.sceneDone(slide);
        }
    }

    /**
     * Test: shows the stage prompt bar.
     *
     * @param {object} slide
     * @param {object} context
     * @returns {Promise<HTMLElement>}
     */
    async showStage(slide, context) {
        const node = await render('stage_bar', context);
        slide.stageEl.replaceChildren(node);
        slide.stageEl.hidden = false;
        this.hintEl.textContent = context.listen ? S.selectplace : '';
        this.syncHeight();
        return node;
    }

    /**
     * Test: the listening stage (hear a phrase, tap where it belongs).
     *
     * @param {object} slide
     */
    async startListen(slide) {
        slide.stage = 'listen';
        slide.listenIndex = 0;
        slide.board.classList.add('is-listening');
        slide.slots.forEach((s) => {
            s.pinEl.tabIndex = 0;
        });
        this.renderActions();
        await this.showListenItem(slide);
    }

    /**
     * Test: shows and plays the current listening item.
     *
     * @param {object} slide
     */
    async showListenItem(slide) {
        const items = slide.data.listen;
        const item = items[slide.listenIndex];
        const node = await this.showStage(slide, {listen: true, step: fmt(S.stage_listen_step,
            {current: slide.listenIndex + 1, total: items.length})});
        node.querySelector('[data-action="stageplay"]').addEventListener('click', () => this.playListen(item));
        window.setTimeout(() => this.playListen(item), 300);
    }

    /**
     * Test: plays a listening item.
     *
     * @param {object} item
     */
    async playListen(item) {
        let url = item.audio;
        if (!url && this.config.hasservicetts) {
            try {
                url = (await Ajax.call([{methodname: 'mod_ailanguageteacher_get_audio', args: {cmid: this.config.cmid,
                    ref: item.token, attemptid: this.attemptid}}])[0]).url;
                item.audio = url;
            } catch (err) {
                url = '';
            }
        }
        try {
            await Speech.play({url, text: item.text, locale: this.config.locale});
        } catch (err) {
            this.say(S.audiounavailable);
        }
    }

    /**
     * Test: the learner chose a place for the current listening item (or skipped it).
     *
     * @param {object} slide
     * @param {string} pintoken
     */
    async chooseListen(slide, pintoken) {
        const item = slide.data.listen[slide.listenIndex];
        if (!item || slide.submitting) {
            return;
        }
        Speech.stop();
        slide.choices[item.token] = pintoken;
        if (pintoken) {
            const slot = slide.slots.get(pintoken);
            Sound.play('drop');
            slot.pinEl.classList.add('is-chosen');
            window.setTimeout(() => slot.pinEl.classList.remove('is-chosen'), 500);
        }
        slide.listenIndex++;
        if (slide.listenIndex < slide.data.listen.length) {
            await this.showListenItem(slide);
            return;
        }
        slide.submitting = true;
        try {
            await Ajax.call([{methodname: 'mod_ailanguageteacher_submit_scene', args: {attemptid: this.attemptid,
                sceneid: slide.data.id, stage: 'listen', answers: Object.entries(slide.choices)
                    .map(([itemtoken, answer]) => ({item: itemtoken, answer}))}}])[0];
        } catch (err) {
            slide.submitting = false;
            Notification.exception(err);
            return;
        }
        slide.submitting = false;
        slide.board.classList.remove('is-listening');
        this.nextStage(slide);
    }

    /**
     * Test: the speaking stage (say the phrase for the highlighted place from a cue in your own language).
     *
     * @param {object} slide
     */
    async startSpeak(slide) {
        slide.stage = 'speak';
        slide.speakIndex = 0;
        slide.board.classList.add('is-speaking');
        await this.showSpeakItem(slide);
    }

    /**
     * Test: shows the current speaking item.
     *
     * @param {object} slide
     */
    async showSpeakItem(slide) {
        const items = slide.data.speak;
        const item = items[slide.speakIndex];
        slide.slots.forEach((s) => s.pinEl.classList.toggle('is-focus', s.token === item.pin));
        const node = await this.showStage(slide, {speak: true, prompt: item.prompt, supportlang: this.config.supportlang,
            step: fmt(S.stage_speak_step, {current: slide.speakIndex + 1, total: items.length})});
        const mic = node.querySelector('[data-action="stagespeak"]');
        const status = node.querySelector('[data-region="status"]');
        const label = node.querySelector('[data-region="miclabel"]');
        item.tries = item.tries || 0;
        mic.addEventListener('click', async() => {
            if (slide.speaking) {
                if (slide.signal && slide.signal.stop) {
                    slide.signal.stop();
                }
                return;
            }
            slide.speaking = true;
            slide.signal = {};
            mic.classList.add('is-listening');
            label.textContent = S.tapwhendone;
            try {
                let args;
                if (this.config.speakmode === 'service') {
                    const rec = await Speech.recordWav({signal: slide.signal, maxms: 8000,
                        onlevel: (v) => mic.style.setProperty('--level', v.toFixed(2))});
                    if (!rec.heard) {
                        throw new Error('nothingheard');
                    }
                    args = {audio: await Speech.toBase64(rec.blob)};
                } else {
                    const transcripts = await Speech.recognise(this.config.locale, slide.signal);
                    if (!transcripts.length) {
                        throw new Error('nothingheard');
                    }
                    args = {transcripts};
                }
                mic.classList.remove('is-listening');
                mic.classList.add('is-checking');
                label.textContent = S.checking;
                const res = await Ajax.call([{methodname: 'mod_ailanguageteacher_assess_speech', args: {
                    cmid: this.config.cmid, kind: 'test', attemptid: this.attemptid, ref: item.token, ...args}}])[0];
                if (!res.counted) {
                    throw new Error(res.status === 'unintelligible' ? 'unintelligible' : 'nothingheard');
                }
                slide.speakDone.add(item.token);
                status.textContent = res.triesleft > 0 ? `${S.recorded} · ${fmt(S.triesleft, res.triesleft)}` : S.recorded;
                label.textContent = res.triesleft > 0 ? S.tryagainshort : S.recorded;
                mic.disabled = res.triesleft <= 0;
                this.renderActions();
            } catch (err) {
                if (err && err.message === 'nothingheard') {
                    status.textContent = S.nothingheard;
                } else if (err && err.message === 'unintelligible') {
                    status.textContent = S.unintelligible;
                } else if (err && err.errorcode === 'speechratelimit') {
                    status.textContent = S.speechratelimit;
                } else if (err && err.errorcode === 'speechinprogress') {
                    status.textContent = S.speechinprogress;
                } else if (err && err.errorcode) {
                    Notification.exception(err);
                } else {
                    status.textContent = S.speechfailed_short;
                }
                label.textContent = S.tapandsay;
            } finally {
                mic.classList.remove('is-listening', 'is-checking');
                mic.style.removeProperty('--level');
                slide.speaking = false;
            }
        });
        this.renderActions();
    }

    /**
     * Test: next speaking item, or the end of the scene.
     *
     * @param {object} slide
     */
    async nextSpeak(slide) {
        if (slide.speaking) {
            return;
        }
        slide.speakIndex++;
        if (slide.speakIndex < slide.data.speak.length) {
            await this.showSpeakItem(slide);
        } else {
            slide.slots.forEach((s) => s.pinEl.classList.remove('is-focus'));
            this.sceneDone(slide);
        }
    }

    /**
     * Test: a scene is finished; moves on or finishes.
     *
     * @param {object} slide
     */
    sceneDone(slide) {
        slide.stage = 'done';
        slide.complete = true;
        slide.board.classList.remove('is-speaking', 'is-listening');
        slide.stageEl.hidden = true;
        this.hintEl.textContent = '';
        Array.from(this.dotsEl.children)[slide.index].classList.add('is-done');
        this.updateScore();
        if (this.index < this.slides.length - 1) {
            this.goTo(this.index + 1);
        } else {
            this.finish();
        }
    }

    /**
     * Small in-player confirm dialog.
     *
     * @param {string} message
     * @param {string} yes
     * @returns {Promise<boolean>}
     */
    async confirm(message, yes) {
        const overlay = await render('dialog', {message, yes, no: S.goback});
        (this.shell || this.host).appendChild(overlay);
        return new Promise((resolve) => {
            const done = (v) => {
                overlay.remove();
                resolve(v);
            };
            overlay.querySelector('[data-action="no"]').addEventListener('click', () => done(false));
            const ok = overlay.querySelector('[data-action="yes"]');
            ok.addEventListener('click', () => done(true));
            overlay.addEventListener('click', (e) => e.target === overlay && done(false));
            ok.focus();
        });
    }

    /* ------------------------------------------------------------------ */
    /* Score, timer, fullscreen, keyboard                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Updates the progress chip in the top bar.
     */
    updateScore() {
        if (!this.scoreEl || this.mode === 'study' || !this.slides) {
            return;
        }
        let total = 0;
        let value = 0;
        this.slides.forEach((s) => {
            if (this.mode === 'practice') {
                s.answers.forEach((chipToken) => {
                    total++;
                    const d = s.details.get(chipToken);
                    value += d && d.state === 'mastered' ? 1 : 0;
                });
            } else {
                total++;
                value += s.complete ? 1 : 0;
            }
        });
        const label = this.mode === 'practice' ? S.mastered : S.score;
        this.scoreEl.replaceChildren(icon(this.mode === 'practice' ? 'star' : 'check'),
            el('span', 'lt-score-label', {text: label}), el('strong', '', {text: String(value)}),
            el('span', 'lt-score-total', {text: `/${total}`}));
        const strong = this.scoreEl.querySelector('strong');
        if (!REDUCED && this.lastScore !== undefined && this.lastScore !== value) {
            strong.animate([{transform: 'scale(1.5)'}, {transform: 'scale(1)'}], {duration: 360, easing: SPRING});
        }
        this.lastScore = value;
    }

    /**
     * Starts the timer (count-down with a time limit, else count-up).
     */
    startTimer() {
        window.clearInterval(this.timerHandle);
        if (!this.timerEl) {
            return;
        }
        const limit = this.data.timelimit || 0;
        const span = this.timerEl.querySelector('span');
        const tick = () => {
            const elapsed = (Date.now() - this.startedAt) / 1000;
            if (limit) {
                const left = limit - elapsed;
                span.textContent = clock(left);
                this.timerEl.classList.toggle('is-warning', left <= 30);
                this.timerEl.classList.toggle('is-danger', left <= 10);
                if (left <= 10 && left > 0 && !(this.slides[this.index] || {}).speaking) {
                    Sound.play('tick');
                }
                if (left <= 0) {
                    window.clearInterval(this.timerHandle);
                    this.say(S.finish);
                    this.finish();
                }
            } else {
                span.textContent = clock(elapsed);
            }
        };
        tick();
        this.timerHandle = window.setInterval(tick, 1000);
    }

    /**
     * Updates the mute button.
     */
    syncMute() {
        const muted = Sound.isMuted();
        setIcon(this.muteBtn, muted ? 'muted' : 'sound');
        this.muteBtn.setAttribute('aria-label', muted ? S.unmute : S.mute);
        this.muteBtn.setAttribute('title', muted ? S.unmute : S.mute);
        this.muteBtn.setAttribute('aria-pressed', muted ? 'true' : 'false');
    }

    /**
     * Toggles full screen (native where available, CSS fallback such as on iPhone Safari).
     */
    toggleFullscreen() {
        const fsEl = document.fullscreenElement || document.webkitFullscreenElement;
        if (fsEl || this.shell.classList.contains('is-pseudo-full')) {
            if (fsEl) {
                (document.exitFullscreen || document.webkitExitFullscreen).call(document);
            }
            this.shell.classList.remove('is-pseudo-full');
            document.documentElement.classList.remove('lt-lock-scroll');
            this.syncFullscreen();
            return;
        }
        const req = this.shell.requestFullscreen || this.shell.webkitRequestFullscreen;
        if (req) {
            Promise.resolve(req.call(this.shell)).catch(() => this.pseudoFull());
        } else {
            this.pseudoFull();
        }
    }

    /**
     * CSS full screen fallback.
     */
    pseudoFull() {
        this.shell.classList.add('is-pseudo-full');
        document.documentElement.classList.add('lt-lock-scroll');
        this.syncFullscreen();
    }

    /**
     * Updates the full screen button and redraws.
     */
    syncFullscreen() {
        if (!this.fullBtn || !this.shell) {
            return;
        }
        const on = !!(document.fullscreenElement || document.webkitFullscreenElement) ||
            this.shell.classList.contains('is-pseudo-full');
        this.shell.classList.toggle('is-full', on);
        setIcon(this.fullBtn, on ? 'unfull' : 'full');
        this.fullBtn.setAttribute('aria-label', on ? S.exitfullscreen : S.fullscreen);
        this.fullBtn.setAttribute('title', on ? S.exitfullscreen : S.fullscreen);
        window.setTimeout(() => this.onResize(), 120);
    }

    /**
     * Keyboard shortcuts.
     *
     * @param {KeyboardEvent} e
     */
    onKey(e) {
        if (!this.shell || this.host.hidden || !this.slides) {
            return;
        }
        if (e.key === 'Escape') {
            if (this.drag && this.drag.active) {
                this.drag.over = null;
                this.drag.overTray = false;
                this.pointerUp(new PointerEvent('pointerup'));
            } else if (this.lesson && (!this.lesson.practice || this.lesson.revisit)) {
                this.closeLesson();
            } else if (this.selected) {
                this.clearSelection();
            } else if (this.shell.classList.contains('is-pseudo-full')) {
                this.toggleFullscreen();
            }
        }
        const tag = (e.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || this.lesson) {
            return;
        }
        if (this.mode === 'study' && (e.key === 'ArrowRight' || e.key === 'ArrowLeft')) {
            this.goTo(this.index + (e.key === 'ArrowRight' ? 1 : -1));
        }
    }

    /**
     * The results as slides: the overall result first, then one slide per scene of a test. Back and Next, the dots
     * and the arrow keys move between them.
     *
     * @param {HTMLElement} node the rendered summary
     */
    resultSlides(node) {
        const slides = Array.from(node.querySelectorAll('.lt-slide-r'));
        const prev = node.querySelector('[data-action="slideprev"]');
        if (slides.length < 2 || !prev) {
            return;
        }
        const next = node.querySelector('[data-action="slidenext"]');
        const dots = Array.from(node.querySelectorAll('.lt-slidedot'));
        const counter = node.querySelector('[data-region="slidecount"]');
        let current = 0;
        const go = (i, focus) => {
            current = Math.max(0, Math.min(slides.length - 1, i));
            slides.forEach((sl, n) => {
                sl.hidden = n !== current;
            });
            dots.forEach((d, n) => {
                d.classList.toggle('is-current', n === current);
                d.setAttribute('aria-current', n === current ? 'true' : 'false');
            });
            prev.disabled = current === 0;
            next.disabled = current === slides.length - 1;
            counter.textContent = fmt(S.results_slide, {number: current + 1, total: slides.length});
            if (!REDUCED) {
                slides[current].animate([{opacity: 0, transform: 'translateX(16px)'}, {opacity: 1, transform: 'none'}],
                    {duration: 300, easing: EASE});
            }
            if (focus) {
                slides[current].focus({preventScroll: true});
            }
        };
        prev.addEventListener('click', () => go(current - 1, true));
        next.addEventListener('click', () => go(current + 1, true));
        dots.forEach((d, n) => d.addEventListener('click', () => go(n, true)));
        node.addEventListener('keydown', (e) => {
            if (e.target.closest('button') && !e.target.closest('.lt-slidenav')) {
                return;
            }
            if (e.key === 'ArrowRight') {
                go(current + 1, true);
            } else if (e.key === 'ArrowLeft') {
                go(current - 1, true);
            }
        });
        go(0, false);
    }

    /* ------------------------------------------------------------------ */
    /* Finish                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Finishes the attempt and shows the results.
     */
    async finish() {
        if (this.finishing) {
            return;
        }
        this.finishing = true;
        window.clearInterval(this.timerHandle);
        this.closeLesson(true);
        Speech.stop();
        try {
            const summary = await Ajax.call([{methodname: 'mod_ailanguageteacher_finish_attempt',
                args: {attemptid: this.attemptid}}])[0];
            await this.showSummary(summary);
        } catch (err) {
            this.finishing = false;
            Notification.exception(err);
        }
    }

    /**
     * Renders the results screen.
     *
     * @param {object} summary
     */
    async showSummary(summary) {
        const c = this.config;
        const pct = summary.percent;
        const test = summary.kind === 'test';
        const pass = test && c.passpercent > 0 ? c.passpercent : 0;
        const passed = pass ? pct >= pass : pct >= 70;
        let headline;
        if (pass) {
            headline = passed ? S.passed : S.notpassed;
        } else if (pct >= 90) {
            headline = S.excellent;
        } else if (pct >= 70) {
            headline = S.greatjob;
        } else {
            headline = pct >= 50 ? S.goodeffort : S.keeppractising;
        }
        const stats = [{label: S.time, value: clock(summary.duration), icon: {clock: true}}];
        let sub = fmt(S.correctof, {correct: summary.correct, total: summary.total});
        if (!test) {
            const p = summary.practice;
            sub = fmt(S.masteredsummary, {done: p.mastered, total: p.total});
            if (p.avgscore >= 0) {
                stats.push({label: S.avgscore, value: `${p.avgscore}%`, icon: {mic: true}});
            }
            stats.push({label: S.hintsused, value: String(p.hints), icon: {hint: true}});
            if (p.needspractice) {
                stats.push({label: S.needspracticecount, value: String(p.needspractice), icon: {repeat: true}});
            }
        } else {
            stats.push({label: S.attemptsleft, value: summary.attemptsleft < 0 ? '∞' : String(summary.attemptsleft),
                icon: {trophy: true}});
        }
        const ring = 2 * Math.PI * 52;
        const node = await render('summary', {
            mode: summary.kind, headline, sub, stats, ring,
            hasverdict: !!pass, pass: passed, verdict: passed ? fmt(S.passmark, pass) : fmt(S.youneed, pass),
            leaderboard: (summary.leaderboard || []).map((r) => ({...r, time: clock(r.duration)})),
            hasreview: test && summary.review.length > 0,
            slides: [{label: 1, current: true}].concat(summary.review.map((r, i) => ({label: i + 2, current: false}))),
            review: summary.review.map((s, i) => ({number: i + 1, title: s.title, image: s.image, correct: s.correct,
                total: s.total, rows: s.rows.map((r) => ({...r,
                speaktext: r.speak >= 0 ? `${r.speak}%` : '–'}))})),
            retry: !test || summary.attemptsleft !== 0,
        });
        this.stopAutoplay();
        if (this.resizeObserver) {
            this.resizeObserver.disconnect();
        }
        this.host.replaceChildren(node);
        this.shell = node;
        this.slides = null;
        node.querySelector('[data-action="again"]').addEventListener('click', () => {
            this.finishing = false;
            if (test) {
                c.attemptsleft = summary.attemptsleft;
            } else {
                c.practicecounts = {total: summary.practice.total, mastered: summary.practice.mastered,
                    done: summary.practice.mastered + summary.practice.needspractice};
            }
            this.showIntro(summary.kind);
        });
        node.querySelector('[data-action="home"]').addEventListener('click', () => this.exit(true));
        this.resultSlides(node);
        const fg = node.querySelector('.lt-ring-fg');
        const count = node.querySelector('[data-region="count"]');
        let ringcolor = 'var(--lt-danger)';
        if (passed) {
            ringcolor = 'var(--lt-success)';
        } else if (pct >= (pass || 70) * 0.7) {
            ringcolor = 'var(--lt-warning)';
        }
        fg.style.stroke = ringcolor;
        requestAnimationFrame(() => {
            fg.style.transition = REDUCED ? 'none' : `stroke-dashoffset 1400ms ${EASE}`;
            fg.style.strokeDashoffset = `${ring * (1 - pct / 100)}`;
        });
        if (REDUCED) {
            count.textContent = Math.round(pct);
        } else {
            const t0 = performance.now();
            const step = (now) => {
                const k = Math.min(1, (now - t0) / 1400);
                count.textContent = Math.round(pct * (1 - Math.pow(1 - k, 3)));
                if (k < 1) {
                    requestAnimationFrame(step);
                }
            };
            requestAnimationFrame(step);
        }
        node.firstElementChild.classList.add(passed ? 'is-pass' : 'is-fail');
        if (passed) {
            Sound.play('finish');
            window.setTimeout(() => confetti(node, 200), 450);
        } else {
            Sound.play('fail');
        }
        this.say(`${headline} ${sub}`);
        node.focus({preventScroll: true});
    }

    /**
     * Returns to the mode chooser.
     *
     * @param {boolean} reload refresh the page (attempt counts, completion)
     */
    exit(reload = false) {
        window.clearInterval(this.timerHandle);
        this.stopAutoplay();
        this.closeLesson(true);
        Speech.stop();
        this.flushStudy();
        if (document.fullscreenElement) {
            document.exitFullscreen();
        }
        document.documentElement.classList.remove('lt-lock-scroll');
        if (reload || this.attemptid || (this.mode === 'study' && this.studySent.size)) {
            window.location.reload();
            return;
        }
        this.host.hidden = true;
        this.host.replaceChildren();
        this.home.hidden = false;
        this.slides = null;
        this.shell = null;
    }
}

/**
 * Entry point.
 *
 * @param {string} selector
 */
export const init = async(selector) => {
    const root = document.querySelector(selector);
    if (!root || root.dataset.ltInit) {
        return;
    }
    root.dataset.ltInit = '1';
    try {
        S = await loadStrings(STRING_KEYS);
        const config = JSON.parse(root.dataset.config || '{}');
        new Player(root, config);
        root.dataset.ltReady = '1';
        // Voices load asynchronously in some browsers.
        if (Speech.canSynthesise()) {
            window.speechSynthesis.getVoices();
        }
    } catch (err) {
        Notification.exception(err);
    }
};
