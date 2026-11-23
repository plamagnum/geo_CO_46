<?php
/**
 * Запуск гри за запитаннями теми
 * /games/play.php?game=<slug>&topic_id=N[&assignment_id=M]
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/assignments.php';
require_once __DIR__ . '/../includes/images.php';
require_once __DIR__ . '/../includes/games.php';

requireLogin();

$pdo = getDB();
ensureImageColumns($pdo);
ensureAssignmentTables($pdo);
ensureGameColumns($pdo);

$userId       = (int)$_SESSION['user_id'];
$topicId      = (int)($_GET['topic_id'] ?? 0);
$assignmentId = (int)($_GET['assignment_id'] ?? 0);
$slug         = (string)($_GET['game'] ?? '');

$stmt = $pdo->prepare('
    SELECT t.*, s.name AS subject_name, c.name AS class_name
    FROM topics t
    JOIN subjects s ON t.subject_id = s.id
    JOIN classes c ON t.class_id = c.id
    WHERE t.id = ?
');
$stmt->execute([$topicId]);
$topic = $stmt->fetch();
if (!$topic) {
    header('Location: /tests/index.php');
    exit;
}

$assignment = null;
if ($assignmentId > 0) {
    $stmt = $pdo->prepare('
        SELECT a.* FROM assignments a
        JOIN assignment_recipients ar ON ar.assignment_id = a.id
        WHERE a.id = ? AND ar.user_id = ? AND a.topic_id = ?
    ');
    $stmt->execute([$assignmentId, $userId, $topicId]);
    $assignment = $stmt->fetch() ?: null;
    if ($assignment) {
        // Завдання виконується саме в тому форматі, який обрав учитель
        if (empty($assignment['game'])) {
            header('Location: /tests/take.php?topic_id=' . $topicId . '&assignment_id=' . $assignmentId);
            exit;
        }
        $slug = $assignment['game'];
    }
}

$game = getGame($slug);
if (!$game) {
    header('Location: /tests/index.php');
    exit;
}

$questions = gameLoadQuestions($pdo, $topicId);
if (empty($questions)) {
    header('Location: ' . ($assignment ? '/tests/assignments.php' : '/tests/index.php') . '?error=no_questions');
    exit;
}

$token = gameStartRun($game['slug'], $topicId, $assignment ? $assignmentId : null, $questions);

$pageTitle = $game['name'] . ': ' . $topic['name'];
require_once __DIR__ . '/../includes/header.php';
$assetVer = static fn(string $file) => is_file(__DIR__ . $file) ? filemtime(__DIR__ . $file) : 0;
?>
<link rel="stylesheet" href="/games/engine.css?v=<?= $assetVer('/engine.css') ?>">
<?php if ($game['has_css']): ?>
    <link rel="stylesheet" href="/games/<?= e($game['slug']) ?>/game.css?v=<?= $assetVer('/' . $game['slug'] . '/game.css') ?>">
<?php endif; ?>

<div class="game-page">
    <div class="test-header">
        <div class="test-meta">
            <span class="badge"><?= e($topic['class_name']) ?></span>
            <span class="badge badge-secondary"><?= e($topic['subject_name']) ?></span>
            <?php if ($assignment): ?>
                <span class="badge badge-info"><?= e(assignmentTypeLabel($assignment['type'])) ?></span>
            <?php endif; ?>
            <span class="badge badge-game"><?= e($game['icon'] . ' ' . $game['name']) ?></span>
        </div>
        <h1 class="test-title"><?= e($topic['name']) ?></h1>
    </div>

    <div id="gameRoot" class="game-root game-<?= e($game['slug']) ?>">
        <noscript>Для гри потрібен увімкнений JavaScript.</noscript>
    </div>
</div>

<script>
window.GAME_CONFIG = <?= json_encode([
    'game'      => $game['slug'],
    'name'      => $game['name'],
    'icon'      => $game['icon'],
    'hints'     => $game['hints'],
    'token'     => $token,
    'csrf'      => generateCsrfToken(),
    'endpoint'  => '/games/api.php',
    'topic'     => $topic['name'],
    'subject'   => $topic['subject_name'],
    'backUrl'   => $assignment ? '/tests/assignments.php' : '/tests/index.php',
    'questions' => gamePublicQuestions($questions),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="/games/engine.js?v=<?= $assetVer('/engine.js') ?>"></script>
<script src="/games/<?= e($game['slug']) ?>/game.js?v=<?= $assetVer('/' . $game['slug'] . '/game.js') ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
