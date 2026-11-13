<?php
/**
 * API ігор: перевірка відповіді, підказки, завершення гри.
 * Правильні відповіді зберігаються лише в сесії на сервері.
 *
 * POST action=answer  token, question_id, answer_ids[]
 * POST action=hint    token, question_id, type (fifty|audience)
 * POST action=finish  token
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/assignments.php';
require_once __DIR__ . '/../includes/games.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function gameJson(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) {
    gameJson(['success' => false, 'error' => 'Потрібно увійти'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gameJson(['success' => false, 'error' => 'Метод не підтримується'], 405);
}
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    gameJson(['success' => false, 'error' => 'Помилка безпеки. Оновіть сторінку.'], 400);
}

$token = (string)($_POST['token'] ?? '');
if (!isset($_SESSION['game_runs'][$token])) {
    gameJson(['success' => false, 'error' => 'Гру не знайдено або вона вже завершена. Оновіть сторінку.'], 404);
}
$run    = &$_SESSION['game_runs'][$token];
$action = $_POST['action'] ?? '';
$qid    = (int)($_POST['question_id'] ?? 0);

switch ($action) {
    case 'answer':
        if (!isset($run['questions'][$qid])) {
            gameJson(['success' => false, 'error' => 'Невідоме запитання'], 400);
        }
        $q = $run['questions'][$qid];
        // Повторна відповідь не змінює першу
        if (!isset($run['answered'][$qid])) {
            $raw = $_POST['answer_ids'] ?? [];
            $selected = array_values(array_unique(array_intersect(
                array_map('intval', is_array($raw) ? $raw : [$raw]), $q['answers']
            )));
            sort($selected);
            $run['answered'][$qid] = [
                'selected'   => $selected,
                'is_correct' => $selected === $q['correct'],
            ];
        }
        $a = $run['answered'][$qid];
        gameJson([
            'success'     => true,
            'is_correct'  => $a['is_correct'],
            'selected'    => $a['selected'],
            'correct_ids' => $q['correct'],
            'answered'    => count($run['answered']),
            'correct'     => count(array_filter($run['answered'], fn($x) => $x['is_correct'])),
            'total'       => count($run['questions']),
        ]);

    case 'hint':
        $type  = $_POST['type'] ?? '';
        $game  = getGame($run['game']);
        if (!$game || !in_array($type, $game['hints'], true)) {
            gameJson(['success' => false, 'error' => 'Ця підказка недоступна'], 400);
        }
        if (!empty($run['hints_used'][$type])) {
            gameJson(['success' => false, 'error' => 'Підказку вже використано'], 400);
        }
        if (!isset($run['questions'][$qid]) || isset($run['answered'][$qid])) {
            gameJson(['success' => false, 'error' => 'Підказка можлива лише до відповіді'], 400);
        }
        $q = $run['questions'][$qid];
        if (count($q['correct']) !== 1) {
            gameJson(['success' => false, 'error' => 'Підказка лише для запитань з однією правильною відповіддю'], 400);
        }
        $wrong = array_values(array_diff($q['answers'], $q['correct']));
        shuffle($wrong);

        if ($type === 'fifty') {
            if (count($q['answers']) < 3) {
                gameJson(['success' => false, 'error' => 'Тут лише два варіанти'], 400);
            }
            $run['hints_used'][$type] = true;
            // Лишаються правильна та одна неправильна
            gameJson(['success' => true, 'hide' => array_slice($wrong, 0, count($wrong) - 1)]);
        }

        // «Допомога залу»: більшість голосує правильно, але не завжди одностайно
        $run['hints_used'][$type] = true;
        $votes = [$q['correct'][0] => random_int(40, 75)];
        $left  = 100 - $votes[$q['correct'][0]];
        foreach ($wrong as $i => $id) {
            $share = $i === count($wrong) - 1 ? $left : random_int(0, $left);
            $votes[$id] = $share;
            $left -= $share;
        }
        gameJson(['success' => true, 'votes' => $votes]);

    case 'finish':
        $total   = count($run['questions']);
        $correct = count(array_filter($run['answered'], fn($x) => $x['is_correct']));
        $percent = $total > 0 ? round($correct / $total * 100, 2) : 0;
        $userId  = (int)$_SESSION['user_id'];
        $assignmentId = $run['assignment_id'];
        $topicId = (int)$run['topic_id'];

        try {
            $pdo = getDB();
            ensureAssignmentTables($pdo);
            ensureGameColumns($pdo);

            $stmt = $pdo->prepare('
                INSERT INTO test_results (user_id, topic_id, total_questions, correct_answers, score_percent, assignment_id, game)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$userId, $topicId, $total, $correct, $percent, $assignmentId, $run['game']]);
            $resultId = (int)$pdo->lastInsertId();

            if ($assignmentId) {
                $pdo->prepare('
                    UPDATE assignment_recipients SET result_id = ?
                    WHERE assignment_id = ? AND user_id = ? AND result_id IS NULL
                ')->execute([$resultId, $assignmentId, $userId]);
                $stmt = $pdo->prepare('SELECT GROUP_CONCAT(score_percent) FROM test_results WHERE user_id = ? AND assignment_id = ?');
                $stmt->execute([$userId, $assignmentId]);
            } else {
                $stmt = $pdo->prepare('SELECT GROUP_CONCAT(score_percent) FROM test_results WHERE user_id = ? AND topic_id = ?');
                $stmt->execute([$userId, $topicId]);
            }
            $summary = attemptsSummary($stmt->fetchColumn() ?: null);
        } catch (Throwable $e) {
            error_log('Помилка збереження результату гри: ' . $e->getMessage());
            gameJson(['success' => false, 'error' => 'Не вдалося зберегти результат. Спробуйте ще раз.'], 500);
        }

        unset($run);
        unset($_SESSION['game_runs'][$token]);

        gameJson([
            'success'       => true,
            'correct'       => $correct,
            'total'         => $total,
            'score_percent' => $percent,
            'grade'         => scoreToGrade((float)$percent),
            'attempts'      => $summary['attempts'],
            'average_grade' => $summary['avg'],
        ]);

    default:
        gameJson(['success' => false, 'error' => 'Невідома дія'], 400);
}
