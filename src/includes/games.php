<?php
/**
 * Модульні ігри.
 *
 * Кожна гра — окрема папка src/games/<slug>/ з файлами:
 *   manifest.json — {"name": "...", "icon": "🎮", "description": "...", "hints": ["fifty", "audience"]}
 *   game.js       — реєструє гру через GameEngine.register('<slug>', {...})
 *   game.css      — (необов'язково) стилі гри
 *
 * Гра лише по-іншому показує запитання теми. Відповіді перевіряє сервер
 * (src/games/api.php), а результат записується в test_results як звичайна спроба тесту.
 */

const GAMES_DIR = __DIR__ . '/../games';

/** Підказки, які сервер уміє надавати іграм */
const GAME_HINTS = ['fifty', 'audience'];

/**
 * Усі встановлені ігри: slug => manifest
 */
function listGames(): array {
    static $games = null;
    if ($games !== null) {
        return $games;
    }
    $games = [];
    foreach (glob(GAMES_DIR . '/*/manifest.json') ?: [] as $file) {
        $slug = basename(dirname($file));
        if (!preg_match('/^[a-z0-9_-]{1,50}$/', $slug) || !is_file(dirname($file) . '/game.js')) {
            continue;
        }
        $manifest = json_decode((string)file_get_contents($file), true);
        if (!is_array($manifest) || empty($manifest['name'])) {
            continue;
        }
        $games[$slug] = [
            'slug'        => $slug,
            'name'        => (string)$manifest['name'],
            'icon'        => (string)($manifest['icon'] ?? '🎮'),
            'description' => (string)($manifest['description'] ?? ''),
            'hints'       => array_values(array_intersect((array)($manifest['hints'] ?? []), GAME_HINTS)),
            'has_css'     => is_file(dirname($file) . '/game.css'),
        ];
    }
    ksort($games);
    return $games;
}

function getGame(?string $slug): ?array {
    return $slug !== null && $slug !== '' ? (listGames()[$slug] ?? null) : null;
}

/** «🚢 Експедиція» або порожній рядок для звичайного тесту */
function gameLabel(?string $slug): string {
    $game = getGame($slug);
    if ($game) {
        return $game['icon'] . ' ' . $game['name'];
    }
    return $slug ? '🎮 ' . $slug : '';
}

function gameBadge(?string $slug): string {
    $label = gameLabel($slug);
    return $label === '' ? '' : ' <span class="badge badge-game">' . e($label) . '</span>';
}

/**
 * Колонка `game` у завданнях і результатах (для баз, створених раніше)
 */
function ensureGameColumns(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    foreach (['assignments', 'test_results'] as $table) {
        if (!$pdo->query("SHOW COLUMNS FROM `$table` LIKE 'game'")->fetch()) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `game` VARCHAR(50) NULL DEFAULT NULL");
        }
    }
    $done = true;
}

/**
 * Посилання для виконання завдання: гра або звичайний тест
 */
function assignmentUrl(array $a): string {
    $query = 'topic_id=' . (int)$a['topic_id'] . '&assignment_id=' . (int)$a['id'];
    if (!empty($a['game'])) {
        return '/games/play.php?game=' . rawurlencode($a['game']) . '&' . $query;
    }
    return '/tests/take.php?' . $query;
}

/**
 * Запитання теми (лише з позначеною правильною відповіддю) у випадковому порядку
 */
function gameLoadQuestions(PDO $pdo, int $topicId): array {
    $stmt = $pdo->prepare('
        SELECT q.* FROM questions q
        WHERE q.topic_id = ?
          AND EXISTS (SELECT 1 FROM answers a WHERE a.question_id = q.id AND a.is_correct = 1)
        ORDER BY RAND()
    ');
    $stmt->execute([$topicId]);
    $questions = $stmt->fetchAll();

    $answersStmt = $pdo->prepare('SELECT * FROM answers WHERE question_id = ? ORDER BY RAND()');
    foreach ($questions as &$q) {
        $answersStmt->execute([$q['id']]);
        $q['answers'] = $answersStmt->fetchAll();
        $q['correct_ids'] = array_map('intval', array_column(
            array_filter($q['answers'], fn($a) => (int)$a['is_correct'] === 1), 'id'
        ));
        sort($q['correct_ids']);
        $q['multiple'] = count($q['correct_ids']) > 1;
    }
    unset($q);
    return $questions;
}

/**
 * Створює ігровий сеанс у сесії. Правильні відповіді лишаються на сервері.
 */
function gameStartRun(string $game, int $topicId, ?int $assignmentId, array $questions): string {
    $runs = $_SESSION['game_runs'] ?? [];
    // Зберігаємо лише кілька останніх незавершених ігор
    uasort($runs, fn($a, $b) => $b['started'] <=> $a['started']);
    $runs = array_slice($runs, 0, 4, true);

    $token = bin2hex(random_bytes(16));
    $qs = [];
    foreach ($questions as $q) {
        $qs[(int)$q['id']] = [
            'answers' => array_map('intval', array_column($q['answers'], 'id')),
            'correct' => $q['correct_ids'],
        ];
    }
    $runs[$token] = [
        'game'          => $game,
        'topic_id'      => $topicId,
        'assignment_id' => $assignmentId,
        'questions'     => $qs,
        'answered'      => [],
        'hints_used'    => [],
        'started'       => time(),
    ];
    $_SESSION['game_runs'] = $runs;
    return $token;
}

/** Дані запитань для браузера — без правильних відповідей */
function gamePublicQuestions(array $questions): array {
    return array_map(fn($q) => [
        'id'       => (int)$q['id'],
        'text'     => $q['text'],
        'image'    => imageUrl($q['image'] ?? null),
        'multiple' => $q['multiple'],
        'answers'  => array_map(fn($a) => [
            'id'    => (int)$a['id'],
            'text'  => $a['text'],
            'image' => imageUrl($a['image'] ?? null),
        ], $q['answers']),
    ], $questions);
}
