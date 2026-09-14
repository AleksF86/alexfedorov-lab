<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../db_config.php';
require __DIR__ . '/lib/PokerTable.php';

$input = json_decode(file_get_contents('php://input'), true);
$code = isset($input['room_code']) ? strtoupper(trim(strip_tags($input['room_code']))) : '';
$token = isset($input['token']) ? trim($input['token']) : '';
if ($code === '' || $token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_fields']);
    exit;
}

try {
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT t.id, t.status FROM poker_tables t WHERE t.room_code = :c');
    $stmt->execute([':c' => $code]);
    $table = $stmt->fetch();
    if (!$table) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'table_not_found']);
        exit;
    }

    $pstmt = $pdo->prepare('SELECT id FROM poker_players WHERE table_id = :tid AND player_token = :tok');
    $pstmt->execute([':tid' => $table['id'], ':tok' => $token]);
    if (!$pstmt->fetch()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'invalid_token']);
        exit;
    }

    if ($table['status'] !== 'waiting') {
        echo json_encode(['ok' => true, 'already_started' => true]);
        exit;
    }

    $pt = new PokerTable($pdo, (int)$table['id']);
    $pt->dealNewHand();
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
