<?php
/**
 * Завдання, надіслані учневі
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/assignments.php';
require_once __DIR__ . '/../config/database.php';

requireLogin();

$pdo    = getDB();
$userId = (int)$_SESSION['user_id'];
ensureAssignmentTables($pdo);

$stmt = $pdo->prepare('
    SELECT a.id, a.type, a.topic_id, a.created_at,
           t.name AS topic_name, s.name AS subject_name, c.name AS class_name,
           tr.score_percent, tr.correct_answers, tr.total_questions, tr.completed_at,
           (SELECT COUNT(*) FROM questions q WHERE q.topic_id = a.topic_id AND EXISTS (SELECT 1 FROM answers an WHERE an.question_id = q.id AND an.is_correct = 1)) AS ready_questions
    FROM assignment_recipients ar
    JOIN assignments a ON ar.assignment_id = a.id
    JOIN topics t ON a.topic_id = t.id
    JOIN subjects s ON t.subject_id = s.id
    JOIN classes c ON t.class_id = c.id
    LEFT JOIN test_results tr ON ar.result_id = tr.id
    WHERE ar.user_id = ?
    ORDER BY (ar.result_id IS NULL) DESC, a.created_at DESC
');
$stmt->execute([$userId]);
$assignments = $stmt->fetchAll();

$pageTitle = 'Мої завдання';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Мої завдання</h1>
    <p class="page-subtitle">Завдання, надіслані вчителем</p>
</div>

<?php if ($errorMessage = testErrorMessage($_GET['error'] ?? null)): ?>
    <div class="alert alert-error"><?= e($errorMessage) ?></div>
<?php endif; ?>

<?php if (empty($assignments)): ?>
    <div class="empty-state">
        <div class="empty-icon">📭</div>
        <h3>Завдань поки немає</h3>
    </div>
<?php else: ?>
    <div class="results-table-wrapper">
        <table class="results-table">
            <thead>
                <tr>
                    <th>Тип</th>
                    <th>Тема</th>
                    <th>Предмет</th>
                    <th>Надіслано</th>
                    <th>Оцінка</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assignments as $a): ?>
                    <tr>
                        <td><span class="badge badge-info"><?= e(assignmentTypeLabel($a['type'])) ?></span></td>
                        <td><?= e($a['topic_name']) ?></td>
                        <td><?= e($a['subject_name']) ?></td>
                        <td><?= date('d.m.Y H:i', strtotime($a['created_at'])) ?></td>
                        <?php if ($a['score_percent'] !== null): ?>
                            <?php $grade = scoreToGrade((float)$a['score_percent']); ?>
                            <td>
                                <span class="score-badge <?= isGoodScore((float)$a['score_percent']) ? 'good' : 'bad' ?>"
                                      title="<?= (int)$a['correct_answers'] ?> / <?= (int)$a['total_questions'] ?>">
                                    <?= $grade ?> <?= gradeWord($grade) ?>
                                </span>
                            </td>
                            <td></td>
                        <?php else: ?>
                            <td>Не виконано</td>
                            <td>
                                <?php if ((int)$a['ready_questions'] === 0): ?>
                                    <span class="text-muted" title="<?= e(testErrorMessage('no_questions')) ?>">Тест ще не готовий</span>
                                <?php else: ?>
                                <a href="/tests/take.php?topic_id=<?= (int)$a['topic_id'] ?>&amp;assignment_id=<?= (int)$a['id'] ?>"
                                   class="btn btn-primary btn-sm">Виконати</a>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
