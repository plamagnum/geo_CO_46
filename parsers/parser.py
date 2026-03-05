#!/usr/bin/env python3
"""
Python парсер для витягування запитань з HTML-сторінок.

Залежності:
    pip install requests beautifulsoup4

Використання:
    python parsers/parser.py

Результат зберігається у JSON файл у директорії output/
Файл можна використати для імпорту через API або адмін-панель.
"""

import json
import os
import sys
import time
from datetime import datetime
from pathlib import Path

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

    def fetch_html(self, url: str) -> str:
        """Завантаження HTML сторінки."""
        print(f"Завантаження сторінки: {url}")
        try:
            response = requests.get(
                url,
                headers=cfg.REQUEST_HEADERS,
                timeout=cfg.REQUEST_TIMEOUT
            )
            response.raise_for_status()
            response.encoding = response.apparent_encoding or 'utf-8'
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
        if not question_text:
            return None

        # Список відповідей
        answers_list = container.select_one(selectors['answers_list'])
        if not answers_list:
            return None

        answer_items = answers_list.select(selectors['answer_item'])
        if not answer_items:
            return None

        answers = []
        correct_marker = selectors['correct_marker']

        for item in answer_items:
            answer_text = item.get_text(strip=True)
            if not answer_text:
                continue

            # Визначення правильності відповіді
            is_correct = False
            if correct_marker['type'] == 'class':
                is_correct = correct_marker['value'] in (item.get('class') or [])
            elif correct_marker['type'] == 'attribute':
                is_correct = item.has_attr(correct_marker['value'])

            answers.append({
                'text': answer_text,
                'is_correct': is_correct
            })

        if not answers:
            return None

        return {
            'text': question_text,
            'answers': answers
        }

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

            if count > 0:
                file_path = self.save_to_json(questions)
                print(f"Результат збережено: {file_path}")
                print("Можна використати для імпорту через API.")
            else:
                print("Запитань не знайдено. Перевірте конфігурацію селекторів.")

        except RuntimeError as e:
            print(f"Помилка: {e}")
            sys.exit(1)

    def parse_from_string(self, html: str) -> list[dict]:
        """Парсинг з HTML рядка (для тестування)."""
        return self.parse(html)


# =============================================
# Точка входу
# =============================================
if __name__ == '__main__':
    parser = HtmlQuestionParser()
    parser.run()
