<?php
// Captain's pairing assistant — evaluates a proposed matchup using historical data.
//
// GET  /php/pairing_analysis.php?format=fourball&blue_ids=1,2&red_ids=7,9
// POST /php/pairing_analysis.php with JSON { format, blue_ids:[], red_ids:[] }
//
// For 1v1 (singles), pass a single id in blue_ids/red_ids.
// For 2v2 formats (fourball/greensome/foursome), pass exactly two ids per side.
//
// Returns: format record per player, partner chemistry (2v2), full h2h matrix,
// and a smoothed "blue_win_probability" in [0.05, 0.95] built from three factors:
//   50%/60% format-strength differential
//   25%       partner-chemistry differential   (2v2 only)
//   25%/40%   direct head-to-head history
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/db_migrate.php';
run_migrations($pdo);

// ── Parse input ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $format    = $body['format']    ?? '';
    $blue_ids  = $body['blue_ids']  ?? [];
    $red_ids   = $body['red_ids']   ?? [];
} else {
    $format   = $_GET['format'] ?? '';
    $blue_ids = array_filter(array_map('intval', explode(',', $_GET['blue_ids'] ?? '')));
    $red_ids  = array_filter(array_map('intval', explode(',', $_GET['red_ids']  ?? '')));
}

$valid_formats = ['fourball','greensome','foursome','singles'];
if (!in_array($format, $valid_formats, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'format must be one of: ' . implode(',', $valid_formats)]);
    exit;
}

$blue_ids = array_values(array_unique(array_map('intval', $blue_ids)));
$red_ids  = array_values(array_unique(array_map('intval', $red_ids)));
$expected = ($format === 'singles') ? 1 : 2;
if (count($blue_ids) !== $expected || count($red_ids) !== $expected) {
    http_response_code(400);
    echo json_encode(['error' => "This format needs $expected player(s) per side"]);
    exit;
}
if (array_intersect($blue_ids, $red_ids)) {
    http_response_code(400);
    echo json_encode(['error' => 'A player cannot appear on both teams']);
    exit;
}

// ── Fetch players (names + teams) and validate they're on the correct teams ──
$all_ids = array_merge($blue_ids, $red_ids);
$place   = implode(',', array_fill(0, count($all_ids), '?'));
$stmt = $pdo->prepare("SELECT id, name, team FROM players WHERE id IN ($place)");
$stmt->execute($all_ids);
$players_by_id = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $players_by_id[(int)$p['id']] = ['id' => (int)$p['id'], 'name' => $p['name'], 'team' => $p['team']];
}
foreach ($blue_ids as $id) {
    if (!isset($players_by_id[$id])) { http_response_code(404); echo json_encode(['error' => "Player $id not found"]); exit; }
    if ($players_by_id[$id]['team'] !== 'blue') { http_response_code(400); echo json_encode(['error' => $players_by_id[$id]['name'] . ' is not on the blue team']); exit; }
}
foreach ($red_ids as $id) {
    if (!isset($players_by_id[$id])) { http_response_code(404); echo json_encode(['error' => "Player $id not found"]); exit; }
    if ($players_by_id[$id]['team'] !== 'red') { http_response_code(400); echo json_encode(['error' => $players_by_id[$id]['name'] . ' is not on the red team']); exit; }
}

// ── Helper: Laplace-smoothed win rate (halves = 0.5) ─────────────────────────
// prior k of neutral (0.5) observations pulls small samples toward 0.5
function smooth_rate($w, $h, $l, $k = 4) {
    $n = $w + $h + $l;
    return ($w + 0.5 * $h + $k * 0.5) / ($n + $k);
}

// ── Per-player format record ─────────────────────────────────────────────────
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
function enrich_record($rec) {
    $rec = $rec ?: ['matches'=>0,'wins'=>0,'halves'=>0,'losses'=>0];
    $rec['rate']       = round(smooth_rate($rec['wins'], $rec['halves'], $rec['losses']), 4);
    $rec['raw_rate']   = $rec['matches'] > 0
        ? round(($rec['wins'] + 0.5 * $rec['halves']) / $rec['matches'], 4)
        : null;
    return $rec;
}

