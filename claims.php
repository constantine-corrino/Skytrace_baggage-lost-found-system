<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/matching.php';

/*  GET   claims.php                 staff: pending claims | passenger: their own claims
    POST  claims.php                 passenger: { bagId, search:{flightNumber,tagNumber,color,brand,description} }
    PATCH claims.php?id=CLM-501      staff: { decision: "approve" | "decline" }              */

const CLAIM_SELECT =
    'SELECT c.*, p.name AS p_name, p.email AS p_email, b.brand, b.`type`
       FROM claims c
       JOIN passengers p ON p.id = c.passenger_id
       JOIN bags b       ON b.id = c.bag_id';

$method = $_SERVER['REQUEST_METHOD'];
$user   = current_user() ?? fail('Please sign in.', 401);

/* ---------------- list ---------------- */
if ($method === 'GET') {
    if ($user['role'] === 'staff') {
        $rows = db()->query(CLAIM_SELECT . " WHERE c.status = 'Pending' ORDER BY c.id")->fetchAll();
    } else {
        $st = db()->prepare(CLAIM_SELECT . ' WHERE c.passenger_id = ? ORDER BY c.id DESC');
        $st->execute([$user['id']]);
        $rows = $st->fetchAll();
    }
    json_out(['claims' => array_map('claim_json', $rows)]);
}

/* ---------------- passenger files a claim ---------------- */
if ($method === 'POST') {
    require_role('passenger');
    $in    = input();
    $bagId = parse_id(clean($in, 'bagId', 20, true), 'BAG');
    $claim = claim_from_request(is_array($in['search'] ?? null) ? $in['search'] : []);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // FOR UPDATE locks the row so two passengers cannot claim the same bag at the same moment.
        $st = $pdo->prepare('SELECT * FROM bags WHERE id = ? FOR UPDATE');
        $st->execute([$bagId]);
        $bag = $st->fetch();
        if (!$bag)                          { $pdo->rollBack(); fail('Bag not found.', 404); }
        if ($bag['status'] !== 'Unclaimed') { $pdo->rollBack(); fail('That bag is no longer available to claim.', 409); }

        // Score is recomputed here - the browser's number is never trusted.
        $score = score_match($claim, $bag)['score'];

        $pdo->prepare('INSERT INTO claims (bag_id, passenger_id, confidence) VALUES (?,?,?)')
            ->execute([$bagId, $user['id'], $score]);
        $claimId = (int)$pdo->lastInsertId();

        $pdo->prepare("UPDATE bags SET status = 'Claim Pending' WHERE id = ?")->execute([$bagId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    $get = $pdo->prepare(CLAIM_SELECT . ' WHERE c.id = ?');
    $get->execute([$claimId]);
    $row = $get->fetch();
    json_out(['claim' => claim_json($row)], 201);
}

/* ---------------- staff approves / declines ---------------- */
if ($method === 'PATCH') {
    $staff    = require_role('staff');
    $claimId  = parse_id($_GET['id'] ?? '', 'CLM');
    $decision = clean(input(), 'decision', 10, true);
    if (!in_array($decision, ['approve', 'decline'], true)) { fail('Decision must be approve or decline.'); }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM claims WHERE id = ? FOR UPDATE');
        $st->execute([$claimId]);
        $c = $st->fetch();
        if (!$c)                        { $pdo->rollBack(); fail('Claim not found.', 404); }
        if ($c['status'] !== 'Pending') { $pdo->rollBack(); fail('This claim was already reviewed.', 409); }

        $approved = $decision === 'approve';
        $pdo->prepare('UPDATE claims SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')
            ->execute([$approved ? 'Approved' : 'Declined', $staff['id'], $claimId]);

        // Approved -> bag is handed over. Declined -> bag goes back into the searchable pool.
        $pdo->prepare('UPDATE bags SET status = ? WHERE id = ?')
            ->execute([$approved ? 'Returned' : 'Unclaimed', $c['bag_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    json_out(['ok' => true]);
}

fail('Method not allowed.', 405);
