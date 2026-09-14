<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$name = isset($input['player_name']) ? substr(trim(strip_tags($input['player_name'])), 0, 32) : '';
if ($name === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_name']);
    exit;
}

try {
    $pdo = db_connect();

    $code = '';
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $code = '';
        for ($i = 0; $i < 4; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
        $check = $pdo->prepare('SELECT id FROM poker_tables WHERE room_code = :c');
        $check->execute([':c' => $code]);
        if (!$check->fetch()) break;
    }

    $pdo->prepare('INSERT INTO poker_tables (room_code, status) VALUES (:code, "waiting")')
        ->execute([':code' => $code]);
    $tableId = (int)$pdo->lastInsertId();

    $token = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO poker_players (table_id, seat, player_name, player_token, chips, status) VALUES (:tid, 0, :name, :token, 1000, "active")')
        ->execute([':tid' => $tableId, ':name' => $name, ':token' => $token]);

    echo json_encode(['ok' => true, 'room_code' => $code, 'token' => $token, 'seat' => 0]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
