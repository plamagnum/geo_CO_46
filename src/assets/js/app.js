/**
 * Основний JavaScript файл додатку
 * Школа Онлайн — система тестування
 */

// =============================================
// Темна/Світла тема
// =============================================
const ThemeManager = {
    STORAGE_KEY: 'school-theme',

    init() {
        const saved = localStorage.getItem(this.STORAGE_KEY) || 'light';
        this.apply(saved);
        this.bindToggle();
    },

    apply(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        const icon = document.querySelector('.theme-icon');
        if (icon) {
            icon.textContent = theme === 'dark' ? '🌙' : '☀️';
        }
        localStorage.setItem(this.STORAGE_KEY, theme);
    },

    toggle() {
        const current = document.documentElement.getAttribute('data-theme') || 'light';
        this.apply(current === 'dark' ? 'light' : 'dark');
    },

    bindToggle() {
        const btn = document.getElementById('themeToggle');
        if (btn) {
            btn.addEventListener('click', () => this.toggle());
        }
    },
};

// =============================================
// Hamburger меню (мобільна навігація)
// =============================================
const MobileMenu = {
    init() {
        const hamburger = document.getElementById('hamburger');
        const nav = document.getElementById('nav');

        if (!hamburger || !nav) return;

        hamburger.addEventListener('click', () => {
            const isOpen = hamburger.classList.toggle('open');
            nav.classList.toggle('open', isOpen);
            hamburger.setAttribute('aria-expanded', isOpen);
        });

        // Закриття при кліку поза меню
        document.addEventListener('click', (e) => {
            if (!hamburger.contains(e.target) && !nav.contains(e.target)) {
                hamburger.classList.remove('open');
                nav.classList.remove('open');
                hamburger.setAttribute('aria-expanded', 'false');
            }
        });
    },
};

// =============================================
// Dropdown меню користувача
// =============================================
const UserMenu = {
    init() {
        const btn = document.getElementById('userBtn');
        const dropdown = document.getElementById('userDropdown');

        if (!btn || !dropdown) return;

        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            dropdown.classList.toggle('show');
        });

        document.addEventListener('click', (e) => {
            if (!btn.contains(e.target)) {
                dropdown.classList.remove('show');
            }
        });
    },
};

// =============================================
// Показ/приховування пароля
// =============================================
const PasswordToggle = {
    init() {
        document.querySelectorAll('.toggle-password').forEach((btn) => {
            btn.addEventListener('click', () => {
                const input = btn.previousElementSibling;
                if (!input) return;
                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                btn.textContent = isPassword ? '🙈' : '👁';
            });
        });
    },
};

