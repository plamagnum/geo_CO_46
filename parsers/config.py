# Конфігурація Python парсера
# Налаштуйте CSS-селектори та HTML-теги тут

# URL сторінки для парсингу
URL = "https://example.com/test-page"

# Метадані для імпорту
TOPIC_ID   = 1
CLASS_ID   = 1
SUBJECT_ID = 1

# CSS-селектори для парсингу (BeautifulSoup синтаксис)
SELECTORS = {
    # Контейнер одного запитання (CSS-селектор)
    "question_container": "div.question",

    # Текст запитання всередині контейнера
    "question_text": "p.question-text",

    # Список варіантів відповідей
    "answers_list": "ul.answers",

    # Один варіант відповіді
    "answer_item": "li",

    # Що позначає правильну відповідь
    # Тип: "class" (клас елемента) або "attribute" (атрибут елемента)
    "correct_marker": {
        "type": "class",   # "class" або "attribute"
        "value": "correct" # Назва класу або атрибуту
    }
}

# Директорія та файл для збереження результату
OUTPUT_DIR  = "output"
OUTPUT_FILE = "parsed_questions.json"

# Затримка між запитами (секунди)
REQUEST_DELAY = 1.0

# Налаштування HTTP запиту
REQUEST_HEADERS = {
    "User-Agent": "Mozilla/5.0 (compatible; SchoolParser/1.0)"
}
REQUEST_TIMEOUT = 30
