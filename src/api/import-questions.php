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
 *       "image": "data:image/png;base64,...",   (необов'язково)
 *       "answers": [
 *         {"text": "Варіант 1", "is_correct": false},
 *         {"text": "Варіант 2", "is_correct": true},
 *         {"text": "Варіант 3", "is_correct": false},
 *         {"text": "", "image": "data:image/jpeg;base64,...", "is_correct": false}
 *       ]
 *     }
 *   ]
 * }
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/images.php';

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
ensureImageColumns($pdo);
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
$withoutCorrect = 0;
$errors        = [];

try {
    foreach ($questions as $index => $questionData) {
        $num          = $index + 1;
        $questionText = trim((string)($questionData['text'] ?? ''));
        $answers      = is_array($questionData['answers'] ?? null) ? $questionData['answers'] : [];

        // Картинка запитання (необов'язково); помилка картинки не скасовує запитання
        $questionImage = null;
        if (!empty($questionData['image'])) {
            try {
                $questionImage = saveImageDataUri((string)$questionData['image']);
            } catch (RuntimeException $e) {
                $errors[] = "Запитання #{$num}: картинку не збережено — " . $e->getMessage();
            }
        }

        if ($questionText === '' && $questionImage === null) {
            $errors[] = "Запитання #{$num}: відсутній текст.";
            continue;
        }

        // Підготовка відповідей (текст або картинка)
        $prepared = [];
        foreach ($answers as $answerData) {
            $answerText  = trim((string)($answerData['text'] ?? ''));
            $answerImage = null;
            if (!empty($answerData['image'])) {
                try {
                    $answerImage = saveImageDataUri((string)$answerData['image']);
                } catch (RuntimeException $e) {
                    $errors[] = "Запитання #{$num}: картинку відповіді не збережено — " . $e->getMessage();
                }
            }
            if ($answerText === '' && $answerImage === null) continue;
            $prepared[] = [$answerText, $answerImage, !empty($answerData['is_correct']) ? 1 : 0];
        }

        if (count($prepared) < 2) {
            $errors[] = "Запитання #{$num}: потрібно мінімум 2 варіанти відповідей.";
            continue;
        }

        if (!array_filter($prepared, fn($a) => $a[2] === 1)) {
            $withoutCorrect++;
        }

        $stmt = $pdo->prepare('INSERT INTO questions (topic_id, text, image) VALUES (?, ?, ?)');
        $stmt->execute([$topicId, $questionText, $questionImage]);
        $questionId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('INSERT INTO answers (question_id, text, is_correct, image) VALUES (?, ?, ?, ?)');
        foreach ($prepared as [$answerText, $answerImage, $isCorrect]) {
            $stmt->execute([$questionId, $answerText, $isCorrect, $answerImage]);
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
    'without_correct' => $withoutCorrect,
    'message'  => "Успішно імпортовано {$importedCount} запитань.",
]);
