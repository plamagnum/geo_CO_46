#!/usr/bin/env python3
"""
Парсер тестів з vseosvita.ua (сторінки виду https://vseosvita.ua/test/...-1014428.html).

УВАГА: публічна сторінка тесту НЕ містить правильних відповідей —
Всеосвіта перевіряє їх на сервері. Тому всі відповіді імпортуються з
is_correct = false. Після імпорту позначте правильні в адмін-панелі
(«Запитання» → «Без правильної відповіді», кнопка «+/−» біля варіанта).
Поки правильну відповідь не позначено, запитання не потрапляє в тест.

Залежності:
    pip install requests beautifulsoup4

Використання:
    python parsers/vseosvita_parser.py URL [URL ...] --topic-id 5
    python parsers/vseosvita_parser.py --file saved_page.html --topic-id 5
    python parsers/vseosvita_parser.py URL --topic-id 5 --no-images -o geo8.json

Результат: parsers/output/vseosvita_questions.json (формат API імпорту).
"""

import argparse
import json
import sys
import time
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))

from parser import HtmlQuestionParser, cfg  # noqa: E402
from bs4 import BeautifulSoup  # noqa: E402

SUPERSCRIPT = str.maketrans('0123456789+-()', '⁰¹²³⁴⁵⁶⁷⁸⁹⁺⁻⁽⁾')
SUBSCRIPT = str.maketrans('0123456789+-()', '₀₁₂₃₄₅₆₇₈₉₊₋₍₎')

# data-quest_type на Всеосвіті: 1 — одна правильна, 2 — кілька правильних
SUPPORTED_TYPES = {'1', '2'}


class VseosvitaParser(HtmlQuestionParser):
    def __init__(self, topic_id: int, class_id: int, subject_id: int,
                 download_images: bool, output_file: str):
        super().__init__()
        self.topic_id = topic_id
        self.class_id = class_id
        self.subject_id = subject_id
        self.download_images = download_images
        self.output_file = output_file
        self.url = ''
        self.sources: list[str] = []

    @staticmethod
    def _clean_text(element) -> str:
        """Текст без HTML; <sup>/<sub> з цифрами → ⁰¹²/₀₁₂ (54<sup>0</sup> → 54⁰)."""
        element = BeautifulSoup(str(element), 'html.parser')
        for tag in element.find_all(['sup', 'sub']):
            text = tag.get_text()
            table = SUPERSCRIPT if tag.name == 'sup' else SUBSCRIPT
            converted = text.translate(table)
            tag.replace_with(converted if converted != text or not text.strip() else f'^{text}')
        for br in element.find_all('br'):
            br.replace_with(' ')
        element.smooth()
        return ' '.join(element.get_text(' ').split()).replace(' ,', ',').replace(' .', '.')

    def parse(self, html: str) -> list[dict]:
        soup = BeautifulSoup(html, 'html.parser')
        blocks = soup.select('div.vr-quest[data-id]')
        if not blocks:
            print('Попередження: запитань не знайдено (немає div.vr-quest). '
                  'Можливо, це не сторінка тесту Всеосвіти.')
            return []

        title = soup.select_one('.lib-inside-title h1')
        if title:
            print(f'Тест: {title.get_text(strip=True)}')
        print(f'Знайдено запитань на сторінці: {len(blocks)}')

        questions = []
        for block in blocks:
            q_type = block.get('data-quest_type', '1')
            number = block.select_one('.vr-q-num')
            number = number.get_text(strip=True) if number else block.get('data-id')
            if q_type not in SUPPORTED_TYPES:
                print(f'  №{number}: тип {q_type} не підтримується (не тест з вибором) — пропущено')
                continue
            question = self._parse_block(block)
            if question:
                questions.append(question)
            else:
                print(f'  №{number}: не вдалося розібрати — пропущено')
        return questions

    def _parse_block(self, block) -> dict | None:
        title = block.select_one('.v-test-questions-title')
        if not title:
            return None

        question_image = None
        if self.download_images:
            for img in title.select('img'):
                question_image = self.fetch_image(img)
                if question_image:
                    break
        question_text = self._clean_text(title)
        if not question_text and not question_image:
            return None

        answers = []
        for item in block.select('.v-test-questions-radio-block, .v-test-questions-checkbox-block'):
            label = item.select_one('label') or item
            answer_image = None
            if self.download_images:
                img = label.select_one('img')
                if img:
                    answer_image = self.fetch_image(img)
            text = self._clean_text(label)
            if not text and not answer_image:
                continue
            checkbox = item.select_one('input')
            # На публічній сторінці checked не буває, але якщо збережено сторінку
            # власника/результатів — використаємо позначку.
            is_correct = bool(checkbox and checkbox.has_attr('checked'))
            answer = {'text': text, 'is_correct': is_correct}
            if answer_image:
                answer['image'] = answer_image
            answers.append(answer)

        if len(answers) < 2:
            return None

        question = {'text': question_text, 'answers': answers}
        if question_image:
            question['image'] = question_image
        return question

    def save_to_json(self, questions: list[dict]) -> str:
        self.output_dir.mkdir(parents=True, exist_ok=True)
        output = {
            'topic_id': self.topic_id,
            'class_id': self.class_id,
            'subject_id': self.subject_id,
            'questions': questions,
            'parsed_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
            'source_url': ', '.join(self.sources),
        }
        file_path = self.output_dir / self.output_file
        with open(file_path, 'w', encoding='utf-8') as f:
            json.dump(output, f, ensure_ascii=False, indent=2)
        return str(file_path)


