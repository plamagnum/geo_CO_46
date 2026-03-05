<?php
/**
 * API endpoint для імпорту запитань
 * POST /api/import-questions.php
 *
 * Формат JSON:
 * {
 *   "topic_id": 1,
 *   "questions": [
 *     {
 *       "text": "Текст запитання?",
 *       "answers": [
 *         {"text": "Варіант 1", "is_correct": false},
 *         {"text": "Варіант 2", "is_correct": true},
 *         {"text": "Варіант 3", "is_correct": false},
 *         {"text": "Варіант 4", "is_correct": false}
 *       ]
 *     }
 *   ]
 * }
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Встановлення заголовків відповіді
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Перевірка методу
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Метод не дозволений. Використовуйте POST.']);
    exit;
}

// Перевірка авторизації адміна
startSession();
if (!isAdmin()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Доступ заборонений. Потрібні права адміна.']);
    exit;
}

// Читання тіла запиту
$rawInput = file_get_contents('php://input');
if (empty($rawInput)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Порожнє тіло запиту.']);
    exit;
}

// Парсинг JSON
$data = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Невірний формат JSON: ' . json_last_error_msg()]);
    exit;
}

// Валідація
$topicId   = (int)($data['topic_id'] ?? 0);
$questions = $data['questions'] ?? [];

if ($topicId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Поле topic_id обов\'язкове та має бути цілим числом.']);
    exit;
}

if (empty($questions) || !is_array($questions)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Поле questions обов\'язкове та має бути масивом.']);
    exit;
}

// Перевірка існування теми
$pdo  = getDB();
$stmt = $pdo->prepare('SELECT id FROM topics WHERE id = ?');
$stmt->execute([$topicId]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => "Тему з ID {$topicId} не знайдено."]);
    exit;
}

// Імпорт запитань транзакцією
$pdo->beginTransaction();
$importedCount = 0;
$errors        = [];

try {
    foreach ($questions as $index => $questionData) {
        $questionText = trim($questionData['text'] ?? '');
        $answers      = $questionData['answers'] ?? [];

        if (empty($questionText)) {
            $errors[] = "Запитання #{$index}: відсутній текст.";
            continue;
        }

        if (count($answers) < 2) {
            $errors[] = "Запитання #{$index}: потрібно мінімум 2 варіанти відповідей.";
            continue;
        }

        // Перевірка наявності правильної відповіді
        $hasCorrect = false;
        foreach ($answers as $answer) {
            if (!empty($answer['is_correct'])) {
                $hasCorrect = true;
                break;
            }
        }

        if (!$hasCorrect) {
            $errors[] = "Запитання #{$index}: не вказана правильна відповідь.";
            continue;
        }

        // Вставка запитання
        $stmt = $pdo->prepare('INSERT INTO questions (topic_id, text) VALUES (?, ?)');
        $stmt->execute([$topicId, $questionText]);
        $questionId = (int)$pdo->lastInsertId();

        // Вставка відповідей
        foreach ($answers as $answerData) {
            $answerText = trim($answerData['text'] ?? '');
            if (empty($answerText)) continue;

            $isCorrect = !empty($answerData['is_correct']) ? 1 : 0;
            $stmt = $pdo->prepare('INSERT INTO answers (question_id, text, is_correct) VALUES (?, ?, ?)');
            $stmt->execute([$questionId, $answerText, $isCorrect]);
        }

        $importedCount++;
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    error_log('Помилка імпорту запитань: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Помилка сервера під час імпорту.']);
    exit;
}

// Відповідь
echo json_encode([
    'success'  => true,
    'imported' => $importedCount,
    'errors'   => $errors,
    'message'  => "Успішно імпортовано {$importedCount} запитань.",
]);
