<?php
/**
 * Результати всіх учнів (адмін)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireAdmin();

$pdo = getDB();

// Фільтри
$filterUser    = (int)($_GET['user_id'] ?? 0);
$filterSubject = (int)($_GET['subject_id'] ?? 0);

// Пагінація
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// Запит з фільтрами
$query = '
    SELECT tr.*, u.first_name, u.last_name, u.email,
           t.name as topic_name, s.name as subject_name, c.name as class_name
    FROM test_results tr
    JOIN users u ON tr.user_id = u.id
    JOIN topics t ON tr.topic_id = t.id
    JOIN subjects s ON t.subject_id = s.id
    JOIN classes c ON t.class_id = c.id
    WHERE 1=1
';
$params = [];

if ($filterUser > 0) {
    $query .= ' AND tr.user_id = ?';
    $params[] = $filterUser;
}
if ($filterSubject > 0) {
    $query .= ' AND t.subject_id = ?';
    $params[] = $filterSubject;
}

$countQuery = str_replace('tr.*, u.first_name, u.last_name, u.email, t.name as topic_name, s.name as subject_name, c.name as class_name', 'COUNT(*)', $query);
$countStmt  = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalCount = $countStmt->fetchColumn();
$totalPages = ceil($totalCount / $perPage);

$query .= ' ORDER BY tr.completed_at DESC LIMIT ? OFFSET ?';
$params[] = $perPage;
$params[] = $offset;

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$results = $stmt->fetchAll();

// Списки для фільтрів
$users    = $pdo->query('SELECT id, first_name, last_name FROM users WHERE role = "user" ORDER BY last_name, first_name')->fetchAll();
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
        <select name="user_id" class="form-select" onchange="this.form.submit()">
            <option value="0">Всі учні</option>
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
    <div class="table-wrapper">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Учень</th>
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
                        <td>
                            <div><?= e($result['first_name'] . ' ' . $result['last_name']) ?></div>
                            <small class="text-muted"><?= e($result['email']) ?></small>
                        </td>
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
                <a href="?page=<?= $i ?>&user_id=<?= $filterUser ?>&subject_id=<?= $filterSubject ?>"
                   class="page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
