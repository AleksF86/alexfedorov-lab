<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db_config.php';

$game = isset($_GET['game']) ? substr(trim(strip_tags($_GET['game'])), 0, 32) : '';

if ($game === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_game']);
    exit;
}

try {
    $pdo = db_connect();
    $stmt = $pdo->prepare(
        'SELECT player_name,
                MAX(score) AS best_score,
                COUNT(*) AS games_played,
                MAX(max_combo) AS best_combo,
                MAX(accuracy) AS best_accuracy,
                MAX(created_at) AS last_played
         FROM game_scores
         WHERE game = :game
         GROUP BY player_name
         ORDER BY best_score DESC
         LIMIT 50'
    );
    $stmt->execute([':game' => $game]);
    echo json_encode(['ok' => true, 'players' => $stmt->fetchAll()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
