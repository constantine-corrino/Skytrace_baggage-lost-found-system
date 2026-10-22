<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/matching.php';

/*  POST search.php   (passenger only)
    body: { flightNumber, tagNumber, color, brand, description }
    returns: { results: [ { bag, score, alreadyClaimed } ] }  sorted best match first.

    The per-field breakdown is deliberately NOT returned: telling a user which field
    matched would let them guess a tag number one digit at a time. */

require_role('passenger');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { fail('POST required.', 405); }

$claim = claim_from_request(input());

$bags = db()->query("SELECT * FROM bags WHERE status <> 'Returned'")->fetchAll();

$results = [];
foreach ($bags as $bag) {
    $m = score_match($claim, $bag);
    if ($m['score'] > 0) {
        $results[] = [
            'bag'            => bag_json($bag),
            'score'          => $m['score'],
            'alreadyClaimed' => $bag['status'] === 'Claim Pending',
        ];
    }
}
usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

json_out(['results' => $results]);
