<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/db_migrate.php';
run_migrations($pdo);

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && isset($_GET['_method'])) {
    $o = strtoupper($_GET['_method']);
    if ($o === 'PUT') $method = $o;
}

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT id, name, team, active FROM players ORDER BY team, name");
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $name = trim($data['name'] ?? '');
    $team = $data['team'] ?? '';
    if (!$name || !in_array($team, ['blue','red'])) {
        http_response_code(400); echo json_encode(['error' => 'name and team (blue|red) required']); exit;
    }
    try {
        $pdo->prepare("INSERT INTO players (name, team) VALUES (?,?)")->execute([$name, $team]);
        $id = (int)$pdo->lastInsertId();
        echo json_encode(['id' => $id, 'name' => $name, 'team' => $team, 'active' => 1]);
    } catch (PDOException $e) {
        http_response_code(409); echo json_encode(['error' => 'Player already exists']);
    }
    exit;
}

if ($method === 'PUT') {
    $id   = (int)($_GET['id'] ?? 0);
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
    $updates = []; $params = [];
    if (isset($data['name']))   { $updates[] = 'name = ?';   $params[] = trim($data['name']); }
    if (isset($data['team']))   { $updates[] = 'team = ?';   $params[] = $data['team']; }
    if (isset($data['active'])) { $updates[] = 'active = ?'; $params[] = (int)$data['active']; }
    if (!$updates) { http_response_code(400); echo json_encode(['error' => 'nothing to update']); exit; }
    $params[] = $id;
    $pdo->prepare("UPDATE players SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405); echo json_encode(['error' => 'Method not allowed']);
