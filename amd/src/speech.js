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
 * Listening and speaking in the browser.
 *
 * - play(): plays a phrase from a URL, or with the browser's own voice when there is no audio file.
 * - recordWav(): records the learner as 16 kHz mono WAV and stops by itself after they finish speaking
 *   (sent to Moodle, which asks the site speech service which words were said; the recording is not kept).
 * - recognise(): the browser's own speech recognition, a browser feature used when the site speech service is not
 *   available. It is not a Moodle or LMS Labs service.
 * - recordClip(): a normal recording (teacher recordings, and "listen back and compare").
 *
 * @module     mod_ailanguageteacher/speech
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition || null;
const AC = window.AudioContext || window.webkitAudioContext || null;

let current = null;

/**
 * Whether the browser can record from a microphone.
 *
 * @returns {boolean}
 */
export const canRecord = () => !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && AC);

/**
 * Whether the browser has built-in speech recognition.
 *
 * @returns {boolean}
 */
export const canRecognise = () => !!Recognition;

/**
 * Whether the browser can speak text itself.
 *
 * @returns {boolean}
 */
export const canSynthesise = () => 'speechSynthesis' in window;

/**
 * Finds the best built-in voice for a locale.
 *
 * @param {string} locale
 * @returns {SpeechSynthesisVoice|null}
 */
const findVoice = (locale) => {
    if (!canSynthesise()) {
        return null;
    }
    const voices = window.speechSynthesis.getVoices();
    const want = locale.toLowerCase();
    const base = want.split('-')[0];
    return voices.find((v) => v.lang.toLowerCase() === want)
        || voices.find((v) => v.lang.toLowerCase().replace('_', '-') === want)
        || voices.find((v) => v.lang.toLowerCase().split(/[-_]/)[0] === base)
        || null;
};

/**
 * Stops whatever is playing.
 */
export const stop = () => {
    if (current) {
        current.pause();
        current = null;
    }
    if (canSynthesise()) {
        window.speechSynthesis.cancel();
    }
};

/**
 * Plays audio from a URL, or speaks the text with the browser's voice.
 *
 * @param {object} what url, text, locale, slow
 * @returns {Promise} resolves when playback ends
 */
export const play = (what) => {
    stop();
    if (what.url) {
        return new Promise((resolve, reject) => {
            const audio = new Audio(what.url);
            current = audio;
            audio.playbackRate = what.slow && !what.slowfile ? 0.75 : 1;
            audio.addEventListener('ended', () => resolve());
            audio.addEventListener('error', () => reject(new Error('audio')));
            audio.play().catch(reject);
        });
    }
    if (!what.text || !canSynthesise()) {
        return Promise.reject(new Error('novoice'));
    }
    return new Promise((resolve, reject) => {
        const u = new SpeechSynthesisUtterance(what.text);
        u.lang = what.locale;
        const voice = findVoice(what.locale);
        if (voice) {
            u.voice = voice;
        }
        u.rate = what.slow ? 0.6 : 0.9;
        u.onend = () => resolve();
        u.onerror = () => reject(new Error('novoice'));
        window.speechSynthesis.speak(u);
    });
};

/**
 * Writes a WAV header and 16-bit samples.
 *
 * @param {Float32Array} samples mono samples at 16 kHz
 * @returns {Blob}
 */
const encodeWav = (samples) => {
    const rate = 16000;
    const buffer = new ArrayBuffer(44 + samples.length * 2);
    const view = new DataView(buffer);
    const text = (offset, s) => {
        for (let i = 0; i < s.length; i++) {
            view.setUint8(offset + i, s.charCodeAt(i));
        }
    };
    text(0, 'RIFF');
    view.setUint32(4, 36 + samples.length * 2, true);
    text(8, 'WAVE');
    text(12, 'fmt ');
    view.setUint32(16, 16, true);
    view.setUint16(20, 1, true);
    view.setUint16(22, 1, true);
    view.setUint32(24, rate, true);
    view.setUint32(28, rate * 2, true);
    view.setUint16(32, 2, true);
    view.setUint16(34, 16, true);
    text(36, 'data');
    view.setUint32(40, samples.length * 2, true);
    let offset = 44;
    for (let i = 0; i < samples.length; i++, offset += 2) {
        const s = Math.max(-1, Math.min(1, samples[i]));
        view.setInt16(offset, s < 0 ? s * 0x8000 : s * 0x7FFF, true);
    }
    return new Blob([view], {type: 'audio/wav'});
};

