        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <div class="footer-inner">
                <p>&copy; <?= date('Y') ?> Школа Онлайн. Система тестування учнів 5-11 класів.</p>
                <div class="footer-links">
                    <a href="/tests/index.php">Тести</a>
                    <?php if (isLoggedIn() && !isAdmin()): ?>
                        <a href="/tests/results.php">Результати</a>
                        <a href="/notes.php">Нотатки</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </footer>

    <script src="/assets/js/app.js"></script>
</body>
</html>
