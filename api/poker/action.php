<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../db_config.php';
require __DIR__ . '/lib/PokerTable.php';

$input = json_decode(file_get_contents('php://input'), true);
$code = isset($input['room_code']) ? strtoupper(trim(strip_tags($input['room_code']))) : '';
$token = isset($input['token']) ? trim($input['token']) : '';
$action = isset($input['action']) ? trim($input['action']) : '';
$amount = isset($input['amount']) ? (int)$input['amount'] : 0;

if ($code === '' || $token === '' || !in_array($action, ['fold', 'check', 'call', 'bet', 'raise'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_request']);
    exit;
}

try {
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT id FROM poker_tables WHERE room_code = :c');
    $stmt->execute([':c' => $code]);
    $table = $stmt->fetch();
    if (!$table) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'table_not_found']);
        exit;
    }
    $tableId = (int)$table['id'];

    $pstmt = $pdo->prepare('SELECT seat FROM poker_players WHERE table_id = :tid AND player_token = :tok');
    $pstmt->execute([':tid' => $tableId, ':tok' => $token]);
    $me = $pstmt->fetch();
    if (!$me) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'invalid_token']);
        exit;
    }

    $pt = new PokerTable($pdo, $tableId);
    $result = $pt->applyAction((int)$me['seat'], $action, $amount);
    echo json_encode($result);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
}
