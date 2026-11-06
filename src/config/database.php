<?php
/**
 * Конфігурація підключення до бази даних
 */

// Параметри підключення (беруться зі змінних середовища або дефолтні)
define('DB_HOST', getenv('DB_HOST') ?: 'mysql');
define('DB_NAME', getenv('DB_NAME') ?: 'school_tests');
define('DB_USER', getenv('DB_USER') ?: 'school_user');
define('DB_PASS', getenv('DB_PASS') ?: 'school_password');
define('DB_CHARSET', 'utf8mb4');

/**
 * Створення PDO підключення до БД
 *
 * @return PDO
 */
function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Логування помилки без виводу деталей користувачу
            error_log('Помилка підключення до БД: ' . $e->getMessage());
            die(json_encode(['error' => 'Помилка підключення до бази даних']));
        }
    }

    return $pdo;
}
