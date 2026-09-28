# Changelog

## [v1.0.3] - 2026-09-28

### Added

- Administrator-only activation page with free access verification, live release/credit-price review and explicit POST confirmation before one-time site unlock.
- Durable pending marker and verification-first recovery for uncertain unlock responses; show unlimited/low balances, Marketplace restorations and server conflict messages without claiming a new debit for prior purchases.
- Activation uses the existing complete LMS Labs credential pair. Phrase request keys, 410 recovery, text drafting (3 credits) and speech (1 credit) are unchanged; no speech-to-text route is added.

All notable changes to mod_ailanguageteacher are recorded here.

## [v1.0.2] - 2026-09-28

- Teacher-initiated short lesson drafting uses the dedicated 3-credit text route; the temporary returned draft is editable and can be imported as ordinary Moodle scenes and phrases. Draft claims and request bodies are persisted before the call and expire after 24 hours.
- Teacher-initiated, one-credit phrase audio uses exact live Google voice capabilities and stores completed MP3 in Moodle. Learner listening never initiates a paid request. Speech-to-Text and image generation remain unavailable; browser transcript matching is not phoneme or accent scoring.
- Added persistent outbound operation claims and a Moodle upgrade savepoint. No production service publication is implied.

## [v1.0.1] - 2026-09-28

Release-pipeline test build.

### Changed

- Version 2026092800, release 1.0.1. No functional changes from 1.0.0 (version 2026092600).

## [v1.0.0] - 2026-09-27

First release.

### Added

- Activity module for teaching spoken phrases on pictures of real-life moments. The learning journey runs Study, Practice, Test.
- Study: phrase cards with meaning, "when to use it" notes, examples, listen and slow listen, and an optional speaking try.
- Practice: drag or tap each phrase to its place on the picture, then say it until mastered. There is a configurable pass score, a number of successes (optionally in a row) and a move-on option after a set number of tries. A progressive hint button, mastery stars and a summary are included.
- Test: match, listen and speak stages, marked on the server with per-attempt tokens so the browser never receives the answer key. Grading can use the highest, average, first or last attempt, with optional attempt and time limits.
- Lesson builder wizard. It covers the language taught with its regional variety (21 languages), situations from a catalogue or custom ones, the learners' language, and CEFR level with approximate IELTS bands for English. It writes a prompt for any AI assistant, then previews and imports the reply. Each picture holds one moment with at most two phrases (up to six pictures per situation), and extra phrases in an AI reply are split onto further pictures. Picture descriptions name the country of the variety taught, such as Spain for es-ES or Mexico for es-MX.
- Scene list and drag-and-drop phrase editor with quick add, colours, distractors, teacher recordings, picture upload (images or a ZIP) and learner preview.
- Speech: a provider-neutral speech service layer for phrase audio and spoken-answer checking, with the LMS Labs service (Google Cloud Text-to-Speech and Speech-to-Text) disabled until its contract is implemented and paid calls are approved. Meanwhile the browser's own voice and speech recognition, or a listen-back self-check, are used. Spoken answers are checked for the words recognised (not pronunciation); silence and unclear speech get no mark and don't count. Recordings are validated in PHP, and there is per-learner rate limiting.
- LMS Labs credentials come from LMS Labs Central Config (`local_aiconfig`: `siteid` and `apikey`) when it has both. The plugin's standalone Site ID and API key are a fallback, used only as a complete pair and never mixed with central values. Values are read when needed, never copied, so Central Config changes apply at once. The settings page shows which pair is in use.
- LMS Labs adapter limited to a read-only, advisory credit balance check. In-Moodle AI drafting is reported as not available.
- Reports on learners, phrases and attempts, with group filtering, paging, CSV and other downloads, and attempt deletion.
- Custom completion rules: finish Study, master every phrase, finish the Test.
- Gradebook integration, events, course reset, backup and restore (including user data and files), and duplication.
- Privacy API provider covering all plugin tables, files and the grades subsystem.
- Mustache templates for all pages and player views, and ES6 AMD modules with built files.
- PHPUnit tests (49 tests) and Behat features (8 scenarios, no JavaScript needed).
- Accessibility: keyboard-reachable report tables, labelled mastery dots and contrast fixes found by axe.

### Verified before packaging

- PHPUnit: 49 tests, 470 assertions, passing on Moodle 4.4.12+ and 4.5 (PHP 8.3), and on 5.0.10, 5.1.7+, 5.2.3+ and 5.3beta (PHP 8.4). This includes the speech service layer, tested with a fake service: spoken-answer checks, silence and unclear speech not counting, recording limits, and phrase-audio caching, and the LMS Labs credential resolution (Central Config first, standalone fallback, never mixed). Core privacy provider tests pass on 4.5 and 5.3.
- Behat: 8 scenarios pass on Moodle 5.3beta, and passed on 4.5 (Boost and Classic) before the two-phrases-per-picture change.
- Fresh install after uninstall on all six versions. `check_database_schema` is clean.
- moodle-cs `moodle-extra` standard: no errors or warnings. Grunt ESLint and stylelint pass.
- Browser runs (Chromium) of the full Study, Practice and Test flow on desktop and phone sizes on Moodle 4.5, and of the teacher pages. axe WCAG 2.1 AA scans of the learner pages, the Practice phrase card with a spoken-answer result, and the teacher pages report no violations.
