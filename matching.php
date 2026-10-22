<?php
declare(strict_types=1);

/* PHP port of matching.js - same weights, same rules, now running on the server
   so the score cannot be faked from the browser. */

const WEIGHTS = [
    'tagNumber'    => 35,
    'flightNumber' => 25,
    'color'        => 15,
    'brand'        => 15,
    'keywords'     => 10,
];

/** Fraction (0-1) of the claim's description words that also appear in the bag's description. */
function keyword_overlap(string $claimText, string $bagText): float
{
    if ($claimText === '') { return 0.0; }
    $stop  = ['a', 'an', 'the', 'with', 'and', 'of', 'on', 'in', 'my', 'it', 'bag', 'suitcase'];
    $clean = preg_replace('/[^a-z0-9\s]/', '', mb_strtolower($claimText));
    $words = array_filter(
        preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY),
        fn($w) => strlen($w) > 2 && !in_array($w, $stop, true)
    );
    if (!$words) { return 0.0; }

    $bagLower = mb_strtolower($bagText);
    $hits = 0;
    foreach ($words as $w) { if (str_contains($bagLower, $w)) { $hits++; } }
    return $hits / count($words);
}

/**
 * Score one bag row (raw DB row) against the passenger's search details.
 * @param array $claim keys: tagNumber, flightNumber, color, brand, description (all strings)
 * @return array{score:int, breakdown:array}
 */
function score_match(array $claim, array $bag): array
{
    $b = [];

    // Tag number: exact = full weight, edit distance <= 2 = half weight (typo tolerance).
    // PHP has levenshtein() built in (strings must be <= 255 chars; tags are capped at 20).
    if ($claim['tagNumber'] !== '' && $bag['tag_number'] !== '') {
        $dist = levenshtein(strtoupper($claim['tagNumber']), strtoupper($bag['tag_number']));
        $b['tagNumber'] = $dist === 0 ? WEIGHTS['tagNumber'] : ($dist <= 2 ? WEIGHTS['tagNumber'] * 0.5 : 0);
    } else {
        $b['tagNumber'] = 0;
    }

    $b['flightNumber'] = ($claim['flightNumber'] !== ''
        && strtoupper($bag['flight_number']) === strtoupper($claim['flightNumber'])) ? WEIGHTS['flightNumber'] : 0;

    $b['color'] = ($claim['color'] !== ''
        && mb_strtolower($bag['color']) === mb_strtolower($claim['color'])) ? WEIGHTS['color'] : 0;

    $b['brand'] = ($claim['brand'] !== ''
        && str_contains(mb_strtolower($bag['brand']), mb_strtolower($claim['brand']))) ? WEIGHTS['brand'] : 0;

    $b['keywords'] = keyword_overlap($claim['description'], $bag['description']) * WEIGHTS['keywords'];

    return ['score' => min(100, (int)round(array_sum($b))), 'breakdown' => $b];
}

/** Read and length-limit the five search fields from a request array. */
function claim_from_request(array $src): array
{
    return [
        'tagNumber'    => clean($src, 'tagNumber', 20),
        'flightNumber' => clean($src, 'flightNumber', 10),
        'color'        => clean($src, 'color', 30),
        'brand'        => clean($src, 'brand', 50),
        'description'  => clean($src, 'description', 500),
    ];
}
