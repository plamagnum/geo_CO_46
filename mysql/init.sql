-- Ініціалізація бази даних для системи тестування школярів
-- Кодування UTF-8

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

USE school_tests;

-- Таблиця класів (5-11)
CREATE TABLE IF NOT EXISTS `classes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(20) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблиця предметів
CREATE TABLE IF NOT EXISTS `subjects` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблиця користувачів
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `first_name` VARCHAR(50) NOT NULL,
    `last_name` VARCHAR(50) NOT NULL,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `class_id` INT NULL,
    `role` ENUM('user','admin') DEFAULT 'user',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблиця тем
CREATE TABLE IF NOT EXISTS `topics` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `subject_id` INT NOT NULL,
    `class_id` INT NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`class_id`) REFERENCES `classes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблиця запитань
CREATE TABLE IF NOT EXISTS `questions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `topic_id` INT NOT NULL,
    `text` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`topic_id`) REFERENCES `topics`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблиця варіантів відповідей
CREATE TABLE IF NOT EXISTS `answers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `question_id` INT NOT NULL,
    `text` TEXT NOT NULL,
    `is_correct` TINYINT(1) DEFAULT 0,
    FOREIGN KEY (`question_id`) REFERENCES `questions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблиця результатів тестів
CREATE TABLE IF NOT EXISTS `test_results` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `topic_id` INT NOT NULL,
    `total_questions` INT NOT NULL,
    `correct_answers` INT NOT NULL,
    `score_percent` DECIMAL(5,2) NOT NULL,
    `completed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`topic_id`) REFERENCES `topics`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблиця нотаток учня
