<?php
/**
 * One-time demo data loader.  Run from the command line, AFTER importing schema.sql:
 *
 *     php database/seed.php
 *
 * It exists as a PHP script (not plain SQL) so the staff passwords can be stored
 * as proper password_hash() values instead of readable text.
 */
if (PHP_SAPI !== 'cli') { exit("Run this from the command line only.\n"); }

require __DIR__ . '/../api/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

if ((int)$pdo->query('SELECT COUNT(*) FROM staff')->fetchColumn() > 0) {
    exit("Database already has data - nothing done.\n");
}

$pdo->beginTransaction();

/* ---- staff ---- */
$st = $pdo->prepare('INSERT INTO staff (username, password_hash, name, role) VALUES (?,?,?,?)');
$st->execute(['j.alvarez', password_hash('terminal4', PASSWORD_DEFAULT), 'J. Alvarez',        'Baggage Services Agent']);
$st->execute(['s.wick',    password_hash('carousel9', PASSWORD_DEFAULT), 'S. Wickramasinghe', 'Duty Manager']);

/* ---- passengers ---- */
$st = $pdo->prepare('INSERT INTO passengers (booking_ref, last_name, name, email, flight_number) VALUES (?,?,?,?,?)');
$st->execute(['QR482K', 'Perera',   'N. Perera',   'n.perera@example.com',  'QR482']);
$st->execute(['LX910B', 'Fernando', 'S. Fernando', 's.fernando@example.com', 'LX910']);
$st->execute(['EK227T', 'de Silva', 'R. de Silva', 'r.desilva@example.com', 'EK227']);

/* ---- bags (explicit ids so they match the old BAG-xxxx numbers) ---- */
$st = $pdo->prepare('INSERT INTO bags
  (id, tag_number, flight_number, route, color, brand, `type`, description, date_found, found_location, status)
  VALUES (?,?,?,?,?,?,?,?,?,?,?)');
$bags = [
  [1037, 'QR48101', 'QR482', 'DOH → CMB', 'Black', 'Tommy Hilfiger',    'Carry-on roller',     "Black carry-on roller bag with a monogrammed tag reading 'N.P.'", '2026-09-07', 'Terminal 2, Carousel 4', 'Returned'],
  [1039, 'UL29903', 'UL299', 'MAA → CMB', 'Red',   'Skybags',          'Backpack',            'Red hiking backpack, laptop compartment, keychain shaped like a turtle', '2026-09-08', 'Terminal 1, Carousel 1', 'Returned'],
  [1042, 'QR48219', 'QR482', 'DOH → CMB', 'Black', 'Samsonite',        'Hard-shell suitcase', 'Black hard-shell suitcase, red ribbon on the handle, small dent on the left corner', '2026-09-10', 'Terminal 2, Carousel 4', 'Unclaimed'],
  [1043, 'LX91087', 'LX910', 'ZRH → CMB', 'Navy',  'Delsey',           'Soft-shell duffel',   'Navy blue duffel bag with a tan leather strap and a small Swiss flag patch', '2026-09-11', 'Terminal 1, Carousel 2', 'Unclaimed'],
  [1044, 'EK22754', 'EK227', 'DXB → CMB', 'Grey',  'American Tourister','Hard-shell suitcase', "Grey hard-shell suitcase with a yellow luggage strap and initials 'R.D.S' on a tag", '2026-09-11', 'Terminal 2, Carousel 6', 'Claim Pending'],
];
foreach ($bags as $b) { $st->execute($b); }

/* ---- one pending claim (R. de Silva on BAG-1044) ---- */
$pid = $pdo->query("SELECT id FROM passengers WHERE booking_ref = 'EK227T'")->fetchColumn();
$pdo->prepare('INSERT INTO claims (id, bag_id, passenger_id, submitted_at, status, confidence) VALUES (501, 1044, ?, ?, ?, 92)')
    ->execute([$pid, '2026-09-11 14:20:00', 'Pending']);

$pdo->commit();
echo "Seeded. Staff: j.alvarez / terminal4, s.wick / carousel9.  Passengers: QR482K Perera, LX910B Fernando, EK227T de Silva.\n";