/**
 * Averages samples down to 16 kHz.
 *
 * @param {Float32Array} input
 * @param {number} rate input sample rate
 * @returns {Float32Array}
 */
const downsample = (input, rate) => {
    if (rate === 16000) {
        return input;
    }
    const ratio = rate / 16000;
    const out = new Float32Array(Math.floor(input.length / ratio));
    for (let i = 0; i < out.length; i++) {
        const start = Math.floor(i * ratio);
        const end = Math.min(input.length, Math.floor((i + 1) * ratio));
        let sum = 0;
        for (let j = start; j < end; j++) {
            sum += input[j];
        }
        out[i] = sum / Math.max(1, end - start);
    }
    return out;
};

/**
 * Converts a Blob to base64 (without the data: prefix).
 *
 * @param {Blob} blob
 * @returns {Promise<string>}
 */
export const toBase64 = (blob) => new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result).split(',')[1] || '');
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(blob);
});

/**
 * Records the learner until they stop speaking.
 *
 * @param {object} opts onlevel(0..1), maxms (default 10000), signal {stop: Function} to end early
 * @returns {Promise<{blob: Blob, heard: boolean}>}
 */
export const recordWav = async(opts = {}) => {
    const stream = await navigator.mediaDevices.getUserMedia({audio: {channelCount: 1, echoCancellation: true,
        noiseSuppression: true, autoGainControl: true}});
    const ctx = new AC();
    if (ctx.state === 'suspended') {
        // A context made after the permission prompt can start suspended, and then no sound is ever processed.
        await ctx.resume().catch(() => null);
    }
    const source = ctx.createMediaStreamSource(stream);
    const processor = ctx.createScriptProcessor(4096, 1, 1);
    const chunks = [];
    const started = performance.now();
    const maxms = opts.maxms || 10000;
    let heard = false;
    let lastvoice = 0;
    let done = false;
    return new Promise((resolve) => {
        const finish = () => {
            if (done) {
                return;
            }
            done = true;
            processor.disconnect();
            source.disconnect();
            stream.getTracks().forEach((t) => t.stop());
            const length = chunks.reduce((n, c) => n + c.length, 0);
            const all = new Float32Array(length);
            let offset = 0;
            chunks.forEach((c) => {
                all.set(c, offset);
                offset += c.length;
            });
            const rate = ctx.sampleRate;
            ctx.close().catch(() => null);
            resolve({blob: encodeWav(downsample(all, rate)), heard});
        };
        if (opts.signal) {
            opts.signal.stop = finish;
        }
        processor.onaudioprocess = (e) => {
            const data = e.inputBuffer.getChannelData(0);
            chunks.push(new Float32Array(data));
            let sum = 0;
            for (let i = 0; i < data.length; i++) {
                sum += data[i] * data[i];
            }
            const rms = Math.sqrt(sum / data.length);
            if (opts.onlevel) {
                opts.onlevel(Math.min(1, rms * 8));
            }
            const now = performance.now();
            if (rms > 0.02) {
                heard = true;
                lastvoice = now;
            }
            // Stop 1.2 s after the learner finishes, after 5 s of silence at the start, or at the time limit.
            if ((heard && now - lastvoice > 1200) || (!heard && now - started > 5000) || now - started > maxms) {
                finish();
            }
        };
        source.connect(processor);
        processor.connect(ctx.destination);
    });
};

/**
 * Shows the microphone level while the browser recognises speech, and notes whether any sound arrived.
 *
 * @param {Function|null} onlevel
 * @returns {Promise<{stop: Function, loud: Function}>}
 */
const levelMeter = async(onlevel) => {
    let loud = false;
    let stream = null;
    let ctx = null;
    let timer = null;
    try {
        stream = await navigator.mediaDevices.getUserMedia({audio: true});
        ctx = new AC();
        if (ctx.state === 'suspended') {
            await ctx.resume().catch(() => null);
        }
        const analyser = ctx.createAnalyser();
        analyser.fftSize = 1024;
        ctx.createMediaStreamSource(stream).connect(analyser);
        const data = new Float32Array(analyser.fftSize);
        timer = window.setInterval(() => {
            analyser.getFloatTimeDomainData(data);
            let sum = 0;
            for (let i = 0; i < data.length; i++) {
                sum += data[i] * data[i];
            }
            const rms = Math.sqrt(sum / data.length);
            if (rms > 0.01) {
                loud = true;
            }
            if (onlevel) {
                onlevel(Math.min(1, rms * 8));
            }
        }, 60);
    } catch (e) {
        // The meter is only a help: recognition goes ahead without it.
        loud = null;
    }
    return {
        loud: () => loud,
        stop: () => {
            if (timer) {
                window.clearInterval(timer);
            }
            if (stream) {
                stream.getTracks().forEach((t) => t.stop());
            }
            if (ctx) {
                ctx.close().catch(() => null);
            }
        },
    };
};

