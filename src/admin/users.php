<?php
/**
 * Управління користувачами (адмін)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireAdmin();

$pdo        = getDB();
$csrfToken  = generateCsrfToken();
$message    = '';
$error      = '';

// Обробка дій
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Помилка безпеки. Спробуйте ще раз.';
    } else {
        $action = $_POST['action'] ?? '';
        $userId = (int)($_POST['user_id'] ?? 0);

        if ($action === 'delete' && $userId > 0) {
            // Заборона видалення себе
            if ($userId === (int)$_SESSION['user_id']) {
                $error = 'Ви не можете видалити свій акаунт!';
            } else {
                $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
                $stmt->execute([$userId]);
                $message = 'Користувача видалено успішно.';
            }
        } elseif ($action === 'change_role' && $userId > 0) {
            $newRole = $_POST['role'] ?? 'user';
            if (!in_array($newRole, ['user', 'admin'])) {
                $error = 'Невірна роль.';
            } elseif ($userId === (int)$_SESSION['user_id']) {
                $error = 'Ви не можете змінити свою роль!';
            } else {
                $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
                $stmt->execute([$newRole, $userId]);
                $message = 'Роль змінено успішно.';
            }
        }
    }
}

// Список користувачів
$users = $pdo->query('
    SELECT u.*, c.name as class_name
    FROM users u
    LEFT JOIN classes c ON u.class_id = c.id
    ORDER BY u.role DESC, u.created_at DESC
')->fetchAll();

$pageTitle = 'Управління користувачами';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-header">
    <h1 class="page-title">Управління користувачами</h1>
    <a href="/admin/index.php" class="btn btn-outline">← Назад</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= e($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<div class="table-wrapper">
    <table class="admin-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Ім'я</th>
                <th>Email</th>
                <th>Клас</th>
                <th>Роль</th>
                <th>Реєстрація</th>
                <th>Дії</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr <?= $user['id'] == $_SESSION['user_id'] ? 'class="current-user"' : '' ?>>
                    <td><?= (int)$user['id'] ?></td>
                    <td><?= e($user['first_name'] . ' ' . $user['last_name']) ?></td>
                    <td><?= e($user['email']) ?></td>
                    <td><?= e($user['class_name'] ?? '—') ?></td>
                    <td>
                        <span class="role-badge role-<?= e($user['role']) ?>">
                            <?= $user['role'] === 'admin' ? 'Адмін' : 'Учень' ?>
                        </span>
                    </td>
                    <td><?= date('d.m.Y', strtotime($user['created_at'])) ?></td>
                    <td class="actions-cell">
                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                            <!-- Зміна ролі -->
                            <form method="POST" action="" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="change_role">
                                <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                <select name="role" class="form-select-sm" onchange="this.form.submit()" title="Змінити роль">
                                    <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>Учень</option>
                                    <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Адмін</option>
                                </select>
                            </form>

                            <!-- Видалення -->
                            <form method="POST" action="" style="display:inline" onsubmit="return confirm('Ви впевнені, що хочете видалити цього користувача?')">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Видалити</button>
                            </form>
                        <?php else: ?>
                            <span class="text-muted">(ви)</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
