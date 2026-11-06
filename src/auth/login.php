<?php
/**
 * Сторінка входу в систему
 */

require_once __DIR__ . '/../includes/auth.php';

startSession();

// Якщо вже авторизований — перенаправити
if (isLoggedIn()) {
    header('Location: /index.php');
    exit;
}

$error = '';
$csrfToken = generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Перевірка CSRF токену
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Помилка безпеки. Спробуйте ще раз.';
    } else {
        $result = loginUser($_POST['email'] ?? '', $_POST['password'] ?? '');
        if ($result['success']) {
            header('Location: /index.php');
            exit;
        } else {
            $error = $result['error'];
        }
    }
}

$pageTitle = 'Вхід';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <h1 class="auth-title">Вхід в систему</h1>
            <p class="auth-subtitle">Введіть ваші облікові дані для входу</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['registered'])): ?>
            <div class="alert alert-success">Реєстрація успішна! Тепер ви можете увійти.</div>
        <?php endif; ?>

        <form method="POST" action="" class="auth-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

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
                        placeholder="Введіть пароль"
                        required
                        autocomplete="current-password"
                    >
                    <button type="button" class="toggle-password" aria-label="Показати пароль">👁</button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-full">Увійти</button>
        </form>

        <div class="auth-footer">
            <p>Ще не маєте акаунту? <a href="/auth/register.php" class="link">Зареєструватися</a></p>
        </div>

        <div class="auth-demo">
            <p class="demo-title">Демо облікові дані:</p>
            <div class="demo-credentials">
                <div>
                    <strong>Адмін:</strong> admin@school.ua / admin123
                </div>
                <div>
                    <strong>Учень:</strong> student@school.ua / student123
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
