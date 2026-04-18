<?php
// GET /php/player_stats.php?id=1
//   -> career totals + year-by-year + match details
//      + format record + head-to-head (opponents) + partners record
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/db_migrate.php';
run_migrations($pdo);

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

// DB stores points doubled (2=win, 1=halved, 0=loss); divide by 2 for display

// ── Career totals ────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        p.id, p.name, p.team, p.active,
        COUNT(mr.id)                                          AS matches_played,
        COALESCE(SUM(mr.points), 0) / 2                      AS total_points,
        COALESCE(SUM(CASE WHEN mr.points=2 THEN 1 END), 0)   AS wins,
        COALESCE(SUM(CASE WHEN mr.points=1 THEN 1 END), 0)   AS halves,
        COALESCE(SUM(CASE WHEN mr.points=0 THEN 1 END), 0)   AS losses,
        COALESCE(SUM(mr.ups), 0)                              AS total_ups,
        ROUND(COALESCE(SUM(mr.points),0) / NULLIF(COUNT(mr.id),0) / 2, 3) AS avg_points
    FROM players p
    LEFT JOIN match_results mr ON mr.player_id = p.id
    WHERE p.id = ?
    GROUP BY p.id
");
$stmt->execute([$id]);
$player = $stmt->fetch();
if (!$player) { http_response_code(404); echo json_encode(['error' => 'Player not found']); exit; }

