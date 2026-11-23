<?php
/**
 * Школи: таблиця schools і users.school_id (створюються автоматично на існуючих базах)
 */

function ensureSchoolsSchema(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `schools` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(150) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if (!$pdo->query("SHOW COLUMNS FROM `users` LIKE 'school_id'")->fetch()) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `school_id` INT NULL DEFAULT NULL, ADD INDEX `idx_users_school` (`school_id`)");
        $pdo->exec("ALTER TABLE `users` ADD CONSTRAINT `fk_users_school` FOREIGN KEY (`school_id`) REFERENCES `schools`(`id`) ON DELETE SET NULL");
    }
    $done = true;
}

/** @return array<int,array{id:int|string,name:string}> */
function listSchools(PDO $pdo): array {
    ensureSchoolsSchema($pdo);
    return $pdo->query('SELECT id, name FROM schools ORDER BY name')->fetchAll();
}
