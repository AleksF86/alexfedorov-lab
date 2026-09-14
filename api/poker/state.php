<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../db_config.php';
require __DIR__ . '/lib/PokerTable.php';

$code = isset($_GET['room_code']) ? strtoupper(trim(strip_tags($_GET['room_code']))) : '';
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
if ($code === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_room_code']);
    exit;
}

try {
    $pdo = db_connect();
    $stmt = $pdo->prepare('SELECT id FROM poker_tables WHERE room_code = :c');
    $stmt->execute([':c' => $code]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'table_not_found']);
        exit;
    }
    $tableId = (int)$row['id'];

    $pt = new PokerTable($pdo, $tableId);
    $pt->checkTimeoutAndAdvance();

    $tstmt = $pdo->prepare('SELECT * FROM poker_tables WHERE id = :id');
    $tstmt->execute([':id' => $tableId]);
    $table = $tstmt->fetch();

    $pstmt = $pdo->prepare('SELECT * FROM poker_players WHERE table_id = :id ORDER BY seat ASC');
    $pstmt->execute([':id' => $tableId]);
    $players = $pstmt->fetchAll();

    $mySeat = null;
    foreach ($players as $p) {
        if ($token !== '' && hash_equals($p['player_token'], $token)) {
            $mySeat = (int)$p['seat'];
            $pdo->prepare('UPDATE poker_players SET last_seen = NOW(), is_connected = 1 WHERE id = :id')
                ->execute([':id' => $p['id']]);
        }
    }

    $revealSeats = [];
    if ($table['status'] === 'showdown' && $table['last_result']) {
        $lr = json_decode($table['last_result'], true);
        if (($lr['type'] ?? '') === 'showdown' && !empty($lr['reveal'])) {
            foreach ($lr['reveal'] as $r) $revealSeats[(int)$r['seat']] = true;
        }
    }

    $playersOut = [];
    foreach ($players as $p) {
        $seat = (int)$p['seat'];
        $isYou = ($mySeat !== null && $seat === $mySeat);
        $showCards = $isYou || isset($revealSeats[$seat]);
        $playersOut[] = [
            'seat' => $seat,
            'name' => $p['player_name'],
            'chips' => (int)$p['chips'],
            'status' => $p['status'],
            'current_bet' => (int)$p['current_bet'],
            'is_you' => $isYou,
            'is_dealer' => $seat === (int)$table['dealer_seat'],
            'hole_cards' => $showCards ? Deck::decode($p['hole_cards']) : ($p['hole_cards'] !== '' ? ['?', '?'] : []),
        ];
    }

    $deadline = $table['turn_deadline'] ? strtotime($table['turn_deadline']) : null;
    $timeLeft = $deadline ? max(0, $deadline - time()) : null;

    echo json_encode([
        'ok' => true,
        'room_code' => $table['room_code'],
        'status' => $table['status'],
        'street' => $table['street'],
        'community_cards' => Deck::decode($table['community_cards']),
        'pot' => (int)$table['pot'],
        'current_bet' => (int)$table['current_bet'],
        'min_raise' => (int)$table['min_raise'],
        'current_seat' => (int)$table['current_seat'],
        'dealer_seat' => (int)$table['dealer_seat'],
        'small_blind' => (int)$table['small_blind'],
        'big_blind' => (int)$table['big_blind'],
        'hand_number' => (int)$table['hand_number'],
        'time_left' => $timeLeft,
        'my_seat' => $mySeat,
        'is_my_turn' => ($mySeat !== null && $mySeat === (int)$table['current_seat'] && $table['status'] === 'playing'),
        'last_result' => $table['last_result'] ? json_decode($table['last_result'], true) : null,
        'players' => $playersOut,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
}
