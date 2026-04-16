<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/db_migrate.php';
run_migrations($pdo);

// All-time leaderboard — sorted by total_points, then avg, then total_ups as tiebreaker
$stmt = $pdo->query("
    SELECT
        p.id,
        p.name,
        p.team,
        p.active,
        COUNT(mr.id)                                            AS matches_played,
        COALESCE(SUM(mr.points), 0)                             AS total_points,
        COALESCE(SUM(CASE WHEN mr.points = 2 THEN 1 END), 0)   AS wins,
        COALESCE(SUM(CASE WHEN mr.points = 1 THEN 1 END), 0)   AS halves,
        COALESCE(SUM(CASE WHEN mr.points = 0 THEN 1 END), 0)   AS losses,
        COALESCE(SUM(mr.ups), 0)                                AS total_ups,
        ROUND(
            COALESCE(SUM(mr.points), 0) / NULLIF(COUNT(mr.id), 0),
        3)                                                      AS avg_points
    FROM players p
    LEFT JOIN match_results mr ON mr.player_id = p.id
    GROUP BY p.id
    ORDER BY total_points DESC, avg_points DESC, total_ups DESC
");

echo json_encode($stmt->fetchAll());
