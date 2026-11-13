<?php
/**
 * Сейф паролів учнів (адмін).
 * Паролі зберігаються зашифрованими; переглянути їх можна лише ввівши майстер-фразу.
 * Деталі схеми шифрування — у includes/password_vault.php.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/password_vault.php';

requireAdmin();
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$pdo       = getDB();
$csrfToken = generateCsrfToken();
$message   = '';
$error     = '';
$revealed  = null;   // розшифрований список (лише в цій відповіді)

const VAULT_MAX_FAILS = 5;
const VAULT_LOCK_SECONDS = 300;

$lockedFor = max(0, (int)($_SESSION['vault_locked_until'] ?? 0) - time());
$filterClass = (int)($_POST['class_id'] ?? $_GET['class_id'] ?? 0);

/** Невдала спроба: затримка + блокування після кількох помилок. */
function vaultFail(): string {
    sleep(2);
    $_SESSION['vault_fails'] = (int)($_SESSION['vault_fails'] ?? 0) + 1;
    if ($_SESSION['vault_fails'] >= VAULT_MAX_FAILS) {
        $_SESSION['vault_fails'] = 0;
        $_SESSION['vault_locked_until'] = time() + VAULT_LOCK_SECONDS;
        return 'Забагато невдалих спроб. Сейф заблоковано на 5 хвилин.';
    }
    return 'Невірна майстер-фраза.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Помилка безпеки. Спробуйте ще раз.';
    } elseif ($action === 'create') {
        $phrase = (string)($_POST['phrase'] ?? '');
        if (vaultIsConfigured($pdo)) {
            $error = 'Сейф уже створено.';
        } elseif (mb_strlen($phrase) < VAULT_MIN_PHRASE) {
            $error = 'Майстер-фраза має містити щонайменше ' . VAULT_MIN_PHRASE . ' символів.';
        } elseif ($phrase !== ($_POST['phrase_confirm'] ?? '')) {
            $error = 'Фрази не збігаються.';
        } else {
            vaultCreate($pdo, $phrase);
            $message = 'Сейф створено. Паролі учнів додаватимуться під час їх входу, реєстрації та скидання пароля.';
        }
    } elseif (in_array($action, ['unlock', 'change'], true) && $lockedFor > 0) {
        $error = 'Сейф заблоковано ще на ' . ceil($lockedFor / 60) . ' хв.';
    } elseif ($action === 'unlock') {
        $keypair = vaultUnlock($pdo, (string)($_POST['phrase'] ?? ''));
        if ($keypair === null) {
            $error = vaultFail();
        } else {
            $_SESSION['vault_fails'] = 0;
            $sql = 'SELECT u.id, u.first_name, u.last_name, u.email, u.password_enc, c.name AS class_name
                    FROM users u LEFT JOIN classes c ON u.class_id = c.id
                    WHERE u.role = "user"';
            $params = [];
            if ($filterClass > 0) {
                $sql .= ' AND u.class_id = ?';
                $params[] = $filterClass;
            }
            $stmt = $pdo->prepare($sql . ' ORDER BY c.id, u.last_name, u.first_name');
            $stmt->execute($params);
            $revealed = [];
            foreach ($stmt->fetchAll() as $row) {
                $row['password'] = vaultDecrypt($row['password_enc'], $keypair);
                unset($row['password_enc']);
                $revealed[] = $row;
            }
            sodium_memzero($keypair);
        }
    } elseif ($action === 'change') {
        $new = (string)($_POST['new_phrase'] ?? '');
        if (mb_strlen($new) < VAULT_MIN_PHRASE) {
            $error = 'Нова фраза має містити щонайменше ' . VAULT_MIN_PHRASE . ' символів.';
        } elseif ($new !== ($_POST['new_phrase_confirm'] ?? '')) {
            $error = 'Нові фрази не збігаються.';
        } elseif (!vaultChangePhrase($pdo, (string)($_POST['old_phrase'] ?? ''), $new)) {
            $error = vaultFail();
        } else {
            $_SESSION['vault_fails'] = 0;
            $message = 'Майстер-фразу змінено.';
        }
    } elseif ($action === 'destroy') {
        if (trim($_POST['confirm_word'] ?? '') !== 'ВИДАЛИТИ') {
            $error = 'Для видалення введіть слово ВИДАЛИТИ.';
        } else {
            vaultDestroy($pdo);
            $message = 'Сейф і всі збережені копії паролів видалено. Можна створити новий.';
        }
    }
}

$configured = vaultIsConfigured($pdo);
$stats = $pdo->query('SELECT COUNT(*) AS total, SUM(password_enc IS NOT NULL) AS stored FROM users WHERE role = "user"')->fetch();
$classes = $pdo->query('SELECT id, name FROM classes ORDER BY id')->fetchAll();

