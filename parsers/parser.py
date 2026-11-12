#!/usr/bin/env python3
"""
Python парсер для витягування запитань з HTML-сторінок.

Залежності:
    pip install requests beautifulsoup4

Картинки (запитань і відповідей) скачуються і вбудовуються в JSON
як data URI — сервер при імпорті зберігає їх у src/uploads/questions/.
Вимкнути: DOWNLOAD_IMAGES = False у config.py.

Використання:
    python parsers/parser.py

Результат зберігається у JSON файл у директорії output/
Файл можна використати для імпорту через API або адмін-панель.
"""

import base64
import hashlib
import json
import mimetypes
import os
import sys
import time
from datetime import datetime
from pathlib import Path
from urllib.parse import urljoin

try:
    import requests
    from bs4 import BeautifulSoup
except ImportError:
    print("Помилка: Необхідні пакети не встановлено.")
    print("Встановіть їх командою: pip install requests beautifulsoup4")
    sys.exit(1)

# Завантаження конфігурації
# Додавання директорії парсера до шляху
sys.path.insert(0, str(Path(__file__).parent))

try:
    import config as cfg
except ImportError:
    print("Помилка: Файл config.py не знайдено в директорії parsers/")
    sys.exit(1)


class HtmlQuestionParser:
    """Парсер HTML-сторінок з тестовими запитаннями."""

    def __init__(self):
        self.url        = cfg.URL
        self.topic_id   = cfg.TOPIC_ID
        self.class_id   = cfg.CLASS_ID
        self.subject_id = cfg.SUBJECT_ID
        self.selectors  = cfg.SELECTORS
        self.output_dir = Path(__file__).parent / cfg.OUTPUT_DIR
        self.output_file = cfg.OUTPUT_FILE
        self.questions  = []
        self.base_url   = cfg.URL
        self.download_images = getattr(cfg, 'DOWNLOAD_IMAGES', True)
        self.images_dir = self.output_dir / getattr(cfg, 'IMAGES_DIR', 'images')
        self.image_max_bytes = getattr(cfg, 'IMAGE_MAX_BYTES', 5 * 1024 * 1024)
        self._image_cache: dict[str, str | None] = {}
        self.images_saved = 0
        self.session = requests.Session()
        self.session.headers.update(cfg.REQUEST_HEADERS)

    def fetch_html(self, url: str) -> str:
        """Завантаження HTML сторінки."""
        print(f"Завантаження сторінки: {url}")
        try:
            response = self.session.get(url, timeout=cfg.REQUEST_TIMEOUT)
            response.raise_for_status()
            response.encoding = response.apparent_encoding or 'utf-8'
            self.base_url = response.url
            print(f"Сторінку завантажено ({len(response.text)} символів).")
            return response.text
        except requests.RequestException as e:
            raise RuntimeError(f"Не вдалося завантажити сторінку: {e}") from e

    def parse(self, html: str) -> list[dict]:
        """Парсинг HTML та витягування запитань."""
        soup = BeautifulSoup(html, 'html.parser')
        selectors = self.selectors

        # Пошук контейнерів запитань
        question_containers = soup.select(selectors['question_container'])

        if not question_containers:
            print("Попередження: Контейнери запитань не знайдені. Перевірте конфігурацію.")
            return []

        print(f"Знайдено контейнерів запитань: {len(question_containers)}")

        questions = []
        for container in question_containers:
            question_data = self._parse_question(container)
            if question_data:
                questions.append(question_data)

        return questions

    def _parse_question(self, container) -> dict | None:
        """Парсинг одного запитання."""
        selectors = self.selectors

        # Текст запитання
        text_element = container.select_one(selectors['question_text'])
        if not text_element:
            # Спроба без класу — просто за тегом
            tag = selectors['question_text'].split('.')[0]
            text_element = container.find(tag)

        if not text_element:
            return None

        question_text = text_element.get_text(strip=True)

        # Список відповідей
        answers_list = container.select_one(selectors['answers_list'])
        if not answers_list:
            return None

        # Картинка запитання (перша, що не всередині списку відповідей)
        question_image = None
        if self.download_images and selectors.get('question_image'):
            for img in container.select(selectors['question_image']):
                if answers_list in img.parents:
                    continue
                question_image = self.fetch_image(img)
                if question_image:
                    break

        if not question_text and not question_image:
            return None

        answer_items = answers_list.select(selectors['answer_item'])
        if not answer_items:
            return None

        answers = []
        correct_marker = selectors['correct_marker']

        for item in answer_items:
            answer_text = item.get_text(strip=True)

            answer_image = None
            if self.download_images and selectors.get('answer_image'):
                img = item.select_one(selectors['answer_image'])
                if img:
                    answer_image = self.fetch_image(img)

            if not answer_text and not answer_image:
                continue

            # Визначення правильності відповіді
            is_correct = False
            if correct_marker['type'] == 'class':
                is_correct = correct_marker['value'] in (item.get('class') or [])
            elif correct_marker['type'] == 'attribute':
                is_correct = item.has_attr(correct_marker['value'])

            answer = {'text': answer_text, 'is_correct': is_correct}
            if answer_image:
                answer['image'] = answer_image
            answers.append(answer)

        if not answers:
            return None

        question = {'text': question_text, 'answers': answers}
        if question_image:
            question['image'] = question_image
        return question

    @staticmethod
    def _image_src(img) -> str:
        """Адреса картинки з урахуванням «лінивого» завантаження."""
        for attr in ('data-src', 'data-original', 'data-lazy-src', 'src'):
            value = (img.get(attr) or '').strip()
            if value:
                return value
        return ''

    def fetch_image(self, img) -> str | None:
        """Скачує картинку і повертає data URI (data:image/...;base64,...) або None."""
        src = self._image_src(img)
        if not src:
            return None
        if src.startswith('data:image/'):
            return src

        url = urljoin(self.base_url, src)
        if url in self._image_cache:
            return self._image_cache[url]

        data_uri = None
        try:
            response = self.session.get(
                url, timeout=cfg.REQUEST_TIMEOUT, headers={'Referer': self.base_url}
            )
            response.raise_for_status()
            content = response.content
            mime = response.headers.get('Content-Type', '').split(';')[0].strip().lower()
            if not mime.startswith('image/'):
                mime = mimetypes.guess_type(url)[0] or ''
            if not mime.startswith('image/') or mime == 'image/svg+xml':
                print(f"  Пропущено (не підтримується): {url}")
            elif len(content) > self.image_max_bytes:
                print(f"  Пропущено (завелика картинка): {url}")
            else:
                data_uri = f"data:{mime};base64," + base64.b64encode(content).decode('ascii')
                self._save_image_copy(content, mime)
        except requests.RequestException as e:
            print(f"  Не вдалося скачати картинку {url}: {e}")

        self._image_cache[url] = data_uri
        return data_uri

    def _save_image_copy(self, content: bytes, mime: str) -> None:
        """Локальна копія картинки в output/images/ (для перегляду)."""
        ext = mimetypes.guess_extension(mime) or '.img'
        if ext == '.jpe':
            ext = '.jpg'
        self.images_dir.mkdir(parents=True, exist_ok=True)
        path = self.images_dir / (hashlib.sha1(content).hexdigest() + ext)
        if not path.exists():
            path.write_bytes(content)
        self.images_saved += 1

    def save_to_json(self, questions: list[dict]) -> str:
        """Збереження результату у JSON файл."""
        self.output_dir.mkdir(parents=True, exist_ok=True)

        # Формат сумісний з API імпорту
        output = {
            'topic_id': self.topic_id,
            'class_id': self.class_id,
            'subject_id': self.subject_id,
            'questions': questions,
            'parsed_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
            'source_url': self.url,
        }

        file_path = self.output_dir / self.output_file
        with open(file_path, 'w', encoding='utf-8') as f:
            json.dump(output, f, ensure_ascii=False, indent=2)

        return str(file_path)

    def run(self):
        """Запуск парсингу з URL."""
        try:
            html = self.fetch_html(self.url)

            # Затримка перед парсингом
            time.sleep(cfg.REQUEST_DELAY)

            questions = self.parse(html)
            count = len(questions)
            print(f"Знайдено запитань: {count}")
            if self.download_images:
                print(f"Скачано картинок: {self.images_saved}")

            if count > 0:
                file_path = self.save_to_json(questions)
                print(f"Результат збережено: {file_path}")
                print("Можна використати для імпорту через API.")
            else:
                print("Запитань не знайдено. Перевірте конфігурацію селекторів.")

        except RuntimeError as e:
            print(f"Помилка: {e}")
            sys.exit(1)

    def parse_from_string(self, html: str, base_url: str | None = None) -> list[dict]:
        """Парсинг з HTML рядка (для тестування). base_url — для відносних адрес картинок."""
        if base_url:
            self.base_url = base_url
        return self.parse(html)


# =============================================
# Точка входу
# =============================================
if __name__ == '__main__':
    parser = HtmlQuestionParser()
    parser.run()
