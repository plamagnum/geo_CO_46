<?php
/**
 * Результати тестів учня
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/assignments.php';

requireLogin();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];

// Статистика
$totalTests = $pdo->prepare('SELECT COUNT(*) FROM test_results WHERE user_id = ?');
$totalTests->execute([$userId]);
$totalTests = $totalTests->fetchColumn();

$scoresStmt = $pdo->prepare('SELECT score_percent FROM test_results WHERE user_id = ?');
$scoresStmt->execute([$userId]);
$allScores = $scoresStmt->fetchAll(PDO::FETCH_COLUMN);

$avgGrade  = averageGrade($allScores);
$bestGrade = empty($allScores) ? 0 : scoreToGrade((float)max($allScores));

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

$stmt = $pdo->prepare('
    SELECT t.name AS topic_name, s.name AS subject_name, GROUP_CONCAT(tr.score_percent) AS percents
    FROM test_results tr
    JOIN topics t ON tr.topic_id = t.id
    JOIN subjects s ON t.subject_id = s.id
    WHERE tr.user_id = ?
    GROUP BY tr.topic_id
    ORDER BY MAX(tr.completed_at) DESC
');
$stmt->execute([$userId]);
$averages = $stmt->fetchAll();

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
        <div class="stat-value"><?= $avgGrade ?> / 12</div>
        <div class="stat-label">Середній бал</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">🏆</div>
        <div class="stat-value"><?= $bestGrade ?> / 12</div>
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

<?php if (!empty($averages)): ?>
    <h2>Середні оцінки за темами</h2>
    <p class="text-muted">Тест можна проходити кілька разів — підсумкова оцінка за тему є середньою за всі спроби.</p>
    <div class="results-table-wrapper">
        <table class="results-table">
            <thead>
                <tr><th>Тема</th><th>Предмет</th><th>Спроб</th><th>Середня оцінка</th></tr>
            </thead>
            <tbody>
                <?php foreach ($averages as $row): ?>
                    <?php $att = attemptsSummary($row['percents']); ?>
                    <tr>
                        <td><?= e($row['topic_name']) ?></td>
                        <td><?= e($row['subject_name']) ?></td>
                        <td><?= $att['attempts'] ?></td>
                        <td>
                            <span class="score-badge <?= $att['avg'] >= 7 ? 'good' : 'bad' ?>">
                                <?= formatGrade($att['avg']) ?> <?= gradeWord($att['avg']) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <h2>Усі спроби</h2>
<?php endif; ?>
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
                            <?php $grade = scoreToGrade((float)$result['score_percent']); ?>
                            <span class="score-badge <?= isGoodScore((float)$result['score_percent']) ? 'good' : 'bad' ?>"
                                  title="<?= round($result['score_percent']) ?>%">
                                <?= $grade ?> <?= gradeWord($grade) ?>
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
