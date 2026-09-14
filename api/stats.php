<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/db_config.php';

$game = isset($_GET['game']) ? substr(trim(strip_tags($_GET['game'])), 0, 32) : '';

if ($game === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_game']);
    exit;
}

function calc_streak(array $dates): int {
    if (empty($dates)) return 0;
    $set = array_flip($dates);
    $cursor = new DateTime('today');
    if (!isset($set[$cursor->format('Y-m-d')])) {
        $cursor->modify('-1 day');
        if (!isset($set[$cursor->format('Y-m-d')])) return 0;
    }
    $streak = 0;
    while (isset($set[$cursor->format('Y-m-d')])) {
        $streak++;
        $cursor->modify('-1 day');
    }
    return $streak;
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
    $players = $stmt->fetchAll();

    $dateStmt = $pdo->prepare(
        'SELECT DISTINCT player_name, DATE(created_at) AS d
         FROM game_scores
         WHERE game = :game'
    );
    $dateStmt->execute([':game' => $game]);
    $datesByPlayer = [];
    foreach ($dateStmt->fetchAll() as $row) {
        $datesByPlayer[$row['player_name']][] = $row['d'];
    }

    foreach ($players as &$p) {
        $dates = $datesByPlayer[$p['player_name']] ?? [];
        $p['streak_days'] = calc_streak($dates);
    }
    unset($p);

    echo json_encode(['ok' => true, 'players' => $players]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
