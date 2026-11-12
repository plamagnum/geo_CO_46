<?php
/**
 * Сторінка реєстрації нового учня
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

startSession();

// Якщо вже авторизований — перенаправити
if (isLoggedIn()) {
    header('Location: /index.php');
    exit;
}

$error = '';
$csrfToken = generateCsrfToken();

// Завантаження класів
$pdo = getDB();
$classes = $pdo->query('SELECT * FROM classes ORDER BY id')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Перевірка CSRF токену
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Помилка безпеки. Спробуйте ще раз.';
    } else {
        $result = registerUser($_POST);
        if ($result['success']) {
            header('Location: /auth/login.php?registered=1');
            exit;
        } else {
            $error = $result['error'];
        }
    }
}

$pageTitle = 'Реєстрація';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <h1 class="auth-title">Реєстрація учня</h1>
            <p class="auth-subtitle">Створіть акаунт для доступу до тестів</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="" class="auth-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <div class="form-row">
                <div class="form-group">
                    <label for="first_name" class="form-label">Ім'я</label>
                    <input
                        type="text"
                        id="first_name"
                        name="first_name"
                        class="form-input"
                        placeholder="Іван"
                        value="<?= e($_POST['first_name'] ?? '') ?>"
                        required
                        maxlength="50"
                    >
                </div>
                <div class="form-group">
                    <label for="last_name" class="form-label">Прізвище</label>
                    <input
                        type="text"
                        id="last_name"
                        name="last_name"
                        class="form-input"
                        placeholder="Петренко"
                        value="<?= e($_POST['last_name'] ?? '') ?>"
                        required
                        maxlength="50"
                    >
                </div>
            </div>

            <div class="form-group">
                <label for="class_id" class="form-label">Клас</label>
                <select id="class_id" name="class_id" class="form-select" required>
                    <option value="">Оберіть клас</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= (int)$class['id'] ?>"
                            <?= (isset($_POST['class_id']) && $_POST['class_id'] == $class['id']) ? 'selected' : '' ?>>
                            <?= e($class['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="email" class="form-label">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    class="form-input"
                    placeholder="example@school.ua"
                    value="<?= e($_POST['email'] ?? '') ?>"
                    required
                    autocomplete="email"
                >
            </div>

            <div class="form-group">
                <label for="password" class="form-label">Пароль</label>
                <div class="input-password-wrapper">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-input"
                        placeholder="Мінімум 6 символів"
                        required
                        minlength="6"
                        autocomplete="new-password"
                    >
                    <button type="button" class="toggle-password" aria-label="Показати пароль">👁</button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-full">Зареєструватися</button>
        </form>

        <div class="auth-footer">
            <p>Вже маєте акаунт? <a href="/auth/login.php" class="link">Увійти</a></p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
