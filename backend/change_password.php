#!/usr/local/bin/php
<?php
/**
 * change_password.php
 *
 * Configd action — changes a local OPNsense user's password.
 * Called only via configd (actions_chpass.conf), never directly from HTTP.
 *
 * INPUT: JSON object on stdin (NOT argv) to prevent shell mangling of
 * special characters ($, !, ", etc.) that are common in passwords.
 *
 *   {"user": "...", "old": "...", "new": "..."}
 *
 * OUTPUT: JSON to stdout.
 *   {"status": "ok"}
 *   {"status": "error", "message": "..."}
 *
 * DEPLOY TO:
 *   /usr/local/opnsense/scripts/auth/change_password.php
 *   chown root:wheel && chmod 750
 */

declare(strict_types=1);

function respond(string $status, string $message = ''): void
{
    $out = ['status' => $status];
    if ($message !== '') {
        $out['message'] = $message;
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

/* ── Load OPNsense bootstrap ── */
require_once('/usr/local/etc/inc/config.inc');
require_once('/usr/local/etc/inc/auth.inc');
require_once('/usr/local/etc/inc/util.inc');

/* ── Read JSON payload from stdin ──────────────────────────────────────────
 * Passwords arrive via stdin as JSON — this is the only safe way to pass
 * arbitrary password strings without shell interpretation mangling special
 * characters ($, !, \, ", etc.). argv is NOT used for credentials.
 */
$raw = stream_get_contents(STDIN);
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    respond('error', 'Invalid input format.');
}

$username = isset($payload['user']) ? trim((string)$payload['user']) : '';
$oldPass  = isset($payload['old'])  ? (string)$payload['old']        : '';
$newPass  = isset($payload['new'])  ? (string)$payload['new']        : '';

if ($username === '' || $oldPass === '' || $newPass === '') {
    respond('error', 'Missing parameters.');
}

/* Only allow characters safe for the local user database */
if (!preg_match('/^[a-zA-Z0-9_\-\.@]{1,64}$/', $username)) {
    respond('error', 'Invalid username.');
}

/* Reject excessively long passwords (DoS protection against slow bcrypt) */
if (strlen($oldPass) > 256 || strlen($newPass) > 256) {
    respond('error', 'Password is too long.');
}

if (strlen($newPass) < 8) {
    respond('error', 'Password must be at least 8 characters.');
}

if ($newPass === $oldPass) {
    respond('error', 'New password must differ from current password.');
}

/* ── Verify old password using OPNsense AuthenticationFactory ──────────────
 * AuthenticationFactory::get('Local Database') is exactly what the captive
 * portal logon API uses internally (AccessController::logonAction).
 * authenticate() verifies the bcrypt/SHA-512 hash from config.xml.
 */
use OPNsense\Auth\AuthenticationFactory;

$factory       = new AuthenticationFactory();
$authenticator = $factory->get('Local Database');

if (!$authenticator) {
    respond('error', 'Internal error: authentication module unavailable.');
}

if (!$authenticator->authenticate($username, $oldPass)) {
    respond('error', 'Invalid current password.');
}

/* ── Locate user record in config.xml ── */
global $config;

if (empty($config['system']['user']) || !is_array($config['system']['user'])) {
    respond('error', 'No local users found in configuration.');
}

$userIdx = null;
foreach ($config['system']['user'] as $idx => $u) {
    if (isset($u['name']) && $u['name'] === $username) {
        $userIdx = $idx;
        break;
    }
}

if ($userIdx === null) {
    respond('error', 'User not found in local database.');
}

/* Refuse to change passwords for built-in system accounts */
if (!empty($config['system']['user'][$userIdx]['is_system'])) {
    respond('error', 'Cannot change password for a system account.');
}

/* ── Enforce OPNsense password policy (if configured) ── */
if (method_exists($authenticator, 'checkPolicy')) {
    $policyErrors = $authenticator->checkPolicy($username, $oldPass, $newPass);
    if (!empty($policyErrors)) {
        respond('error', implode(' ', (array)$policyErrors));
    }
}

/* ── Write new password hash ──────────────────────────────────────────────
 * local_user_set_password(): bcrypt cost=11, or SHA-512 if FIPS active.
 * local_user_set(): syncs /etc/master.passwd if user has a shell.
 * write_config(): atomic commit to /conf/config.xml with backup.
 */
local_user_set_password($config['system']['user'][$userIdx], $newPass);
local_user_set($config['system']['user'][$userIdx]);

$config['system']['user'][$userIdx]['pwd_changed_at'] = microtime(true);

write_config('Captive portal: password changed for user ' . $username);

respond('ok');
