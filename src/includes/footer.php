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
    <script>
    if (document.body.classList.contains('no-copy')) {
        const editable = (el) => el && el.closest && el.closest('input, textarea, [contenteditable="true"]');
        ['copy', 'cut', 'contextmenu', 'selectstart', 'dragstart'].forEach((type) =>
            document.addEventListener(type, (e) => { if (!editable(e.target)) e.preventDefault(); }));
        document.addEventListener('keydown', (e) => {
            const k = e.key.toLowerCase();
            if ((e.ctrlKey || e.metaKey) && ['c', 'x', 'a', 's', 'p', 'u'].includes(k) && !editable(e.target)) e.preventDefault();
        });
    }
    </script>
</body>
</html>