/**
 * Recognises what the learner says with the browser's own speech service.
 *
 * Resolves the transcripts (best first). When nothing was recognised it rejects with a reason the player can explain:
 * micsilent (the microphone sent no sound), unintelligible (sound, but no words), not-allowed (blocked),
 * audio-capture (no microphone), or recogniserunavailable (the browser's speech service did not answer).
 *
 * @param {string} locale
 * @param {object} signal receives stop()
 * @param {Function|null} onlevel microphone level (0..1)
 * @returns {Promise<string[]>} transcripts, best first
 */
export const recognise = async(locale, signal = {}, onlevel = null) => {
    const meter = await levelMeter(onlevel);
    return new Promise((resolve, reject) => {
        const rec = new Recognition();
        rec.lang = locale;
        // Interim results are kept so words already heard are not lost when the learner taps to finish early.
        rec.interimResults = true;
        rec.maxAlternatives = 5;
        rec.continuous = false;
        const finals = [];
        let interim = [];
        let sound = false;
        let failure = null;
        let stopped = false;
        const timer = window.setTimeout(() => rec.stop(), 12000);
        rec.onsoundstart = () => {
            sound = true;
        };
        rec.onspeechstart = () => {
            sound = true;
        };
        rec.onresult = (e) => {
            interim = [];
            for (let i = e.resultIndex || 0; i < e.results.length; i++) {
                const alts = [];
                for (let j = 0; j < e.results[i].length; j++) {
                    alts.push(e.results[i][j].transcript);
                }
                if (e.results[i].isFinal) {
                    finals.push(...alts);
                } else {
                    interim = alts;
                }
            }
        };
        rec.onerror = (e) => {
            failure = e.error || 'recognition';
        };
        rec.onend = () => {
            window.clearTimeout(timer);
            const loud = meter.loud();
            meter.stop();
            const out = (finals.length ? finals : interim).map((t) => t.trim()).filter((t) => t !== '');
            if (out.length) {
                resolve(out);
            } else if (failure === 'not-allowed') {
                reject(new Error('not-allowed'));
            } else if (failure === 'audio-capture') {
                reject(new Error('audio-capture'));
            } else if (['network', 'service-not-allowed', 'language-not-supported', 'bad-grammar'].includes(failure)) {
                reject(new Error('recogniserunavailable'));
            } else if (stopped && !sound && !loud) {
                // Tapped to finish before saying anything.
                resolve([]);
            } else if (!sound && loud === false) {
                reject(new Error('micsilent'));
            } else if (sound || loud) {
                reject(new Error('unintelligible'));
            } else {
                resolve([]);
            }
        };
        signal.stop = () => {
            stopped = true;
            rec.stop();
        };
        try {
            rec.start();
        } catch (e) {
            window.clearTimeout(timer);
            meter.stop();
            reject(new Error('recogniserunavailable'));
        }
    });
};

/**
 * Records an ordinary audio clip (for listening back, or a teacher's recording).
 *
 * @param {object} signal receives stop()
 * @param {number} maxms
 * @returns {Promise<Blob>}
 */
export const recordClip = async(signal = {}, maxms = 15000) => {
    const stream = await navigator.mediaDevices.getUserMedia({audio: true});
    const types = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/mp4', 'audio/webm'];
    const type = window.MediaRecorder && types.find((t) => window.MediaRecorder.isTypeSupported(t));
    const recorder = new window.MediaRecorder(stream, type ? {mimeType: type} : {});
    const parts = [];
    return new Promise((resolve) => {
        recorder.ondataavailable = (e) => parts.push(e.data);
        recorder.onstop = () => {
            stream.getTracks().forEach((t) => t.stop());
            resolve(new Blob(parts, {type: recorder.mimeType || 'audio/webm'}));
        };
        const timer = window.setTimeout(() => recorder.state !== 'inactive' && recorder.stop(), maxms);
        signal.stop = () => {
            window.clearTimeout(timer);
            if (recorder.state !== 'inactive') {
                recorder.stop();
            }
        };
        recorder.start();
    });
};
