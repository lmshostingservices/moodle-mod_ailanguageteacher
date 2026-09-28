<?php
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
 * AI Language Teacher activation (administrators only): access check and one-time unlock with LMS Labs credits.
 *
 * GET shows the stored access state and the live release price (no credits are spent on page load).
 * Every action is a POST with sesskey: check (free), review (free: shows the confirmation), unlock (spends credits,
 * only with confirm=1 and the exact price and release the administrator saw).
 *
 * @package    mod_ailanguageteacher
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use mod_ailanguageteacher\local\unlock;
use mod_ailanguageteacher\local\credentials;

require_login();
admin_externalpage_setup('mod_ailanguageteacher_activation');
require_capability('moodle/site:config', context_system::instance());

$action = optional_param('action', '', PARAM_ALPHA);
$pageurl = new moodle_url('/mod/ailanguageteacher/activation.php');
$PAGE->set_url($pageurl);
$str = fn($k, $a = null) => get_string($k, 'mod_ailanguageteacher', $a);

if ($action !== '') {
    // Every action is a POST with a valid sesskey; nothing happens on a GET.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new moodle_exception('invalidrequest');
    }
    require_sesskey();
}

/**
 * A balance for display.
 *
 * @param array $state
 * @return string
 */
$balancetext = function (array $state) use ($str): string {
    if (!empty($state['unlimited'])) {
        return $str('act_balance_unlimited');
    }
    return isset($state['credits']) && $state['credits'] !== null ? $str('act_credits', $state['credits'])
        : $str('act_balance_unknown');
};

/**
 * A readable error (never contains request data).
 *
 * @param string $error
 * @return string
 */
$errortext = function (string $error) use ($str): string {
    if ($error === 'nocredentials' || $error === 'network') {
        return $str('act_err_' . $error);
    }
    return $error;
};

/**
 * Why unlocking is not offered.
 *
 * @param string $blocked
 * @param array $state
 * @param array $release
 * @return string
 */
$blockedtext = function (string $blocked, array $state, array $release) use ($str): string {
    return $str('act_blocked_' . $blocked);
};

/**
 * Why the release/price is not confirmed.
 *
 * @param array $release
 * @return string
 */
$reasontext = function (array $release) use ($str): string {
    $a = $release['reason'] === 'mode' ? $release['mode'] : $release['availability'];
    return $str('act_reason_' . $release['reason'], s($a !== '' ? $a : '-'));
};

