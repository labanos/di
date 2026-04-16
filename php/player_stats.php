<?php
// GET /php/player_stats.php?id=1 -> career totals + year-by-year for one player
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/db_migrate.php';
run_migrations($pdo);

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

$stmt = $pdo->prepare("
    SELECT
        p.id, p.name, p.team, p.active,
        COUNT(mr.id)                                          AS matches_played,
        COALESCE(SUM(mr.points), 0)                           AS total_points,
        COALESCE(SUM(CASE WHEN mr.points=2 THEN 1 END), 0)   AS wins,
        COALESCE(SUM(CASE WHEN mr.points=1 THEN 1 END), 0)   AS halves,
        COALESCE(SUM(CASE WHEN mr.points=0 THEN 1 END), 0)   AS losses,
        COALESCE(SUM(mr.ups), 0)                              AS total_ups,
        ROUND(COALESCE(SUM(mr.points),0) / NULLIF(COUNT(mr.id),0), 3) AS avg_points
    FROM players p
    LEFT JOIN match_results mr ON mr.player_id = p.id
    WHERE p.id = ?
    GROUP BY p.id
");
$stmt->execute([$id]);
$player = $stmt->fetch();
if (!$player) { http_response_code(404); echo json_encode(['error' => 'Player not found']); exit; }

$stmt = $pdo->prepare("
    SELECT
        t.year,
        COUNT(mr.id)                                       AS matches,
        SUM(mr.points)                                     AS points,
        SUM(CASE WHEN mr.points=2 THEN 1 END)              AS wins,
        SUM(CASE WHEN mr.points=1 THEN 1 END)              AS halves,
        SUM(CASE WHEN mr.points=0 THEN 1 END)              AS losses,
        SUM(mr.ups)                                        AS ups
    FROM match_results mr
    JOIN matches m     ON m.id = mr.match_id
    JOIN rounds r      ON r.id = m.round_id
    JOIN tournaments t ON t.id = r.tournament_id
    WHERE mr.player_id = ?
    GROUP BY t.year
    ORDER BY t.year ASC
");
$stmt->execute([$id]);
$player['years'] = $stmt->fetchAll();

echo json_encode($player);
