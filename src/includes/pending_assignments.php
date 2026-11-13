<?php
/**
 * Блок «Нові завдання» для учня. Очікує змінну $pendingAssignments.
 */
if (!empty($pendingAssignments)): ?>
    <div class="pending-assignments">
        <h2>📨 Нові завдання (<?= count($pendingAssignments) ?>)</h2>
        <div class="results-list">
            <?php foreach ($pendingAssignments as $pa): ?>
                <div class="result-item">
                    <div class="result-info">
                        <strong><?= e($pa['topic_name']) ?></strong>
                        <small>
                            <span class="badge badge-info"><?= e(assignmentTypeLabel($pa['type'])) ?></span><?= gameBadge($pa['game'] ?? null) ?>
                            <?= e($pa['subject_name']) ?> · <?= date('d.m.Y H:i', strtotime($pa['created_at'])) ?>
                        </small>
                    </div>
                    <?php if ((int)$pa['ready_questions'] === 0): ?>
                        <span class="text-muted" title="<?= e(testErrorMessage('no_questions')) ?>">Тест ще не готовий</span>
                    <?php else: ?>
                    <a href="<?= e(assignmentUrl($pa)) ?>"
                       class="btn btn-primary btn-sm"><?= !empty($pa['game']) ? '🎮 Грати' : 'Виконати' ?></a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <a href="/tests/assignments.php" class="btn btn-outline btn-sm">Усі мої завдання</a>
    </div>
<?php endif; ?>
