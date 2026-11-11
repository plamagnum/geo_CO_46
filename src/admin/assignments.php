<?php
/**
 * Надсилання завдань учням (адмін)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/assignments.php';
require_once __DIR__ . '/../config/database.php';

requireAdmin();

$pdo = getDB();
ensureAssignmentTables($pdo);
$csrfToken = generateCsrfToken();
$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Помилка безпеки.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'send') {
            $topicId = (int)($_POST['topic_id'] ?? 0);
            $type    = $_POST['type'] ?? '';
            $target  = $_POST['target'] ?? '';
            $classId = (int)($_POST['class_id'] ?? 0);
            $userIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['user_ids'] ?? [])))));

            $readyStmt = $pdo->prepare('
                SELECT COUNT(*) FROM questions q WHERE q.topic_id = ?
                  AND EXISTS (SELECT 1 FROM answers an WHERE an.question_id = q.id AND an.is_correct = 1)
            ');
            $readyStmt->execute([$topicId]);

            if ($topicId <= 0 || !isset(ASSIGNMENT_TYPES[$type])) {
                $error = 'Оберіть тему та тип завдання.';
            } elseif ((int)$readyStmt->fetchColumn() === 0) {
                $error = 'У цій темі немає запитань з позначеною правильною відповіддю. Позначте їх у розділі «Запитання».';
            } else {
                $recipients = [];
                if ($target === 'class' && $classId > 0) {
                    $stmt = $pdo->prepare('SELECT id FROM users WHERE role = "user" AND class_id = ?');
                    $stmt->execute([$classId]);
                    $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
                } elseif ($target === 'students' && !empty($userIds)) {
                    $in = implode(',', array_fill(0, count($userIds), '?'));
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'user' AND id IN ($in)");
                    $stmt->execute($userIds);
                    $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
                }

                if (empty($recipients)) {
                    $error = 'Не знайдено жодного учня для надсилання.';
                } else {
                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare('INSERT INTO assignments (topic_id, type, created_by) VALUES (?, ?, ?)');
                        $stmt->execute([$topicId, $type, (int)$_SESSION['user_id']]);
                        $assignmentId = (int)$pdo->lastInsertId();

                        $ins = $pdo->prepare('INSERT INTO assignment_recipients (assignment_id, user_id) VALUES (?, ?)');
                        foreach ($recipients as $uid) {
                            $ins->execute([$assignmentId, (int)$uid]);
                        }
                        $pdo->commit();
                        $message = 'Завдання надіслано учням: ' . count($recipients) . '.';
                    } catch (Throwable $ex) {
                        $pdo->rollBack();
                        error_log('Помилка створення завдання: ' . $ex->getMessage());
                        $error = 'Не вдалося надіслати завдання.';
                    }
                }
            }
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM assignments WHERE id = ?');
            $stmt->execute([(int)($_POST['assignment_id'] ?? 0)]);
            $message = 'Завдання видалено.';
        }
    }
}

$classes = $pdo->query('SELECT * FROM classes ORDER BY id')->fetchAll();
$topics  = $pdo->query('
    SELECT t.id, t.name, s.name AS subject_name, c.name AS class_name,
           (SELECT COUNT(*) FROM questions q WHERE q.topic_id = t.id
              AND EXISTS (SELECT 1 FROM answers an WHERE an.question_id = q.id AND an.is_correct = 1)) AS ready_questions
    FROM topics t
    JOIN subjects s ON t.subject_id = s.id
    JOIN classes c ON t.class_id = c.id
    ORDER BY t.class_id, s.name, t.name
')->fetchAll();
$students = $pdo->query('
    SELECT u.id, u.first_name, u.last_name, c.name AS class_name
    FROM users u LEFT JOIN classes c ON u.class_id = c.id
    WHERE u.role = "user"
    ORDER BY u.class_id, u.last_name, u.first_name
')->fetchAll();

$assignments = $pdo->query('
    SELECT a.*, t.name AS topic_name, s.name AS subject_name,
           (SELECT COUNT(*) FROM questions q WHERE q.topic_id = a.topic_id
              AND EXISTS (SELECT 1 FROM answers an WHERE an.question_id = q.id AND an.is_correct = 1)) AS ready_questions,
           COUNT(ar.id) AS recipients_count,
           SUM(ar.result_id IS NOT NULL) AS done_count
    FROM assignments a
    JOIN topics t ON a.topic_id = t.id
    JOIN subjects s ON t.subject_id = s.id
    LEFT JOIN assignment_recipients ar ON ar.assignment_id = a.id
    GROUP BY a.id
    ORDER BY a.created_at DESC
    LIMIT 50
')->fetchAll();

$pageTitle = 'Завдання';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-header">
    <h1 class="page-title">Завдання</h1>
    <a href="/admin/index.php" class="btn btn-outline">← Назад</a>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<div class="admin-form-card">
    <h2>Надіслати завдання</h2>
    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="action" value="send">

        <div class="form-group">
            <label class="form-label" for="type">Тип завдання</label>
            <select name="type" id="type" class="form-select" required>
                <?php foreach (ASSIGNMENT_TYPES as $key => $label): ?>
                    <option value="<?= e($key) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label" for="topic_id">Тема (тест)</label>
            <select name="topic_id" id="topic_id" class="form-select" required>
                <option value="">— оберіть —</option>
                <?php foreach ($topics as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= (int)$t['ready_questions'] === 0 ? 'disabled' : '' ?>>
                        <?= e($t['class_name'] . ' · ' . $t['subject_name'] . ' · ' . $t['name']) ?>
                        (<?= (int)$t['ready_questions'] > 0 ? (int)$t['ready_questions'] . ' запит.' : 'немає правильних відповідей' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label" for="target">Кому</label>
            <select name="target" id="target" class="form-select" required>
                <option value="class">Усім учням класу</option>
                <option value="students">Окремим учням</option>
            </select>
        </div>

        <div class="form-group" id="classBox">
            <label class="form-label" for="class_id">Клас</label>
            <select name="class_id" id="class_id" class="form-select">
                <?php foreach ($classes as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" id="studentsBox" style="display:none">
            <label class="form-label" for="user_ids">Учні (Ctrl/Cmd — кілька)</label>
            <select name="user_ids[]" id="user_ids" class="form-select" multiple size="10">
                <?php foreach ($students as $u): ?>
                    <option value="<?= (int)$u['id'] ?>">
                        <?= e($u['last_name'] . ' ' . $u['first_name'] . ' (' . ($u['class_name'] ?? 'без класу') . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-primary">Надіслати</button>
    </form>
</div>

<h2>Надіслані завдання</h2>
<?php if (empty($assignments)): ?>
    <div class="empty-state"><h3>Завдань ще немає</h3></div>
<?php else: ?>
    <div class="results-table-wrapper">
        <table class="admin-table">
            <thead>
                <tr><th>Тип</th><th>Предмет</th><th>Тема</th><th>Виконано</th><th>Дата</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($assignments as $a): ?>
                    <tr>
                        <td><span class="badge badge-info"><?= e(assignmentTypeLabel($a['type'])) ?></span></td>
                        <td><?= e($a['subject_name']) ?></td>
                        <td>
                            <?= e($a['topic_name']) ?>
                            <?php if ((int)$a['ready_questions'] === 0): ?>
                                <br><small class="text-danger">⚠ немає запитань з правильною відповіддю — учні не зможуть виконати</small>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$a['done_count'] ?> / <?= (int)$a['recipients_count'] ?></td>
                        <td><?= date('d.m.Y H:i', strtotime($a['created_at'])) ?></td>
                        <td>
                            <form method="POST" action="" onsubmit="return confirm('Видалити завдання?');">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline">Видалити</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<script>
(function () {
    var target = document.getElementById('target');
    function sync() {
        var byClass = target.value === 'class';
        document.getElementById('classBox').style.display = byClass ? '' : 'none';
        document.getElementById('studentsBox').style.display = byClass ? 'none' : '';
    }
    target.addEventListener('change', sync);
    sync();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
