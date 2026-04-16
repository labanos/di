<?php
// GET  /php/matches.php?year=2024[&round=1]  -> match results for a year/round
// POST /php/matches.php                       -> upsert a match result (admin)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/db_migrate.php';
run_migrations($pdo);

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $year  = (int)($_GET['year']  ?? 0);
    $round = (int)($_GET['round'] ?? 0);
    if (!$year) { http_response_code(400); echo json_encode(['error' => 'year required']); exit; }
    $sql = "
        SELECT
            t.year, r.round_number, r.format,
            m.id AS match_id, m.match_number,
            p.id AS player_id, p.name AS player_name, p.team,
            mr.points, mr.ups,
            partner.name AS partner_name
        FROM tournaments t
        JOIN rounds r         ON r.tournament_id = t.id
        JOIN matches m        ON m.round_id = r.id
        JOIN match_results mr ON mr.match_id = m.id
        JOIN players p        ON p.id = mr.player_id
        LEFT JOIN players partner ON partner.id = mr.partner_id
        WHERE t.year = ?
    ";
    $params = [$year];
    if ($round) { $sql .= " AND r.round_number = ?"; $params[] = $round; }
    $sql .= " ORDER BY r.round_number, m.match_number, p.team";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($method === 'POST') {
    $data      = json_decode(file_get_contents('php://input'), true);
    $matchId   = (int)($data['match_id']   ?? 0);
    $playerId  = (int)($data['player_id']  ?? 0);
    $points    = (int)($data['points']     ?? 0);
    $ups       = (int)($data['ups']        ?? 0);
    $partnerId = isset($data['partner_id']) ? (int)$data['partner_id'] : null;
    if (!$matchId || !$playerId) {
        http_response_code(400); echo json_encode(['error' => 'match_id and player_id required']); exit;
    }
    $pdo->prepare("
        INSERT INTO match_results (match_id, player_id, points, ups, partner_id)
        VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE points=VALUES(points), ups=VALUES(ups), partner_id=VALUES(partner_id)
    ")->execute([$matchId, $playerId, $points, $ups, $partnerId]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405); echo json_encode(['error' => 'Method not allowed']);
