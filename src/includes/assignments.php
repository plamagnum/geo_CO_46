<?php
/**
 * Допоміжні функції для завдань (поточне / тематичне)
 */

const ASSIGNMENT_TYPES = [
    'current'  => 'Поточне',
    'thematic' => 'Тематичне',
];

function assignmentTypeLabel(string $type): string {
    return ASSIGNMENT_TYPES[$type] ?? $type;
}

/**
 * Створює таблиці завдань, якщо вони відсутні
 * (для баз, ініціалізованих до появи цієї функції).
 */
function ensureAssignmentTables(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `assignments` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `topic_id` INT NOT NULL,
            `type` ENUM('current','thematic') NOT NULL DEFAULT 'current',
            `created_by` INT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`topic_id`) REFERENCES `topics`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `assignment_recipients` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `assignment_id` INT NOT NULL,
            `user_id` INT NOT NULL,
            `result_id` INT NULL,
            UNIQUE KEY `uniq_assignment_user` (`assignment_id`, `user_id`),
            FOREIGN KEY (`assignment_id`) REFERENCES `assignments`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`result_id`) REFERENCES `test_results`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $done = true;
}
