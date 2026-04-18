<?php
// GET /php/year_schedule.php?year=YYYY — v3
// Returns rounds with match results (players, points, ups) for a given year.
// points=NULL means the match is upcoming (players assigned, not yet played).
// If a round has no matches at all, its matches array is empty.
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/db_migrate.php';
run_migrations($pdo);

$year = (int)($_GET['year'] ?? date('Y'));

// ── Rounds for this year ───────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT r.id, r.round_number, r.format
    FROM rounds r
    JOIN tournaments t ON t.id = r.tournament_id
    WHERE t.year = ?
    ORDER BY r.round_number ASC
");
$stmt->execute([$year]);
$rounds = $stmt->fetchAll();

if (empty($rounds)) {
    echo json_encode(['year' => $year, 'rounds' => []]);
    exit;
}

$roundIds     = array_column($rounds, 'id');
$placeholders = implode(',', array_fill(0, count($roundIds), '?'));

// ── All match results for those rounds ────────────────────────────────────────
$stmt2 = $pdo->prepare("
    SELECT
        m.id           AS match_id,
        m.match_number,
        r.id           AS round_id,
        p.name,
        p.team,
        mr.points,
        mr.ups
    FROM matches m
    JOIN rounds r ON r.id = m.round_id
    LEFT JOIN match_results mr ON mr.match_id = m.id
    LEFT JOIN players p        ON p.id = mr.player_id
    WHERE r.id IN ($placeholders)
    ORDER BY m.match_number ASC, p.team ASC, p.name ASC
");
$stmt2->execute($roundIds);
$rows = $stmt2->fetchAll();

// ── Group: round_id → match_id → {blue[], red[]} ──────────────────────────────
$matchData = [];
foreach ($rows as $row) {
    $rid = $row['round_id'];
    $mid = $row['match_id'];
    if (!isset($matchData[$rid][$mid])) {
        $matchData[$rid][$mid] = [
            'match_id'     => (int)$mid,
            'match_number' => (int)$row['match_number'],
            'blue' => [],
            'red'  => [],
        ];
    }
    if ($row['name']) {
        $matchData[$rid][$mid][$row['team']][] = [
            'name'   => $row['name'],
            // Preserve NULL so frontend can distinguish upcoming vs played
            'points' => $row['points'] !== null ? (int)$row['points'] : null,
            'ups'    => $row['ups']    !== null ? (int)$row['ups']    : null,
        ];
    }
}

// ── Attach matches to rounds ───────────────────────────────────────────────────
foreach ($rounds as &$round) {
    $rid                   = $round['id'];
    $round['id']           = (int)$round['id'];
    $round['round_number'] = (int)$round['round_number'];
    $round['matches']      = array_values($matchData[$rid] ?? []);
}
unset($round);

echo json_encode(['year' => $year, 'rounds' => $rounds]);
