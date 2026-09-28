# AI Language Teacher (mod_ailanguageteacher)

AI Language Teacher is a Moodle activity that teaches everyday spoken phrases through pictures of real-life moments, such as greeting a visitor or buying a train ticket. Each phrase sits on the part of the picture where it would be said. Learners work through three stages:

1. **Study**: hear each phrase on the picture, see what it means in their own language, read when to use it, and try saying it.
2. **Practice**: drag each phrase to its place on the picture, then say it until it is mastered. Each spoken answer is checked for the words recognised (not pronunciation). Mastery means a set number of successful tries at or above the pass mark, optionally in a row; silence or speech that can't be made out doesn't count. A progressive hint button helps, and after a set number of tries learners may move on.
3. **Test**: a graded check with three stages: match (place each phrase), listen (hear a phrase and tap its place) and speak (say the phrase shown in the learner's language). The server marks the test, so the browser never receives the answer key.

Teachers build a lesson with a four-step wizard:

1. Choose the language taught and its regional variety.
2. Choose situations from a catalogue, or add their own.
3. Choose the learners' language, used for meanings, notes and cues.
4. Choose the CEFR level. For English, approximate IELTS bands are shown.

The builder offers an explicit 3-credit short lesson draft through the dedicated LMS Labs text route. Its objective, explanation, vocabulary and practice prompts are converted into editable scene and phrase fields. The teacher reviews the draft, edits its JSON if needed, and imports it as Moodle content. The manual prompt-and-paste path remains available.

Each picture shows one moment and holds at most two phrases, such as a greeting and its reply. A new moment, such as saying goodbye, gets its own picture, and learners move through a situation's pictures one after another. If an AI reply puts more phrases in one scene, the import splits them across extra scenes instead of dropping them. Picture descriptions are set in the country of the chosen variety: es-ES gives Spain and es-MX gives Mexico, so people, places and everyday details look local.

## Requirements

- Moodle 4.4 to 5.3. `$plugin->requires` is 2024042200 (Moodle 4.4) and `$plugin->supported` is `[404, 503]`.
- A PHP version supported by the Moodle release in use.
- A microphone and a current browser for learners who speak. Without one, learners can still study, match and listen.

Tested on disposable sites (see CHANGELOG for the exact builds): Moodle 4.4 and 4.5 on PHP 8.3; Moodle 5.0, 5.1, 5.2 and 5.3 (beta) on PHP 8.4; PostgreSQL 16 (5.3 on PostgreSQL 17), and PHPUnit on MariaDB 10.11 with Moodle 4.5. MySQL and SQL Server were not tested.

## Installation

1. Unzip the package so the plugin sits in `mod/ailanguageteacher` (Moodle 4.4 to 5.0) or `public/mod/ailanguageteacher` (Moodle 5.1 and later).
2. Visit *Site administration > Notifications* (or run `admin/cli/upgrade.php`) to install it.
3. There is no Google key to enter. Teachers with AI permission may explicitly create one-credit phrase MP3 audio from exact live LMS Labs voice capabilities. Learner listening uses stored audio or the browser's own voice and never makes a paid synthesis request. Paid Speech-to-Text is unavailable: browser recognition or a listen-back self-check compares recognised words, not phonemes, pronunciation or accent.

## Configuration

*Site administration > Plugins > Activity modules > AI Language Teacher*

| Setting | Purpose |
| --- | --- |
| Browser speech recognition | Paid Speech-to-Text is unavailable; use the browser's built-in transcript or a listen-back self-check. Some browsers send audio to their own service, outside Moodle's control. |
| Speaking checks per minute | Rate limit per learner (default 20). |
| Standalone LMS Labs site ID and API key | Leave empty when LMS Labs Central Config (`local_aiconfig`) is installed: its complete credential pair is used automatically. The standalone pair is used only when Central Config does not have both. The key stays on the server and is sent only to lms-labs.com. |
| AI requests per teacher per hour | Local request limit; the dedicated service also enforces its own draft admission limit. |
| Default language taught, default learners' language, sounds, leaderboard | Defaults for new activities. |

Lesson drafting and teacher-requested phrase audio require a registered, entitled LMS Labs site and enough credits. A generated draft costs 3 credits on successful delivery; a completed phrase MP3 costs 1 credit. The balance display is advisory. No paid requests are made by learners, no Speech-to-Text or image generation is offered, and ambiguous provider responses retain their existing idempotency key.

Per-activity settings include the spoken-answer pass mark, the number of successful attempts needed and whether they must be in a row, the tries before learners may move on, sequential unlocking of stages, the Test's listening and speaking stages, grade, grading method, maximum attempts, time limit, label shuffling, sounds, leaderboard, and three completion rules: finish Study, master every phrase, finish the Test.

## Capabilities

| Capability | Default roles | Allows |
| --- | --- | --- |
| `mod/ailanguageteacher:addinstance` | editing teacher, manager | Add the activity to a course |
| `mod/ailanguageteacher:view` | guest, student, teacher, editing teacher, manager | Open the activity |
| `mod/ailanguageteacher:attempt` | student | Study, practise and take the Test, with progress saved |
| `mod/ailanguageteacher:manage` | editing teacher, manager | Build the lesson and edit scenes, phrases, pictures and recordings |
| `mod/ailanguageteacher:useai` | editing teacher, manager | Request a short lesson draft or explicitly create phrase audio |
| `mod/ailanguageteacher:viewreports` | teacher, editing teacher, manager | See the learners, phrases and attempts reports |

## External services

All browser actions go through Moodle external functions called with `core/ajax`, which check the session key, context and capability:

`start_attempt`, `submit_scene`, `practice_event`, `assess_speech`, `get_audio`, `finish_attempt`, `record_study`, `reset_progress`, `save_scene`, `save_phrase_audio`, `delete_phrase_audio`, `generate_lesson`, `import_lesson`, `voice_choices`, `create_audio`, `generate_image` (each prefixed `mod_ailanguageteacher_`).

`generate_image` remains explicitly unavailable because there is no approved image route or tariff for this plugin.

## Privacy and data handling

The plugin stores, per learner and activity:

- attempts, with stage results and grades;
- responses to Test items;
- progress for each phrase (unseen, studied, matched, mastered or needs practice);
- spoken-answer checks (the words recognised and how closely they matched; recordings are not kept);
- the AI request log for teachers.

Teacher recordings of phrases, scene pictures and explicitly created phrase audio are stored as Moodle files. The Privacy API provider exports and deletes user data and reports the grades subsystem link.

Data sent outside Moodle:

- **LMS Labs speech service** (Google Cloud): a teacher's explicit one-credit audio request sends the phrase text, exact locale, selected live voice and speed server-to-server. No learner recording is sent. Moodle caches the completed MP3; the LMS Labs speech ledger retains no text or audio.
- **Browser speech recognition** (when enabled). Speech recognition runs in the learner's browser. Depending on the browser, audio may go to the browser vendor's own service. This is a browser feature, not a service the plugin calls or meters.
- **LMS Labs.** A teacher's explicit three-credit lesson request sends a short brief, exact target locale, level and topic. The validated draft and outbound brief remain temporarily in Moodle for 24 hours; saved scenes and phrases are ordinary Moodle course content. The site ID and API key remain PHP-only. Provider retention and deployment policies must be verified separately.

## Backup and restore

Activity settings, situations, scenes, phrases, pictures and teacher recordings are backed up and restored, as are learner attempts, responses, progress and spoken-answer checks when user data is included. Attempt token maps are remapped on restore. Duplicating an activity is supported.

## Grades and completion

The Test is graded out of the activity's maximum grade using the chosen method (highest, average, first or last attempt). The three custom completion rules are finish Study, master every phrase, and finish the Test.

## Source and issues

A public source repository and issue tracker have not been published yet.

## Licence

GNU GPL v3 or later. See `LICENSE`.

Copyright 2026 LMS Hosting Services.