// ── Year-by-year summary (most recent first) ────────────────────────────────
$stmt2 = $pdo->prepare("
    SELECT
        t.year,
        COUNT(mr.id)                                       AS matches,
        SUM(mr.points) / 2                                 AS points,
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
    ORDER BY t.year DESC
");
$stmt2->execute([$id]);
$player['years'] = $stmt2->fetchAll();

// ── Per-match details: partner + opponents per match ────────────────────────
$stmt3 = $pdo->prepare("
    SELECT
        t.year,
        m.id            AS match_id,
        m.match_number,
        r.round_number,
        r.format,
        mr.points,
        mr.ups,
        GROUP_CONCAT(DISTINCT CASE WHEN p2.team =  pl.team AND p2.id != pl.id THEN p2.name END) AS partners,
        GROUP_CONCAT(DISTINCT CASE WHEN p2.team != pl.team                     THEN p2.name END) AS opponents
    FROM match_results mr
    JOIN matches m      ON m.id  = mr.match_id
    JOIN rounds r       ON r.id  = m.round_id
    JOIN tournaments t  ON t.id  = r.tournament_id
    JOIN players pl     ON pl.id = mr.player_id
    LEFT JOIN match_results mr2 ON mr2.match_id = m.id AND mr2.player_id != mr.player_id
    LEFT JOIN players p2        ON p2.id = mr2.player_id
    WHERE mr.player_id = ?
    GROUP BY t.year, m.id, m.match_number, r.round_number, r.format, mr.points, mr.ups
    ORDER BY t.year ASC, r.round_number ASC, m.match_number ASC
");
$stmt3->execute([$id]);

$byYear = [];
foreach ($stmt3->fetchAll() as $md) {
    $byYear[$md['year']][] = [
        'match_id'     => (int)$md['match_id'],
        'match_number' => (int)$md['match_number'],
        'round_number' => (int)$md['round_number'],
        'format'       => $md['format'] ?? '',
        'points'       => (int)$md['points'],
        'ups'          => (int)$md['ups'],
        'partners'     => $md['partners'] ? explode(',', $md['partners']) : [],
        'opponents'    => $md['opponents'] ? explode(',', $md['opponents']) : [],
    ];
}
foreach ($player['years'] as &$yr) {
    $yr['match_details'] = $byYear[$yr['year']] ?? [];
}
unset($yr);

// ── Record by match format ───────────────────────────────────────────────
$stmt4 = $pdo->prepare("
    SELECT
        r.format,
        COUNT(mr.id)                                             AS matches,
        COALESCE(SUM(CASE WHEN mr.points=2 THEN 1 END), 0)      AS wins,
        COALESCE(SUM(CASE WHEN mr.points=1 THEN 1 END), 0)      AS halves,
        COALESCE(SUM(CASE WHEN mr.points=0 THEN 1 END), 0)      AS losses
    FROM match_results mr
    JOIN matches m ON m.id = mr.match_id
    JOIN rounds r  ON r.id = m.round_id
    WHERE mr.player_id = ? AND mr.points IS NOT NULL
    GROUP BY r.format
    ORDER BY
        CASE r.format
            WHEN 'fourball'  THEN 1
            WHEN 'greensome' THEN 2
            WHEN 'foursome'  THEN 3
            WHEN 'singles'   THEN 4
            ELSE 5
        END
");
$stmt4->execute([$id]);
$player['format_record'] = array_map(function($r) {
    return [
        'format'  => $r['format'],
        'matches' => (int)$r['matches'],
        'wins'    => (int)$r['wins'],
        'halves'  => (int)$r['halves'],
        'losses'  => (int)$r['losses'],
    ];
}, $stmt4->fetchAll(PDO::FETCH_ASSOC));

// Helper to normalise a people-list row to {id, name, played, wins, halves, losses}
function normPeople($id_key, $name_key) {
    return function($r) use ($id_key, $name_key) {
        return [
            'id'     => (int)$r[$id_key],
            'name'   => $r[$name_key],
            'played' => (int)$r['played'],
            'wins'   => (int)$r['wins'],
            'halves' => (int)$r['halves'],
            'losses' => (int)$r['losses'],
        ];
    };
}

// ── Head-to-head vs each opponent ───────────────────────────────────────
// Each opponent appearance is counted individually (fourball opponents each
// get one record per match, same as singles).
$stmt5 = $pdo->prepare("
    SELECT
        opp.id   AS opp_id,
        opp.name AS opp_name,
        COUNT(*)                                                 AS played,
        COALESCE(SUM(CASE WHEN mr.points=2 THEN 1 END), 0)      AS wins,
        COALESCE(SUM(CASE WHEN mr.points=1 THEN 1 END), 0)      AS halves,
        COALESCE(SUM(CASE WHEN mr.points=0 THEN 1 END), 0)      AS losses
    FROM match_results mr
    JOIN players me           ON me.id  = mr.player_id
    JOIN match_results mr_opp ON mr_opp.match_id  = mr.match_id
                              AND mr_opp.player_id != mr.player_id
    JOIN players opp          ON opp.id = mr_opp.player_id
                              AND opp.team != me.team
    WHERE mr.player_id = ? AND mr.points IS NOT NULL
    GROUP BY opp.id, opp.name
    ORDER BY played DESC, wins DESC
");
$stmt5->execute([$id]);
$player['head_to_head'] = array_map(normPeople('opp_id', 'opp_name'), $stmt5->fetchAll(PDO::FETCH_ASSOC));

// ── Partnership record (same-team players in shared matches) ─────────────
// Singles matches have no partner, so they won't appear here.
$stmt6 = $pdo->prepare("
    SELECT
        partner.id   AS partner_id,
        partner.name AS partner_name,
        COUNT(*)                                                 AS played,
        COALESCE(SUM(CASE WHEN mr.points=2 THEN 1 END), 0)      AS wins,
        COALESCE(SUM(CASE WHEN mr.points=1 THEN 1 END), 0)      AS halves,
        COALESCE(SUM(CASE WHEN mr.points=0 THEN 1 END), 0)      AS losses
    FROM match_results mr
    JOIN players me          ON me.id  = mr.player_id
    JOIN match_results mr_p  ON mr_p.match_id  = mr.match_id
                             AND mr_p.player_id != mr.player_id
    JOIN players partner     ON partner.id = mr_p.player_id
                             AND partner.team = me.team
    WHERE mr.player_id = ? AND mr.points IS NOT NULL
    GROUP BY partner.id, partner.name
    ORDER BY played DESC, wins DESC
");
$stmt6->execute([$id]);
$player['partners_record'] = array_map(normPeople('partner_id', 'partner_name'), $stmt6->fetchAll(PDO::FETCH_ASSOC));

echo json_encode($player);
