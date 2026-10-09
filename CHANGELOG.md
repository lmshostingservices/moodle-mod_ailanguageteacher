# Changelog

All notable changes to mod_ailanguageteacher are recorded here.

## [v1.3.0] - 2026-10-10

Builds on 1.2.1. New tariffs approved by the owner on 8 Oct 2026: **5 credits per scene** (LMS Labs AI or the teacher's own AI assistant; was 3) and **5 credits per phrase voice clip** (was 1). Unchanged: 50 to unlock. LMS Labs charges these; Moodle shows them and asks first.

### Fixed

- **"Error reading from database" when creating scenes** on sites that had installed 1.1.0. Those sites never got the stored-requests table. The upgrade now creates it, moves any request 1.1.0 left unresolved (with its key, so asking again cannot charge twice), and removes the old table.

### Added

- **Voices that match who says each phrase.** A phrase that belongs to a woman or a man in the picture ("the male taxi driver") gets a female or male voice. The teacher sets the second voice in the Voices step and can choose per phrase in the scene editor. Voices already made keep their voice.
- **"Listen before speaking"** (Practice): the microphone opens once the learner has heard the phrase.
- **Test results as slides:** the result first, then one slide per scene with its picture and every phrase marked.
- **The situation as a short card**, one sentence per line, and character counters in the scene editor.

### Changed

- The prompt for the teacher's own AI assistant asks for "the woman at the door" or "the male taxi driver", so voices can match.

## [v1.2.1] - 2026-10-09

Builds on 1.2.0, which it replaces. It follows the LMS Labs handover of 9 Oct 2026 for the lesson import route. No tariff or database changes.

### Changed

- **An unexpected server error (5xx) is never taken as "not charged".** The request is kept, and the same button asks again with the same key. Only LMS Labs' documented provider failures count as a definite no.
- **"Already used with different content" (409) and "no longer available" (410) now say the earlier request may have been charged**, and tell the teacher to contact LMS Labs support before trying again.
- **A lost phrase voice says it may have been charged.** LMS Labs keeps no voices, so a completed voice that did not arrive cannot be recovered.
- **Every scene sent for charging has a title.** An empty title becomes "Scene N", because LMS Labs rejects empty titles.

## [v1.2.0] - 2026-10-08

Builds on the live 1.0.3, which it replaces. The number 1.1.0 is skipped because an earlier, withdrawn test build used it. Tariffs are unchanged: 50 credits to unlock, 3 per scene, 1 per phrase voice.

### Changed

- **Activation is now part of the plugin settings page.** The separate activation page is gone; in 1.0.3 it failed with an error when opened. The settings page shows the last known access, where the Site ID and API key come from, the balance, and "Check access" and "Unlock…". Opening the settings page never calls LMS Labs and never spends credits. The live price is checked and confirmed on the unlock step.
- **The whole plugin needs this site to be unlocked with LMS Labs**, either with 50 credits or free when LMS Labs has a record of a Moodle Marketplace purchase. Until then teachers cannot set up lessons and learners see "This activity is not available yet". An unlocked site stays usable when LMS Labs cannot be reached; only a definite "locked" answer locks it again.
- **One set-up path in nine steps**, moved through with Back and Next only:
  1. Language.
  2. Situations.
  3. Learners' language.
  4. Level.
  5. Create the scenes.
  6. Pictures.
  7. Voices.
  8. Check the scenes.
  9. Finish.

  A step bar shows where the teacher is ("Step 7 of 9"). Next stays closed until the step is done, and says why. "Set up the lesson" replaces "Build lesson" and "Scenes" in the menu, and opens at the first step that is not done yet.
- **Step 5 has two cards:**
  - "Let LMS Labs AI write it": 3 credits per delivered scene, confirmed first.
  - "Use an AI assistant": copy the prompt into ChatGPT or another assistant and paste the reply. These scenes now cost 3 credits each too, the same as LMS Labs scenes. Only the number of scenes and their titles go to LMS Labs (`POST /api/moodle/ai-language-teacher/lessons/import`), and the scenes are created only after LMS Labs confirms. This route has been requested from LMS Labs; until it is live the teacher is told it is not available yet.
  - A scene LMS Labs AI delivered is created free, once.
- **The free "Add scenes" upload is gone**, and so are the copy-paste picture prompts for other AI tools. Every scene is created in step 5. Teachers upload their own picture for each scene in step 6; LMS Labs AI pictures for Language Teacher have been requested.
- **Voices step.** The teacher chooses one LMS Labs Chirp 3 HD voice for the lesson; it is stored in the new `ttsvoice` field. "Create missing voices" then makes every missing phrase voice, one after another, at 1 credit each, confirmed first. The per-phrase "Create voice" in the editor uses the same voice, with no browser pop-up.
- **Slow listening uses the paid voice, slowed down**, instead of the browser's voice, at no extra cost.
- **The voice catalogue is kept in Moodle's cache for an hour** (a failure for five minutes), so learner pages no longer wait on LMS Labs.

### Fixed

- **Stuck requests.**
  - A refusal from LMS Labs (no credits, no entitlement, invalid input and so on) now ends the stored request, so the next click is a new request. In 1.0.3 the request stayed pending for ever.
  - "Still working" (202, honouring Retry-After) and "no answer" keep the same key, so the same button asks again and can never charge twice.
  - When an unfinished request had different content, the teacher can now choose to start again. In 1.0.3 the message said "Finish or reconcile it" with no way to do either.
  - A delivered draft or voice that cannot be used is ended rather than replayed, and the teacher is told to contact support.
- **Credentials never follow a redirect.** The LMS Labs transport and the balance check no longer follow redirects, so the Site ID and API key headers can only reach lms-labs.com. The unlock address is fixed to lms-labs.com; the `config.php` override is removed.
- **Phrase voice requests ask for MP3** (`Accept: audio/mpeg, application/json`), not only JSON.
- **Lesson draft requests are always written in English**, whatever the teacher's Moodle language, so the same choices always send the same request.
- **Draft checks.** A draft with malformed vocabulary or practice entries is rejected cleanly.
- **Code and docs.**
  - Moodle code-checker errors fixed (176 errors and 9 warnings in 1.0.3).
  - The scheduled task name is a language string.
  - The out-of-date phrase-voice cache test is rewritten for the current behaviour.
  - The changelog order and the upgrade docblock are corrected.
- **Privacy.** The privacy metadata now declares the stored files, the scene titles and Site ID sent to LMS Labs, and the learner's browser speech recognition.

## [v1.0.3] - 2026-09-28

### Added

- Administrator-only activation page with free access verification, live release/credit-price review and explicit POST confirmation before one-time site unlock.
- Durable pending marker and verification-first recovery for uncertain unlock responses; show unlimited/low balances, Marketplace restorations and server conflict messages without claiming a new debit for prior purchases.
- Activation uses the existing complete LMS Labs credential pair. Phrase request keys, 410 recovery, text drafting (3 credits) and speech (1 credit) are unchanged; no speech-to-text route is added.

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
