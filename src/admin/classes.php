<?php
/**
 * Управління класами (адмін)
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
        $action  = $_POST['action'] ?? '';
        $classId = (int)($_POST['class_id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');

        if ($action === 'add' && !empty($name)) {
            $stmt = $pdo->prepare('INSERT INTO classes (name) VALUES (?)');
            $stmt->execute([$name]);
            $message = 'Клас додано успішно.';
        } elseif ($action === 'edit' && $classId > 0 && !empty($name)) {
            $stmt = $pdo->prepare('UPDATE classes SET name = ? WHERE id = ?');
            $stmt->execute([$name, $classId]);
            $message = 'Клас оновлено.';
        } elseif ($action === 'delete' && $classId > 0) {
            $stmt = $pdo->prepare('DELETE FROM classes WHERE id = ?');
            $stmt->execute([$classId]);
            $message = 'Клас видалено.';
        }
    }
}

$classes = $pdo->query('SELECT * FROM classes ORDER BY id')->fetchAll();

$pageTitle = 'Управління класами';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-header">
    <h1 class="page-title">Управління класами</h1>
    <a href="/admin/index.php" class="btn btn-outline">← Назад</a>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<!-- Форма додавання -->
<div class="admin-form-card">
    <h2>Додати клас</h2>
    <form method="POST" action="" class="inline-form">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="action" value="add">
        <div class="form-group">
            <input type="text" name="name" class="form-input" placeholder="Назва класу (напр. 5 клас)" maxlength="20" required>
        </div>
        <button type="submit" class="btn btn-primary">Додати</button>
    </form>
</div>

<!-- Список класів -->
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
            <?php foreach ($classes as $class): ?>
                <tr>
                    <td><?= (int)$class['id'] ?></td>
                    <td>
                        <form method="POST" action="" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="class_id" value="<?= (int)$class['id'] ?>">
                            <input type="text" name="name" value="<?= e($class['name']) ?>" class="form-input-inline" maxlength="20" required>
                            <button type="submit" class="btn btn-outline btn-sm">Зберегти</button>
                        </form>
                    </td>
                    <td>
                        <form method="POST" action="" style="display:inline" onsubmit="return confirm('Видалити клас? Всі пов\'язані теми та запитання буде видалено!')">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="class_id" value="<?= (int)$class['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Видалити</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