// ── Partner chemistry (2v2 only, in this format) ─────────────────────────────
function partner_record($pdo, $a_id, $b_id, $format) {
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
    $stmt->execute([$a_id, $b_id, $format]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return enrich_record([
        'matches' => (int)$row['matches'],
        'wins'    => (int)$row['wins'],
        'halves'  => (int)$row['halves'],
        'losses'  => (int)$row['losses'],
    ]);
}

$blue_partner = null; $red_partner = null;
if ($format !== 'singles') {
    $blue_partner = partner_record($pdo, $blue_ids[0], $blue_ids[1], $format);
    $red_partner  = partner_record($pdo, $red_ids[0],  $red_ids[1],  $format);
}

// ── H2H matrix: blue player vs red player (in this format only) ──────────────
// Counts one row per (blue, red) pairing where they were in the same match.
$bph = implode(',', array_fill(0, count($blue_ids), '?'));
$rph = implode(',', array_fill(0, count($red_ids),  '?'));
$stmt = $pdo->prepare("
    SELECT mr.player_id       AS blue_id,
           opp.player_id       AS red_id,
           COUNT(*)                                     AS played,
           SUM(CASE WHEN mr.points=2 THEN 1 ELSE 0 END) AS blue_wins,
           SUM(CASE WHEN mr.points=1 THEN 1 ELSE 0 END) AS halves,
           SUM(CASE WHEN mr.points=0 THEN 1 ELSE 0 END) AS blue_losses
    FROM match_results mr
    JOIN match_results opp ON opp.match_id = mr.match_id
                           AND opp.player_id != mr.player_id
    JOIN matches m ON m.id = mr.match_id
    JOIN rounds r  ON r.id = m.round_id
    WHERE mr.player_id IN ($bph)
      AND opp.player_id IN ($rph)
      AND r.format = ?
      AND mr.points IS NOT NULL
    GROUP BY mr.player_id, opp.player_id
");
$stmt->execute(array_merge($blue_ids, $red_ids, [$format]));
$h2h_by_pair = [];
$tot_w = 0; $tot_h = 0; $tot_l = 0;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $w = (int)$row['blue_wins']; $h = (int)$row['halves']; $l = (int)$row['blue_losses'];
    $h2h_by_pair[(int)$row['blue_id']][(int)$row['red_id']] = [
        'played' => (int)$row['played'],
        'wins'   => $w,  // from blue's perspective
        'halves' => $h,
        'losses' => $l,
    ];
    $tot_w += $w; $tot_h += $h; $tot_l += $l;
}
// Also pull overall (all formats) h2h as a secondary context signal
$stmt = $pdo->prepare("
    SELECT mr.player_id  AS blue_id,
           opp.player_id AS red_id,
           COUNT(*)                                     AS played,
           SUM(CASE WHEN mr.points=2 THEN 1 ELSE 0 END) AS blue_wins,
           SUM(CASE WHEN mr.points=1 THEN 1 ELSE 0 END) AS halves,
           SUM(CASE WHEN mr.points=0 THEN 1 ELSE 0 END) AS blue_losses
    FROM match_results mr
    JOIN match_results opp ON opp.match_id = mr.match_id
                           AND opp.player_id != mr.player_id
    WHERE mr.player_id IN ($bph)
      AND opp.player_id IN ($rph)
      AND mr.points IS NOT NULL
    GROUP BY mr.player_id, opp.player_id
");
$stmt->execute(array_merge($blue_ids, $red_ids));
$h2h_overall_by_pair = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $h2h_overall_by_pair[(int)$row['blue_id']][(int)$row['red_id']] = [
        'played' => (int)$row['played'],
        'wins'   => (int)$row['blue_wins'],
        'halves' => (int)$row['halves'],
        'losses' => (int)$row['blue_losses'],
    ];
}

// Build matrix rows in requested order
$h2h_matrix = [];
foreach ($blue_ids as $bid) {
    foreach ($red_ids as $rid) {
        $fmt     = $h2h_by_pair[$bid][$rid]         ?? ['played'=>0,'wins'=>0,'halves'=>0,'losses'=>0];
        $overall = $h2h_overall_by_pair[$bid][$rid] ?? ['played'=>0,'wins'=>0,'halves'=>0,'losses'=>0];
        $h2h_matrix[] = [
            'blue_id'     => $bid,
            'blue_name'   => $players_by_id[$bid]['name'],
            'red_id'      => $rid,
            'red_name'    => $players_by_id[$rid]['name'],
            'in_format'   => $fmt,
            'all_formats' => $overall,
        ];
    }
}

// ── Assemble per-team blocks ─────────────────────────────────────────────────
function team_block($ids, $players_by_id, $format_record_by_id, $partner) {
    $out = ['players' => []];
    foreach ($ids as $id) {
        $rec = enrich_record($format_record_by_id[$id] ?? null);
        $out['players'][] = [
            'id'            => $id,
            'name'          => $players_by_id[$id]['name'],
            'format_record' => $rec,
        ];
    }
    $strengths = array_map(function($p) { return $p['format_record']['rate']; }, $out['players']);
    $out['pair_strength'] = count($strengths) ? round(array_sum($strengths) / count($strengths), 4) : 0.5;
    if ($partner) $out['partner_record'] = $partner;
    return $out;
}

$blue = team_block($blue_ids, $players_by_id, $format_record_by_id, $blue_partner);
$red  = team_block($red_ids,  $players_by_id, $format_record_by_id, $red_partner);

// ── Scoring ──────────────────────────────────────────────────────────────────
$h2h_score = smooth_rate($tot_w, $tot_h, $tot_l, 4);

if ($format === 'singles') {
    $alpha = 0.6; $beta = 0.0; $gamma = 0.4;
    $blue_chem = 0.0; $red_chem = 0.0;
} else {
    $alpha = 0.5; $beta = 0.25; $gamma = 0.25;
    $blue_chem = ($blue_partner['rate'] ?? 0.5) - 0.5;
    $red_chem  = ($red_partner['rate']  ?? 0.5) - 0.5;
}

$strength_diff = $blue['pair_strength'] - $red['pair_strength'];
$chem_diff     = $blue_chem - $red_chem;
$h2h_diff      = $h2h_score - 0.5;

$blue_prob = 0.5 + $alpha * $strength_diff + $beta * $chem_diff + $gamma * $h2h_diff;
$blue_prob = max(0.05, min(0.95, $blue_prob));

$factors = [
    [
        'label'  => 'Format strength',
        'weight' => $alpha,
        'blue'   => round($blue['pair_strength'], 3),
        'red'    => round($red['pair_strength'], 3),
        'delta'  => round($strength_diff, 3),
    ],
];
if ($format !== 'singles') {
    $factors[] = [
        'label'  => 'Partner chemistry',
        'weight' => $beta,
        'blue'   => round($blue_partner['rate'], 3),
        'red'    => round($red_partner['rate'], 3),
        'delta'  => round($chem_diff, 3),
        'blue_sample' => $blue_partner['matches'],
        'red_sample'  => $red_partner['matches'],
    ];
}
$factors[] = [
    'label'  => 'Direct head-to-head',
    'weight' => $gamma,
    'blue'   => round($h2h_score, 3),
    'red'    => round(1 - $h2h_score, 3),
    'delta'  => round($h2h_diff, 3),
    'sample' => $tot_w + $tot_h + $tot_l,
];

echo json_encode([
    'format'                => $format,
    'blue'                  => $blue,
    'red'                   => $red,
    'h2h_matrix'            => $h2h_matrix,
    'h2h_totals_in_format'  => ['played' => $tot_w + $tot_h + $tot_l, 'blue_wins' => $tot_w, 'halves' => $tot_h, 'blue_losses' => $tot_l],
    'blue_win_probability'  => round($blue_prob, 3),
    'red_win_probability'   => round(1 - $blue_prob, 3),
    'factors'               => $factors,
], JSON_UNESCAPED_UNICODE);
