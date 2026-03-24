#!/usr/local/bin/php
<?php
/**
 * register.php
 *
 * Creates a new disabled OPNsense local user in the 'captive' group.
 * The account must be manually enabled by an administrator before
 * the user can log in to the captive portal.
 *
 * INPUT: JSON object on stdin (NOT argv) to prevent shell mangling of
 * special characters ($, !, ", etc.) common in passwords.
 *
 *   {"type": "employee|guest", "password": "...", "fullname": "...",
 *    "email": "...", "reason": "..."}
 *   type defaults to 'employee'; email is optional.
 *   Username is auto-generated: first-initial + lastname [+ counter] [+ _guest]
 *
 * OUTPUT: JSON to stdout.
 *   {"status": "ok"}
 *   {"status": "error", "message": "..."}
 *
 * DEPLOY TO:
 *   /usr/local/opnsense/scripts/captiveportal/register.php
 *   chown root:wheel && chmod 750
 */

declare(strict_types=1);

function respond(string $status, string $message = '', array $extra = []): void
{
    $out = ['status' => $status];
    if ($message !== '') {
        $out['message'] = $message;
    }
    foreach ($extra as $k => $v) {
        $out[$k] = $v;
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

/* ── Load OPNsense bootstrap ── */
require_once('/usr/local/etc/inc/config.inc');
require_once('/usr/local/etc/inc/auth.inc');
require_once('/usr/local/etc/inc/util.inc');

/* ── Read JSON payload from stdin ── */
$raw     = stream_get_contents(STDIN);
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    respond('error', 'Invalid input format.');
}

$accType  = isset($payload['type'])     ? trim((string)$payload['type'])     : 'employee';
$password = isset($payload['password']) ? (string)$payload['password']       : '';
$fullname = isset($payload['fullname']) ? trim((string)$payload['fullname']) : '';
$email    = isset($payload['email'])    ? trim((string)$payload['email'])    : '';
$reason   = isset($payload['reason'])   ? trim((string)$payload['reason'])   : '';
$expiresStr = isset($payload['expires']) ? trim((string)$payload['expires']) : '';

if (!in_array($accType, ['employee', 'guest'], true)) {
    $accType = 'employee';
}

/* ── Validate mandatory fields ── */
if ($fullname === '') {
    respond('error', 'Full name is required.');
}
if ($reason === '') {
    respond('error', 'Reason for access is required.');
}

/* ── Validate expiration date (required for guests) ── */
$expiresTs = null;
if ($accType === 'guest') {
    if ($expiresStr === '') {
        respond('error', 'Expiration date is required for guest accounts.');
    }
    $ts = strtotime($expiresStr . ' 23:59:59');
    if ($ts === false || $ts <= time()) {
        respond('error', 'Expiration date must be a valid future date.');
    }
    $expiresTs = $ts;
}

/* ── Validate password ── */
if ($password === '') {
    respond('error', 'Password is required.');
}
if (strlen($password) < 8) {
    respond('error', 'Password must be at least 8 characters.');
}
if (strlen($password) > 256) {
    respond('error', 'Password is too long.');
}

/* ── Validate optional email ── */
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond('error', 'Invalid e-mail address.');
}

/* ── Generate username from Full Name + account type ─────────────────────────
 * Rule: first letter of first name + last name, lowercase, a-z0-9 only.
 *   "Adam Abacki"  → aabacki  (employee)  /  aabacki_guest  (guest)
 * If the candidate is taken, append an incrementing counter before the suffix:
 *   aabacki1, aabacki2 …  /  aabacki1_guest, aabacki2_guest …
 */
global $config;

$existingNames = [];
if (!empty($config['system']['user']) && is_array($config['system']['user'])) {
    foreach ($config['system']['user'] as $u) {
        if (isset($u['name'])) {
            $existingNames[] = strtolower((string)$u['name']);
        }
    }
}

function make_username_base(string $fullname): string
{
    $words = preg_split('/\s+/', trim($fullname));
    $words = array_values(array_filter($words));
    if (count($words) === 0) {
        return 'user';
    }
    // Remove non-ASCII letters/digits, lowercase
    $clean = function(string $s): string {
        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $s));
    };
    if (count($words) === 1) {
        $base = $clean($words[0]);
    } else {
        $first = $clean($words[0]);
        $last  = $clean(end($words));
        $base  = (strlen($first) > 0 ? $first[0] : '') . $last;
    }
    return strlen($base) >= 1 ? $base : 'user';
}

$suffix    = ($accType === 'guest') ? '_guest' : '';
$base      = make_username_base($fullname);
$candidate = $base . $suffix;

