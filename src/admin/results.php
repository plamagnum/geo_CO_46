<?php
/**
 * Результати всіх учнів (адмін)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/assignments.php';

requireAdmin();

$pdo = getDB();

// Фільтри
$filterUser    = (int)($_GET['user_id'] ?? 0);
$filterSubject = (int)($_GET['subject_id'] ?? 0);
$filterClass   = (int)($_GET['class_id'] ?? 0);

// Пагінація
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// Спільні умови фільтрації (клас — це клас учня)
$where  = ' WHERE 1=1';
$params = [];
if ($filterUser > 0) {
    $where .= ' AND tr.user_id = ?';
    $params[] = $filterUser;
}
if ($filterSubject > 0) {
    $where .= ' AND t.subject_id = ?';
    $params[] = $filterSubject;
}
if ($filterClass > 0) {
    $where .= ' AND u.class_id = ?';
    $params[] = $filterClass;
}
$from = '
    FROM test_results tr
    JOIN users u ON tr.user_id = u.id
    JOIN topics t ON tr.topic_id = t.id
    JOIN subjects s ON t.subject_id = s.id
    JOIN classes c ON t.class_id = c.id
    LEFT JOIN classes uc ON u.class_id = uc.id
';

$countStmt = $pdo->prepare('SELECT COUNT(*)' . $from . $where);
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($totalCount / $perPage);

$stmt = $pdo->prepare('
    SELECT tr.*, u.first_name, u.last_name, u.email, uc.name AS user_class_name,
           t.name as topic_name, s.name as subject_name, c.name as class_name'
    . $from . $where . ' ORDER BY tr.completed_at DESC LIMIT ? OFFSET ?');
$stmt->execute(array_merge($params, [$perPage, $offset]));
$results = $stmt->fetchAll();

// Середні оцінки учень × тема (з урахуванням фільтрів)
$stmt = $pdo->prepare('
    SELECT u.first_name, u.last_name, uc.name AS user_class_name,
           t.name AS topic_name, s.name AS subject_name,
           GROUP_CONCAT(tr.score_percent) AS percents'
    . $from . $where
    . ' GROUP BY tr.user_id, tr.topic_id ORDER BY uc.name, u.last_name, u.first_name, s.name, t.name LIMIT 200');
$stmt->execute($params);
$averages = $stmt->fetchAll();

// Списки для фільтрів
$classes = $pdo->query('SELECT id, name FROM classes ORDER BY CAST(name AS UNSIGNED), name')->fetchAll();
if ($filterClass > 0) {
    $stmt = $pdo->prepare('SELECT id, first_name, last_name FROM users WHERE role = "user" AND class_id = ? ORDER BY last_name, first_name');
    $stmt->execute([$filterClass]);
    $users = $stmt->fetchAll();
} else {
    $users = $pdo->query('SELECT id, first_name, last_name FROM users WHERE role = "user" ORDER BY last_name, first_name')->fetchAll();
}
$subjects = $pdo->query('SELECT * FROM subjects ORDER BY name')->fetchAll();

$pageTitle = 'Результати учнів';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-header">
    <h1 class="page-title">Результати всіх учнів</h1>
    <a href="/admin/index.php" class="btn btn-outline">← Назад</a>
</div>

<!-- Фільтри -->
<form method="GET" action="" class="filters-form">
    <div class="filter-group">
        <select name="class_id" class="form-select"
                onchange="this.form.user_id.value = 0; this.form.submit()">
            <option value="0">Всі класи</option>
            <?php foreach ($classes as $class): ?>
                <option value="<?= (int)$class['id'] ?>" <?= $filterClass == $class['id'] ? 'selected' : '' ?>>
                    <?= e($class['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <select name="user_id" class="form-select" onchange="this.form.submit()">
            <option value="0"><?= $filterClass > 0 ? 'Всі учні класу' : 'Всі учні' ?></option>
            <?php foreach ($users as $user): ?>
                <option value="<?= (int)$user['id'] ?>" <?= $filterUser == $user['id'] ? 'selected' : '' ?>>
                    <?= e($user['last_name'] . ' ' . $user['first_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <select name="subject_id" class="form-select" onchange="this.form.submit()">
            <option value="0">Всі предмети</option>
            <?php foreach ($subjects as $subject): ?>
                <option value="<?= (int)$subject['id'] ?>" <?= $filterSubject == $subject['id'] ? 'selected' : '' ?>>
                    <?= e($subject['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<p class="results-count">Знайдено результатів: <?= (int)$totalCount ?></p>

<!-- Таблиця результатів -->
<?php if (empty($results)): ?>
    <div class="empty-state">
        <div class="empty-icon">📭</div>
        <p>Результатів не знайдено.</p>
    </div>
<?php else: ?>

<?php if (!empty($averages)): ?>
    <h2>Середні оцінки за темами</h2>
    <p class="text-muted">Тест можна проходити кілька разів — підсумкова оцінка за тему є середньою за всі спроби.</p>
    <div class="table-wrapper">
        <table class="admin-table">
            <thead>
                <tr><th>Учень</th><th>Клас учня</th><th>Тема</th><th>Предмет</th><th>Спроб</th><th>Середня оцінка</th></tr>
            </thead>
            <tbody>
                <?php foreach ($averages as $row): ?>
                    <?php $att = attemptsSummary($row['percents']); ?>
                    <tr>
                        <td><?= e($row['last_name'] . ' ' . $row['first_name']) ?></td>
                        <td><?= e($row['user_class_name'] ?? '—') ?></td>
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
    <div class="table-wrapper">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Учень</th>
                    <th>Тема</th>
                    <th>Предмет</th>
                    <th>Клас теми</th>
                    <th>Правильних</th>
                    <th>Результат</th>
                    <th>Дата</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $result): ?>
                    <tr>
                        <td>
                            <div><?= e($result['first_name'] . ' ' . $result['last_name']) ?></div>
                            <small class="text-muted"><?= e($result['email']) ?><?= $result['user_class_name'] ? ' · ' . e($result['user_class_name']) : '' ?></small>
                        </td>
                        <td><?= e($result['topic_name']) ?><?= gameBadge($result['game'] ?? null) ?></td>
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
                <a href="?page=<?= $i ?>&user_id=<?= $filterUser ?>&subject_id=<?= $filterSubject ?>&class_id=<?= $filterClass ?>"
                   class="page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