if ($action === 'check') {
    $state = unlock::verify();
    $msg = $state['status'] === 'unknown' ? $str('act_msg_check_unknown', s($errortext($state['error'] ?? '')))
        : $str('act_msg_check_' . $state['status']);
    if (!empty($state['resolved'])) {
        $msg .= ' ' . $str('act_msg_resolved_' . $state['resolved']);
    }
    redirect($pageurl, $msg, null, $state['status'] === 'unknown' ? \core\output\notification::NOTIFY_WARNING
        : \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'unlock') {
    // Confirmed on the review screen; the live price and release are checked again before anything is bought.
    $confirm = optional_param('confirm', 0, PARAM_BOOL);
    $expected = required_param('expected', PARAM_INT);
    $sha = required_param('sha', PARAM_ALPHANUM);
    if (!$confirm) {
        redirect($pageurl);
    }
    $r = unlock::buy($expected, $sha);
    $type = \core\output\notification::NOTIFY_ERROR;
    switch ($r['outcome']) {
        case 'unlocked':
            // A new unlock: creditsConsumed is what LMS Labs recorded for it.
            $type = \core\output\notification::NOTIFY_SUCCESS;
            $msg = $str('act_msg_unlocked') . ' ' . ($r['consumed'] !== null ? $str('act_msg_consumed', $r['consumed'])
                : $str('act_msg_notreported'));
            break;
        case 'restored':
            // A recognised purchase (Marketplace or earlier purchase) activated at zero credits.
            $type = \core\output\notification::NOTIFY_SUCCESS;
            $msg = $str('act_msg_unlocked') . ' ' . $str('act_msg_restoredpurchase', s($r['source']));
            break;
        case 'already':
            // Access granted; no charge is claimed. creditsConsumed (if any) is the original purchase.
            $type = \core\output\notification::NOTIFY_SUCCESS;
            $msg = $str('act_msg_already');
            if ($r['historic'] !== null) {
                $msg .= ' ' . $str('act_msg_historic', $r['historic']);
            }
            break;
        case 'insufficient':
            $msg = $str('act_msg_insufficient', s($errortext($r['error'])));
            break;
        case 'stale':
            $type = \core\output\notification::NOTIFY_WARNING;
            $msg = $str('act_msg_stale', s($r['error']));
            break;
        case 'ambiguous':
            $msg = $str('act_msg_ambiguous', s($r['error']));
            break;
        case 'conflict':
            $msg = $str('act_msg_conflict', s($r['error']));
            break;
        case 'changed':
            // Detected here before sending: nothing was bought.
            $type = \core\output\notification::NOTIFY_WARNING;
            $msg = $str('act_msg_changed');
            break;
        case 'uncertain':
            $type = \core\output\notification::NOTIFY_WARNING;
            $msg = $str('act_msg_uncertain', s($errortext($r['error'])));
            break;
        case 'refused':
            $msg = $str('act_msg_refused', s($errortext($r['error'])));
            break;
        default:
            $msg = $str('act_msg_blocked', $str('act_blocked_' . ($r['error'] ?: 'unverified')));
    }
    if (in_array($r['outcome'], ['unlocked', 'restored', 'already', 'insufficient'], true)) {
        // The balance LMS Labs reports (verify: credits; unlock: remainingCredits; insufficient: currentCredits).
        $bal = !empty($r['balance']['unlimited']) || $r['balance']['credits'] !== null ? $r['balance'] : ($r['state'] ?? []);
        if (!empty($bal['unlimited']) || (isset($bal['credits']) && $bal['credits'] !== null)) {
            $msg .= ' ' . $str('act_msg_balance', $balancetext($bal));
        }
    }
    if (in_array($r['outcome'], ['unlocked', 'restored', 'already'], true) && $r['message'] !== '') {
        $msg .= ' ' . $str('act_msg_servermessage', s($r['message']));
    }
    redirect($pageurl, $msg, null, $type);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($str('activation'));

if ($action === 'review') {
    // Free: a fresh access check and the live price. Nothing is bought here.
    $r = unlock::review();
    if (!$r['canbuy']) {
        echo $OUTPUT->notification($str('act_msg_blocked', $blockedtext($r['blocked'], $r['state'], $r['release'])),
            \core\output\notification::NOTIFY_WARNING);
        echo $OUTPUT->continue_button($pageurl);
        echo $OUTPUT->footer();
        exit;
    }
    $release = $r['release'];
    $confirmurl = new moodle_url($pageurl, ['action' => 'unlock', 'confirm' => 1, 'expected' => $release['price'],
        'sha' => $release['sha']]);
    $warning = $r['warning'] === 'insufficient' ? ' ' . $str('act_warn_insufficient',
        ['price' => $release['price'], 'balance' => $balancetext($r['state'])]) : '';
    $message = $str('act_confirm', [
        'price' => $release['price'],
        'balance' => $balancetext($r['state']),
        'release' => ($release['version'] !== '' ? $release['version'] . ', ' : '') . 'SHA-256 ' . $release['sha'],
    ]) . $warning;
    echo $OUTPUT->confirm(
        $message,
        new single_button($confirmurl, $str('act_confirmbutton', $release['price']), 'post', single_button::BUTTON_PRIMARY),
        new single_button($pageurl, get_string('cancel'), 'get')
    );
    echo $OUTPUT->footer();
    exit;
}

// Status page (GET): stored access state + live release price. No credits are spent here.
$source = credentials::source();
$state = unlock::state();
$release = unlock::release();
$pending = unlock::pending();

echo html_writer::tag('p', $str('act_intro'));
echo $OUTPUT->notification($str('act_notproof'), \core\output\notification::NOTIFY_INFO, false);

$table = new html_table();
$table->attributes['class'] = 'generaltable activation';
$sourcecell = s($str('act_source_' . $source));
if (credentials::central_installed()) {
    $sourcecell .= ' ' . html_writer::link(new moodle_url('/admin/settings.php', ['section' => 'local_aiconfig']),
        $str('act_configurecentral'));
} else {
    $sourcecell .= ' ' . html_writer::span(s($str('act_nocentral')), 'text-muted');
}
$sourcecell .= ' ' . html_writer::link(new moodle_url('/admin/settings.php', ['section' => 'modsettingailanguageteacher']),
    $str('act_configurelocal'));

$accesscell = html_writer::tag('strong', s($str('act_status_' . $state['status'])));
$details = [];
if (!empty($state['checkedat'])) {
    $details[] = $str('act_checkedat', userdate($state['checkedat']));
}
if (!empty($state['unlockedat'])) {
    $details[] = $str('act_unlockedat', userdate($state['unlockedat']));
}
if (!empty($state['source'])) {
    $details[] = $str('act_entitlementsource', s($state['source']));
}
if ($state['status'] === 'unknown' && !empty($state['error'])) {
    $details[] = $errortext($state['error']);
}
if ($details) {
    $accesscell .= html_writer::div(s(implode(' · ', $details)), 'text-muted small');
}

if ($release['ok']) {
    $pricecell = html_writer::tag('strong', s($str('act_price_live', $release['price'])))
        . html_writer::div(s($str('act_release', ['version' => $release['version'] !== '' ? $release['version'] : '-',
            'sha' => $release['sha']])), 'text-muted small');
} else {
    $pricecell = s($str('act_price_unavailable', $reasontext($release)));
}

$table->data = [
    [$str('act_source'), $sourcecell],
    [$str('act_access'), $accesscell],
    [$str('act_balance'), s($balancetext($state))],
    [$str('act_price'), $pricecell],
];
echo html_writer::table($table);

if ($pending) {
    echo $OUTPUT->notification($str('act_pendingnote', userdate($pending['time'])) . ' ' . $str('act_blocked_pending'),
        \core\output\notification::NOTIFY_WARNING, false);
}

// Why "Unlock" is not available right now (the review step checks again before anything is bought).
$blocked = '';
if ($source === 'missing') {
    $blocked = 'nocredentials';
} else if ($pending) {
    $blocked = 'pending';
} else if ($state['status'] === 'unlocked') {
    $blocked = 'unlocked';
} else if (!$release['ok']) {
    $blocked = 'release';
}

$check = new single_button(new moodle_url($pageurl, ['action' => 'check']), $str('act_check'), 'post');
$check->disabled = $source === 'missing';
$buy = new single_button(new moodle_url($pageurl, ['action' => 'review']), $str('act_unlock'), 'post',
    single_button::BUTTON_PRIMARY);
$buy->disabled = $blocked !== '';
echo html_writer::div($OUTPUT->render($check) . ' ' . $OUTPUT->render($buy), 'd-flex gap-2 activation-actions');
if ($blocked !== '') {
    echo html_writer::div(s($blockedtext($blocked, $state, $release)), 'text-muted mt-2');
} else if ($release['ok'] && unlock::low($state, (int)$release['price'])) {
    echo html_writer::div(s($str('act_warn_insufficient', ['price' => $release['price'],
        'balance' => $balancetext($state)])), 'text-warning mt-2');
}
echo $OUTPUT->footer();