def main() -> None:
    ap = argparse.ArgumentParser(description='Парсер тестів vseosvita.ua (без правильних відповідей)')
    ap.add_argument('urls', nargs='*', help='Адреси сторінок тестів')
    ap.add_argument('--file', action='append', default=[], help='Локально збережена HTML-сторінка')
    ap.add_argument('--topic-id', type=int, default=cfg.TOPIC_ID)
    ap.add_argument('--class-id', type=int, default=cfg.CLASS_ID)
    ap.add_argument('--subject-id', type=int, default=cfg.SUBJECT_ID)
    ap.add_argument('--no-images', action='store_true', help='Не скачувати картинки')
    ap.add_argument('-o', '--output', default='vseosvita_questions.json', help='Ім\'я файлу в parsers/output/')
    args = ap.parse_args()

    if not args.urls and not args.file:
        ap.error('вкажіть URL тесту або --file збережена_сторінка.html')

    p = VseosvitaParser(args.topic_id, args.class_id, args.subject_id,
                        not args.no_images and getattr(cfg, 'DOWNLOAD_IMAGES', True), args.output)

    questions: list[dict] = []
    try:
        for path in args.file:
            print(f'Файл: {path}')
            html = Path(path).read_text(encoding='utf-8', errors='replace')
            questions += p.parse_from_string(html, base_url='https://vseosvita.ua/')
            p.sources.append(Path(path).name)
        for i, url in enumerate(args.urls):
            if i:
                time.sleep(cfg.REQUEST_DELAY)
            html = p.fetch_html(url)
            questions += p.parse(html)
            p.sources.append(url)
    except (RuntimeError, OSError) as e:
        print(f'Помилка: {e}')
        sys.exit(1)

    if not questions:
        print('Запитань не знайдено.')
        sys.exit(1)

    marked = sum(1 for q in questions if any(a['is_correct'] for a in q['answers']))
    print(f'Разом запитань: {len(questions)}')
    if p.download_images:
        print(f'Скачано картинок: {p.images_saved}')
    if marked < len(questions):
        print(f'⚠ Без правильної відповіді: {len(questions) - marked} — '
              'позначте їх в адмін-панелі після імпорту.')
    print(f'Результат збережено: {p.save_to_json(questions)}')


if __name__ == '__main__':
    main()
