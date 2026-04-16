<?php
// GET /php/results.php           -> all years: team totals
// GET /php/results.php?year=2024 -> one year: full match detail
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/db_migrate.php';
run_migrations($pdo);

$year = isset($_GET['year']) ? (int)$_GET['year'] : null;

if ($year) {
    $stmt = $pdo->prepare("
        SELECT
            r.round_number,
            r.format,
            m.id           AS match_id,
            m.match_number,
            p.id           AS player_id,
            p.name         AS player_name,
            p.team,
            mr.points,
            mr.ups,
            partner.name   AS partner_name
        FROM tournaments t
        JOIN rounds r          ON r.tournament_id = t.id
        JOIN matches m         ON m.round_id = r.id
        JOIN match_results mr  ON mr.match_id = m.id
        JOIN players p         ON p.id = mr.player_id
        LEFT JOIN players partner ON partner.id = mr.partner_id
        WHERE t.year = ?
        ORDER BY r.round_number, m.match_number, p.team, p.name
    ");
    $stmt->execute([$year]);
    echo json_encode($stmt->fetchAll());
} else {
    $stmt = $pdo->query("
        SELECT
            t.year,
            p.team,
            SUM(mr.points) AS team_points,
            SUM(mr.ups)    AS team_ups
        FROM tournaments t
        JOIN rounds r         ON r.tournament_id = t.id
        JOIN matches m        ON m.round_id = r.id
        JOIN match_results mr ON mr.match_id = m.id
        JOIN players p        ON p.id = mr.player_id
        GROUP BY t.year, p.team
        ORDER BY t.year ASC
    ");
    $rows = $stmt->fetchAll();
    $out  = [];
    foreach ($rows as $row) {
        $y = $row['year'];
        if (!isset($out[$y])) $out[$y] = [];
        $out[$y][$row['team']] = [
            'points' => (int)$row['team_points'],
            'ups'    => (int)$row['team_ups'],
        ];
    }
    echo json_encode($out);
}
