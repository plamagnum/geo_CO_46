# Школа Онлайн — Система тестування учнів 5-11 класів

Повноцінний fullstack веб-додаток для тестування учнів шкіл з різних предметів.

---

## Зміст

1. [Вимоги](#вимоги)
2. [Технологічний стек](#технологічний-стек)
3. [Клонування та запуск](#клонування-та-запуск)
4. [Доступ до додатку](#доступ-до-додатку)
5. [Облікові дані за замовчуванням](#облікові-дані-за-замовчуванням)
6. [Структура проєкту](#структура-проєкту)
7. [Функціонал](#функціонал)
8. [Як додавати запитання](#як-додавати-запитання)
9. [API для імпорту](#api-для-імпорту)
10. [Парсери](#парсери)
11. [Налаштування та кастомізація](#налаштування-та-кастомізація)

---

## Вимоги

- [Docker](https://docs.docker.com/get-docker/) версія 20.10+
- [Docker Compose](https://docs.docker.com/compose/install/) версія 2.0+

---

## Технологічний стек

| Компонент         | Технологія          |
|-------------------|---------------------|
| Веб-сервер        | nginx 1.25          |
| Backend           | PHP 8.2 (чистий)    |
| Frontend          | HTML, CSS, JS Vanilla |
| База даних        | MySQL 8.0           |
| Адміністрування БД | phpMyAdmin 5.2      |
| Контейнеризація   | Docker Compose      |

---

## Клонування та запуск

### 1. Клонуйте репозиторій

```bash
git clone https://github.com/your-username/school-testing-app.git
cd school-testing-app
```

### 2. Запустіть контейнери

```bash
docker-compose up -d
```

Перший запуск займе кілька хвилин (завантаження образів, ініціалізація БД).

### 3. Перевірте статус контейнерів

```bash
docker-compose ps
```

Всі контейнери (`nginx`, `php`, `mysql`, `phpmyadmin`) повинні мати статус `Up`.

### 4. Перевірте логи (якщо є проблеми)

```bash
docker-compose logs -f
```

### 5. Зупинка

```bash
docker-compose down
```

### 6. Повне видалення (включаючи дані БД)

```bash
docker-compose down -v
```

---

## Доступ до додатку

| Сервіс          | URL                        |
|-----------------|----------------------------|
| Веб-додаток     | http://localhost            |
| phpMyAdmin      | http://localhost:8080       |
| MySQL           | localhost:3306              |

---

## Облікові дані за замовчуванням

### Адміністратор
- **Email:** admin@school.ua
- **Пароль:** admin123

### Тестовий учень
- **Email:** student@school.ua
- **Пароль:** student123

### MySQL (для прямого підключення)
- **Root:** root / root_password
- **Користувач:** school_user / school_password
- **База даних:** school_tests

---

## Структура проєкту

```
/
├── docker-compose.yml          # Docker Compose конфігурація
├── Dockerfile                  # PHP-FPM образ
├── nginx/
│   └── default.conf            # Конфігурація nginx
├── mysql/
│   └── init.sql                # Ініціалізація БД, таблиці, seed
├── src/                        # PHP код (монтується в контейнер)
│   ├── config/
│   │   └── database.php        # Підключення до БД
│   ├── api/
│   │   └── import-questions.php # API endpoint для імпорту
│   ├── includes/
│   │   ├── header.php          # Шапка сайту
│   │   ├── footer.php          # Підвал сайту
│   │   └── auth.php            # Функції авторизації
│   ├── admin/
│   │   ├── index.php           # Панель адміна
│   │   ├── users.php           # Управління користувачами
│   │   ├── classes.php         # Управління класами
│   │   ├── subjects.php        # Управління предметами
│   │   ├── topics.php          # Управління темами
│   │   ├── questions.php       # Управління запитаннями
│   │   └── results.php         # Результати всіх учнів
│   ├── auth/
│   │   ├── login.php           # Сторінка входу
│   │   ├── register.php        # Реєстрація учня
│   │   └── logout.php          # Вихід
│   ├── tests/
│   │   ├── index.php           # Список тестів
│   │   ├── take.php            # Проходження тесту
│   │   └── results.php         # Мої результати
│   ├── assets/
│   │   ├── css/style.css       # Стилі (темна/світла тема)
│   │   └── js/app.js           # JavaScript логіка
│   ├── notes.php               # Нотатки учня (CRUD)
│   └── index.php               # Головна сторінка
├── parsers/
│   ├── parser.php              # PHP парсер
│   ├── parser.py               # Python парсер
│   ├── config.php              # Конфігурація PHP парсера
│   ├── config.py               # Конфігурація Python парсера
│   └── output/                 # Директорія для JSON результатів
└── README.md
```

---

## Функціонал

### Для учнів
- ✅ Реєстрація (ім'я, прізвище, клас, email, пароль)
- ✅ Авторизація
- ✅ Перегляд доступних тестів (фільтр за класом та предметом)
- ✅ Проходження тестів з миттєвою підсвіткою відповідей
  - 🟢 Правильна відповідь — **зелений** колір
  - 🔴 Неправильна відповідь — **червоний** колір
- ✅ Перегляд своїх результатів (з пагінацією, статистикою)
- ✅ Нотатки (CRUD: створення, перегляд, редагування, видалення)

### Для адміна
- ✅ Управління користувачами (перегляд, зміна ролі, видалення)
- ✅ Управління класами (CRUD)
- ✅ Управління предметами (CRUD)
- ✅ Управління темами (CRUD, фільтрація)
- ✅ Управління запитаннями (CRUD, фільтрація, імпорт JSON)
- ✅ Перегляд результатів всіх учнів (з фільтрами та пагінацією)

### Дизайн
- ✅ Темна / Світла тема (перемикач у шапці, збереження в localStorage)
- ✅ Mobile-first адаптивний дизайн
- ✅ Hamburger меню на мобільних
- ✅ Анімації при виборі відповідей
- ✅ Сучасний градієнтний дизайн

---

## Як додавати запитання

### 1. Вручну через адмін-панель

1. Увійдіть як адміністратор: http://localhost/auth/login.php
2. Перейдіть до **Панель адміна → Запитання**
3. Заповніть форму:
   - Оберіть тему
   - Введіть текст запитання
   - Введіть 4 варіанти відповідей
   - Позначте правильний варіант (радіокнопка зліва)
4. Натисніть **Додати запитання**

### 2. Через API (JSON імпорт)

Дивіться наступний розділ [API для імпорту](#api-для-імпорту).

### 3. Через парсери

Дивіться розділ [Парсери](#парсери).

---

## API для імпорту

### Endpoint

```
POST /api/import-questions.php
Content-Type: application/json
```

> ⚠️ Потрібна авторизація адміністратора (cookie сесії).

### Формат JSON

```json
{
  "topic_id": 1,
  "questions": [
    {
      "text": "Яке число є найменшим натуральним?",
      "answers": [
        {"text": "0",  "is_correct": false},
        {"text": "1",  "is_correct": true},
        {"text": "2",  "is_correct": false},
        {"text": "-1", "is_correct": false}
      ]
    }
  ]
}
```

### Відповідь (успішно)

```json
{
  "success": true,
  "imported": 5,
  "errors": [],
  "message": "Успішно імпортовано 5 запитань."
}
```

### Приклад з curl

```bash
curl -X POST http://localhost/api/import-questions.php \
  -H "Content-Type: application/json" \
  -b "PHPSESSID=your_session_id" \
  -d @questions.json
```

### Через адмін-панель

1. Перейдіть до **Панель адміна → Запитання**
2. Натисніть кнопку **📥 Імпорт JSON**
3. Вставте JSON у текстове поле
4. Натисніть **Імпортувати**

---

## Парсери

### PHP парсер

#### Налаштування

Відредагуйте файл `parsers/config.php`:

```php
return [
    'url'        => 'https://your-test-site.com/page',
    'topic_id'   => 1,  // ID теми в базі даних
    'class_id'   => 1,
    'subject_id' => 1,
    'selectors'  => [
        'question_container' => ['tag' => 'div', 'class' => 'question'],
        'question_text'      => ['tag' => 'p',   'class' => 'question-text'],
        'answers_list'       => ['tag' => 'ul',  'class' => 'answers'],
        'answer_item'        => ['tag' => 'li'],
        'correct_marker'     => ['type' => 'class', 'value' => 'correct'],
    ],
];
```

#### Запуск

```bash
php parsers/parser.php
```

Результат: `parsers/output/parsed_questions.json`

---

### Python парсер

#### Встановлення залежностей

```bash
pip install requests beautifulsoup4
```

#### Налаштування

Відредагуйте файл `parsers/config.py`:

```python
URL        = "https://your-test-site.com/page"
TOPIC_ID   = 1
CLASS_ID   = 1
SUBJECT_ID = 1

SELECTORS = {
    "question_container": "div.question",
    "question_text":      "p.question-text",
    "answers_list":       "ul.answers",
    "answer_item":        "li",
    "correct_marker": {
        "type":  "class",
        "value": "correct"
    }
}
```

#### Запуск

```bash
python parsers/parser.py
```

Результат: `parsers/output/parsed_questions.json`

---

### Імпорт результату парсингу

Після парсингу скористайтесь отриманим JSON файлом для імпорту:

```bash
curl -X POST http://localhost/api/import-questions.php \
  -H "Content-Type: application/json" \
  -b "PHPSESSID=your_session_id" \
  -d @parsers/output/parsed_questions.json
```

Або вставте вміст файлу у форму імпорту в адмін-панелі.

---

## Налаштування та кастомізація

### Зміна портів

Відредагуйте `docker-compose.yml`:

```yaml
services:
  nginx:
    ports:
      - "8000:80"  # Змінити 80 на бажаний порт
  phpmyadmin:
    ports:
      - "8081:80"  # Змінити 8080 на бажаний порт
```

### Зміна паролів

1. Відредагуйте `docker-compose.yml` (секція `mysql.environment`)
2. Відредагуйте `src/config/database.php` (або змінні середовища)
3. Відредагуйте `mysql/init.sql` (паролі адміна через `password_hash()`)

### Додавання нових предметів

Через адмін-панель: **Панель адміна → Предмети → Додати предмет**

### Налаштування теми

CSS змінні у файлі `src/assets/css/style.css` (секція `:root` та `[data-theme="dark"]`).

### База даних

Таблиці бази даних (схема в `mysql/init.sql`):

| Таблиця        | Опис                              |
|----------------|-----------------------------------|
| `users`        | Користувачі (учні та адміни)      |
| `classes`      | Класи (5-11)                      |
| `subjects`     | Предмети                          |
| `topics`       | Теми (предмет + клас)             |
| `questions`    | Запитання                         |
| `answers`      | Варіанти відповідей               |
| `test_results` | Результати тестів                 |
| `user_notes`   | Нотатки учнів                     |

---

## Безпека

- ✅ Prepared statements для всіх SQL запитів
- ✅ Захист від XSS (екранування `htmlspecialchars`)
- ✅ CSRF токени у всіх формах
- ✅ Паролі хешуються через `password_hash()`
- ✅ Сесії з безпечними налаштуваннями (httponly, samesite)
- ✅ Перевірка прав доступу на кожній захищеній сторінці

---

## Ліцензія

MIT License. Вільно використовуйте для навчальних цілей.
