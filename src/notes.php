<?php
/**
 * Нотатки учня (CRUD)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

requireLogin();

$pdo       = getDB();
$userId    = (int)$_SESSION['user_id'];
$csrfToken = generateCsrfToken();
$message   = '';
$error     = '';

// CRUD операції
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Помилка безпеки.';
    } else {
        $action  = $_POST['action'] ?? '';
        $noteId  = (int)($_POST['note_id'] ?? 0);
        $title   = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');

        if ($action === 'add') {
            if (empty($title)) {
                $error = 'Введіть заголовок нотатки.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO user_notes (user_id, title, content) VALUES (?, ?, ?)');
                $stmt->execute([$userId, $title, $content]);
                $message = 'Нотатку додано.';
            }
        } elseif ($action === 'edit' && $noteId > 0) {
            // Перевірка, що нотатка належить поточному користувачу
            $stmt = $pdo->prepare('SELECT id FROM user_notes WHERE id = ? AND user_id = ?');
            $stmt->execute([$noteId, $userId]);
            if ($stmt->fetch()) {
                if (empty($title)) {
                    $error = 'Введіть заголовок нотатки.';
                } else {
                    $stmt = $pdo->prepare('UPDATE user_notes SET title = ?, content = ? WHERE id = ? AND user_id = ?');
                    $stmt->execute([$title, $content, $noteId, $userId]);
                    $message = 'Нотатку оновлено.';
                }
            } else {
                $error = 'Нотатку не знайдено.';
            }
        } elseif ($action === 'delete' && $noteId > 0) {
            // Видалення лише своїх нотаток
            $stmt = $pdo->prepare('DELETE FROM user_notes WHERE id = ? AND user_id = ?');
            $stmt->execute([$noteId, $userId]);
            $message = 'Нотатку видалено.';
        }
    }
}

// Завантаження нотаток поточного користувача
$notes = $pdo->prepare('SELECT * FROM user_notes WHERE user_id = ? ORDER BY updated_at DESC');
$notes->execute([$userId]);
$notes = $notes->fetchAll();

// Нотатка для редагування
$editNote = null;
$editId   = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM user_notes WHERE id = ? AND user_id = ?');
    $stmt->execute([$editId, $userId]);
    $editNote = $stmt->fetch() ?: null;
}

$pageTitle = 'Мої нотатки';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Мої нотатки</h1>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="notes-layout">
    <!-- Форма додавання/редагування -->
    <div class="notes-form-card">
        <h2><?= $editNote ? 'Редагувати нотатку' : 'Нова нотатка' ?></h2>
        <form method="POST" action="/notes.php" class="notes-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="<?= $editNote ? 'edit' : 'add' ?>">
            <?php if ($editNote): ?>
                <input type="hidden" name="note_id" value="<?= (int)$editNote['id'] ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="title" class="form-label">Заголовок</label>
                <input
                    type="text"
                    id="title"
                    name="title"
                    class="form-input"
                    value="<?= e($editNote ? $editNote['title'] : ($_POST['title'] ?? '')) ?>"
                    placeholder="Введіть заголовок"
                    maxlength="200"
                    required
                >
            </div>

            <div class="form-group">
                <label for="content" class="form-label">Зміст</label>
                <textarea
                    id="content"
                    name="content"
                    class="form-textarea"
                    rows="6"
                    placeholder="Введіть текст нотатки..."
                ><?= e($editNote ? $editNote['content'] : ($_POST['content'] ?? '')) ?></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <?= $editNote ? 'Зберегти зміни' : 'Додати нотатку' ?>
                </button>
                <?php if ($editNote): ?>
                    <a href="/notes.php" class="btn btn-outline">Скасувати</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Список нотаток -->
    <div class="notes-list">
        <?php if (empty($notes)): ?>
            <div class="empty-state">
                <div class="empty-icon">📝</div>
                <h3>У вас ще немає нотаток</h3>
                <p>Створіть першу нотатку для збереження важливої інформації.</p>
            </div>
        <?php else: ?>
            <?php foreach ($notes as $note): ?>
                <div class="note-card <?= $editNote && $editNote['id'] == $note['id'] ? 'editing' : '' ?>">
                    <div class="note-header">
                        <h3 class="note-title"><?= e($note['title']) ?></h3>
                        <div class="note-actions">
                            <a href="/notes.php?edit=<?= (int)$note['id'] ?>" class="btn btn-outline btn-sm">✏️ Редагувати</a>
                            <form method="POST" action="/notes.php" style="display:inline" onsubmit="return confirm('Видалити нотатку?')">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">🗑️ Видалити</button>
                            </form>
                        </div>
                    </div>
                    <?php if (!empty($note['content'])): ?>
                        <div class="note-content"><?= nl2br(e($note['content'])) ?></div>
                    <?php endif; ?>
                    <div class="note-meta">
                        <small>Оновлено: <?= date('d.m.Y H:i', strtotime($note['updated_at'])) ?></small>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
