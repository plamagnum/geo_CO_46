<?php
/**
 * Панель адміністратора
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireAdmin();

$pdo = getDB();

// Статистика
$stats = [
    'users'     => $pdo->query('SELECT COUNT(*) FROM users WHERE role = "user"')->fetchColumn(),
    'admins'    => $pdo->query('SELECT COUNT(*) FROM users WHERE role = "admin"')->fetchColumn(),
    'tests'     => $pdo->query('SELECT COUNT(*) FROM test_results')->fetchColumn(),
    'questions' => $pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn(),
    'topics'    => $pdo->query('SELECT COUNT(*) FROM topics')->fetchColumn(),
    'subjects'  => $pdo->query('SELECT COUNT(*) FROM subjects')->fetchColumn(),
];

// Останні результати
$recentResults = $pdo->query('
    SELECT tr.*, u.first_name, u.last_name, t.name as topic_name, s.name as subject_name
    FROM test_results tr
    JOIN users u ON tr.user_id = u.id
    JOIN topics t ON tr.topic_id = t.id
    JOIN subjects s ON t.subject_id = s.id
    ORDER BY tr.completed_at DESC
    LIMIT 5
')->fetchAll();

$pageTitle = 'Панель адміна';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-header">
    <h1 class="page-title">Панель адміністратора</h1>
</div>

<!-- Статистика -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">👥</div>
        <div class="stat-value"><?= (int)$stats['users'] ?></div>
        <div class="stat-label">Учнів</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📊</div>
        <div class="stat-value"><?= (int)$stats['tests'] ?></div>
        <div class="stat-label">Тестів пройдено</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">❓</div>
        <div class="stat-value"><?= (int)$stats['questions'] ?></div>
        <div class="stat-label">Запитань</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📚</div>
        <div class="stat-value"><?= (int)$stats['topics'] ?></div>
        <div class="stat-label">Тем</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📖</div>
        <div class="stat-value"><?= (int)$stats['subjects'] ?></div>
        <div class="stat-label">Предметів</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">🛡️</div>
        <div class="stat-value"><?= (int)$stats['admins'] ?></div>
        <div class="stat-label">Адмінів</div>
    </div>
</div>

<!-- Швидкі дії -->
<div class="quick-actions">
    <h2>Управління</h2>
    <div class="actions-grid">
        <a href="/admin/users.php" class="action-card">
            <span class="action-icon">👥</span>
            <span>Користувачі</span>
        </a>
        <a href="/admin/questions.php" class="action-card">
            <span class="action-icon">❓</span>
            <span>Запитання</span>
        </a>
        <a href="/admin/topics.php" class="action-card">
            <span class="action-icon">📋</span>
            <span>Теми</span>
        </a>
        <a href="/admin/subjects.php" class="action-card">
            <span class="action-icon">📚</span>
            <span>Предмети</span>
        </a>
        <a href="/admin/classes.php" class="action-card">
            <span class="action-icon">🏫</span>
            <span>Класи</span>
        </a>
        <a href="/admin/results.php" class="action-card">
            <span class="action-icon">📊</span>
            <span>Результати</span>
        </a>
    </div>
</div>

<!-- Останні результати -->
<?php if (!empty($recentResults)): ?>
<div class="recent-section">
    <h2>Останні результати учнів</h2>
    <table class="admin-table">
        <thead>
            <tr>
                <th>Учень</th>
                <th>Тема</th>
                <th>Предмет</th>
                <th>Результат</th>
                <th>Дата</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentResults as $result): ?>
                <tr>
                    <td><?= e($result['first_name'] . ' ' . $result['last_name']) ?></td>
                    <td><?= e($result['topic_name']) ?></td>
                    <td><?= e($result['subject_name']) ?></td>
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
    <a href="/admin/results.php" class="btn btn-outline">Всі результати</a>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
