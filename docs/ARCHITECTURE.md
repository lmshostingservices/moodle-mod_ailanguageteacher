# Architecture

This document is for developers maintaining mod_ailanguageteacher. The README covers features and administration.

## Layers

| Layer | Files | Role |
| --- | --- | --- |
| Moodle callbacks | `lib.php` | Instance add/update/delete, gradebook, file serving, navigation, completion info, course reset. Callbacks only; logic lives in classes. |
| Content | `classes/local/manager.php` | Situations, scenes, phrases, pictures, recordings, pin ordering, "ready" scenes, instance defaults and grade calculation. |
| Learning | `classes/local/learning.php` | Learner progress state machine, Study, Practice and Test attempts, spoken-answer checks, grading, review, leaderboard, user-data deletion. |
| Lesson drafts | `classes/local/lesson.php` | Builder choices, the AI prompt, parsing and cleaning pasted AI replies, import, AI rate limiting and logging. |
| Languages | `classes/local/languages.php` | Supported languages, locales, script flags, voices, CEFR levels and the situation catalogue. |
| Speech | `classes/local/audio.php`, `classes/local/speech/service.php`, `lmslabs.php`, `factory.php`, `wav.php`, `matcher.php` | Speaking mode (service, browser or self), cached phrase audio, the provider-neutral service interface, the LMS Labs service (disabled until its contract exists), recording checks and the words-recognised matcher. |
| AI provider | `classes/local/ai/provider.php`, `lmslabs.php`, `factory.php` | Provider interface. The LMS Labs adapter only reads the balance; generation reports "not available". |
| Web services | `classes/external/*.php`, `db/services.php` | One class per external function. `base.php` loads the course module, attempt, scene or phrase and checks context and capability. |
| Pages | `view.php`, `builder.php`, `scenes.php`, `editor.php`, `report.php`, `index.php` | `require_login`, capability checks and template data, rendered with `$OUTPUT->render_from_template()`. |
| Browser | `amd/src/*.js`, `templates/*.mustache` | `player.js` (learner journey), `editor.js`, `builder.js`, `scenes.js`, `report.js`, `speech.js` (recording, VAD, WAV), `sound.js` (Web Audio effects), `ui.js` (DOM and template helpers). |

## Progress and attempts

Each learner has one progress row per phrase, moving through `unseen → studied → matched → mastered`. A phrase becomes `needspractice` when the learner uses the move-on option without mastering it.

Attempts have a `kind` (study, practice or test) and a `state` (inprogress or finished). When a Test attempt starts, each phrase is given random per-purpose tokens that are stored in `tokenmap`. The browser only ever sends tokens back. `learning::resolve_phrase()` maps them to phrases on the server, so the answer key never reaches the browser. Practice attempts send phrase details, because Practice is not graded.

## Speech

The flow is `learner browser → Moodle PHP → LMS Labs speech API → Google Cloud`. Google credentials stay with LMS Labs; Moodle uses its LMS Labs Site ID and API key server-side only, resolved by `local\credentials` (LMS Labs Central Config `local_aiconfig` first, then the plugin's standalone pair; a pair is used only when complete and the two are never mixed). The LMS Labs service (`speech\lmslabs`) is disabled until LMS Labs supplies an implemented, tested contract and paid calls are approved: it reports itself unavailable, supports no locale and makes no network calls. Unit tests replace it through `speech\factory::$override`. The full design is in the project document "speech provider design (Google only)".

`audio::speaking_mode($locale)` chooses the mode:

- `service` when the service is available and supports transcription for the locale (text to speech and transcription are checked separately).
- `browser` when browser recognition is enabled. This is the browser's own feature, not a Moodle or LMS Labs service.
- `self` otherwise: the learner listens back and compares.

In `service` mode the browser records 16 kHz, 16-bit mono WAV (at most 8 s). `wav::validate()` checks the format, length and size in PHP before anything is sent, one transcription at a time runs per learner and phrase (a Moodle lock), and each call carries a new idempotency key. In `browser` mode the browser sends only its transcripts.

Either way the result is a spoken-answer check, not pronunciation assessment: `matcher::best()` compares every transcript with the phrase and its accepted alternatives, and `matcher::recognised()` lists which words (or characters, for zh, ja, th and yue) were recognised in order. The match percentage decides pass or fail. Recogniser confidence is never used as a score. Silence (`nospeech`) or unclear speech (`unintelligible`) gets no mark and does not count: no stored result, no change to progress or mastery, and no Test try used.

Audio for a phrase comes from, in order: the teacher's recording, stored phrase audio in `ttsaudio` (made once by the service; the file name is a hash of the service's synthesis identity, locale, voice, speed and text, and a lock per file stops parallel requests creating it twice), then the browser's `speechSynthesis`.

## Safety rules kept in code

- API keys are read only in PHP and sent only in request headers to their own service. They never appear in rendered HTML, JS, URLs or logs.
- Paid calls are never retried automatically. LMS Labs generation stays off until the plugin's routes, schemas, entitlement and tariff are approved.
- No `PARAM_RAW`. The pasted AI draft travels as PARAM_TEXT JSON, with `<` escaped as `\u003c` by the browser, and every field is cleaned on import.
- Text from the database is output through Mustache escaping or `textContent`.

## Tests

- `tests/*_test.php`: PHPUnit covering the matcher, recording checks, the speech service layer with a fake service (spoken-answer checks, silence, phrase-audio caching), learning, lessons, content, services, privacy, backup and restore, completion and the LMS Labs adapter.
- `tests/behat/*.feature`: non-JavaScript Behat covering the builder redirect, learner and teacher views, and capability-based navigation.
- `tests/generator/`: data generator and Behat generator (`mod_ailanguageteacher > scenes`).
