<?php
/**
 * Конфігурація PHP парсера
 * Налаштуйте CSS-селектори та HTML-теги тут
 */

return [
    // URL сторінки для парсингу
    'url' => 'https://example.com/test-page',

    // Метадані для імпорту
    'topic_id'   => 1,
    'class_id'   => 1,
    'subject_id' => 1,

    // HTML-теги та атрибути для пошуку
    'selectors' => [
        // Контейнер одного запитання
        'question_container' => [
            'tag'   => 'div',
            'class' => 'question',
        ],

        // Текст запитання всередині контейнера
        'question_text' => [
            'tag'   => 'p',
            'class' => 'question-text',
        ],

        // Список варіантів відповідей
        'answers_list' => [
            'tag'   => 'ul',
            'class' => 'answers',
        ],

        // Один варіант відповіді
        'answer_item' => [
            'tag' => 'li',
        ],

        // Атрибут або клас, що позначає правильну відповідь
        'correct_marker' => [
            'type'  => 'class',   // 'class' або 'attribute'
            'value' => 'correct', // Назва класу або атрибуту
        ],
    ],

    // Директорія для збереження результату
    'output_dir'  => __DIR__ . '/output',
    'output_file' => 'parsed_questions.json',
];
