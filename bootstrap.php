<?php
declare(strict_types=1);

/* Loaded by every endpoint. Sets up JSON output, sessions, the database
   connection and a handful of small helpers. */

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/* Idle time-out: a signed-in session that makes no user-initiated request for this
   many seconds is wiped. Keep in sync with IDLE_LIMIT_MS in app.js. */
const IDLE_TIMEOUT = 900;                  // 15 minutes

ini_set('session.use_strict_mode', '1');   // refuse session IDs the server did not create
ini_set('session.use_only_cookies', '1');  // never accept a session ID from the URL
ini_set('session.gc_maxlifetime', '3600'); // PHP must not delete session data before our own time-out

session_name('skytrace');
session_set_cookie_params([
    'lifetime' => 0,                       // cookie dies when the browser closes
    'path'     => '/',
    'httponly' => true,                    // JavaScript cannot read the session cookie
    'samesite' => 'Lax',                   // basic CSRF protection
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

/* ---- idle time-out ---- */
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > IDLE_TIMEOUT) {
    $_SESSION = [];                        // signed out: user, lock counters, everything
    session_regenerate_id(true);           // and the old session ID is now useless
}
// The staff dashboard refreshes itself every 15 s. Those calls send
// "X-Background-Poll: 1" and must NOT reset the idle clock, otherwise a
// dashboard left open would never time out.
if (($_SERVER['HTTP_X_BACKGROUND_POLL'] ?? '') !== '1') {
    $_SESSION['last_activity'] = time();
}

/* Any uncaught error -> generic JSON message (details go to the server log, not the user). */
set_exception_handler(function (Throwable $e) {
    error_log((string)$e);
    if (!headers_sent()) { http_response_code(500); }
    echo json_encode(['error' => 'Server error. Please try again.']);
});

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,   // real prepared statements = SQL-injection safe
            ]
        );
    }
    return $pdo;
}

function json_out($data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $code = 400): never
{
    json_out(['error' => $message], $code);
}

/** Decoded JSON request body (the front end sends JSON). */
function input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) { return []; }
    $data = json_decode($raw, true);
    if (!is_array($data)) { fail('Invalid JSON body.'); }
    return $data;
}

/** Read one string field, trimmed and length-checked. */
function clean(array $src, string $key, int $max = 255, bool $required = false): string
{
    $v = (isset($src[$key]) && is_scalar($src[$key])) ? trim((string)$src[$key]) : '';
    if ($required && $v === '') { fail("Missing field: $key"); }
    if (mb_strlen($v) > $max)   { fail("Field too long: $key"); }
    return $v;
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/** Stop with 401/403 unless a user with this role ('staff' or 'passenger') is signed in. */
function require_role(string $role): array
{
    $u = current_user();
    if (!$u)                  { fail('Please sign in.', 401); }
    if ($u['role'] !== $role) { fail('You do not have access to this.', 403); }
    return $u;
}

/** "BAG-1042" -> 1042 */
function parse_id(string $raw, string $prefix): int
{
    if (!preg_match('/^' . $prefix . '-(\d{1,9})\z/', $raw, $m)) { fail('Bad id.'); }
    return (int)$m[1];
}

/* ---- DB row -> JSON shapes the front end already understands ---- */

function bag_json(array $r): array
{
    return [
        'id'            => 'BAG-' . $r['id'],
        'tagNumber'     => $r['tag_number'],
        'flightNumber'  => $r['flight_number'],
        'route'         => $r['route'],
        'color'         => $r['color'],
        'brand'         => $r['brand'],
        'type'          => $r['type'],
        'description'   => $r['description'],
        'dateFound'     => $r['date_found'],
        'foundLocation' => $r['found_location'],
        'status'        => $r['status'],
    ];
}

/** Expects a claims row joined with passengers (p_name, p_email) and bags (brand, type). */
function claim_json(array $r): array
{
    return [
        'id'             => 'CLM-' . $r['id'],
        'bagId'          => 'BAG-' . $r['bag_id'],
        'bagLabel'       => $r['brand'] . ' ' . strtolower($r['type']),
        'passengerName'  => $r['p_name'],
        'passengerEmail' => $r['p_email'],
        'submittedAt'    => substr($r['submitted_at'], 0, 16),
        'status'         => $r['status'],
        'confidence'     => (int)$r['confidence'],
    ];
}
