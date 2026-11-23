<?php
/**
 * Надсилання завдань учням (адмін)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/assignments.php';
require_once __DIR__ . '/../config/database.php';

requireAdmin();

$pdo = getDB();

function cancelForm(string $csrf, int $aid, string $scope, string $label, array $extra = [], string $confirm = 'Скасувати завдання?'): string {
    $h = '<form method="POST" action="?view=' . $aid . '" style="display:inline" onsubmit="return confirm(\'' . e($confirm) . '\');">'
       . '<input type="hidden" name="csrf_token" value="' . e($csrf) . '">'
       . '<input type="hidden" name="action" value="cancel">'
       . '<input type="hidden" name="assignment_id" value="' . $aid . '">'
       . '<input type="hidden" name="scope" value="' . e($scope) . '">';
    foreach ($extra as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . (int)$v . '">';
    }
    return $h . '<button type="submit" class="btn btn-sm btn-outline">' . e($label) . '</button></form>';
}

ensureSchoolsSchema($pdo);
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
            $game    = (string)($_POST['game'] ?? '');
            $target  = $_POST['target'] ?? '';
            $classId = (int)($_POST['class_id'] ?? 0);
            $schoolId = (int)($_POST['school_id'] ?? 0);
            $userIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['user_ids'] ?? [])))));

            $readyStmt = $pdo->prepare('
                SELECT COUNT(*) FROM questions q WHERE q.topic_id = ?
                  AND EXISTS (SELECT 1 FROM answers an WHERE an.question_id = q.id AND an.is_correct = 1)
            ');
            $readyStmt->execute([$topicId]);

            if ($topicId <= 0 || !isset(ASSIGNMENT_TYPES[$type])) {
                $error = 'Оберіть тему та тип завдання.';
            } elseif ($game !== '' && !getGame($game)) {
                $error = 'Обрану гру не знайдено.';
            } elseif ((int)$readyStmt->fetchColumn() === 0) {
                $error = 'У цій темі немає запитань з позначеною правильною відповіддю. Позначте їх у розділі «Запитання».';
            } else {
                $recipients = [];
                if ($target === 'class' && $classId > 0) {
                    $sql = 'SELECT id FROM users WHERE role = "user" AND class_id = ?';
                    $args = [$classId];
                    if ($schoolId > 0) {
                        $sql .= ' AND school_id = ?';
                        $args[] = $schoolId;
                    }
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($args);
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
                        $stmt = $pdo->prepare('INSERT INTO assignments (topic_id, type, game, created_by) VALUES (?, ?, ?, ?)');
                        $stmt->execute([$topicId, $type, $game !== '' ? $game : null, (int)$_SESSION['user_id']]);
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
        } elseif ($action === 'cancel') {
            $aid   = (int)($_POST['assignment_id'] ?? 0);
            $scope = $_POST['scope'] ?? '';
            $sid   = (int)($_POST['school_id'] ?? 0);
            $cid   = (int)($_POST['class_id'] ?? 0);
            $uid   = (int)($_POST['user_id'] ?? 0);
            // Скасовуються лише невиконані; виконані результати зберігаються
            $sql = 'DELETE ar FROM assignment_recipients ar JOIN users u ON u.id = ar.user_id
                    WHERE ar.assignment_id = ? AND ar.result_id IS NULL';
            $args = [$aid];
            if ($scope === 'user' && $uid > 0) {
                $sql .= ' AND u.id = ?'; $args[] = $uid;
            } elseif ($scope === 'class' && $cid > 0) {
                $sql .= ' AND u.class_id = ?'; $args[] = $cid;
                $sql .= $sid > 0 ? ' AND u.school_id = ?' : ' AND u.school_id IS NULL';
                if ($sid > 0) $args[] = $sid;
            } elseif ($scope === 'school') {
                $sql .= $sid > 0 ? ' AND u.school_id = ?' : ' AND u.school_id IS NULL';
                if ($sid > 0) $args[] = $sid;
            } elseif ($scope !== 'all') {
                $sql = null;
            }
            if ($sql === null || $aid <= 0) {
                $error = 'Некоректний запит.';
            } else {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($args);
                $message = 'Завдання скасовано для невиконаних учнів: ' . $stmt->rowCount() . '.';
                $left = $pdo->prepare('SELECT COUNT(*) FROM assignment_recipients WHERE assignment_id = ?');
                $left->execute([$aid]);
                if ((int)$left->fetchColumn() === 0) {
                    $pdo->prepare('DELETE FROM assignments WHERE id = ?')->execute([$aid]);
                    $message .= ' Отримувачів не лишилось — завдання видалено.';
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
$schools = listSchools($pdo);
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
    SELECT u.id, u.first_name, u.last_name, u.class_id, u.school_id, c.name AS class_name
    FROM users u LEFT JOIN classes c ON u.class_id = c.id
    WHERE u.role = "user"
    ORDER BY u.school_id, u.class_id, u.last_name, u.first_name
')->fetchAll();

$assignments = $pdo->query('
    SELECT a.*, t.name AS topic_name, s.name AS subject_name,
           (SELECT COUNT(*) FROM questions q WHERE q.topic_id = a.topic_id
              AND EXISTS (SELECT 1 FROM answers an WHERE an.question_id = q.id AND an.is_correct = 1)) AS ready_questions,
           COUNT(ar.id) AS recipients_count,
           (SELECT GROUP_CONCAT(DISTINCT CONCAT(COALESCE(sc2.name, "Без школи"), " · ", COALESCE(c2.name, "—")) SEPARATOR "; ")
              FROM assignment_recipients ar2 JOIN users u2 ON u2.id = ar2.user_id
              LEFT JOIN schools sc2 ON sc2.id = u2.school_id LEFT JOIN classes c2 ON c2.id = u2.class_id
              WHERE ar2.assignment_id = a.id) AS audience,
           SUM(ar.result_id IS NOT NULL) AS done_count
    FROM assignments a
    JOIN topics t ON a.topic_id = t.id
    JOIN subjects s ON t.subject_id = s.id
    LEFT JOIN assignment_recipients ar ON ar.assignment_id = a.id
    GROUP BY a.id
    ORDER BY a.created_at DESC
    LIMIT 50
')->fetchAll();

// Деталі: кому призначено (школа → клас → учень)
$viewId = (int)($_GET['view'] ?? 0);
$detail = null;
if ($viewId > 0) {
    $stmt = $pdo->prepare('
        SELECT a.*, t.name AS topic_name, s.name AS subject_name FROM assignments a
        JOIN topics t ON a.topic_id = t.id JOIN subjects s ON t.subject_id = s.id WHERE a.id = ?');
    $stmt->execute([$viewId]);
    $detail = $stmt->fetch() ?: null;
    if ($detail) {
        $stmt = $pdo->prepare('
            SELECT u.id, u.first_name, u.last_name, u.school_id, u.class_id,
                   sc.name AS school_name, c.name AS class_name, ar.result_id, tr.score_percent
            FROM assignment_recipients ar
            JOIN users u ON u.id = ar.user_id
            LEFT JOIN schools sc ON sc.id = u.school_id
            LEFT JOIN classes c ON c.id = u.class_id
            LEFT JOIN test_results tr ON tr.id = ar.result_id
            WHERE ar.assignment_id = ?
            ORDER BY sc.name, u.class_id, u.last_name, u.first_name');
        $stmt->execute([$viewId]);
        $tree = [];
        foreach ($stmt->fetchAll() as $r) {
            $sk = (int)$r['school_id'];
            $ck = (int)$r['class_id'];
            $tree[$sk]['name'] = $r['school_name'] ?? 'Без школи';
            $tree[$sk]['classes'][$ck]['name'] = $r['class_name'] ?? 'Без класу';
            $tree[$sk]['classes'][$ck]['users'][] = $r;
        }
    }
}

$pageTitle = 'Завдання';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-header">
    <h1 class="page-title">Завдання</h1>
    <a href="/admin/index.php" class="btn btn-outline">← Назад</a>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<?php if ($detail): ?>
<div class="admin-form-card">
    <p><a href="/admin/assignments.php" class="btn btn-outline btn-sm">← До списку завдань</a></p>
    <h2><?= e($detail['subject_name'] . ' · ' . $detail['topic_name']) ?></h2>
    <p>
        <span class="badge badge-info"><?= e(assignmentTypeLabel($detail['type'])) ?></span><?= gameBadge($detail['game'] ?? null) ?>
        <small class="text-muted"> надіслано <?= date('d.m.Y H:i', strtotime($detail['created_at'])) ?></small>
    </p>
    <?php if (empty($tree)): ?>
        <p class="text-muted">Невиконаних отримувачів не лишилось.</p>
    <?php else: ?>
        <p><?= cancelForm($csrfToken, (int)$detail['id'], 'all', 'Скасувати для всіх невиконаних', [], 'Скасувати завдання для всіх, хто ще не виконав?') ?></p>
        <?php foreach ($tree as $sid => $school): ?>
            <h3>🏛 <?= e($school['name']) ?>
                <?= cancelForm($csrfToken, (int)$detail['id'], 'school', 'Скасувати для школи', ['school_id' => $sid], 'Скасувати для невиконаних учнів цієї школи?') ?></h3>
            <?php foreach ($school['classes'] as $cid => $class): ?>
                <h4>🏫 <?= e($class['name']) ?>
                    <?= cancelForm($csrfToken, (int)$detail['id'], 'class', 'Скасувати для класу', ['school_id' => $sid, 'class_id' => $cid], 'Скасувати для невиконаних учнів цього класу?') ?></h4>
                <div class="table-wrapper"><table class="admin-table">
                    <thead><tr><th>Учень</th><th>Статус</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($class['users'] as $u): ?>
                        <tr>
                            <td><?= e($u['last_name'] . ' ' . $u['first_name']) ?></td>
                            <td><?php if ($u['result_id']): $g = scoreToGrade((float)$u['score_percent']); ?>
                                <span class="score-badge <?= isGoodScore((float)$u['score_percent']) ? 'good' : 'bad' ?>"><?= $g ?> <?= gradeWord($g) ?></span>
                                <?php else: ?><span class="badge badge-secondary">Очікує виконання</span><?php endif; ?></td>
                            <td><?= $u['result_id'] ? '' : cancelForm($csrfToken, (int)$detail['id'], 'user', 'Скасувати', ['user_id' => $u['id']], 'Скасувати для цього учня?') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

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
            <label class="form-label" for="game">Формат</label>
            <select name="game" id="game" class="form-select">
                <option value="">📝 Звичайний тест</option>
                <?php foreach (listGames() as $g): ?>
                    <option value="<?= e($g['slug']) ?>" title="<?= e($g['description']) ?>"><?= e($g['icon'] . ' Гра «' . $g['name'] . '»') ?></option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted" id="gameDescription"></small>
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

        <div class="form-group">
            <label class="form-label" for="school_id">Школа</label>
            <select name="school_id" id="school_id" class="form-select">
                <option value="0">Усі школи</option>
                <?php foreach ($schools as $sc): ?>
                    <option value="<?= (int)$sc['id'] ?>"><?= e($sc['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label" for="class_id">Клас</label>
            <select name="class_id" id="class_id" class="form-select">
                <option value="0" id="anyClass" hidden>Усі класи</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" id="studentsBox" style="display:none">
            <label class="form-label" for="user_ids">Учні (Ctrl/Cmd — кілька; список відфільтровано за школою та класом)</label>
            <select name="user_ids[]" id="user_ids" class="form-select" multiple size="10">
                <?php foreach ($students as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" data-school="<?= (int)$u['school_id'] ?>" data-class="<?= (int)$u['class_id'] ?>">
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
                <tr><th>Тип</th><th>Предмет</th><th>Тема</th><th>Кому (школа · клас)</th><th>Виконано</th><th>Дата</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($assignments as $a): ?>
                    <tr>
                        <td><span class="badge badge-info"><?= e(assignmentTypeLabel($a['type'])) ?></span><?= gameBadge($a['game'] ?? null) ?></td>
                        <td><?= e($a['subject_name']) ?></td>
                        <td>
                            <?= e($a['topic_name']) ?>
                            <?php if ((int)$a['ready_questions'] === 0): ?>
                                <br><small class="text-danger">⚠ немає запитань з правильною відповіддю — учні не зможуть виконати</small>
                            <?php endif; ?>
                        </td>
                        <td><small><?= e($a['audience'] ?? '—') ?></small></td>
                        <td><?= (int)$a['done_count'] ?> / <?= (int)$a['recipients_count'] ?></td>
                        <td><?= date('d.m.Y H:i', strtotime($a['created_at'])) ?></td>
                        <td>
                            <a href="?view=<?= (int)$a['id'] ?>" class="btn btn-sm btn-primary">Кому / скасувати</a>
                            <form method="POST" action="" style="display:inline" onsubmit="return confirm('Видалити завдання повністю разом з історією виконання?');">
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
    var school = document.getElementById('school_id');
    var cls = document.getElementById('class_id');
    var anyClass = document.getElementById('anyClass');
    var students = document.getElementById('user_ids');
    function filterStudents() {
        Array.prototype.forEach.call(students.options, function (o) {
            var ok = (school.value === '0' || o.dataset.school === school.value)
                  && (cls.value === '0' || o.dataset.class === cls.value);
            o.hidden = !ok;
            o.disabled = !ok;
            if (!ok) o.selected = false;
        });
    }
    function sync() {
        var byClass = target.value === 'class';
        document.getElementById('studentsBox').style.display = byClass ? 'none' : '';
        // «Усі класи» має сенс лише для вибору окремих учнів
        anyClass.hidden = byClass;
        anyClass.disabled = byClass;
        if (byClass && cls.value === '0') cls.selectedIndex = 1;
        filterStudents();
    }
    target.addEventListener('change', sync);
    school.addEventListener('change', filterStudents);
    cls.addEventListener('change', filterStudents);
    sync();

    var game = document.getElementById('game');
    function describe() {
        var opt = game.options[game.selectedIndex];
        document.getElementById('gameDescription').textContent = opt.value ? opt.title : '';
    }
    game.addEventListener('change', describe);
    describe();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