// If the plain candidate is taken, try base1, base2, …
if (in_array(strtolower($candidate), $existingNames, true)) {
    for ($i = 1; $i <= 9999; $i++) {
        $candidate = $base . $i . $suffix;
        if (!in_array(strtolower($candidate), $existingNames, true)) {
            break;
        }
    }
}

$username = $candidate;

/* ── Allocate a UID that is not already in use ──────────────────────────────
 * Using nextuid alone is not safe — OPNsense may have recycled UIDs from
 * deleted accounts that are still referenced in group member[] arrays.
 * Build a set of all UIDs in use (users + group members) and find the
 * first free UID at or above nextuid.
 */
$usedUids = [];
if (!empty($config['system']['user'])) {
    foreach ($config['system']['user'] as $u) {
        if (isset($u['uid'])) $usedUids[(int)$u['uid']] = true;
    }
}
if (!empty($config['system']['group'])) {
    foreach ($config['system']['group'] as $g) {
        if (!empty($g['member'])) {
            foreach ((array)$g['member'] as $mid) {
                $usedUids[(int)$mid] = true;
            }
        }
    }
}
$uid = max(2000, (int)($config['system']['nextuid'] ?? 2000));
while (isset($usedUids[$uid])) { $uid++; }
$config['system']['nextuid'] = (string)($uid + 1);

/* ── Build user record ── */
// OPNsense 23.x+ MVC user management identifies records by the uuid XML
// attribute.  Without it the Edit User dialog loads an empty form because
// the API call GET /api/core/user/getUser/{uuid} finds nothing.
$uuid = sprintf(
    '%08x-%04x-%04x-%04x-%012x',
    random_int(0, 0xffffffff),
    random_int(0, 0xffff),
    random_int(0, 0x0fff) | 0x4000,   // version 4
    random_int(0, 0x3fff) | 0x8000,   // variant bits
    random_int(0, 0xffffffffffff)
);

$newUser = [
    '@attributes' => ['uuid' => $uuid],
    'name'        => $username,
    'descr'       => $fullname,        // Full Name field in OPNsense GUI
    'scope'       => 'user',
    'uid'         => (string)$uid,
    'disabled'    => '1',              // OPNsense: <disabled>1</disabled> = account disabled
];

if ($email !== '') {
    $newUser['email'] = $email;
}
if ($reason !== '') {
    $newUser['comment'] = substr($reason, 0, 512);
}
// OPNsense stores expiration as Unix timestamp string in the 'expires' field
if ($expiresTs !== null) {
    $newUser['expires'] = (string)$expiresTs;
}

/* Hash the password using OPNsense's own function (bcrypt or SHA-512 in FIPS) */
local_user_set_password($newUser, $password);

/* ── Add user to config ── */
if (!isset($config['system']['user']) || !is_array($config['system']['user'])) {
    $config['system']['user'] = [];
}
$config['system']['user'][] = $newUser;

/* ── Assign to 'captive' group ──
 * OPNsense stores group members as a comma-separated string in a single
 * <member> XML element (e.g. "2000,2001,2002"). Appending via PHP array
 * push would create a separate <member> element — incorrect. Instead,
 * parse the CSV string, append the new UID, and write it back as a string.
 */
$targetGroup = 'captive';
if (!empty($config['system']['group']) && is_array($config['system']['group'])) {
    foreach ($config['system']['group'] as &$grp) {
        if (isset($grp['name']) && $grp['name'] === $targetGroup) {
            if (!isset($grp['member']) || $grp['member'] === '') {
                // No members yet — start with just this UID
                $grp['member'] = (string)$uid;
            } else {
                // Collect existing UIDs — member may be:
                //   a) a CSV string in a single <member> element  "2000,2001,2002"
                //   b) a PHP array from multiple <member> elements ['2000','2001','2002']
                //      (each element may itself be a CSV string from old portal code)
                // Normalise everything to a flat array of UID strings, then write
                // back as a single CSV string (OPNsense native format).
                $raw = is_array($grp['member']) ? $grp['member'] : [(string)$grp['member']];
                $existing = [];
                foreach ($raw as $chunk) {
                    foreach (array_filter(array_map('trim', explode(',', (string)$chunk))) as $u) {
                        $existing[$u] = true;
                    }
                }
                $existing[(string)$uid] = true;
                $grp['member'] = implode(',', array_keys($existing));
            }
            break;
        }
    }
    unset($grp);
}

/* ── Persist to config.xml ── */
write_config('Captive portal: self-service registration for user ' . $username);

respond('ok', '', ['username' => $username]);
