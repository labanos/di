<?php
// Captain's "suggest best opponent" tool.
//
// Given a locked team + 1-2 players + format, enumerate every valid opposing
// lineup and rank by win probability against the locked pair.
//
// GET /php/pair_suggest.php?format=fourball&locked_team=red&locked_ids=3,7&limit=10&rank=best
//
// rank=best  (default): return the opposing lineups with the HIGHEST win
//                       probability against the locked pair (hardest matchups).
// rank=worst         : return the opposing lineups with the LOWEST win
//                       probability against the locked pair (weakest opponents).
//
// Response shape:
// {
//   format, locked_team, opposing_team, rank,
//   locked_players: [{id,name}], locked_pair_strength, locked_partner_rate,
//   candidates_evaluated: N,
//   suggestions: [
//     { player_ids, players, win_probability, pair_strength, partner_rate, partner_sample, h2h_rate, h2h_sample },
//     ... top N, sorted by win_probability (desc for best, asc for worst)
//   ]
// }
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/db_migrate.php';
run_migrations($pdo);

// ── Parse input ───────────────────────────────────────────────────────────────────────
$format      = $_GET['format']      ?? '';
$locked_team = $_GET['locked_team'] ?? '';
$locked_ids  = array_filter(array_map('intval', explode(',', $_GET['locked_ids'] ?? '')));
$limit       = max(1, min(50, intval($_GET['limit'] ?? 10)));
$rank        = strtolower($_GET['rank'] ?? 'best');
if (!in_array($rank, ['best','worst'], true)) $rank = 'best';

$valid_formats = ['fourball','greensome','foursome','singles'];
if (!in_array($format, $valid_formats, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'format must be one of: ' . implode(',', $valid_formats)]);
    exit;
}
if (!in_array($locked_team, ['blue','red'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'locked_team must be blue or red']);
    exit;
}
$expected = ($format === 'singles') ? 1 : 2;
$locked_ids = array_values(array_unique(array_map('intval', $locked_ids)));
if (count($locked_ids) !== $expected) {
    http_response_code(400);
    echo json_encode(['error' => "$format needs $expected locked player(s)"]);
    exit;
}
$opposing_team = ($locked_team === 'blue') ? 'red' : 'blue';

// ── Validate locked players (must be on locked team) ──────────────────────────────────────────────────
$place_locked = implode(',', array_fill(0, count($locked_ids), '?'));
$stmt = $pdo->prepare("SELECT id, name, team FROM players WHERE id IN ($place_locked)");
$stmt->execute($locked_ids);
$locked_players = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
    if ($p['team'] !== $locked_team) {
        http_response_code(400);
        echo json_encode(['error' => $p['name'] . " is not on the $locked_team team"]);
        exit;
    }
    $locked_players[(int)$p['id']] = ['id' => (int)$p['id'], 'name' => $p['name']];
}
if (count($locked_players) !== count($locked_ids)) {
    http_response_code(404);
    echo json_encode(['error' => 'One or more locked players not found']);
    exit;
}

// ── Opposing roster ──────────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id, name FROM players WHERE team = ? ORDER BY name");
$stmt->execute([$opposing_team]);
$opposing_players = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $opposing_players[(int)$p['id']] = ['id' => (int)$p['id'], 'name' => $p['name']];
}
$opposing_ids = array_keys($opposing_players);
if (count($opposing_ids) < $expected) {
    http_response_code(400);
    echo json_encode(['error' => "Not enough players on $opposing_team team for this format"]);
    exit;
}

// ── Helpers ──────────────────────────────────────────────────────────────────────────────────────
function smooth_rate($w, $h, $l, $k = 4) {
    $n = $w + $h + $l;
    return ($w + 0.5 * $h + $k * 0.5) / ($n + $k);
}
function enrich_record($rec) {
    $rec = $rec ?: ['matches'=>0,'wins'=>0,'halves'=>0,'losses'=>0];
    $rec['rate'] = round(smooth_rate($rec['wins'], $rec['halves'], $rec['losses']), 4);
    return $rec;
}

// ── Per-player format record (locked + opposing) ────────────────────────────────────────────────────────
$all_ids = array_merge($locked_ids, $opposing_ids);
$place_all = implode(',', array_fill(0, count($all_ids), '?'));
$stmt = $pdo->prepare("
    SELECT mr.player_id,
           COUNT(*)                                     AS matches,
           SUM(CASE WHEN mr.points=2 THEN 1 ELSE 0 END) AS wins,
           SUM(CASE WHEN mr.points=1 THEN 1 ELSE 0 END) AS halves,
           SUM(CASE WHEN mr.points=0 THEN 1 ELSE 0 END) AS losses
    FROM match_results mr
    JOIN matches m ON m.id = mr.match_id
    JOIN rounds r  ON r.id = m.round_id
    WHERE mr.player_id IN ($place_all)
      AND r.format = ?
      AND mr.points IS NOT NULL
    GROUP BY mr.player_id
");
$stmt->execute(array_merge($all_ids, [$format]));
$format_record_by_id = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $format_record_by_id[(int)$row['player_id']] = [
        'matches' => (int)$row['matches'],
        'wins'    => (int)$row['wins'],
        'halves'  => (int)$row['halves'],
        'losses'  => (int)$row['losses'],
    ];
}

