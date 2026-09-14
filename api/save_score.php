<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db_config.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$game = isset($input['game']) ? substr(trim(strip_tags($input['game'])), 0, 32) : '';
$playerName = isset($input['player_name']) ? substr(trim(strip_tags($input['player_name'])), 0, 32) : '';
$score = isset($input['score']) ? (int)$input['score'] : -1;
$maxCombo = isset($input['max_combo']) ? (int)$input['max_combo'] : -1;
$accuracy = isset($input['accuracy']) ? (int)$input['accuracy'] : -1;
$rank = isset($input['rank']) ? substr(trim(strip_tags($input['rank'])), 0, 2) : '';

if ($game === '' || $playerName === '' || $score < 0 || $maxCombo < 0 || $accuracy < 0 || $accuracy > 100 || $rank === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_fields']);
    exit;
}

try {
    $pdo = db_connect();
    $stmt = $pdo->prepare(
        'INSERT INTO game_scores (game, player_name, score, max_combo, accuracy, rank_letter)
         VALUES (:game, :player_name, :score, :max_combo, :accuracy, :rank_letter)'
    );
    $stmt->execute([
        ':game' => $game,
        ':player_name' => $playerName,
        ':score' => $score,
        ':max_combo' => $maxCombo,
        ':accuracy' => $accuracy,
        ':rank_letter' => $rank,
    ]);
    echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