CREATE TABLE IF NOT EXISTS `user_notes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `content` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- SEED ДАНІ
-- =============================================

-- Класи 5-11
INSERT INTO `classes` (`name`) VALUES
    ('5 клас'),
    ('6 клас'),
    ('7 клас'),
    ('8 клас'),
    ('9 клас'),
    ('10 клас'),
    ('11 клас');

-- Предмети
INSERT INTO `subjects` (`name`) VALUES
    ('Математика'),
    ('Українська мова'),
    ('Історія України'),
    ('Географія'),
    ('Фізика'),
    ('Хімія'),
    ('Біологія'),
    ('Англійська мова');

-- Теми (приклади для різних предметів та класів)
INSERT INTO `topics` (`subject_id`, `class_id`, `name`) VALUES
    -- Математика, 5 клас
    (1, 1, 'Натуральні числа'),
    (1, 1, 'Дроби'),
    (1, 1, 'Геометричні фігури'),
    -- Математика, 6 клас
    (1, 2, 'Цілі числа'),
    (1, 2, 'Відсотки'),
    -- Математика, 7 клас
    (1, 3, 'Алгебраїчні вирази'),
    (1, 3, 'Рівняння'),
    -- Математика, 8 клас
    (1, 4, 'Квадратні рівняння'),
    (1, 4, 'Функції'),
    -- Математика, 9 клас
    (1, 5, 'Тригонометрія'),
    -- Математика, 10 клас
    (1, 6, 'Похідна функції'),
    -- Математика, 11 клас
    (1, 7, 'Інтеграл'),

    -- Українська мова, 5 клас
    (2, 1, 'Фонетика'),
    (2, 1, 'Лексика'),
    -- Українська мова, 6 клас
    (2, 2, 'Морфологія'),
    (2, 2, 'Частини мови'),
    -- Українська мова, 7 клас
    (2, 3, 'Синтаксис'),
    -- Українська мова, 8 клас
    (2, 4, 'Пунктуація'),

    -- Історія України, 5 клас
    (3, 1, 'Стародавня Україна'),
    -- Історія України, 7 клас
    (3, 3, 'Козацька доба'),
    -- Історія України, 9 клас
    (3, 5, 'Україна у XIX столітті'),
    -- Історія України, 11 клас
    (3, 7, 'Незалежність України'),

    -- Географія, 6 клас
    (4, 2, 'Фізична географія'),
    (4, 2, 'Материки та океани'),
    -- Географія, 7 клас
    (4, 3, 'Географія материків'),
    -- Географія, 8 клас
    (4, 4, 'Географія України'),

    -- Фізика, 7 клас
    (5, 3, 'Механіка'),
    (5, 3, 'Кінематика'),
    -- Фізика, 8 клас
    (5, 4, 'Термодинаміка'),
    -- Фізика, 9 клас
    (5, 5, 'Електрика'),
    -- Фізика, 10 клас
    (5, 6, 'Оптика'),
    -- Фізика, 11 клас
    (5, 7, 'Атомна фізика'),

    -- Хімія, 7 клас
    (6, 3, 'Основи хімії'),
    -- Хімія, 8 клас
    (6, 4, 'Неорганічна хімія'),
    -- Хімія, 9 клас
    (6, 5, 'Органічна хімія'),

    -- Біологія, 6 клас
    (7, 2, 'Рослини'),
    (7, 2, 'Клітина'),
    -- Біологія, 7 клас
    (7, 3, 'Тварини'),
    -- Біологія, 8 клас
    (7, 4, 'Анатомія людини'),
    -- Біологія, 9 клас
    (7, 5, 'Генетика'),
    -- Біологія, 11 клас
    (7, 7, 'Еволюція'),

    -- Англійська мова, 5 клас
    (8, 1, 'Часи дієслова'),
    (8, 1, 'Лексика: сім''я'),
    -- Англійська мова, 6 клас
    (8, 2, 'Present Simple vs Present Continuous'),
    -- Англійська мова, 7 клас
    (8, 3, 'Past Simple'),
    -- Англійська мова, 8 клас
    (8, 4, 'Future Tenses'),
    -- Англійська мова, 9 клас
    (8, 5, 'Conditionals'),
    -- Англійська мова, 10 клас
    (8, 6, 'Passive Voice'),
    -- Англійська мова, 11 клас
    (8, 7, 'Modal Verbs');

-- =============================================
-- ДЕМО ЗАПИТАННЯ ТА ВІДПОВІДІ
-- =============================================

-- Математика, 5 клас, Натуральні числа (topic_id = 1)
INSERT INTO `questions` (`topic_id`, `text`) VALUES
    (1, 'Яке число є найменшим натуральним числом?'),
    (1, 'Скільки буде 15 + 27?'),
    (1, 'Скільки буде 8 × 7?'),
    (1, 'Яке число стоїть між 99 і 101?'),
    (1, 'Скільки буде 100 - 38?');

INSERT INTO `answers` (`question_id`, `text`, `is_correct`) VALUES
    -- Питання 1: Яке число є найменшим натуральним числом?
    (1, '0', 0),
    (1, '1', 1),
    (1, '2', 0),
    (1, '-1', 0),
    -- Питання 2: Скільки буде 15 + 27?
    (2, '32', 0),
    (2, '41', 0),
    (2, '42', 1),
    (2, '43', 0),
    -- Питання 3: Скільки буде 8 × 7?
    (3, '54', 0),
    (3, '56', 1),
    (3, '58', 0),
    (3, '64', 0),
    -- Питання 4: Яке число стоїть між 99 і 101?
    (4, '98', 0),
    (4, '100', 1),
    (4, '102', 0),
    (4, '99', 0),
    -- Питання 5: Скільки буде 100 - 38?
    (5, '52', 0),
    (5, '62', 1),
    (5, '72', 0),
    (5, '68', 0);

-- Математика, 5 клас, Дроби (topic_id = 2)
INSERT INTO `questions` (`topic_id`, `text`) VALUES
    (2, 'Яка дріб є рівнозначною дробу 1/2?'),
    (2, 'Скільки буде 1/4 + 1/4?'),
    (2, 'Що таке чисельник дробу?'),
    (2, 'Скільки буде 3/4 - 1/4?');

INSERT INTO `answers` (`question_id`, `text`, `is_correct`) VALUES
    -- Питання 6
    (6, '1/3', 0),
    (6, '2/4', 1),
    (6, '3/4', 0),
    (6, '2/3', 0),
    -- Питання 7
    (7, '1/8', 0),
    (7, '2/8', 0),
    (7, '1/2', 1),
    (7, '1/4', 0),
    -- Питання 8
    (8, 'Число під рискою', 0),
    (8, 'Число над рискою', 1),
    (8, 'Сам дріб', 0),
    (8, 'Ціла частина', 0),
    -- Питання 9
    (9, '1/4', 0),
    (9, '2/4', 0),
    (9, '1/2', 1),
    (9, '3/8', 0);

-- Фізика, 7 клас, Механіка (topic_id = 29)
INSERT INTO `questions` (`topic_id`, `text`) VALUES
    (29, 'Що вимірює динамометр?'),
    (29, 'Яка одиниця вимірювання сили?'),
    (29, 'Що таке швидкість?'),
    (29, 'Яка одиниця вимірювання маси в СІ?'),
    (29, 'Що таке інерція?');

INSERT INTO `answers` (`question_id`, `text`, `is_correct`) VALUES
    -- Питання 10
    (10, 'Масу', 0),
    (10, 'Силу', 1),
    (10, 'Температуру', 0),
    (10, 'Тиск', 0),
    -- Питання 11
    (11, 'Метр', 0),
    (11, 'Кілограм', 0),
    (11, 'Ньютон', 1),
    (11, 'Джоуль', 0),
    -- Питання 12
    (12, 'Відстань, пройдена за одиницю часу', 1),
    (12, 'Зміна напрямку руху', 0),
    (12, 'Сила тяжіння', 0),
    (12, 'Маса тіла', 0),
    -- Питання 13
    (13, 'Грам', 0),
    (13, 'Кілограм', 1),
    (13, 'Ньютон', 0),
    (13, 'Метр', 0),
    -- Питання 14
    (14, 'Прискорення тіла', 0),
    (14, 'Властивість тіла зберігати стан спокою або рівномірного руху', 1),
    (14, 'Сила тертя', 0),
    (14, 'Швидкість тіла', 0);

-- Англійська мова, 5 клас, Часи дієслова (topic_id = 47)
INSERT INTO `questions` (`topic_id`, `text`) VALUES
    (47, 'Which sentence is in Present Simple?'),
    (47, 'What is the past form of "go"?'),
    (47, 'Choose the correct form: She ___ a student.'),
    (47, 'What does "always" indicate?'),
    (47, 'Which sentence is correct?');

INSERT INTO `answers` (`question_id`, `text`, `is_correct`) VALUES
    -- Питання 15
    (15, 'She is reading now.', 0),
    (15, 'She reads every day.', 1),
    (15, 'She will read tomorrow.', 0),
    (15, 'She read yesterday.', 0),
    -- Питання 16
    (16, 'goed', 0),
    (16, 'goes', 0),
    (16, 'went', 1),
    (16, 'going', 0),
    -- Питання 17
    (17, 'She am a student.', 0),
    (17, 'She are a student.', 0),
    (17, 'She is a student.', 1),
    (17, 'She be a student.', 0),
    -- Питання 18
    (18, 'Future tense', 0),
    (18, 'Present Continuous', 0),
    (18, 'Present Simple (habit)', 1),
    (18, 'Past Simple', 0),
    -- Питання 19
    (19, 'I goes to school.', 0),
    (19, 'I go to school.', 1),
    (19, 'I goed to school.', 0),
    (19, 'I going to school.', 0);

-- Географія, 6 клас, Материки та океани (topic_id = 25)
INSERT INTO `questions` (`topic_id`, `text`) VALUES
    (25, 'Скільки материків на Землі?'),
    (25, 'Який найбільший океан?'),
    (25, 'Який материк найбільший за площею?'),
    (25, 'Який океан найменший?'),
    (25, 'На якому материку знаходиться Україна?');

INSERT INTO `answers` (`question_id`, `text`, `is_correct`) VALUES
    -- Питання 20
    (20, '5', 0),
    (20, '6', 1),
    (20, '7', 0),
    (20, '4', 0),
    -- Питання 21
    (21, 'Атлантичний', 0),
    (21, 'Індійський', 0),
    (21, 'Тихий', 1),
    (21, 'Льодовитий', 0),
    -- Питання 22
    (22, 'Африка', 0),
    (22, 'Євразія', 1),
    (22, 'Америка', 0),
    (22, 'Антарктида', 0),
    -- Питання 23
    (23, 'Тихий', 0),
    (23, 'Атлантичний', 0),
    (23, 'Індійський', 0),
    (23, 'Північний Льодовитий', 1),
    -- Питання 24
    (24, 'Африка', 0),
    (24, 'Австралія', 0),
    (24, 'Євразія', 1),
    (24, 'Америка', 0);

-- =============================================
-- ОБЛІКОВІ ЗАПИСИ
-- =============================================

-- Адмін (admin@school.ua / admin123)
INSERT INTO `users` (`first_name`, `last_name`, `email`, `password_hash`, `class_id`, `role`) VALUES
    ('Адміністратор', 'Системи', 'admin@school.ua', '$2y$10$D1/mjOdd1Mb7xPgadsnMH.TWh3M2BCjpSHZBAGW4c4QvTW80sHCga', NULL, 'admin');

-- Тестовий учень (student@school.ua / student123)
INSERT INTO `users` (`first_name`, `last_name`, `email`, `password_hash`, `class_id`, `role`) VALUES
    ('Тестовий', 'Учень', 'student@school.ua', '$2y$10$Qq8Newk6v67aH8pBDDR6NewcQhcd72uitaj7If8Vhfpj4UdOAFuDK', 1, 'user');

-- Примітка: Паролі хешовані через password_hash()
-- admin123 -> використовується password_hash('admin123', PASSWORD_DEFAULT)
-- student123 -> використовується password_hash('student123', PASSWORD_DEFAULT)
-- Для оновлення хешів виконайте seed_passwords.php або оновіть через phpMyAdmin

-- Оновлення паролів правильними хешами (буде виконано PHP скриптом при першому запуску)
-- admin@school.ua : admin123
-- student@school.ua : student123