// Locked pair strength (fixed across all candidates)
$locked_strengths = [];
foreach ($locked_ids as $id) {
    $locked_strengths[] = enrich_record($format_record_by_id[$id] ?? null)['rate'];
}
$locked_pair_strength = array_sum($locked_strengths) / count($locked_strengths);

// Locked partner rate (2v2 only)
$locked_partner_rate = 0.5;
$locked_partner_matches = 0;
if ($format !== 'singles') {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)                                     AS matches,
               SUM(CASE WHEN mr.points=2 THEN 1 ELSE 0 END) AS wins,
               SUM(CASE WHEN mr.points=1 THEN 1 ELSE 0 END) AS halves,
               SUM(CASE WHEN mr.points=0 THEN 1 ELSE 0 END) AS losses
        FROM match_results mr
        JOIN matches m ON m.id = mr.match_id
        JOIN rounds r  ON r.id = m.round_id
        WHERE mr.player_id = ? AND mr.partner_id = ?
          AND r.format = ?
          AND mr.points IS NOT NULL
    ");
    $stmt->execute([$locked_ids[0], $locked_ids[1], $format]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $lp = enrich_record([
        'matches' => (int)$row['matches'],
        'wins'    => (int)$row['wins'],
        'halves'  => (int)$row['halves'],
        'losses'  => (int)$row['losses'],
    ]);
    $locked_partner_rate    = $lp['rate'];
    $locked_partner_matches = $lp['matches'];
}

// ── Partner records within opposing team, this format (2v2 only) ─────────────
$opposing_partner = [];  // [a_id][b_id] = enriched rec
if ($format !== 'singles') {
    $place_opp = implode(',', array_fill(0, count($opposing_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT mr.player_id, mr.partner_id,
               COUNT(*)                                     AS matches,
               SUM(CASE WHEN mr.points=2 THEN 1 ELSE 0 END) AS wins,
               SUM(CASE WHEN mr.points=1 THEN 1 ELSE 0 END) AS halves,
               SUM(CASE WHEN mr.points=0 THEN 1 ELSE 0 END) AS losses
        FROM match_results mr
        JOIN matches m ON m.id = mr.match_id
        JOIN rounds r  ON r.id = m.round_id
        WHERE mr.player_id IN ($place_opp)
          AND mr.partner_id IN ($place_opp)
          AND r.format = ?
          AND mr.points IS NOT NULL
        GROUP BY mr.player_id, mr.partner_id
    ");
    $stmt->execute(array_merge($opposing_ids, $opposing_ids, [$format]));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $a = (int)$row['player_id']; $b = (int)$row['partner_id'];
        $rec = enrich_record([
            'matches' => (int)$row['matches'],
            'wins'    => (int)$row['wins'],
            'halves'  => (int)$row['halves'],
            'losses'  => (int)$row['losses'],
        ]);
        $opposing_partner[$a][$b] = $rec;
    }
}

// ── H2H: opposing player vs locked player, this format ───────────────────────────
$place_opp = implode(',', array_fill(0, count($opposing_ids), '?'));
$stmt = $pdo->prepare("
    SELECT mr.player_id  AS opp_id,
           opp.player_id AS locked_id,
           COUNT(*)                                     AS played,
           SUM(CASE WHEN mr.points=2 THEN 1 ELSE 0 END) AS opp_wins,
           SUM(CASE WHEN mr.points=1 THEN 1 ELSE 0 END) AS halves,
           SUM(CASE WHEN mr.points=0 THEN 1 ELSE 0 END) AS opp_losses
    FROM match_results mr
    JOIN match_results opp ON opp.match_id = mr.match_id
                           AND opp.player_id != mr.player_id
    JOIN matches m ON m.id = mr.match_id
    JOIN rounds r  ON r.id = m.round_id
    WHERE mr.player_id IN ($place_opp)
      AND opp.player_id IN ($place_locked)
      AND r.format = ?
      AND mr.points IS NOT NULL
    GROUP BY mr.player_id, opp.player_id
");
$stmt->execute(array_merge($opposing_ids, $locked_ids, [$format]));
$h2h_cell = [];  // [opp_id][locked_id] = {wins, halves, losses, played}
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $h2h_cell[(int)$row['opp_id']][(int)$row['locked_id']] = [
        'played' => (int)$row['played'],
        'wins'   => (int)$row['opp_wins'],
        'halves' => (int)$row['halves'],
        'losses' => (int)$row['opp_losses'],
    ];
}

// ── Enumerate candidate lineups ──────────────────────────────────────────────────────────
$candidates = [];
if ($format === 'singles') {
    foreach ($opposing_ids as $id) { $candidates[] = [$id]; }
} else {
    $n = count($opposing_ids);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $candidates[] = [$opposing_ids[$i], $opposing_ids[$j]];
        }
    }
}

