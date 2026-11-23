<?php
/**
 * Управління темами (адмін)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireAdmin();

$pdo       = getDB();
$csrfToken = generateCsrfToken();
$message   = '';
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Помилка безпеки.';
    } else {
        $action    = $_POST['action'] ?? '';
        $topicId   = (int)($_POST['topic_id'] ?? 0);
        $name      = trim($_POST['name'] ?? '');
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $classId   = (int)($_POST['class_id'] ?? 0);

        if ($action === 'add' && !empty($name) && $subjectId > 0 && $classId > 0) {
            $stmt = $pdo->prepare('INSERT INTO topics (name, subject_id, class_id) VALUES (?, ?, ?)');
            $stmt->execute([$name, $subjectId, $classId]);
            $message = 'Тему додано.';
        } elseif ($action === 'edit' && $topicId > 0 && !empty($name)) {
            $stmt = $pdo->prepare('UPDATE topics SET name = ?, subject_id = ?, class_id = ? WHERE id = ?');
            $stmt->execute([$name, $subjectId, $classId, $topicId]);
            $message = 'Тему оновлено.';
        } elseif ($action === 'delete' && $topicId > 0) {
            $stmt = $pdo->prepare('DELETE FROM topics WHERE id = ?');
            $stmt->execute([$topicId]);
            $message = 'Тему видалено.';
        }
    }
}

// Фільтрація
$filterSubject = (int)($_GET['subject_id'] ?? 0);
$filterClass   = (int)($_GET['class_id'] ?? 0);

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

if ($filterSubject > 0) {
    $topicsQuery .= ' AND t.subject_id = ?';
    $params[] = $filterSubject;
}
if ($filterClass > 0) {
    $topicsQuery .= ' AND t.class_id = ?';
    $params[] = $filterClass;
}

$topicsQuery .= ' GROUP BY t.id ORDER BY s.name, c.id, t.name';
$stmt = $pdo->prepare($topicsQuery);
$stmt->execute($params);
$topics = $stmt->fetchAll();

$subjects = $pdo->query('SELECT * FROM subjects ORDER BY name')->fetchAll();
$classes  = $pdo->query('SELECT * FROM classes ORDER BY id')->fetchAll();

$pageTitle = 'Управління темами';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-header">
    <h1 class="page-title">Управління темами</h1>
    <a href="/admin/index.php" class="btn btn-outline">← Назад</a>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<!-- Форма додавання -->
<div class="admin-form-card">
    <h2>Додати тему</h2>
    <form method="POST" action="" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="action" value="add">
        <div class="form-group">
            <label class="form-label">Предмет</label>
            <select name="subject_id" class="form-select" required>
                <option value="">Оберіть предмет</option>
                <?php foreach ($subjects as $subject): ?>
                    <option value="<?= (int)$subject['id'] ?>"><?= e($subject['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Клас</label>
            <select name="class_id" class="form-select" required>
                <option value="">Оберіть клас</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?= (int)$class['id'] ?>"><?= e($class['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group form-group-full">
            <label class="form-label">Назва теми</label>
            <input type="text" name="name" class="form-input" placeholder="Назва теми" maxlength="200" required>
        </div>
        <div class="form-group">
            <button type="submit" class="btn btn-primary">Додати тему</button>
        </div>
    </form>
</div>

<!-- Фільтри -->
<form method="GET" action="" class="filters-form">
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
    <div class="filter-group">
        <select name="class_id" class="form-select" onchange="this.form.submit()">
            <option value="0">Всі класи</option>
            <?php foreach ($classes as $class): ?>
                <option value="<?= (int)$class['id'] ?>" <?= $filterClass == $class['id'] ? 'selected' : '' ?>>
                    <?= e($class['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<!-- Список тем -->
<div class="table-wrapper">
    <table class="admin-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Назва</th>
                <th>Предмет</th>
                <th>Клас</th>
                <th>Запитань</th>
                <th>Дії</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($topics as $topic): ?>
                <tr>
                    <td><?= (int)$topic['id'] ?></td>
                    <td><?= e($topic['name']) ?></td>
                    <td><?= e($topic['subject_name']) ?></td>
                    <td><?= e($topic['class_name']) ?></td>
                    <td><?= (int)$topic['questions_count'] ?></td>
                    <td class="actions-cell">
                        <button
                            class="btn btn-outline btn-sm"
                            onclick="editTopic(<?= (int)$topic['id'] ?>, '<?= addslashes(e($topic['name'])) ?>', <?= (int)$topic['subject_id'] ?>, <?= (int)$topic['class_id'] ?>)"
                        >Редагувати</button>

                        <form method="POST" action="" style="display:inline" onsubmit="return confirm('Видалити тему?')">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="topic_id" value="<?= (int)$topic['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Видалити</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Модальне вікно редагування -->
<div class="modal" id="editModal" style="display:none">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Редагувати тему</h3>
            <button class="modal-close" onclick="closeModal()">×</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="topic_id" id="editTopicId">
            <div class="form-group">
                <label class="form-label">Предмет</label>
                <select name="subject_id" id="editSubjectId" class="form-select" required>
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?= (int)$subject['id'] ?>"><?= e($subject['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Клас</label>
                <select name="class_id" id="editClassId" class="form-select" required>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= (int)$class['id'] ?>"><?= e($class['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Назва теми</label>
                <input type="text" name="name" id="editTopicName" class="form-input" maxlength="200" required>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Зберегти</button>
                <button type="button" class="btn btn-outline" onclick="closeModal()">Скасувати</button>
            </div>
        </form>
    </div>
</div>

<script>
function editTopic(id, name, subjectId, classId) {
    document.getElementById('editTopicId').value = id;
    document.getElementById('editTopicName').value = name;
    document.getElementById('editSubjectId').value = subjectId;
    document.getElementById('editClassId').value = classId;
    document.getElementById('editModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('editModal').style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
