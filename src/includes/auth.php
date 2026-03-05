<?php
/**
 * Функції авторизації та аутентифікації
 */

require_once __DIR__ . '/../config/database.php';

// Тривалість сесії (30 хвилин)
define('SESSION_LIFETIME', 1800);

/**
 * Запуск сесії з безпечними налаштуваннями
 */
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => false, // Для локальної розробки без HTTPS
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    // Оновлення часу активності
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
        sessionDestroy();
    }
    $_SESSION['last_activity'] = time();
}

/**
 * Знищення сесії
 */
function sessionDestroy(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
    header('Location: /auth/login.php');
    exit;
}

/**
 * Перевірка чи авторизований користувач
 *
 * @return bool
 */
function isLoggedIn(): bool {
    startSession();
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Перевірка чи є користувач адміном
 *
 * @return bool
 */
function isAdmin(): bool {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Вимагати авторизацію (перенаправляє на вхід якщо не авторизований)
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: /auth/login.php');
        exit;
    }
}

/**
 * Вимагати права адміна
 */
function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        header('Location: /index.php?error=access_denied');
        exit;
    }
}

/**
 * Отримати поточного користувача
 *
 * @return array|null
 */
function getCurrentUser(): ?array {
    if (!isLoggedIn()) {
        return null;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT u.*, c.name as class_name FROM users u LEFT JOIN classes c ON u.class_id = c.id WHERE u.id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

/**
 * Авторизація користувача
 *
 * @param string $email
 * @param string $password
 * @return array ['success' => bool, 'error' => string]
 */
function loginUser(string $email, string $password): array {
    if (empty($email) || empty($password)) {
        return ['success' => false, 'error' => 'Введіть email та пароль'];
    }

    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([strtolower(trim($email))]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'error' => 'Невірний email або пароль'];
    }

    // Збереження даних у сесії
    startSession();
    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['role']      = $user['role'];
    $_SESSION['full_name'] = $user['first_name'] . ' ' . $user['last_name'];
    $_SESSION['email']     = $user['email'];

    return ['success' => true];
}

/**
 * Реєстрація нового користувача
 *
 * @param array $data
 * @return array ['success' => bool, 'error' => string]
 */
function registerUser(array $data): array {
    $firstName = trim($data['first_name'] ?? '');
    $lastName  = trim($data['last_name'] ?? '');
    $email     = strtolower(trim($data['email'] ?? ''));
    $password  = $data['password'] ?? '';
    $classId   = (int)($data['class_id'] ?? 0);

    // Валідація
    if (empty($firstName) || empty($lastName)) {
        return ['success' => false, 'error' => 'Введіть ім\'я та прізвище'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Невірний формат email'];
    }
    if (strlen($password) < 6) {
        return ['success' => false, 'error' => 'Пароль має містити мінімум 6 символів'];
    }
    if ($classId < 1 || $classId > 7) {
        return ['success' => false, 'error' => 'Оберіть клас'];
    }

    $pdo = getDB();

    // Перевірка унікальності email
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return ['success' => false, 'error' => 'Цей email вже зареєстрований'];
    }

    // Хешування пароля
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // Вставка нового користувача
    $stmt = $pdo->prepare('INSERT INTO users (first_name, last_name, email, password_hash, class_id, role) VALUES (?, ?, ?, ?, ?, "user")');
    $stmt->execute([$firstName, $lastName, $email, $passwordHash, $classId]);

    return ['success' => true];
}

/**
 * Генерація CSRF токену
 *
 * @return string
 */
function generateCsrfToken(): string {
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Перевірка CSRF токену
 *
 * @param string $token
 * @return bool
 */
function verifyCsrfToken(string $token): bool {
    startSession();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Екранування HTML для захисту від XSS
 *
 * @param string $str
 * @return string
 */
function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
