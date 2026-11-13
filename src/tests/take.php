<?php
/**
 * Проходження тесту
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/assignments.php';
require_once __DIR__ . '/../includes/images.php';

requireLogin();

$pdo = getDB();
ensureImageColumns($pdo);
ensureAssignmentTables($pdo);
$topicId = (int)($_GET['topic_id'] ?? 0);
$userId  = (int)$_SESSION['user_id'];

if ($topicId <= 0) {
    header('Location: /tests/index.php');
    exit;
}

// Завантаження теми
$stmt = $pdo->prepare('
    SELECT t.*, s.name as subject_name, c.name as class_name
    FROM topics t
    JOIN subjects s ON t.subject_id = s.id
    JOIN classes c ON t.class_id = c.id
    WHERE t.id = ?
');
$stmt->execute([$topicId]);
$topic = $stmt->fetch();

if (!$topic) {
    header('Location: /tests/index.php');
    exit;
}

// Призначене завдання (необов'язково)
$assignmentId = (int)($_GET['assignment_id'] ?? $_POST['assignment_id'] ?? 0);
$assignment = null;
if ($assignmentId > 0) {
    $stmt = $pdo->prepare('
        SELECT a.*, ar.result_id FROM assignments a
        JOIN assignment_recipients ar ON ar.assignment_id = a.id
        WHERE a.id = ? AND ar.user_id = ? AND a.topic_id = ?
    ');
    $stmt->execute([$assignmentId, $userId, $topicId]);
    $assignment = $stmt->fetch() ?: null;
}

// Завантаження запитань з відповідями
// Лише запитання з позначеною правильною відповіддю
$stmt = $pdo->prepare('
    SELECT q.* FROM questions q
    WHERE q.topic_id = ?
      AND EXISTS (SELECT 1 FROM answers a WHERE a.question_id = q.id AND a.is_correct = 1)
    ORDER BY RAND()
');
$stmt->execute([$topicId]);
$questions = $stmt->fetchAll();

if (empty($questions)) {
    header('Location: ' . ($assignment ? '/tests/assignments.php' : '/tests/index.php') . '?error=no_questions');
    exit;
}

// Завантаження відповідей для кожного запитання
foreach ($questions as &$question) {
    $stmt = $pdo->prepare('SELECT * FROM answers WHERE question_id = ? ORDER BY RAND()');
    $stmt->execute([$question['id']]);
    $question['answers'] = $stmt->fetchAll();
    $question['correct_ids'] = array_map('intval', array_column(
        array_filter($question['answers'], fn($a) => (int)$a['is_correct'] === 1), 'id'
    ));
    sort($question['correct_ids']);
    // Кілька правильних відповідей — учень обирає галочками
    $question['multiple'] = count($question['correct_ids']) > 1;
}
unset($question);

// Обробка результатів після завершення тесту (AJAX запит)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (ob_get_level() === 0) {
        ob_start();
    }

    $sendJson = static function (array $payload, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        if (ob_get_length() !== false && ob_get_length() > 0) {
            ob_clean();
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    };

    if (!isset($_POST['action']) || $_POST['action'] !== 'submit_test') {
        $sendJson(['success' => false, 'error' => 'Некоректний тип запиту'], 400);
    }

    // Перевірка CSRF
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $sendJson(['success' => false, 'error' => 'Помилка безпеки'], 400);
    }

    try {
        $userAnswers = $_POST['answers'] ?? [];
        $correctCount = 0;
        $totalCount = count($questions);
        $results = [];

        foreach ($questions as $question) {
            $validIds = array_map('intval', array_column($question['answers'], 'id'));
            $raw      = $userAnswers[$question['id']] ?? [];
            $selected = array_values(array_unique(array_intersect(
                array_map('intval', is_array($raw) ? $raw : [$raw]), $validIds
            )));
            sort($selected);

            // Зараховується лише точний збіг: обрано всі правильні й жодної зайвої
            $isCorrect = $selected === $question['correct_ids'];
            if ($isCorrect) {
                $correctCount++;
            }

            $results[$question['id']] = [
                'selected'   => $selected,
                'correct'    => $question['correct_ids'],
                'is_correct' => $isCorrect,
            ];
        }

        $scorePercent = $totalCount > 0 ? round(($correctCount / $totalCount) * 100, 2) : 0;

        // Збереження результату в БД
        $stmt = $pdo->prepare('
            INSERT INTO test_results (user_id, topic_id, total_questions, correct_answers, score_percent, assignment_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$userId, $topicId, $totalCount, $correctCount, $scorePercent, $assignment ? $assignmentId : null]);
        $resultId = $pdo->lastInsertId();

        if ($assignment && empty($assignment['result_id'])) {
            $stmt = $pdo->prepare('
                UPDATE assignment_recipients SET result_id = ?
                WHERE assignment_id = ? AND user_id = ? AND result_id IS NULL
            ');
            $stmt->execute([$resultId, $assignmentId, $userId]);
        }

        // Середня оцінка за всі спроби (у межах завдання або теми)
        if ($assignment) {
            $stmt = $pdo->prepare('SELECT GROUP_CONCAT(score_percent) FROM test_results WHERE user_id = ? AND assignment_id = ?');
            $stmt->execute([$userId, $assignmentId]);
        } else {
            $stmt = $pdo->prepare('SELECT GROUP_CONCAT(score_percent) FROM test_results WHERE user_id = ? AND topic_id = ?');
            $stmt->execute([$userId, $topicId]);
        }
        $summary = attemptsSummary($stmt->fetchColumn() ?: null);

        $sendJson([
            'success'        => true,
            'attempts'       => $summary['attempts'],
            'average_grade'  => $summary['avg'],
            'correct'        => $correctCount,
            'total'          => $totalCount,
            'score_percent'  => $scorePercent,
            'grade'          => scoreToGrade((float)$scorePercent),
            'results'        => $results,
            'result_id'      => $resultId,
        ]);
    } catch (Throwable $e) {
        error_log('Помилка збереження результату тесту: ' . $e->getMessage());
        $sendJson([
            'success' => false,
            'error' => 'Не вдалося зберегти результат тесту. Спробуйте ще раз.'
        ], 500);
    }
}

$csrfToken = generateCsrfToken();
$pageTitle = 'Тест: ' . $topic['name'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="test-page" data-topic-id="<?= (int)$topicId ?>">
    <div class="test-header">
        <div class="test-meta">
            <span class="badge"><?= e($topic['class_name']) ?></span>
            <span class="badge badge-secondary"><?= e($topic['subject_name']) ?></span>
            <?php if ($assignment): ?>
                <span class="badge badge-info"><?= e(assignmentTypeLabel($assignment['type'])) ?></span>
            <?php endif; ?>
        </div>
        <h1 class="test-title"><?= e($topic['name']) ?></h1>
        <div class="test-progress">
            <div class="progress-bar">
                <div class="progress-fill" id="progressFill" style="width: 0%"></div>
            </div>
            <span class="progress-text">
                Запитання <span id="currentQ">1</span> з <span id="totalQ"><?= count($questions) ?></span>
            </span>
        </div>
    </div>

    <form id="testForm" method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="action" value="submit_test">
        <?php if ($assignment): ?>
            <input type="hidden" name="assignment_id" value="<?= (int)$assignmentId ?>">
        <?php endif; ?>

        <div class="questions-container">
            <?php foreach ($questions as $index => $question): ?>
                <div class="question-card <?= $index === 0 ? 'active' : '' ?>"
                     id="question-<?= (int)$question['id'] ?>"
                     data-index="<?= $index ?>"
                     data-question-id="<?= (int)$question['id'] ?>">

                    <div class="question-number">Запитання <?= $index + 1 ?></div>
                    <h2 class="question-text"><?= e($question['text']) ?></h2>
                    <?= imageTag($question['image'] ?? null) ?>
                    <?php if ($question['multiple']): ?>
                        <p class="multi-hint">☑ Оберіть усі правильні відповіді (їх кілька), потім натисніть «Перевірити»</p>
                    <?php endif; ?>

                    <div class="answers-list<?= $question['multiple'] ? ' multi' : '' ?>">
                        <?php foreach ($question['answers'] as $answer): ?>
                            <label class="answer-option" for="answer-<?= (int)$answer['id'] ?>">
                                <input
                                    type="<?= $question['multiple'] ? 'checkbox' : 'radio' ?>"
                                    name="answers[<?= (int)$question['id'] ?>]<?= $question['multiple'] ? '[]' : '' ?>"
                                    id="answer-<?= (int)$answer['id'] ?>"
                                    value="<?= (int)$answer['id'] ?>"
                                    data-correct="<?= $answer['is_correct'] ? '1' : '0' ?>"
                                    class="answer-radio"
                                >
                                <span class="answer-text"><?= e($answer['text']) ?><?= imageTag($answer['image'] ?? null, 'a-image') ?></span>
                                <span class="answer-icon"></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($question['multiple']): ?>
                        <button type="button" class="btn btn-secondary btn-sm check-multi-btn" disabled>Перевірити</button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="test-navigation">
            <button type="button" class="btn btn-outline" id="prevBtn" disabled>← Назад</button>
            <button type="button" class="btn btn-primary" id="nextBtn">Далі →</button>
            <button type="submit" class="btn btn-success" id="submitBtn" style="display:none">Завершити тест ✓</button>
        </div>
    </form>

    <!-- Результати тесту -->
    <div class="test-results" id="testResults" style="display:none">
        <div class="results-header">
            <div class="results-icon" id="resultsIcon">🎉</div>
            <h2 class="results-title">Тест завершено!</h2>
            <div class="score-display">
                <div class="score-circle" id="scoreCircle">
                    <span class="score-number" id="scoreNumber">0%</span>
                </div>
            </div>
            <p class="score-details" id="scoreDetails"></p>
            <p class="score-average" id="scoreAverage"></p>
        </div>

        <div class="results-actions">
            <a href="/tests/index.php" class="btn btn-outline">← До списку тестів</a>
            <a href="" class="btn btn-outline" onclick="location.reload(); return false;">↻ Пройти ще раз</a>
            <button type="button" class="btn btn-primary" id="reviewBtn">Переглянути відповіді</button>
            <a href="/tests/results.php" class="btn btn-secondary">Мої результати</a>
        </div>

        <div class="answers-review" id="answersReview" style="display:none">
            <h3>Аналіз відповідей</h3>
            <div id="reviewList"></div>
        </div>
    </div>
</div>

<script>
// Дані запитань для JavaScript (через window, бо const не потрапляє у window)
window.questionsData = <?= json_encode(array_map(function($q) {
    return [
        'id'      => (int)$q['id'],
        'multiple' => $q['multiple'],
        'text'    => $q['text'],
        'image'   => imageUrl($q['image'] ?? null),
        'answers' => array_map(function($a) {
            return [
                'id'         => (int)$a['id'],
                'text'       => $a['text'],
                'image'      => imageUrl($a['image'] ?? null),
                'is_correct' => (bool)$a['is_correct'],
            ];
        }, $q['answers']),
    ];
}, $questions), JSON_UNESCAPED_UNICODE) ?>;
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
