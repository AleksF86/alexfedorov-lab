<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db_config.php';

$game = isset($_GET['game']) ? substr(trim(strip_tags($_GET['game'])), 0, 32) : '';
$player = isset($_GET['player']) ? substr(trim(strip_tags($_GET['player'])), 0, 32) : '';

if ($game === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_game']);
    exit;
}

try {
    $pdo = db_connect();

    if ($player !== '') {
        $stmt = $pdo->prepare(
            'SELECT score, max_combo, accuracy, rank_letter, created_at
             FROM game_scores WHERE game = :game AND player_name = :player
             ORDER BY created_at DESC LIMIT 20'
        );
        $stmt->execute([':game' => $game, ':player' => $player]);
        $history = $stmt->fetchAll();

        $bestStmt = $pdo->prepare(
            'SELECT score, max_combo, accuracy, rank_letter, created_at
             FROM game_scores WHERE game = :game AND player_name = :player
             ORDER BY score DESC LIMIT 1'
        );
        $bestStmt->execute([':game' => $game, ':player' => $player]);
        $best = $bestStmt->fetch() ?: null;

        echo json_encode(['ok' => true, 'history' => $history, 'best' => $best]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT player_name, score, max_combo, accuracy, rank_letter, created_at
             FROM game_scores WHERE game = :game
             ORDER BY score DESC LIMIT 10'
        );
        $stmt->execute([':game' => $game]);
        echo json_encode(['ok' => true, 'top' => $stmt->fetchAll()]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
