<?php
/**
 * PHP парсер для витягування запитань з HTML-сторінок
 *
 * Використання:
 *   php parsers/parser.php
 *
 * Результат зберігається у JSON файл у директорії output/
 * Файл можна використати для імпорту через API або адмін-панель
 */

// Завантаження конфігурації
$config = require __DIR__ . '/config.php';

// =============================================
// Клас парсера
// =============================================
class HtmlQuestionParser {
    private array $config;
    private array $questions = [];

    public function __construct(array $config) {
        $this->config = $config;
    }

    /**
     * Завантаження HTML сторінки
     */
    private function fetchHtml(string $url): string {
        // Налаштування контексту для HTTP запиту
        $options = [
            'http' => [
                'method'     => 'GET',
                'header'     => "User-Agent: Mozilla/5.0 (compatible; SchoolParser/1.0)\r\n",
                'timeout'    => 30,
                'follow_location' => 1,
            ],
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
            ],
        ];

        $context = stream_context_create($options);
        $html = @file_get_contents($url, false, $context);

        if ($html === false) {
            throw new RuntimeException("Не вдалося завантажити сторінку: {$url}");
        }

        return $html;
    }

    /**
     * Парсинг HTML та витягування запитань
     */
    public function parse(string $html): array {
        // Вимкнення попереджень DOM
        libxml_use_internal_errors(true);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $selectors = $this->config['selectors'];

        // Пошук контейнерів запитань
        $questionTag   = $selectors['question_container']['tag'];
        $questionClass = $selectors['question_container']['class'];

        // XPath вираз для пошуку контейнерів з заданим класом
        $xpathQuery = "//{$questionTag}[contains(concat(' ', normalize-space(@class), ' '), ' {$questionClass} ')]";
        $questionNodes = $xpath->query($xpathQuery);

        if ($questionNodes === false || $questionNodes->length === 0) {
            echo "Попередження: Контейнери запитань не знайдені. Перевірте конфігурацію.\n";
            return [];
        }

        echo "Знайдено контейнерів запитань: {$questionNodes->length}\n";

        foreach ($questionNodes as $questionNode) {
            $questionData = $this->parseQuestion($xpath, $questionNode);
            if ($questionData !== null) {
                $this->questions[] = $questionData;
            }
        }

        return $this->questions;
    }

    /**
     * Парсинг одного запитання
     */
    private function parseQuestion(DOMXPath $xpath, DOMNode $questionNode): ?array {
        $selectors = $this->config['selectors'];

        // Отримання тексту запитання
        $textTag   = $selectors['question_text']['tag'];
        $textClass = $selectors['question_text']['class'];

        $textQuery = ".//{$textTag}[contains(concat(' ', normalize-space(@class), ' '), ' {$textClass} ')]";
        $textNodes = $xpath->query($textQuery, $questionNode);

        if ($textNodes === false || $textNodes->length === 0) {
            // Якщо клас не знайдено, спробувати просто за тегом
            $textNodes = $xpath->query(".//{$textTag}", $questionNode);
        }

        $questionText = '';
        if ($textNodes !== false && $textNodes->length > 0) {
            $questionText = trim($textNodes->item(0)->textContent);
        }

        if (empty($questionText)) {
            return null;
        }

        // Отримання варіантів відповідей
        $answersTag   = $selectors['answers_list']['tag'];
        $answersClass = $selectors['answers_list']['class'];
        $answerTag    = $selectors['answer_item']['tag'];

        $answersQuery = ".//{$answersTag}[contains(concat(' ', normalize-space(@class), ' '), ' {$answersClass} ')]";
        $answersNodes = $xpath->query($answersQuery, $questionNode);

        if ($answersNodes === false || $answersNodes->length === 0) {
            return null;
        }

        $answersList = $xpath->query(".//{$answerTag}", $answersNodes->item(0));
        if ($answersList === false || $answersList->length === 0) {
            return null;
        }

        $answers = [];
        $correctMarker = $selectors['correct_marker'];

        foreach ($answersList as $answerNode) {
            $answerText = trim($answerNode->textContent);
            if (empty($answerText)) continue;

            // Визначення правильності відповіді
            $isCorrect = false;
            if ($correctMarker['type'] === 'class') {
                $nodeClass = $answerNode->getAttribute('class');
                $isCorrect = str_contains($nodeClass, $correctMarker['value']);
            } elseif ($correctMarker['type'] === 'attribute') {
                $isCorrect = $answerNode->hasAttribute($correctMarker['value']);
            }

            $answers[] = [
                'text'       => $answerText,
                'is_correct' => $isCorrect,
            ];
        }

        if (empty($answers)) {
            return null;
        }

        return [
            'text'    => $questionText,
            'answers' => $answers,
        ];
    }

    /**
     * Збереження результату у JSON файл
     */
    public function saveToJson(array $questions): string {
        $outputDir  = $this->config['output_dir'];
        $outputFile = $this->config['output_file'];

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Формат сумісний з API імпорту
        $output = [
            'topic_id'   => $this->config['topic_id'],
            'class_id'   => $this->config['class_id'],
            'subject_id' => $this->config['subject_id'],
            'questions'  => $questions,
            'parsed_at'  => date('Y-m-d H:i:s'),
            'source_url' => $this->config['url'],
        ];

        $filePath = $outputDir . '/' . $outputFile;
        $json = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if (file_put_contents($filePath, $json) === false) {
            throw new RuntimeException("Не вдалося зберегти файл: {$filePath}");
        }

        return $filePath;
    }

    /**
     * Запуск парсингу з URL
     */
    public function run(): void {
        $url = $this->config['url'];
        echo "Завантаження сторінки: {$url}\n";

        try {
            $html = $this->fetchHtml($url);
            echo "Сторінку завантажено (" . strlen($html) . " байт).\n";

            $questions = $this->parse($html);
            $count = count($questions);
            echo "Знайдено запитань: {$count}\n";

            if ($count > 0) {
                $filePath = $this->saveToJson($questions);
                echo "Результат збережено: {$filePath}\n";
                echo "Можна використати для імпорту через API.\n";
            } else {
                echo "Запитань не знайдено. Перевірте конфігурацію селекторів.\n";
            }
        } catch (RuntimeException $e) {
            echo "Помилка: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    /**
     * Парсинг з HTML рядка (для тестування)
     */
    public function parseFromString(string $html): array {
        return $this->parse($html);
    }
}

// =============================================
// Точка входу
// =============================================
$parser = new HtmlQuestionParser($config);
$parser->run();
