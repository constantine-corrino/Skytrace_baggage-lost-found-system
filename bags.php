<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

/*  GET   bags.php?board=1          public: latest 4 bags, limited fields (landing page board)
    GET   bags.php                  staff:  every bag, newest first
    POST  bags.php                  staff:  log a found bag
    PATCH bags.php?id=BAG-1042      staff:  mark an Unclaimed bag as Returned                */

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (isset($_GET['board'])) {
        $rows = db()->query(
            'SELECT flight_number, tag_number, found_location, status FROM bags ORDER BY id DESC LIMIT 4'
        )->fetchAll();
        json_out(['bags' => array_map(fn($r) => [
            'flightNumber'  => $r['flight_number'],
            'tagNumber'     => $r['tag_number'],
            'foundLocation' => $r['found_location'],
            'status'        => $r['status'],
        ], $rows)]);
    }

    require_role('staff');
    $rows = db()->query('SELECT * FROM bags ORDER BY id DESC')->fetchAll();
    json_out(['bags' => array_map('bag_json', $rows)]);
}

if ($method === 'POST') {
    $staff = require_role('staff');
    $in = input();

    $date = clean($in, 'dateFound', 10);
    if ($date === '') {
        $date = date('Y-m-d');
    } else {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) { fail('Date must be YYYY-MM-DD.'); }
    }

    $st = db()->prepare(
        'INSERT INTO bags (tag_number, flight_number, route, color, brand, `type`, description,
                           date_found, found_location, status, logged_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    $st->execute([
        strtoupper(clean($in, 'tagNumber', 20, true)),
        strtoupper(clean($in, 'flightNumber', 10, true)),
        clean($in, 'route', 30),
        clean($in, 'color', 30, true),
        clean($in, 'brand', 50, true),
        clean($in, 'type', 50, true),
        clean($in, 'description', 1000),
        $date,
        clean($in, 'foundLocation', 100, true),
        'Unclaimed',
        $staff['id'],
    ]);

    $get = db()->prepare('SELECT * FROM bags WHERE id = ?');
    $get->execute([(int)db()->lastInsertId()]);
    $row = $get->fetch();
    json_out(['bag' => bag_json($row)], 201);
}

if ($method === 'PATCH') {
    require_role('staff');
    $id = parse_id($_GET['id'] ?? '', 'BAG');

    // Only an Unclaimed bag can be handed back directly; claimed ones go through claim review.
    $st = db()->prepare("UPDATE bags SET status = 'Returned' WHERE id = ? AND status = 'Unclaimed'");
    $st->execute([$id]);
    if ($st->rowCount() === 0) { fail('That bag is not available to mark as returned.', 409); }
    json_out(['ok' => true]);
}

fail('Method not allowed.', 405);
