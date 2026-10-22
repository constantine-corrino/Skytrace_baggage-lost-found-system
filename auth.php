<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

/*  auth.php?action=me                 GET   -> who is signed in (used on page reload)
    auth.php?action=register-passenger POST  { firstName, lastName, email, bookingRef, flightNumber }
    auth.php?action=login-passenger    POST  { bookingRef, lastName }
    auth.php?action=login-staff        POST  { username, password }
    auth.php?action=logout             POST                                          */

$action = $_GET['action'] ?? '';

if ($action === 'me') {
    json_out(['user' => current_user()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { fail('POST required.', 405); }

/* Very simple brute-force brake: 5 failures -> locked for 60 seconds (per browser session). */
if (($_SESSION['lock_until'] ?? 0) > time()) { fail('Too many attempts. Wait a minute and try again.', 429); }

function login_failed(string $message): never
{
    $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;
    if ($_SESSION['fails'] >= 5) { $_SESSION['lock_until'] = time() + 60; $_SESSION['fails'] = 0; }
    fail($message, 401);
}

function login_ok(array $user): never
{
    session_regenerate_id(true);            // new session id after login (prevents session fixation)
    $_SESSION['user'] = $user;
    $_SESSION['fails'] = 0;
    json_out(['user' => $user]);
}

switch ($action) {

    case 'register-passenger':
        $in     = input();
        $first  = clean($in, 'firstName', 45, true);
        $last   = clean($in, 'lastName', 45, true);
        $email  = clean($in, 'email', 100, true);
        $ref    = strtoupper(clean($in, 'bookingRef', 10, true));
        $flight = strtoupper(str_replace(' ', '', clean($in, 'flightNumber', 10, true)));

        // Whitelist patterns. (\z / the D modifier stop a trailing newline slipping past "$".)
        $namePattern = '/^\p{L}[\p{L} \'\-]*$/uD';
        if (!preg_match($namePattern, $first))               { fail('Please enter a valid first name.', 422); }
        if (!preg_match($namePattern, $last))                { fail('Please enter a valid last name.', 422); }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))      { fail('Please enter a valid email address.', 422); }
        if (!preg_match('/^[A-Z0-9]{6}$/D', $ref))           { fail('Booking reference must be 6 letters/numbers.', 422); }
        if (!preg_match('/^[A-Z0-9]{3,10}$/D', $flight))     { fail('Please enter a valid flight number.', 422); }

        try {
            $st = db()->prepare(
                'INSERT INTO passengers (booking_ref, last_name, name, email, flight_number) VALUES (?,?,?,?,?)'
            );
            $st->execute([$ref, $last, $first . ' ' . $last, $email, $flight]);
        } catch (PDOException $e) {
            // 23000 = UNIQUE(booking_ref) violated. Letting the database enforce this
            // (instead of SELECT-then-INSERT) means two simultaneous sign-ups cannot both succeed.
            if ($e->getCode() === '23000') {
                fail('An account already exists for that booking reference. Please sign in instead.', 409);
            }
            throw $e;                       // anything else -> generic 500 from bootstrap.php
        }

        login_ok([
            'role'         => 'passenger',
            'id'           => (int)db()->lastInsertId(),
            'name'         => $first . ' ' . $last,
            'email'        => $email,
            'flightNumber' => $flight,
        ]);

    case 'login-passenger':
        $in  = input();
        $ref = strtoupper(clean($in, 'bookingRef', 10, true));
        $last = clean($in, 'lastName', 50, true);

        // utf8mb4_unicode_ci makes the last-name comparison case-insensitive
        $st = db()->prepare('SELECT * FROM passengers WHERE booking_ref = ? AND last_name = ?');
        $st->execute([$ref, $last]);
        $p = $st->fetch();
        if (!$p) { login_failed("We couldn't find a booking with that reference and last name."); }

        login_ok([
            'role'         => 'passenger',
            'id'           => (int)$p['id'],
            'name'         => $p['name'],
            'email'        => $p['email'],
            'flightNumber' => $p['flight_number'],
        ]);

    case 'login-staff':
        $in   = input();
        $user = strtolower(clean($in, 'username', 50, true));
        $pass = isset($in['password']) && is_string($in['password']) ? $in['password'] : '';

        $st = db()->prepare('SELECT * FROM staff WHERE username = ?');
        $st->execute([$user]);
        $s = $st->fetch();
        if (!$s || !password_verify($pass, $s['password_hash'])) {
            login_failed('Incorrect username or password.');
        }

        login_ok([
            'role'  => 'staff',
            'id'    => (int)$s['id'],
            'name'  => $s['name'],
            'title' => $s['role'],
        ]);

    case 'logout':
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {          // also remove the cookie from the browser
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        json_out(['ok' => true]);

    default:
        fail('Unknown action.', 404);
}
