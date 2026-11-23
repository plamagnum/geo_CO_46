<?php
/**
 * Управління запитаннями (адмін)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/images.php';

requireAdmin();

$pdo       = getDB();
ensureImageColumns($pdo);
$csrfToken = generateCsrfToken();
$message   = '';
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Помилка безпеки.';
    } else {
        $action     = $_POST['action'] ?? '';
        $questionId = (int)($_POST['question_id'] ?? 0);

        if ($action === 'add') {
            $topicId      = (int)($_POST['topic_id'] ?? 0);
            $questionText = trim($_POST['question_text'] ?? '');
            $answers      = $_POST['answers'] ?? [];
            $correctAnswers = array_map('intval', (array)($_POST['correct_answers'] ?? []));

            $questionImage = null;
            try {
                $questionImage = saveUploadedImage($_FILES['question_image'] ?? null);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }

            if ($error !== '') {
                // помилка картинки вже записана
            } elseif ((empty($questionText) && $questionImage === null) || $topicId <= 0 || count($answers) < 2) {
                $error = 'Заповніть всі поля. Потрібно мінімум 2 варіанти відповідей.';
            } elseif (!array_filter($correctAnswers, fn($i) => trim((string)($answers[$i] ?? '')) !== '')) {
                $error = 'Позначте хоча б одну правильну відповідь (серед заповнених варіантів).';
            } else {
                $stmt = $pdo->prepare('INSERT INTO questions (topic_id, text, image) VALUES (?, ?, ?)');
                $stmt->execute([$topicId, $questionText, $questionImage]);
                $newQuestionId = (int)$pdo->lastInsertId();

                foreach ($answers as $index => $answerText) {
                    $answerText = trim($answerText);
                    if (empty($answerText)) continue;
                    $isCorrect = in_array($index, $correctAnswers, true) ? 1 : 0;
                    $stmt = $pdo->prepare('INSERT INTO answers (question_id, text, is_correct) VALUES (?, ?, ?)');
                    $stmt->execute([$newQuestionId, $answerText, $isCorrect]);
                }
                $message = 'Запитання додано успішно.';
            }
        } elseif ($action === 'remove_image' && $questionId > 0) {
            $stmt = $pdo->prepare('UPDATE questions SET image = NULL WHERE id = ?');
            $stmt->execute([$questionId]);
            $message = 'Картинку прибрано із запитання.';
        } elseif (isset($_POST['toggle_answer_id']) && $questionId > 0) {
            $answerId = (int)$_POST['toggle_answer_id'];
            $stmt = $pdo->prepare('UPDATE answers SET is_correct = 1 - is_correct WHERE id = ? AND question_id = ?');
            $stmt->execute([$answerId, $questionId]);
            $message = $stmt->rowCount() ? 'Позначку правильної відповіді змінено.' : 'Відповідь не знайдено.';
        } elseif ($action === 'set_correct' && $questionId > 0) {
            $answerId = (int)($_POST['answer_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT id FROM answers WHERE id = ? AND question_id = ?');
            $stmt->execute([$answerId, $questionId]);
            if ($stmt->fetch()) {
                $stmt = $pdo->prepare('UPDATE answers SET is_correct = (id = ?) WHERE question_id = ?');
                $stmt->execute([$answerId, $questionId]);
                $message = 'Правильну відповідь збережено.';
            } else {
                $error = 'Відповідь не знайдено.';
            }
        } elseif ($action === 'bulk_move' || $action === 'bulk_class') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['question_ids'] ?? [])))));
            $in  = implode(',', array_fill(0, count($ids), '?'));

            // Знайти тему за (предмет, клас, назва) або створити нову
            $findOrCreateTopic = static function (int $subjectId, int $classId, string $name) use ($pdo): int {
                $stmt = $pdo->prepare('SELECT id FROM topics WHERE subject_id = ? AND class_id = ? AND name = ? LIMIT 1');
                $stmt->execute([$subjectId, $classId, $name]);
                $id = (int)$stmt->fetchColumn();
                if ($id === 0) {
                    $stmt = $pdo->prepare('INSERT INTO topics (subject_id, class_id, name) VALUES (?, ?, ?)');
                    $stmt->execute([$subjectId, $classId, $name]);
                    $id = (int)$pdo->lastInsertId();
                }
                return $id;
            };

            if (empty($ids)) {
                $error = 'Не вибрано жодного запитання.';
            } elseif ($action === 'bulk_move') {
                $target = $_POST['target_topic_id'] ?? '';
                $targetTopicId = 0;

                if ($target === 'new') {
                    $newClassId   = (int)($_POST['new_class_id'] ?? 0);
                    $newSubjectId = (int)($_POST['new_subject_id'] ?? 0);
                    $newName      = trim($_POST['new_topic_name'] ?? '');
                    if ($newClassId <= 0 || $newSubjectId <= 0 || $newName === '') {
                        $error = 'Для нової теми вкажіть клас, предмет і назву.';
                    } else {
                        $targetTopicId = $findOrCreateTopic($newSubjectId, $newClassId, $newName);
                    }
                } else {
                    $stmt = $pdo->prepare('SELECT id FROM topics WHERE id = ?');
                    $stmt->execute([(int)$target]);
                    $targetTopicId = (int)$stmt->fetchColumn();
                    if ($targetTopicId === 0) {
                        $error = 'Оберіть тему, у яку перенести запитання.';
                    }
                }

                if ($targetTopicId > 0) {
                    $stmt = $pdo->prepare("UPDATE questions SET topic_id = ? WHERE id IN ($in)");
                    $stmt->execute(array_merge([$targetTopicId], $ids));
                    $message = 'Перенесено запитань: ' . $stmt->rowCount() . '.';
                }
            } else {
                $targetClassId = (int)($_POST['target_class_id'] ?? 0);
                $stmt = $pdo->prepare('SELECT id FROM classes WHERE id = ?');
                $stmt->execute([$targetClassId]);

                if (!$stmt->fetchColumn()) {
                    $error = 'Оберіть клас.';
                } else {
                    // Для кожної вихідної теми — тема з тією ж назвою та предметом у новому класі
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT t.id, t.subject_id, t.name FROM questions q
                        JOIN topics t ON q.topic_id = t.id
                        WHERE q.id IN ($in) AND t.class_id <> ?
                    ");
                    $stmt->execute(array_merge($ids, [$targetClassId]));
                    $moved = 0;

                    $pdo->beginTransaction();
                    try {
                        foreach ($stmt->fetchAll() as $src) {
                            $dstId = $findOrCreateTopic((int)$src['subject_id'], $targetClassId, $src['name']);
                            $upd = $pdo->prepare("UPDATE questions SET topic_id = ? WHERE topic_id = ? AND id IN ($in)");
                            $upd->execute(array_merge([$dstId, (int)$src['id']], $ids));
                            $moved += $upd->rowCount();
                        }
                        $pdo->commit();
                        $message = 'Клас змінено для запитань: ' . $moved . '.';
                    } catch (Throwable $ex) {
                        $pdo->rollBack();
                        error_log('Помилка зміни класу: ' . $ex->getMessage());
                        $error = 'Не вдалося змінити клас.';
                    }
                }
            }
        } elseif ($action === 'bulk_delete') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['question_ids'] ?? [])))));
            if (empty($ids)) {
                $error = 'Не вибрано жодного запитання.';
            } else {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("DELETE FROM questions WHERE id IN ($in)");
                $stmt->execute($ids);
                $message = 'Видалено запитань: ' . $stmt->rowCount() . '.';
            }
        } elseif ($action === 'delete' && $questionId > 0) {
            $stmt = $pdo->prepare('DELETE FROM questions WHERE id = ?');
            $stmt->execute([$questionId]);
            $message = 'Запитання видалено.';
        }
    }
}

// Фільтрація
$filterTopic = (int)($_GET['topic_id'] ?? 0);
$filterUnmarked = !empty($_GET['unmarked']);

$hasCorrectSql = 'EXISTS (SELECT 1 FROM answers a WHERE a.question_id = q.id AND a.is_correct = 1)';
$unmarkedCount = (int)$pdo->query("SELECT COUNT(*) FROM questions q WHERE NOT $hasCorrectSql")->fetchColumn();

// Завантаження запитань
$questionsQuery = '
    SELECT q.*, t.name as topic_name, s.name as subject_name, c.name as class_name
    FROM questions q
    JOIN topics t ON q.topic_id = t.id
    JOIN subjects s ON t.subject_id = s.id
    JOIN classes c ON t.class_id = c.id
    WHERE 1=1
';
$params = [];

if ($filterTopic > 0) {
    $questionsQuery .= ' AND q.topic_id = ?';
    $params[] = $filterTopic;
}
if ($filterUnmarked) {
    $questionsQuery .= " AND NOT $hasCorrectSql";
}
$questionsQuery .= ' ORDER BY t.name, q.id DESC LIMIT 100';

$stmt = $pdo->prepare($questionsQuery);
$stmt->execute($params);
$questions = $stmt->fetchAll();

// Додавання відповідей до кожного запитання
foreach ($questions as &$question) {
    $stmt = $pdo->prepare('SELECT * FROM answers WHERE question_id = ? ORDER BY id');
    $stmt->execute([$question['id']]);
    $question['answers'] = $stmt->fetchAll();
}
unset($question);

// Дані для форм
$topics   = $pdo->query('
    SELECT t.*, s.name as subject_name, c.name as class_name
    FROM topics t
    JOIN subjects s ON t.subject_id = s.id
    JOIN classes c ON t.class_id = c.id
    ORDER BY s.name, c.id, t.name
')->fetchAll();

$classes  = $pdo->query('SELECT * FROM classes ORDER BY id')->fetchAll();
$subjects = $pdo->query('SELECT * FROM subjects ORDER BY name')->fetchAll();

// Теми, згруповані за класом (для списку перенесення)
$topicsByClass = [];
foreach ($topics as $topic) {
    $topicsByClass[$topic['class_name']][] = $topic;
}

$pageTitle = 'Управління запитаннями';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-header">
    <h1 class="page-title">Управління запитаннями</h1>
    <div class="header-actions">
        <a href="/admin/index.php" class="btn btn-outline">← Назад</a>
        <button type="button" class="btn btn-secondary" onclick="toggleImportForm()">📥 Імпорт JSON</button>
    </div>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<!-- Форма імпорту JSON -->
<div class="admin-form-card" id="importForm" style="display:none">
    <h2>Імпорт запитань через JSON</h2>
    <p>Вставте JSON у форматі API або <a href="/api/import-questions.php" target="_blank">використайте API endpoint</a>.</p>
    <div class="form-group">
        <label class="form-label">JSON дані</label>
        <textarea id="jsonImportData" class="form-textarea" rows="10" placeholder='{"topic_id": 1, "questions": [...]}'></textarea>
    </div>
    <div class="form-group">
        <label class="form-label">…або виберіть JSON-файл від парсера (зручно, якщо в ньому є картинки)</label>
        <input type="file" id="jsonImportFile" class="form-input" accept=".json,application/json">
    </div>
    <button type="button" class="btn btn-primary" onclick="importQuestions()">Імпортувати</button>
    <div id="importResult"></div>
</div>

<!-- Форма додавання запитання -->
<div class="admin-form-card">
    <h2>Додати запитання вручну</h2>
    <form method="POST" action="" class="question-form" id="addQuestionForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="action" value="add">

        <div class="form-group">
            <label class="form-label">Тема</label>
            <select name="topic_id" class="form-select" required>
                <option value="">Оберіть тему</option>
                <?php foreach ($topics as $topic): ?>
                    <option value="<?= (int)$topic['id'] ?>">
                        <?= e($topic['subject_name'] . ' / ' . $topic['class_name'] . ' / ' . $topic['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">Текст запитання</label>
            <textarea name="question_text" class="form-textarea" rows="3" placeholder="Введіть текст запитання..." required maxlength="1000"></textarea>
        </div>

        <div class="form-group">
            <label class="form-label">Картинка (необов'язково)</label>
            <input type="file" name="question_image" class="form-input" accept="image/jpeg,image/png,image/gif,image/webp">
        </div>

        <div class="answers-editor">
            <p class="form-label">Варіанти відповідей (позначте правильну; можна кілька — тоді учень обиратиме галочками):</p>
            <?php for ($i = 0; $i < 4; $i++): ?>
                <div class="answer-row">
                    <label class="answer-correct-label">
                        <input type="checkbox" name="correct_answers[]" value="<?= $i ?>">
                        <span class="radio-dot"></span>
                    </label>
                    <input
                        type="text"
                        name="answers[]"
                        class="form-input"
                        placeholder="Варіант <?= $i + 1 ?>"
                        maxlength="500"
                        <?= $i < 2 ? 'required' : '' ?>
                    >
                </div>
            <?php endfor; ?>
        </div>

        <button type="submit" class="btn btn-primary">Додати запитання</button>
    </form>
</div>

<!-- Фільтр -->
<form method="GET" action="" class="filters-form">
    <div class="filter-group">
        <label class="filter-label">Фільтр за темою:</label>
        <select name="topic_id" class="form-select" onchange="this.form.submit()">
            <option value="0">Всі теми</option>
            <?php foreach ($topics as $topic): ?>
                <option value="<?= (int)$topic['id'] ?>" <?= $filterTopic == $topic['id'] ? 'selected' : '' ?>>
                    <?= e($topic['subject_name'] . ' / ' . $topic['class_name'] . ' / ' . $topic['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-label">
            <input type="checkbox" name="unmarked" value="1" <?= $filterUnmarked ? 'checked' : '' ?> onchange="this.form.submit()">
            Лише без правильної відповіді (<?= $unmarkedCount ?>)
        </label>
    </div>
</form>

<?php if ($unmarkedCount > 0): ?>
    <div class="alert alert-error">
        ⚠ <?= $unmarkedCount ?> запитань без правильної відповіді — вони не потрапляють у тести.
        Натисніть на правильний варіант, щоб позначити його.
    </div>
<?php endif; ?>

<!-- Масові дії -->
<?php if (!empty($questions)): ?>
    <form method="POST" action="" id="bulkForm" class="bulk-bar" onsubmit="return confirmBulk(event)">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <div class="bulk-row">
            <label class="bulk-select-all">
                <input type="checkbox" id="selectAll"> Вибрати всі на сторінці
            </label>
            <span class="bulk-count">Вибрано: <b id="selectedCount">0</b></span>
            <button type="submit" name="action" value="bulk_delete" class="btn btn-danger btn-sm bulk-btn" disabled>🗑 Видалити вибрані</button>
        </div>

        <div class="bulk-row bulk-edit" id="bulkEdit" hidden>
            <div class="bulk-group">
                <label for="targetTopic">Перенести в тему:</label>
                <select name="target_topic_id" id="targetTopic" class="form-select">
                    <option value="">— оберіть тему —</option>
                    <option value="new">➕ Нова тема…</option>
                    <?php foreach ($topicsByClass as $className => $classTopics): ?>
                        <optgroup label="<?= e($className) ?>">
                            <?php foreach ($classTopics as $topic): ?>
                                <option value="<?= (int)$topic['id'] ?>"><?= e($topic['subject_name'] . ' · ' . $topic['name']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <span id="newTopicFields" class="bulk-group" hidden>
                    <select name="new_class_id" class="form-select" aria-label="Клас нової теми">
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= (int)$class['id'] ?>"><?= e($class['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="new_subject_id" class="form-select" aria-label="Предмет нової теми">
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?= (int)$subject['id'] ?>"><?= e($subject['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="new_topic_name" class="form-input" placeholder="Назва теми" maxlength="200">
                </span>
                <button type="submit" name="action" value="bulk_move" class="btn btn-primary btn-sm bulk-btn" disabled>Перенести</button>
            </div>

            <div class="bulk-group">
                <label for="targetClass">Змінити клас:</label>
                <select name="target_class_id" id="targetClass" class="form-select">
                    <option value="">— оберіть клас —</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= (int)$class['id'] ?>"><?= e($class['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="action" value="bulk_class" class="btn btn-primary btn-sm bulk-btn" disabled
                        title="Тема з тією ж назвою та предметом буде знайдена або створена в обраному класі">Змінити</button>
            </div>
        </div>
    </form>
<?php endif; ?>

<!-- Список запитань -->
<div class="questions-admin-list">
    <?php foreach ($questions as $question): ?>
        <?php $hasCorrect = (bool)array_filter($question['answers'], fn($a) => $a['is_correct']); ?>
        <div class="question-admin-card" <?= $hasCorrect ? '' : 'style="border-left: 4px solid var(--danger)"' ?>>
            <div class="question-admin-header">
                <div class="question-admin-meta">
                    <input type="checkbox" class="question-select" name="question_ids[]" form="bulkForm"
                           value="<?= (int)$question['id'] ?>" aria-label="Вибрати запитання">
                    <span class="badge"><?= e($question['class_name']) ?></span>
                    <span class="badge badge-secondary"><?= e($question['subject_name']) ?></span>
                    <span class="badge badge-info"><?= e($question['topic_name']) ?></span>
                    <?php if (!$hasCorrect): ?>
                        <span class="badge" style="background: var(--danger); color: #fff">Без відповіді</span>
                    <?php endif; ?>
                </div>
                <form method="POST" action="" style="display:inline" onsubmit="return confirm('Видалити запитання?')">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="question_id" value="<?= (int)$question['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm">✕</button>
                </form>
            </div>
            <p class="question-admin-text"><?= e($question['text']) ?></p>
            <?php if (imageUrl($question['image'] ?? null)): ?>
                <div class="q-image-admin">
                    <?= imageTag($question['image'], 'q-image') ?>
                    <form method="POST" action="" onsubmit="return confirm('Прибрати картинку із запитання?')">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="remove_image">
                        <input type="hidden" name="question_id" value="<?= (int)$question['id'] ?>">
                        <button type="submit" class="btn btn-outline btn-sm">Прибрати картинку</button>
                    </form>
                </div>
            <?php endif; ?>
            <form method="POST" action="" class="answers-preview">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="set_correct">
                <input type="hidden" name="question_id" value="<?= (int)$question['id'] ?>">
                <?php $correctTotal = count(array_filter($question['answers'], fn($a) => (int)$a['is_correct'] === 1)); ?>
                <?php if ($correctTotal > 1): ?>
                    <span class="badge badge-multi">☑ кілька правильних (<?= $correctTotal ?>) — учень обирає галочками</span>
                <?php endif; ?>
                <?php foreach ($question['answers'] as $answer): ?>
                    <div class="answer-preview-row">
                        <button type="submit" name="answer_id" value="<?= (int)$answer['id'] ?>"
                                class="answer-preview <?= $answer['is_correct'] ? 'correct' : '' ?>"
                                style="cursor: pointer; border: none; font: inherit; text-align: left"
                                title="Зробити єдиною правильною">
                            <?= $answer['is_correct'] ? '✓' : '○' ?> <?= e($answer['text']) ?><?= imageTag($answer['image'] ?? null, 'a-image') ?>
                        </button>
                        <button type="submit" name="toggle_answer_id" value="<?= (int)$answer['id'] ?>"
                                class="btn btn-outline btn-sm answer-toggle"
                                title="<?= $answer['is_correct'] ? 'Зняти позначку правильної' : 'Додати як ще одну правильну' ?>">
                            <?= $answer['is_correct'] ? '−' : '+' ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </form>
        </div>
    <?php endforeach; ?>

    <?php if (empty($questions)): ?>
        <div class="empty-state">
            <div class="empty-icon">❓</div>
            <p>Запитань не знайдено. Додайте перше запитання!</p>
        </div>
    <?php endif; ?>
</div>

<script>
// Масовий вибір
const questionBoxes = document.querySelectorAll('.question-select');
const selectAllBox  = document.getElementById('selectAll');

function selectedCount() {
    return document.querySelectorAll('.question-select:checked').length;
}

function updateBulkBar() {
    const count = selectedCount();
    const counter = document.getElementById('selectedCount');
    if (!counter) return;
    counter.textContent = count;
    document.querySelectorAll('.bulk-btn').forEach(btn => { btn.disabled = count === 0; });
    document.getElementById('bulkEdit').hidden = count === 0;
    selectAllBox.checked = count > 0 && count === questionBoxes.length;
    selectAllBox.indeterminate = count > 0 && count < questionBoxes.length;
    questionBoxes.forEach(box => box.closest('.question-admin-card').classList.toggle('selected', box.checked));
}

questionBoxes.forEach(box => box.addEventListener('change', updateBulkBar));

document.getElementById('targetTopic')?.addEventListener('change', (e) => {
    document.getElementById('newTopicFields').hidden = e.target.value !== 'new';
});

function confirmBulk(event) {
    const form   = event.target;
    const action = event.submitter ? event.submitter.value : '';
    const count  = selectedCount();

    if (action === 'bulk_delete') {
        return confirm('Видалити вибрані запитання (' + count + ')?');
    }
    if (action === 'bulk_move') {
        const sel = form.target_topic_id;
        if (!sel.value) { alert('Оберіть тему.'); sel.focus(); return false; }
        if (sel.value === 'new' && !form.new_topic_name.value.trim()) {
            alert('Вкажіть назву нової теми.'); form.new_topic_name.focus(); return false;
        }
        const name = sel.value === 'new' ? form.new_topic_name.value.trim() : sel.options[sel.selectedIndex].text;
        return confirm('Перенести ' + count + ' запит. у тему «' + name + '»?');
    }
    if (action === 'bulk_class') {
        const sel = form.target_class_id;
        if (!sel.value) { alert('Оберіть клас.'); sel.focus(); return false; }
        return confirm('Змінити клас для ' + count + ' запит. на «' + sel.options[sel.selectedIndex].text + '»?');
    }
    return true;
}
selectAllBox?.addEventListener('change', () => {
    questionBoxes.forEach(box => { box.checked = selectAllBox.checked; });
    updateBulkBar();
});

// Збереження позиції прокрутки після відправки форм на цій сторінці
const SCROLL_KEY = 'questionsScroll:' + location.pathname + location.search;
document.querySelectorAll('form[method="POST"]').forEach(form => {
    form.addEventListener('submit', (e) => {
        if (!e.defaultPrevented) sessionStorage.setItem(SCROLL_KEY, String(window.scrollY));
    });
});
(function restoreScroll() {
    const saved = sessionStorage.getItem(SCROLL_KEY);
    if (saved === null) return;
    sessionStorage.removeItem(SCROLL_KEY);
    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
    window.scrollTo(0, parseInt(saved, 10) || 0);
    // Повідомлення вгорі сторінки не видно — показуємо його поверх контенту
    document.querySelectorAll('.admin-header ~ .alert-success, .admin-header ~ .alert-error').forEach((alert, i) => {
        if (i > 1) return;
        alert.classList.add('alert-floating');
        setTimeout(() => alert.classList.remove('alert-floating'), 4000);
    });
})();

function toggleImportForm() {
    const form = document.getElementById('importForm');
    const isHidden = form.style.display === 'none';
    form.style.display = isHidden ? 'block' : 'none';
    if (isHidden) {
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        document.getElementById('jsonImportData').focus({ preventScroll: true });
    }
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
}

async function importQuestions() {
    const fileInput = document.getElementById('jsonImportFile');
    const jsonData = fileInput.files.length
        ? (await fileInput.files[0].text()).trim()
        : document.getElementById('jsonImportData').value.trim();
    const resultDiv = document.getElementById('importResult');

    if (!jsonData) {
        resultDiv.innerHTML = '<div class="alert alert-error">Введіть JSON дані</div>';
        return;
    }

    try {
        JSON.parse(jsonData); // Перевірка валідності JSON
    } catch (e) {
        resultDiv.innerHTML = '<div class="alert alert-error">Невірний формат JSON</div>';
        return;
    }

    resultDiv.innerHTML = '<div class="alert">Імпортую...</div>';

    try {
        const response = await fetch('/api/import-questions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: jsonData,
        });
        if (response.status === 413) {
            resultDiv.innerHTML = '<div class="alert alert-error">Файл завеликий для сервера (збільште client_max_body_size у nginx).</div>';
            return;
        }
        const result = await response.json();

        const errorsHtml = (result.errors || []).length
            ? '<ul>' + result.errors.map(err => `<li>${escapeHtml(err)}</li>`).join('') + '</ul>'
            : '';

        if (result.success && result.imported > 0) {
            const unmarkedHtml = result.without_correct > 0
                ? `<div class="alert alert-error">⚠ ${result.without_correct} з них без правильної відповіді. <a href="?unmarked=1">Позначити відповіді →</a></div>`
                : '';
            resultDiv.innerHTML = `<div class="alert alert-success">✓ Імпортовано ${result.imported} запитань!</div>`
                + unmarkedHtml
                + (errorsHtml ? `<div class="alert alert-error">Пропущено:${errorsHtml}</div>` : '');
            if (!errorsHtml && !unmarkedHtml) {
                setTimeout(() => location.reload(), 2000);
            }
        } else if (result.success) {
            resultDiv.innerHTML = `<div class="alert alert-error">Жодного запитання не імпортовано.${errorsHtml}</div>`;
        } else {
            resultDiv.innerHTML = `<div class="alert alert-error">Помилка: ${escapeHtml(result.error || 'Невідома помилка')}</div>`;
        }
    } catch (e) {
        resultDiv.innerHTML = '<div class="alert alert-error">Помилка з\'єднання з сервером</div>';
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
