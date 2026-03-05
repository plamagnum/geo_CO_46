<?php
/**
 * Головна сторінка
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

startSession();

$pageTitle = 'Головна';
$isLogged = isLoggedIn();
$isAdminUser = isAdmin();

// Статистика для головної сторінки
$stats = [];
if ($isLogged) {
    $pdo = getDB();

    if ($isAdminUser) {
        // Статистика для адміна
        $stats['users']     = $pdo->query('SELECT COUNT(*) FROM users WHERE role = "user"')->fetchColumn();
        $stats['tests']     = $pdo->query('SELECT COUNT(*) FROM test_results')->fetchColumn();
        $stats['questions'] = $pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn();
        $stats['topics']    = $pdo->query('SELECT COUNT(*) FROM topics')->fetchColumn();
    } else {
        // Статистика для учня
        $userId = $_SESSION['user_id'];
        $stats['my_tests'] = $pdo->prepare('SELECT COUNT(*) FROM test_results WHERE user_id = ?');
        $stats['my_tests']->execute([$userId]);
        $stats['my_tests'] = $stats['my_tests']->fetchColumn();

        $avgStmt = $pdo->prepare('SELECT AVG(score_percent) FROM test_results WHERE user_id = ?');
        $avgStmt->execute([$userId]);
        $stats['avg_score'] = round($avgStmt->fetchColumn() ?? 0, 1);

        $lastResultStmt = $pdo->prepare('
            SELECT tr.*, t.name as topic_name, s.name as subject_name
            FROM test_results tr
            JOIN topics t ON tr.topic_id = t.id
            JOIN subjects s ON t.subject_id = s.id
            WHERE tr.user_id = ?
            ORDER BY tr.completed_at DESC
            LIMIT 3
        ');
        $lastResultStmt->execute([$userId]);
        $stats['last_results'] = $lastResultStmt->fetchAll();
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<?php if (!$isLogged): ?>
    <!-- Привітальна секція для незареєстрованих -->
    <section class="hero">
        <div class="hero-content">
            <h1 class="hero-title">Система тестування учнів<br><span class="gradient-text">5–11 класів</span></h1>
            <p class="hero-desc">Перевіряйте свої знання з математики, фізики, хімії, біології, географії та інших предметів. Зручно, швидко та ефективно.</p>
            <div class="hero-actions">
                <a href="/auth/register.php" class="btn btn-primary btn-large">Почати навчання</a>
                <a href="/auth/login.php" class="btn btn-outline btn-large">Увійти</a>
            </div>
        </div>
        <div class="hero-image">
            <div class="hero-emoji">📚</div>
        </div>
    </section>

    <section class="features">
        <h2 class="section-title">Можливості системи</h2>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">📝</div>
                <h3>Різноманітні тести</h3>
                <p>Тести з 8 предметів для учнів 5–11 класів</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">✅</div>
                <h3>Миттєвий результат</h3>
                <p>Дізнайтеся правильну відповідь одразу після вибору</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📊</div>
                <h3>Статистика прогресу</h3>
                <p>Відстежуйте свої результати та покращуйте знання</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🗒️</div>
                <h3>Нотатки</h3>
                <p>Зберігайте важливі матеріали у своєму кабінеті</p>
            </div>
        </div>
    </section>

<?php elseif ($isAdminUser): ?>
    <!-- Панель адміна -->
    <div class="dashboard">
        <h1 class="page-title">Панель адміністратора</h1>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-value"><?= (int)$stats['users'] ?></div>
                <div class="stat-label">Учнів</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📊</div>
                <div class="stat-value"><?= (int)$stats['tests'] ?></div>
                <div class="stat-label">Тестів пройдено</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">❓</div>
                <div class="stat-value"><?= (int)$stats['questions'] ?></div>
                <div class="stat-label">Запитань</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📚</div>
                <div class="stat-value"><?= (int)$stats['topics'] ?></div>
                <div class="stat-label">Тем</div>
            </div>
        </div>

        <div class="quick-actions">
            <h2>Швидкі дії</h2>
            <div class="actions-grid">
                <a href="/admin/users.php" class="action-card">
                    <span class="action-icon">👥</span>
                    <span>Управління користувачами</span>
                </a>
                <a href="/admin/questions.php" class="action-card">
                    <span class="action-icon">❓</span>
                    <span>Управління запитаннями</span>
                </a>
                <a href="/admin/subjects.php" class="action-card">
                    <span class="action-icon">📚</span>
                    <span>Управління предметами</span>
                </a>
                <a href="/admin/topics.php" class="action-card">
                    <span class="action-icon">📋</span>
                    <span>Управління темами</span>
                </a>
                <a href="/admin/results.php" class="action-card">
                    <span class="action-icon">📊</span>
                    <span>Результати учнів</span>
                </a>
                <a href="/admin/classes.php" class="action-card">
                    <span class="action-icon">🏫</span>
                    <span>Управління класами</span>
                </a>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- Кабінет учня -->
    <div class="dashboard">
        <h1 class="page-title">Вітаємо, <?= e($_SESSION['full_name'] ?? '') ?>!</h1>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">📝</div>
                <div class="stat-value"><?= (int)$stats['my_tests'] ?></div>
                <div class="stat-label">Тестів пройдено</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⭐</div>
                <div class="stat-value"><?= $stats['avg_score'] ?>%</div>
                <div class="stat-label">Середній бал</div>
            </div>
        </div>

        <div class="quick-actions">
            <h2>Швидкий доступ</h2>
            <div class="actions-grid">
                <a href="/tests/index.php" class="action-card action-primary">
                    <span class="action-icon">📝</span>
                    <span>Почати тест</span>
                </a>
                <a href="/tests/results.php" class="action-card">
                    <span class="action-icon">📊</span>
                    <span>Мої результати</span>
                </a>
                <a href="/notes.php" class="action-card">
                    <span class="action-icon">🗒️</span>
                    <span>Мої нотатки</span>
                </a>
            </div>
        </div>

        <?php if (!empty($stats['last_results'])): ?>
            <div class="recent-results">
                <h2>Останні результати</h2>
                <div class="results-list">
                    <?php foreach ($stats['last_results'] as $result): ?>
                        <div class="result-item">
                            <div class="result-info">
                                <strong><?= e($result['topic_name']) ?></strong>
                                <small><?= e($result['subject_name']) ?></small>
                            </div>
                            <div class="result-score <?= $result['score_percent'] >= 60 ? 'good' : 'bad' ?>">
                                <?= round($result['score_percent']) ?>%
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <a href="/tests/results.php" class="btn btn-outline">Всі результати</a>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
