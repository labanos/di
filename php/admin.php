<?php
// Admin endpoint: read/write match results by human-readable names
// GET  ?year=X&round=Y  -> all match slots for that round (with results if they exist)
// POST                  -> upsert a match result from player names + winner/ups
// v1.1
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db_connect.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: all match slots for a year+round, with results if they exist ──────
if ($method === 'GET') {
    $year  = (int)($_GET['year']  ?? 0);
    $round = (int)($_GET['round'] ?? 0);
    if (!$year || !$round) {
        http_response_code(400);
        echo json_encode(['error' => 'year and round required']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT r.format, m.id AS match_id, m.match_number,
               mr.player_id, p.name AS player_name, p.team,
               mr.points, mr.ups,
               part.name AS partner_name, mr.partner_id
        FROM tournaments t
        JOIN rounds r  ON r.tournament_id = t.id AND r.round_number = ?
        JOIN matches m ON m.round_id = r.id
        LEFT JOIN match_results mr ON mr.match_id = m.id
        LEFT JOIN players p        ON p.id = mr.player_id
        LEFT JOIN players part     ON part.id = mr.partner_id
        WHERE t.year = ?
        ORDER BY m.match_number, p.team, p.name
    ");
    $stmt->execute([$round, $year]);
    $rows = $stmt->fetchAll();

    $matches = [];
    foreach ($rows as $row) {
        $mn = $row['match_number'];
        if (!isset($matches[$mn])) {
            $matches[$mn] = [
                'match_id'     => $row['match_id'],
                'match_number' => $mn,
                'format'       => $row['format'],
                'results'      => [],
            ];
        }
        if ($row['player_id']) {
            $matches[$mn]['results'][] = [
                'player_id'    => (int)$row['player_id'],
                'player_name'  => $row['player_name'],
                'team'         => $row['team'],
                'points'       => (int)$row['points'],
                'ups'          => (int)$row['ups'],
                'partner_id'   => $row['partner_id'] ? (int)$row['partner_id'] : null,
                'partner_name' => $row['partner_name'],
            ];
        }
    }

    echo json_encode(array_values($matches));
    exit;
}

// ── POST: upsert match results ───────────────────────────────────────────────
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $year         = (int)($data['year']         ?? 0);
    $round_number = (int)($data['round_number'] ?? 0);
    $match_number = (int)($data['match_number'] ?? 0);
    $winner       = $data['winner']  ?? '';   // 'blue' | 'red' | 'halved'
    $ups_value    = (int)($data['ups'] ?? 0);
    $players      = $data['players'] ?? [];   // [{name, team, partner_name?}]

    if (!$year || !$round_number || !$match_number || !$players || !$winner) {
        http_response_code(400);
        echo json_encode(['error' => 'year, round_number, match_number, winner, players required']);
        exit;
    }

    // Find match_id
    $stmt = $pdo->prepare("
        SELECT m.id FROM matches m
        JOIN rounds r ON r.id = m.round_id
        JOIN tournaments t ON t.id = r.tournament_id
        WHERE t.year = ? AND r.round_number = ? AND m.match_number = ?
    ");
    $stmt->execute([$year, $round_number, $match_number]);
    $match = $stmt->fetch();
    if (!$match) {
        http_response_code(404);
        echo json_encode(['error' => "Match not found: year=$year round=$round_number match=$match_number"]);
        exit;
    }
    $match_id = $match['id'];

    // Resolve player IDs and partner IDs, compute points/ups
    $resolved = [];
    foreach ($players as $p) {
        $stmt = $pdo->prepare("SELECT id FROM players WHERE name = ?");
        $stmt->execute([$p['name']]);
        $row = $stmt->fetch();
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => "Player not found: {$p['name']}"]);
            exit;
        }
        $player_id = $row['id'];

        $partner_id = null;
        if (!empty($p['partner_name'])) {
            $stmt2 = $pdo->prepare("SELECT id FROM players WHERE name = ?");
            $stmt2->execute([$p['partner_name']]);
            $r2 = $stmt2->fetch();
            if ($r2) $partner_id = $r2['id'];
        }

        if ($winner === 'halved') {
            $points = 1; $ups = 0;
        } elseif ($winner === $p['team']) {
            $points = 2; $ups = $ups_value;
        } else {
            $points = 0; $ups = -$ups_value;
        }

        $resolved[] = [$match_id, $player_id, $points, $ups, $partner_id];
    }

    $stmt = $pdo->prepare("
        INSERT INTO match_results (match_id, player_id, points, ups, partner_id)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE points=VALUES(points), ups=VALUES(ups), partner_id=VALUES(partner_id)
    ");
    foreach ($resolved as $r) {
        $stmt->execute($r);
    }

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
