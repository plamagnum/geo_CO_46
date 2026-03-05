<?php
/**
 * Управління предметами (адмін)
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
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $name      = trim($_POST['name'] ?? '');

        if ($action === 'add' && !empty($name)) {
            $stmt = $pdo->prepare('INSERT INTO subjects (name) VALUES (?)');
            $stmt->execute([$name]);
            $message = 'Предмет додано.';
        } elseif ($action === 'edit' && $subjectId > 0 && !empty($name)) {
            $stmt = $pdo->prepare('UPDATE subjects SET name = ? WHERE id = ?');
            $stmt->execute([$name, $subjectId]);
            $message = 'Предмет оновлено.';
        } elseif ($action === 'delete' && $subjectId > 0) {
            $stmt = $pdo->prepare('DELETE FROM subjects WHERE id = ?');
            $stmt->execute([$subjectId]);
            $message = 'Предмет видалено.';
        }
    }
}

$subjects = $pdo->query('SELECT * FROM subjects ORDER BY name')->fetchAll();

$pageTitle = 'Управління предметами';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-header">
    <h1 class="page-title">Управління предметами</h1>
    <a href="/admin/index.php" class="btn btn-outline">← Назад</a>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<!-- Форма додавання -->
<div class="admin-form-card">
    <h2>Додати предмет</h2>
    <form method="POST" action="" class="inline-form">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="action" value="add">
        <div class="form-group">
            <input type="text" name="name" class="form-input" placeholder="Назва предмету" maxlength="100" required>
        </div>
        <button type="submit" class="btn btn-primary">Додати</button>
    </form>
</div>

<!-- Список предметів -->
<div class="table-wrapper">
    <table class="admin-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Назва</th>
                <th>Дії</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($subjects as $subject): ?>
                <tr>
                    <td><?= (int)$subject['id'] ?></td>
                    <td>
                        <form method="POST" action="" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="subject_id" value="<?= (int)$subject['id'] ?>">
                            <input type="text" name="name" value="<?= e($subject['name']) ?>" class="form-input-inline" maxlength="100" required>
                            <button type="submit" class="btn btn-outline btn-sm">Зберегти</button>
                        </form>
                    </td>
                    <td>
                        <form method="POST" action="" style="display:inline" onsubmit="return confirm('Видалити предмет?')">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="subject_id" value="<?= (int)$subject['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Видалити</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
