<?php
/**
 * Управління запитаннями (адмін)
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
        $action     = $_POST['action'] ?? '';
        $questionId = (int)($_POST['question_id'] ?? 0);

        if ($action === 'add') {
            $topicId      = (int)($_POST['topic_id'] ?? 0);
            $questionText = trim($_POST['question_text'] ?? '');
            $answers      = $_POST['answers'] ?? [];
            $correctAnswer = (int)($_POST['correct_answer'] ?? 0);

            if (empty($questionText) || $topicId <= 0 || count($answers) < 2) {
                $error = 'Заповніть всі поля. Потрібно мінімум 2 варіанти відповідей.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO questions (topic_id, text) VALUES (?, ?)');
                $stmt->execute([$topicId, $questionText]);
                $newQuestionId = (int)$pdo->lastInsertId();

                foreach ($answers as $index => $answerText) {
                    $answerText = trim($answerText);
                    if (empty($answerText)) continue;
                    $isCorrect = ($index === $correctAnswer) ? 1 : 0;
                    $stmt = $pdo->prepare('INSERT INTO answers (question_id, text, is_correct) VALUES (?, ?, ?)');
                    $stmt->execute([$newQuestionId, $answerText, $isCorrect]);
                }
                $message = 'Запитання додано успішно.';
            }
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
    <button type="button" class="btn btn-primary" onclick="importQuestions()">Імпортувати</button>
    <div id="importResult"></div>
</div>

<!-- Форма додавання запитання -->
<div class="admin-form-card">
    <h2>Додати запитання вручну</h2>
    <form method="POST" action="" class="question-form" id="addQuestionForm">
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

        <div class="answers-editor">
            <p class="form-label">Варіанти відповідей (оберіть правильну):</p>
            <?php for ($i = 0; $i < 4; $i++): ?>
                <div class="answer-row">
                    <label class="answer-correct-label">
                        <input type="radio" name="correct_answer" value="<?= $i ?>" <?= $i === 0 ? 'required' : '' ?>>
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
    <form method="POST" action="" id="bulkForm" class="bulk-bar"
          onsubmit="return confirm('Видалити вибрані запитання (' + selectedCount() + ')?')">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="action" value="bulk_delete">
        <label class="bulk-select-all">
            <input type="checkbox" id="selectAll"> Вибрати всі на сторінці
        </label>
        <span class="bulk-count">Вибрано: <b id="selectedCount">0</b></span>
        <button type="submit" class="btn btn-danger btn-sm" id="bulkDeleteBtn" disabled>🗑 Видалити вибрані</button>
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
            <form method="POST" action="" class="answers-preview">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="set_correct">
                <input type="hidden" name="question_id" value="<?= (int)$question['id'] ?>">
                <?php foreach ($question['answers'] as $answer): ?>
                    <button type="submit" name="answer_id" value="<?= (int)$answer['id'] ?>"
                            class="answer-preview <?= $answer['is_correct'] ? 'correct' : '' ?>"
                            style="cursor: pointer; border: none; font: inherit; text-align: left"
                            title="Позначити як правильну">
                        <?= $answer['is_correct'] ? '✓' : '○' ?> <?= e($answer['text']) ?>
                    </button>
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
    const button  = document.getElementById('bulkDeleteBtn');
    if (!counter) return;
    counter.textContent = count;
    button.disabled = count === 0;
    selectAllBox.checked = count > 0 && count === questionBoxes.length;
    selectAllBox.indeterminate = count > 0 && count < questionBoxes.length;
    questionBoxes.forEach(box => box.closest('.question-admin-card').classList.toggle('selected', box.checked));
}

questionBoxes.forEach(box => box.addEventListener('change', updateBulkBar));
selectAllBox?.addEventListener('change', () => {
    questionBoxes.forEach(box => { box.checked = selectAllBox.checked; });
    updateBulkBar();
});

// Збереження позиції прокрутки після відправки форм на цій сторінці
const SCROLL_KEY = 'questionsScroll:' + location.pathname + location.search;
document.querySelectorAll('form[method="POST"]').forEach(form => {
    form.addEventListener('submit', () => sessionStorage.setItem(SCROLL_KEY, String(window.scrollY)));
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
    const jsonData = document.getElementById('jsonImportData').value.trim();
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
