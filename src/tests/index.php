<?php
/**
 * Список доступних тестів
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireLogin();

$pdo = getDB();
$currentUser = getCurrentUser();
$userClassId = $currentUser['class_id'] ?? null;

// Фільтри
$selectedClassId   = (int)($_GET['class_id'] ?? $userClassId ?? 0);
$selectedSubjectId = (int)($_GET['subject_id'] ?? 0);

// Завантаження класів
$classes = $pdo->query('SELECT * FROM classes ORDER BY id')->fetchAll();

// Завантаження предметів
$subjects = $pdo->query('SELECT * FROM subjects ORDER BY name')->fetchAll();

// Завантаження тем з фільтрацією
$topicsQuery = '
    SELECT t.*, s.name as subject_name, c.name as class_name,
           COUNT(q.id) as questions_count
    FROM topics t
    JOIN subjects s ON t.subject_id = s.id
    JOIN classes c ON t.class_id = c.id
    LEFT JOIN questions q ON q.topic_id = t.id
    WHERE 1=1
';
$params = [];

if ($selectedClassId > 0) {
    $topicsQuery .= ' AND t.class_id = ?';
    $params[] = $selectedClassId;
}

if ($selectedSubjectId > 0) {
    $topicsQuery .= ' AND t.subject_id = ?';
    $params[] = $selectedSubjectId;
}

$topicsQuery .= ' GROUP BY t.id HAVING questions_count > 0 ORDER BY s.name, t.name';

$stmt = $pdo->prepare($topicsQuery);
$stmt->execute($params);
$topics = $stmt->fetchAll();

$pageTitle = 'Тести';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Доступні тести</h1>
    <p class="page-subtitle">Оберіть тему для тестування</p>
</div>

<!-- Фільтри -->
<div class="filters">
    <form method="GET" action="" class="filters-form">
        <div class="filter-group">
            <label for="class_id" class="filter-label">Клас:</label>
            <select name="class_id" id="class_id" class="form-select" onchange="this.form.submit()">
                <option value="0">Всі класи</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?= (int)$class['id'] ?>" <?= $selectedClassId == $class['id'] ? 'selected' : '' ?>>
                        <?= e($class['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label for="subject_id" class="filter-label">Предмет:</label>
            <select name="subject_id" id="subject_id" class="form-select" onchange="this.form.submit()">
                <option value="0">Всі предмети</option>
                <?php foreach ($subjects as $subject): ?>
                    <option value="<?= (int)$subject['id'] ?>" <?= $selectedSubjectId == $subject['id'] ? 'selected' : '' ?>>
                        <?= e($subject['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($selectedClassId > 0 || $selectedSubjectId > 0): ?>
            <a href="/tests/index.php" class="btn btn-outline btn-sm">Скинути</a>
        <?php endif; ?>
    </form>
</div>

<!-- Список тем -->
<?php if (empty($topics)): ?>
    <div class="empty-state">
        <div class="empty-icon">📭</div>
        <h3>Тести не знайдено</h3>
        <p>Спробуйте змінити фільтри або зверніться до адміністратора.</p>
    </div>
<?php else: ?>
    <div class="topics-grid">
        <?php foreach ($topics as $topic): ?>
            <div class="topic-card">
                <div class="topic-meta">
                    <span class="topic-class"><?= e($topic['class_name']) ?></span>
                    <span class="topic-subject"><?= e($topic['subject_name']) ?></span>
                </div>
                <h3 class="topic-name"><?= e($topic['name']) ?></h3>
                <div class="topic-info">
                    <span class="questions-count">
                        <span class="count-icon">❓</span>
                        <?= (int)$topic['questions_count'] ?> запитань
                    </span>
                </div>
                <a href="/tests/take.php?topic_id=<?= (int)$topic['id'] ?>" class="btn btn-primary btn-full">
                    Почати тест
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
