<?php
/**
 * Шапка сайту (header)
 */

require_once __DIR__ . '/auth.php';

startSession();
$currentUser = getCurrentUser();
$isLogged = isLoggedIn();
$isAdminUser = isAdmin();

// Визначення поточної сторінки для навігації
$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?>Школа Онлайн</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📚</text></svg>">
</head>
<body>
    <header class="header">
        <div class="container">
            <div class="header-inner">
                <a href="/index.php" class="logo">
                    <span class="logo-icon">📚</span>
                    <span class="logo-text">Школа Онлайн</span>
                </a>

                <button class="hamburger" id="hamburger" aria-label="Меню" aria-expanded="false">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>

                <nav class="nav" id="nav">
                    <?php if ($isLogged): ?>
                        <a href="/tests/index.php" class="nav-link <?= $currentDir === 'tests' ? 'active' : '' ?>">Тести</a>
                        <?php if ($isAdminUser): ?>
                            <a href="/admin/index.php" class="nav-link <?= $currentDir === 'admin' ? 'active' : '' ?>">Панель адміна</a>
                        <?php else: ?>
                            <a href="/tests/results.php" class="nav-link <?= $currentPage === 'results.php' ? 'active' : '' ?>">Мої результати</a>
                            <a href="/notes.php" class="nav-link <?= $currentPage === 'notes.php' ? 'active' : '' ?>">Нотатки</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </nav>

                <div class="header-actions">
                    <button class="theme-toggle" id="themeToggle" aria-label="Перемикач теми" title="Перемикач теми">
                        <span class="theme-icon">☀️</span>
                    </button>

                    <?php if ($isLogged): ?>
                        <div class="user-menu">
                            <button class="user-btn" id="userBtn">
                                <span class="user-avatar"><?= e(mb_substr($currentUser['first_name'] ?? 'U', 0, 1)) ?></span>
                                <span class="user-name"><?= e($currentUser['first_name'] ?? '') ?></span>
                                <span class="chevron">▾</span>
                            </button>
                            <div class="user-dropdown" id="userDropdown">
                                <div class="user-info">
                                    <strong><?= e(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')) ?></strong>
                                    <small><?= e($currentUser['email'] ?? '') ?></small>
                                    <?php if (!empty($currentUser['class_name'])): ?>
                                        <small><?= e($currentUser['class_name']) ?></small>
                                    <?php endif; ?>
                                </div>
                                <a href="/auth/logout.php" class="dropdown-item logout">Вийти</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="/auth/login.php" class="btn btn-outline">Вхід</a>
                        <a href="/auth/register.php" class="btn btn-primary">Реєстрація</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <main class="main">
        <div class="container">
