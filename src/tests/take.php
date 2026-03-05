<?php
/**
 * Проходження тесту
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireLogin();

$pdo = getDB();
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

// Завантаження запитань з відповідями
$stmt = $pdo->prepare('SELECT * FROM questions WHERE topic_id = ? ORDER BY RAND()');
$stmt->execute([$topicId]);
$questions = $stmt->fetchAll();

if (empty($questions)) {
    header('Location: /tests/index.php?error=no_questions');
    exit;
}

// Завантаження відповідей для кожного запитання
foreach ($questions as &$question) {
    $stmt = $pdo->prepare('SELECT * FROM answers WHERE question_id = ? ORDER BY RAND()');
    $stmt->execute([$question['id']]);
    $question['answers'] = $stmt->fetchAll();
}
unset($question);

// Обробка результатів після завершення тесту (AJAX запит)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_test') {
    header('Content-Type: application/json');

    // Перевірка CSRF
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Помилка безпеки']);
        exit;
    }

    $userAnswers = $_POST['answers'] ?? [];
    $correctCount = 0;
    $totalCount = count($questions);
    $results = [];

    foreach ($questions as $question) {
        $selectedAnswerId = (int)($userAnswers[$question['id']] ?? 0);
        $correctAnswerId  = 0;

        foreach ($question['answers'] as $answer) {
            if ($answer['is_correct']) {
                $correctAnswerId = (int)$answer['id'];
                break;
            }
        }

        $isCorrect = ($selectedAnswerId === $correctAnswerId);
        if ($isCorrect) {
            $correctCount++;
        }

        $results[$question['id']] = [
            'selected'  => $selectedAnswerId,
            'correct'   => $correctAnswerId,
            'is_correct' => $isCorrect,
        ];
    }

    $scorePercent = $totalCount > 0 ? round(($correctCount / $totalCount) * 100, 2) : 0;

    // Збереження результату в БД
    $stmt = $pdo->prepare('
        INSERT INTO test_results (user_id, topic_id, total_questions, correct_answers, score_percent)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$userId, $topicId, $totalCount, $correctCount, $scorePercent]);
    $resultId = $pdo->lastInsertId();

    echo json_encode([
        'success'        => true,
        'correct'        => $correctCount,
        'total'          => $totalCount,
        'score_percent'  => $scorePercent,
        'results'        => $results,
        'result_id'      => $resultId,
    ]);
    exit;
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

        <div class="questions-container">
            <?php foreach ($questions as $index => $question): ?>
                <div class="question-card <?= $index === 0 ? 'active' : '' ?>"
                     id="question-<?= (int)$question['id'] ?>"
                     data-index="<?= $index ?>"
                     data-question-id="<?= (int)$question['id'] ?>">

                    <div class="question-number">Запитання <?= $index + 1 ?></div>
                    <h2 class="question-text"><?= e($question['text']) ?></h2>

                    <div class="answers-list">
                        <?php foreach ($question['answers'] as $answer): ?>
                            <label class="answer-option" for="answer-<?= (int)$answer['id'] ?>">
                                <input
                                    type="radio"
                                    name="answers[<?= (int)$question['id'] ?>]"
                                    id="answer-<?= (int)$answer['id'] ?>"
                                    value="<?= (int)$answer['id'] ?>"
                                    data-correct="<?= $answer['is_correct'] ? '1' : '0' ?>"
                                    class="answer-radio"
                                >
                                <span class="answer-text"><?= e($answer['text']) ?></span>
                                <span class="answer-icon"></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
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
        </div>

        <div class="results-actions">
            <a href="/tests/index.php" class="btn btn-outline">← До списку тестів</a>
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
// Дані запитань для JavaScript
const questionsData = <?= json_encode(array_map(function($q) {
    return [
        'id'      => (int)$q['id'],
        'text'    => $q['text'],
        'answers' => array_map(function($a) {
            return [
                'id'         => (int)$a['id'],
                'text'       => $a['text'],
                'is_correct' => (bool)$a['is_correct'],
            ];
        }, $q['answers']),
    ];
}, $questions), JSON_UNESCAPED_UNICODE) ?>;
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
