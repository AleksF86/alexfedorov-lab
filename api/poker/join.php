<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
$code = isset($input['room_code']) ? strtoupper(trim(strip_tags($input['room_code']))) : '';
$name = isset($input['player_name']) ? substr(trim(strip_tags($input['player_name'])), 0, 32) : '';
if ($code === '' || $name === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_fields']);
    exit;
}

try {
    $pdo = db_connect();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM poker_tables WHERE room_code = :c FOR UPDATE');
    $stmt->execute([':c' => $code]);
    $table = $stmt->fetch();
    if (!$table) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'table_not_found']);
        exit;
    }

    $pstmt = $pdo->prepare('SELECT * FROM poker_players WHERE table_id = :tid ORDER BY seat ASC');
    $pstmt->execute([':tid' => $table['id']]);
    $players = $pstmt->fetchAll();

    if (count($players) >= 6) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'table_full']);
        exit;
    }

    $usedSeats = array_map(fn($p) => (int)$p['seat'], $players);
    $seat = 0;
    while (in_array($seat, $usedSeats)) $seat++;

    $status = $table['status'] === 'waiting' ? 'active' : 'sitting_out';
    $token = bin2hex(random_bytes(16));

    $pdo->prepare('INSERT INTO poker_players (table_id, seat, player_name, player_token, chips, status) VALUES (:tid, :seat, :name, :token, 1000, :status)')
        ->execute([':tid' => $table['id'], ':seat' => $seat, ':name' => $name, ':token' => $token, ':status' => $status]);

    $pdo->commit();
    echo json_encode(['ok' => true, 'room_code' => $code, 'token' => $token, 'seat' => $seat]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