// =============================================
// Логіка тестування
// =============================================
const TestEngine = {
    currentIndex: 0,
    totalQuestions: 0,
    userAnswers: {},
    answeredQuestions: new Set(),
    testCompleted: false,

    init() {
        const testForm = document.getElementById('testForm');
        if (!testForm) return;

        // Визначення кількості запитань
        const cards = document.querySelectorAll('.question-card');
        this.totalQuestions = cards.length;

        if (this.totalQuestions === 0) return;

        this.updateProgress();
        this.bindAnswers();
        this.bindNavigation();
    },

    // Прив'язка кліків на відповідях
    bindAnswers() {
        document.querySelectorAll('.answer-option').forEach((option) => {
            option.addEventListener('click', (e) => {
                const radio = option.querySelector('.answer-radio');
                if (!radio || option.classList.contains('answered')) return;

                const questionCard = option.closest('.question-card');
                const questionId = questionCard?.dataset.questionId;

                if (!questionId) return;

                // Позначення відповіді як обраної
                radio.checked = true;
                this.userAnswers[questionId] = radio.value;

                // Визначення правильної/неправильної відповіді
                const isCorrect = radio.dataset.correct === '1';

                // Блокування всіх відповідей в цьому питанні
                questionCard.querySelectorAll('.answer-option').forEach((opt) => {
                    opt.classList.add('answered');
                    const r = opt.querySelector('.answer-radio');
                    if (r?.dataset.correct === '1') {
                        opt.classList.add('correct');
                    }
                });

                // Позначення обраної відповіді
                if (!isCorrect) {
                    option.classList.add('wrong');
                }

                this.answeredQuestions.add(questionId);
                this.updateNavButtons();
            });
        });
    },

    // Навігація між запитаннями
    bindNavigation() {
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const submitBtn = document.getElementById('submitBtn');

        prevBtn?.addEventListener('click', () => {
            if (this.currentIndex > 0) {
                this.showQuestion(this.currentIndex - 1);
            }
        });

        nextBtn?.addEventListener('click', () => {
            if (this.currentIndex < this.totalQuestions - 1) {
                this.showQuestion(this.currentIndex + 1);
            }
        });

        // Обробка відправки форми
        const form = document.getElementById('testForm');
        form?.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (this.testCompleted) return;
            await this.submitTest(form);
        });
    },

    showQuestion(index) {
        const cards = document.querySelectorAll('.question-card');
        cards.forEach((c, i) => c.classList.toggle('active', i === index));
        this.currentIndex = index;
        this.updateProgress();
        this.updateNavButtons();

        // Скрол до верху
        document.querySelector('.test-page')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    },

    updateProgress() {
        const fill = document.getElementById('progressFill');
        const currentQ = document.getElementById('currentQ');
        const totalQ = document.getElementById('totalQ');

        const percent = ((this.currentIndex + 1) / this.totalQuestions) * 100;
        if (fill) fill.style.width = percent + '%';
        if (currentQ) currentQ.textContent = this.currentIndex + 1;
        if (totalQ) totalQ.textContent = this.totalQuestions;
    },

    updateNavButtons() {
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const submitBtn = document.getElementById('submitBtn');

        if (prevBtn) prevBtn.disabled = this.currentIndex === 0;

        const isLast = this.currentIndex === this.totalQuestions - 1;
        if (nextBtn) nextBtn.style.display = isLast ? 'none' : 'inline-flex';
        if (submitBtn) submitBtn.style.display = isLast ? 'inline-flex' : 'none';
    },

    async submitTest(form) {
        const formData = new FormData(form);

        // Додавання відповідей (може статись що не всі запитання мають відповідь)
        for (const card of document.querySelectorAll('.question-card')) {
            const questionId = card.dataset.questionId;
            const checkedRadio = card.querySelector('.answer-radio:checked');
            if (checkedRadio && questionId) {
                formData.set(`answers[${questionId}]`, checkedRadio.value);
            }
        }

        try {
            const response = await fetch(form.action || window.location.href, {
                method: 'POST',
                body: formData,
            });

            const result = await response.json();

            if (result.success) {
                this.testCompleted = true;
                this.showResults(result);
            } else {
                alert('Помилка при збереженні результату: ' + (result.error || 'Невідома помилка'));
            }
        } catch (err) {
            console.error('Помилка при відправці тесту:', err);
            alert('Помилка з\'єднання. Спробуйте ще раз.');
        }
    },

    showResults(result) {
        // Приховати форму, показати результати
        document.getElementById('testForm').style.display = 'none';
        document.querySelector('.test-header').style.display = 'none';

        const resultsDiv = document.getElementById('testResults');
        resultsDiv.style.display = 'block';

        // Відображення рахунку
        document.getElementById('scoreNumber').textContent = Math.round(result.score_percent) + '%';
        document.getElementById('scoreDetails').textContent =
            `Правильних відповідей: ${result.correct} з ${result.total}`;

        // Іконка залежно від результату
        const icon = document.getElementById('resultsIcon');
        if (result.score_percent >= 90) icon.textContent = '🏆';
        else if (result.score_percent >= 60) icon.textContent = '🎉';
        else icon.textContent = '📚';

        // Колір кола залежно від результату
        const circle = document.getElementById('scoreCircle');
        if (result.score_percent >= 60) {
            circle.style.borderColor = 'var(--success)';
            document.getElementById('scoreNumber').style.color = 'var(--success)';
        } else {
            circle.style.borderColor = 'var(--danger)';
            document.getElementById('scoreNumber').style.color = 'var(--danger)';
        }

        // Прив'язка кнопки перегляду
        const reviewBtn = document.getElementById('reviewBtn');
        if (reviewBtn) {
            reviewBtn.addEventListener('click', () => {
                this.showReview(result.results);
            });
        }
    },

    showReview(results) {
        const reviewDiv = document.getElementById('answersReview');
        const reviewList = document.getElementById('reviewList');
        if (!reviewDiv || !reviewList || !window.questionsData) return;

        reviewDiv.style.display = 'block';
        reviewList.innerHTML = '';

        window.questionsData.forEach((question) => {
            const qResult = results[question.id];
            if (!qResult) return;

            const item = document.createElement('div');
            item.className = `review-item ${qResult.is_correct ? 'correct-answer' : 'wrong-answer'}`;

            const icon = qResult.is_correct ? '✓' : '✗';
            let answersHtml = '';

            question.answers.forEach((answer) => {
                const isSelected = answer.id === qResult.selected;
                const isCorrect = answer.id === qResult.correct;
                let style = '';
                if (isCorrect) style = 'color: var(--success); font-weight: 600;';
                else if (isSelected && !isCorrect) style = 'color: var(--danger);';
                answersHtml += `<div style="${style}">${isCorrect ? '✓' : (isSelected ? '✗' : '○')} ${answer.text}</div>`;
            });

            item.innerHTML = `
                <div class="review-question">${icon} ${question.text}</div>
                <div class="review-answers">${answersHtml}</div>
            `;
            reviewList.appendChild(item);
        });

        reviewDiv.scrollIntoView({ behavior: 'smooth' });
    },
};

// =============================================
// Автоматичне закриття повідомлень
// =============================================
const AlertManager = {
    init() {
        document.querySelectorAll('.alert-success').forEach((alert) => {
            setTimeout(() => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            }, 4000);
        });
    },
};

// =============================================
// Ініціалізація при завантаженні сторінки
// =============================================
document.addEventListener('DOMContentLoaded', () => {
    ThemeManager.init();
    MobileMenu.init();
    UserMenu.init();
    PasswordToggle.init();
    TestEngine.init();
    AlertManager.init();
});
