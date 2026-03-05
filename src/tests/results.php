<?php
/**
 * Результати тестів учня
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireLogin();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// Статистика
$totalTests = $pdo->prepare('SELECT COUNT(*) FROM test_results WHERE user_id = ?');
$totalTests->execute([$userId]);
$totalTests = $totalTests->fetchColumn();

$avgScore = $pdo->prepare('SELECT AVG(score_percent) FROM test_results WHERE user_id = ?');
$avgScore->execute([$userId]);
$avgScore = round($avgScore->fetchColumn() ?? 0, 1);

$bestScore = $pdo->prepare('SELECT MAX(score_percent) FROM test_results WHERE user_id = ?');
$bestScore->execute([$userId]);
$bestScore = round($bestScore->fetchColumn() ?? 0, 1);

// Список результатів з пагінацією
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

$totalCount = $pdo->prepare('SELECT COUNT(*) FROM test_results WHERE user_id = ?');
$totalCount->execute([$userId]);
$totalCount = $totalCount->fetchColumn();
$totalPages = ceil($totalCount / $perPage);

$stmt = $pdo->prepare('
    SELECT tr.*, t.name as topic_name, s.name as subject_name, c.name as class_name
    FROM test_results tr
    JOIN topics t ON tr.topic_id = t.id
    JOIN subjects s ON t.subject_id = s.id
    JOIN classes c ON t.class_id = c.id
    WHERE tr.user_id = ?
    ORDER BY tr.completed_at DESC
    LIMIT ? OFFSET ?
');
$stmt->execute([$userId, $perPage, $offset]);
$results = $stmt->fetchAll();

$pageTitle = 'Мої результати';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Мої результати</h1>
    <a href="/tests/index.php" class="btn btn-primary">Пройти тест</a>
</div>

<!-- Статистика -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">📝</div>
        <div class="stat-value"><?= (int)$totalTests ?></div>
        <div class="stat-label">Тестів пройдено</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">⭐</div>
        <div class="stat-value"><?= $avgScore ?>%</div>
        <div class="stat-label">Середній бал</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">🏆</div>
        <div class="stat-value"><?= $bestScore ?>%</div>
        <div class="stat-label">Найкращий результат</div>
    </div>
</div>

<!-- Список результатів -->
<?php if (empty($results)): ?>
    <div class="empty-state">
        <div class="empty-icon">📭</div>
        <h3>Ви ще не проходили тести</h3>
        <p>Перейдіть до списку тестів і пройдіть перший тест!</p>
        <a href="/tests/index.php" class="btn btn-primary">До тестів</a>
    </div>
<?php else: ?>
    <div class="results-table-wrapper">
        <table class="results-table">
            <thead>
                <tr>
                    <th>Тема</th>
                    <th>Предмет</th>
                    <th>Клас</th>
                    <th>Правильних</th>
                    <th>Результат</th>
                    <th>Дата</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $result): ?>
                    <tr>
                        <td><?= e($result['topic_name']) ?></td>
                        <td><?= e($result['subject_name']) ?></td>
                        <td><?= e($result['class_name']) ?></td>
                        <td><?= (int)$result['correct_answers'] ?> / <?= (int)$result['total_questions'] ?></td>
                        <td>
                            <span class="score-badge <?= $result['score_percent'] >= 60 ? 'good' : 'bad' ?>">
                                <?= round($result['score_percent']) ?>%
                            </span>
                        </td>
                        <td><?= date('d.m.Y H:i', strtotime($result['completed_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Пагінація -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?= $i ?>" class="page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
