<?php
/**
 * Управління школами (адмін) — CRUD
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireAdmin();

$pdo       = getDB();
ensureSchoolsSchema($pdo);
$csrfToken = generateCsrfToken();
$message   = '';
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Помилка безпеки.';
    } else {
        $action   = $_POST['action'] ?? '';
        $schoolId = (int)($_POST['school_id'] ?? 0);
        $name     = trim(preg_replace('/\s+/u', ' ', (string)($_POST['name'] ?? '')));

        $dup = $pdo->prepare('SELECT id FROM schools WHERE name = ? AND id <> ?');
        $dup->execute([$name, $schoolId]);

        if (in_array($action, ['add', 'edit'], true) && ($name === '' || mb_strlen($name) > 150)) {
            $error = 'Введіть назву школи (до 150 символів).';
        } elseif (in_array($action, ['add', 'edit'], true) && $dup->fetch()) {
            $error = 'Школа з такою назвою вже існує.';
        } elseif ($action === 'add') {
            $pdo->prepare('INSERT INTO schools (name) VALUES (?)')->execute([$name]);
            $message = 'Школу додано.';
        } elseif ($action === 'edit' && $schoolId > 0) {
            $pdo->prepare('UPDATE schools SET name = ? WHERE id = ?')->execute([$name, $schoolId]);
            $message = 'Школу оновлено.';
        } elseif ($action === 'delete' && $schoolId > 0) {
            $pdo->prepare('DELETE FROM schools WHERE id = ?')->execute([$schoolId]);
            $message = 'Школу видалено. Учні цієї школи залишились без школи.';
        }
    }
}

$schools = $pdo->query('
    SELECT s.*, (SELECT COUNT(*) FROM users u WHERE u.school_id = s.id AND u.role = "user") AS students
    FROM schools s ORDER BY s.name
')->fetchAll();
$noSchool = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role = "user" AND school_id IS NULL')->fetchColumn();

$pageTitle = 'Управління школами';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-header">
    <h1 class="page-title">Управління школами</h1>
    <a href="/admin/index.php" class="btn btn-outline">← Назад</a>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="admin-form-card">
    <h2>Додати школу</h2>
    <form method="POST" action="" class="inline-form">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="action" value="add">
        <div class="form-group">
            <input type="text" name="name" class="form-input" placeholder="Назва школи (напр. ЗОШ №1)" maxlength="150" required>
        </div>
        <button type="submit" class="btn btn-primary">Додати</button>
    </form>
    <?php if ($noSchool > 0): ?>
        <p class="text-muted">Учнів без школи: <?= $noSchool ?>. Призначити школу можна в розділі «Користувачі».</p>
    <?php endif; ?>
</div>

<?php if (empty($schools)): ?>
    <div class="empty-state"><h3>Шкіл ще немає</h3><p>Поки список порожній, у реєстрації вибір школи не показується.</p></div>
<?php else: ?>
<div class="table-wrapper">
    <table class="admin-table">
        <thead><tr><th>ID</th><th>Назва</th><th>Учнів</th><th>Дії</th></tr></thead>
        <tbody>
            <?php foreach ($schools as $school): ?>
                <tr>
                    <td><?= (int)$school['id'] ?></td>
                    <td>
                        <form method="POST" action="" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="school_id" value="<?= (int)$school['id'] ?>">
                            <input type="text" name="name" value="<?= e($school['name']) ?>" class="form-input-inline" maxlength="150" required>
                            <button type="submit" class="btn btn-outline btn-sm">Зберегти</button>
                        </form>
                    </td>
                    <td><?= (int)$school['students'] ?></td>
                    <td>
                        <form method="POST" action="" style="display:inline" onsubmit="return confirm('Видалити школу? Учні залишаться, але без школи.')">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="school_id" value="<?= (int)$school['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Видалити</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
