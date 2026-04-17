<?php
// Admin endpoint — match results + player management
// GET  ?year=X&round=Y          -> match slots for that round
// GET  ?action=players          -> all players
// POST {action:'add_player'}    -> create player
// POST {action:'update_player'} -> rename / change team
// POST {action:'merge_players'} -> reassign results from merge_id -> keep_id, delete merge_id
// POST {action:'delete_player'} -> delete player (only if no results)
// POST {action:'delete_match'}  -> delete match and all its results
// POST (no action)              -> replace match results (creates match row if needed)
// v1.5
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db_connect.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {

    // Player list
    if (($_GET['action'] ?? '') === 'players') {
        $stmt = $pdo->query("SELECT id, name, team, active FROM players ORDER BY team, name");
        echo json_encode($stmt->fetchAll());
        exit;
    }

    // Match slots for a year + round
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

// ── POST ─────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $data   = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';

    // ── Add player ────────────────────────────────────────────────────────────
    if ($action === 'add_player') {
        $name = trim($data['name'] ?? '');
        $team = $data['team'] ?? '';
        if (!$name || !in_array($team, ['blue','red'])) {
            http_response_code(400);
            echo json_encode(['error' => 'name and team (blue|red) required']);
            exit;
        }
        try {
            $pdo->prepare("INSERT INTO players (name, team, active) VALUES (?, ?, 1)")
                ->execute([$name, $team]);
            echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        } catch (PDOException $e) {
            http_response_code(409);
            echo json_encode(['error' => 'Player already exists']);
        }
        exit;
    }

    // ── Update player (rename / change team) ─────────────────────────────────
    if ($action === 'update_player') {
        $id   = (int)($data['id']   ?? 0);
        $name = trim($data['name']  ?? '');
        $team = $data['team'] ?? '';
        if (!$id || !$name || !in_array($team, ['blue','red'])) {
            http_response_code(400);
            echo json_encode(['error' => 'id, name and team required']);
            exit;
        }
        $pdo->prepare("UPDATE players SET name = ?, team = ? WHERE id = ?")
            ->execute([$name, $team, $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Merge players ─────────────────────────────────────────────────────────
    if ($action === 'merge_players') {
        $keep_id  = (int)($data['keep_id']  ?? 0);
        $merge_id = (int)($data['merge_id'] ?? 0);
        if (!$keep_id || !$merge_id || $keep_id === $merge_id) {
            http_response_code(400);
            echo json_encode(['error' => 'keep_id and merge_id required and must differ']);
            exit;
        }

        $pdo->prepare("UPDATE match_results SET partner_id = ? WHERE partner_id = ?")
            ->execute([$keep_id, $merge_id]);

        $pdo->prepare("
            UPDATE match_results
            SET player_id = ?
            WHERE player_id = ?
              AND match_id NOT IN (
                  SELECT match_id FROM (
                      SELECT match_id FROM match_results WHERE player_id = ?
                  ) AS tmp
              )
        ")->execute([$keep_id, $merge_id, $keep_id]);

        $pdo->prepare("DELETE FROM match_results WHERE player_id = ?")
            ->execute([$merge_id]);
        $pdo->prepare("DELETE FROM players WHERE id = ?")
            ->execute([$merge_id]);

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Delete player ─────────────────────────────────────────────────────────
    if ($action === 'delete_player') {
        $id = (int)($data['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM match_results WHERE player_id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['error' => 'Player has match results — merge instead of deleting']);
            exit;
        }
        $pdo->prepare("DELETE FROM players WHERE id = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Delete match ──────────────────────────────────────────────────────────
    if ($action === 'delete_match') {
        $match_id = (int)($data['match_id'] ?? 0);
        if (!$match_id) {
            http_response_code(400);
            echo json_encode(['error' => 'match_id required']);
            exit;
        }
        $pdo->prepare("DELETE FROM match_results WHERE match_id = ?")->execute([$match_id]);
        $pdo->prepare("DELETE FROM matches WHERE id = ?")->execute([$match_id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Save match results (creates the match row if it doesn't exist yet) ────
    $year         = (int)($data['year']         ?? 0);
    $round_number = (int)($data['round_number'] ?? 0);
    $match_number = (int)($data['match_number'] ?? 0);
    $winner       = $data['winner']  ?? '';
    $ups_value    = (int)($data['ups'] ?? 0);
    $players      = $data['players'] ?? [];

    if (!$year || !$round_number || !$match_number || !$players || !$winner) {
        http_response_code(400);
        echo json_encode(['error' => 'year, round_number, match_number, winner, players required']);
        exit;
    }

    // Look up existing match row
    $stmt = $pdo->prepare("
        SELECT m.id FROM matches m
        JOIN rounds r ON r.id = m.round_id
        JOIN tournaments t ON t.id = r.tournament_id
        WHERE t.year = ? AND r.round_number = ? AND m.match_number = ?
    ");
    $stmt->execute([$year, $round_number, $match_number]);
    $match = $stmt->fetch();

    if (!$match) {
        // Match row missing — find the round and create it
        $stmt2 = $pdo->prepare("
            SELECT r.id FROM rounds r
            JOIN tournaments t ON t.id = r.tournament_id
            WHERE t.year = ? AND r.round_number = ?
        ");
        $stmt2->execute([$year, $round_number]);
        $round_row = $stmt2->fetch();
        if (!$round_row) {
            http_response_code(404);
            echo json_encode(['error' => "Round not found: year=$year round=$round_number"]);
            exit;
        }
        $pdo->prepare("INSERT INTO matches (round_id, match_number) VALUES (?, ?)")
            ->execute([$round_row['id'], $match_number]);
        $match_id = (int)$pdo->lastInsertId();
    } else {
        $match_id = (int)$match['id'];
    }

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

    $pdo->prepare("DELETE FROM match_results WHERE match_id = ?")->execute([$match_id]);

    $stmt = $pdo->prepare("
        INSERT INTO match_results (match_id, player_id, points, ups, partner_id)
        VALUES (?, ?, ?, ?, ?)
    ");
    foreach ($resolved as $r) {
        $stmt->execute($r);
    }

    // Return match_id so the UI can update the card's dataset for subsequent deletes
    echo json_encode(['ok' => true, 'match_id' => $match_id]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