$pageTitle = 'Сейф паролів';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="admin-header">
    <h1 class="page-title">🔒 Сейф паролів учнів</h1>
    <a href="/admin/users.php" class="btn btn-outline">← Користувачі</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?= e($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if (!$configured): ?>
    <div class="admin-form-card">
        <h2>Створення сейфа</h2>
        <p>Паролі учнів будуть зберігатися <strong>зашифрованими</strong>. Переглянути їх можна лише ввівши
           майстер-фразу. Сама фраза ніде не зберігається — навіть з копією бази даних паролі не прочитати.</p>
        <div class="alert alert-error">
            ⚠ Запишіть фразу в надійному місці. Якщо її забути — збережені паролі відновити неможливо
            (залишиться лише скидання паролів у розділі «Користувачі»).
        </div>
        <p class="text-muted">Паролі учнів, які вже зареєстровані, з'являться в сейфі після їхнього наступного входу
           або після скидання пароля.</p>
        <form method="POST" action="" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label class="form-label" for="phrase">Майстер-фраза (мінімум <?= VAULT_MIN_PHRASE ?> символів)</label>
                <input type="password" name="phrase" id="phrase" class="form-input" required minlength="<?= VAULT_MIN_PHRASE ?>" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label class="form-label" for="phrase_confirm">Повторіть фразу</label>
                <input type="password" name="phrase_confirm" id="phrase_confirm" class="form-input" required autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary">Створити сейф</button>
        </form>
    </div>
<?php else: ?>
    <p class="text-muted">
        Збережено паролів: <strong><?= (int)$stats['stored'] ?></strong> з <?= (int)$stats['total'] ?> учнів.
        Решта з'явиться після їхнього входу або скидання пароля.
    </p>

    <?php if ($revealed !== null): ?>
        <div id="vault-revealed">
            <div class="admin-header">
                <h2>Паролі<?= $filterClass ? '' : ' всіх учнів' ?></h2>
                <a href="/admin/passwords.php" class="btn btn-danger btn-sm">🙈 Сховати</a>
            </div>
            <p class="text-muted">Список автоматично сховається через 2 хвилини.</p>
            <div class="table-wrapper">
                <table class="admin-table">
                    <thead><tr><th>Клас</th><th>Учень</th><th>Email (логін)</th><th>Пароль</th></tr></thead>
                    <tbody>
                    <?php foreach ($revealed as $r): ?>
                        <tr>
                            <td><?= e($r['class_name'] ?? '—') ?></td>
                            <td><?= e($r['last_name'] . ' ' . $r['first_name']) ?></td>
                            <td><?= e($r['email']) ?></td>
                            <td>
                                <?php if ($r['password'] !== null): ?>
                                    <code class="new-password"><?= e($r['password']) ?></code>
                                <?php else: ?>
                                    <span class="text-muted">невідомий — скиньте пароль у «Користувачах»</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$revealed): ?>
                        <tr><td colspan="4" class="text-muted">Учнів не знайдено.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <script>setTimeout(function () { location.replace('/admin/passwords.php'); }, 120000);</script>
    <?php else: ?>
        <div class="admin-form-card">
            <h2>Відкрити сейф</h2>
            <form method="POST" action="" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="unlock">
                <div class="form-group">
                    <label class="form-label" for="class_id">Клас</label>
                    <select name="class_id" id="class_id" class="form-select">
                        <option value="0">Всі класи</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= $filterClass === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="phrase">Майстер-фраза</label>
                    <input type="password" name="phrase" id="phrase" class="form-input" required autofocus autocomplete="off">
                </div>
                <button type="submit" class="btn btn-primary">🔓 Показати паролі</button>
            </form>
        </div>
    <?php endif; ?>

    <details class="admin-form-card">
        <summary>Змінити майстер-фразу</summary>
        <form method="POST" action="" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="change">
            <div class="form-group">
                <label class="form-label">Поточна фраза</label>
                <input type="password" name="old_phrase" class="form-input" required autocomplete="off">
            </div>
            <div class="form-group">
                <label class="form-label">Нова фраза (мінімум <?= VAULT_MIN_PHRASE ?> символів)</label>
                <input type="password" name="new_phrase" class="form-input" required minlength="<?= VAULT_MIN_PHRASE ?>" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label class="form-label">Повторіть нову фразу</label>
                <input type="password" name="new_phrase_confirm" class="form-input" required autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary">Змінити</button>
        </form>
    </details>

    <details class="admin-form-card">
        <summary>Забули фразу? Видалити сейф</summary>
        <p>Буде видалено сейф і всі збережені копії паролів (самі паролі учнів продовжать працювати).
           Після цього можна створити новий сейф з новою фразою.</p>
        <form method="POST" action="" onsubmit="return confirm('Видалити сейф і всі збережені паролі?')">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="destroy">
            <div class="form-group">
                <label class="form-label">Введіть слово ВИДАЛИТИ</label>
                <input type="text" name="confirm_word" class="form-input" required autocomplete="off">
            </div>
            <button type="submit" class="btn btn-danger">Видалити сейф</button>
        </form>
    </details>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