// ── Score each candidate ────────────────────────────────────────────────────────────────────
$suggestions = [];
foreach ($candidates as $cand_ids) {
    // Pair strength
    $strengths = [];
    foreach ($cand_ids as $id) {
        $strengths[] = enrich_record($format_record_by_id[$id] ?? null)['rate'];
    }
    $cand_pair_strength = array_sum($strengths) / count($strengths);

    // Chemistry (2v2)
    $cand_partner_rate   = 0.5;
    $cand_partner_sample = 0;
    if ($format !== 'singles') {
        $a = $cand_ids[0]; $b = $cand_ids[1];
        $rec = $opposing_partner[$a][$b] ?? $opposing_partner[$b][$a] ?? enrich_record(null);
        $cand_partner_rate   = $rec['rate'];
        $cand_partner_sample = $rec['matches'];
    }

    // H2H aggregate (candidate's perspective = opposing wins)
    $tw = 0; $th = 0; $tl = 0; $tp = 0;
    foreach ($cand_ids as $oid) {
        foreach ($locked_ids as $lid) {
            $c = $h2h_cell[$oid][$lid] ?? null;
            if ($c) { $tw += $c['wins']; $th += $c['halves']; $tl += $c['losses']; $tp += $c['played']; }
        }
    }
    $h2h_rate = smooth_rate($tw, $th, $tl, 4);

    // Scoring (same weights as pairing_analysis.php)
    if ($format === 'singles') {
        $alpha = 0.6; $beta = 0.0; $gamma = 0.4;
        $chem_diff = 0.0;
    } else {
        $alpha = 0.5; $beta = 0.25; $gamma = 0.25;
        $chem_diff = ($cand_partner_rate - 0.5) - ($locked_partner_rate - 0.5);
    }
    $strength_diff = $cand_pair_strength - $locked_pair_strength;
    $h2h_diff      = $h2h_rate - 0.5;

    $win_prob = 0.5 + $alpha * $strength_diff + $beta * $chem_diff + $gamma * $h2h_diff;
    $win_prob = max(0.05, min(0.95, $win_prob));

    $entry = [
        'player_ids'      => $cand_ids,
        'players'         => array_map(function($id) use ($opposing_players) { return $opposing_players[$id]; }, $cand_ids),
        'win_probability' => round($win_prob, 3),
        'pair_strength'   => round($cand_pair_strength, 3),
        'h2h_rate'        => round($h2h_rate, 3),
        'h2h_sample'      => $tp,
    ];
    if ($format !== 'singles') {
        $entry['partner_rate']   = round($cand_partner_rate, 3);
        $entry['partner_sample'] = $cand_partner_sample;
    }
    $suggestions[] = $entry;
}

// Sort by win probability (desc for best, asc for worst); break ties by
// pair_strength (same direction as win_prob), then by h2h_sample (always desc
// — more evidence ranks higher either way).
if ($rank === 'worst') {
    usort($suggestions, function($a, $b) {
        if ($a['win_probability'] !== $b['win_probability']) return $a['win_probability'] <=> $b['win_probability'];
        if ($a['pair_strength']   !== $b['pair_strength'])   return $a['pair_strength']   <=> $b['pair_strength'];
        return $b['h2h_sample'] <=> $a['h2h_sample'];
    });
} else {
    usort($suggestions, function($a, $b) {
        if ($b['win_probability'] !== $a['win_probability']) return $b['win_probability'] <=> $a['win_probability'];
        if ($b['pair_strength']   !== $a['pair_strength'])   return $b['pair_strength']   <=> $a['pair_strength'];
        return $b['h2h_sample'] <=> $a['h2h_sample'];
    });
}
$total_candidates = count($suggestions);
$suggestions = array_slice($suggestions, 0, $limit);

echo json_encode([
    'format'                => $format,
    'locked_team'           => $locked_team,
    'opposing_team'         => $opposing_team,
    'rank'                  => $rank,
    'locked_players'        => array_map(function($id) use ($locked_players) { return $locked_players[$id]; }, $locked_ids),
    'locked_pair_strength'  => round($locked_pair_strength, 3),
    'locked_partner_rate'   => $format !== 'singles' ? round($locked_partner_rate, 3) : null,
    'locked_partner_sample' => $format !== 'singles' ? $locked_partner_matches : null,
    'candidates_evaluated'  => $total_candidates,
    'suggestions'           => $suggestions,
], JSON_UNESCAPED_UNICODE);
