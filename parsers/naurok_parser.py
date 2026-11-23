#!/usr/bin/env python3
"""
Парсер тестів з naurok.com.ua (сторінки виду https://naurok.com.ua/test/...-3504653.html).

УВАГА: публічна сторінка тесту (без сертифікації автора) НЕ показує правильних
відповідей, тому вони імпортуються з is_correct = false. Позначте правильні в
адмін-панелі після імпорту. Якщо у збереженій сторінці варіант має клас
is-correct/is-right/correct — він буде позначений автоматично.

Залежності: pip install requests beautifulsoup4

Використання:
    python parsers/naurok_parser.py URL [URL ...] --topic-id 5 --class-id 1 --subject-id 2
    python parsers/naurok_parser.py --file page.html --topic-id 5 --no-images -o geo6.json
"""

import argparse
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))

from vseosvita_parser import VseosvitaParser, cfg  # noqa: E402
from bs4 import BeautifulSoup  # noqa: E402

CORRECT_CLASSES = {'is-correct', 'is-right', 'correct', 'right'}


class NaurokParser(VseosvitaParser):
    def parse(self, html: str) -> list[dict]:
        soup = BeautifulSoup(html, 'html.parser')
        blocks = soup.select('article.tb-single-q')
        if not blocks:
            print('Попередження: запитань не знайдено (немає article.tb-single-q). '
                  'Можливо, це не сторінка тесту naurok.com.ua.')
            return []

        title = soup.select_one('h1.tb-cover-name') or soup.select_one('h1')
        if title:
            print(f'Тест: {title.get_text(strip=True)}')
        print(f'Знайдено запитань на сторінці: {len(blocks)}')

        questions = []
        for i, block in enumerate(blocks, 1):
            if not block.select_one('ul.tb-single-opts'):
                print(f'  №{i}: немає варіантів відповіді (не тест з вибором) — пропущено')
                continue
            question = self._parse_block(block)
            if question:
                questions.append(question)
            else:
                print(f'  №{i}: не вдалося розібрати — пропущено')
        return questions

    def _parse_block(self, block) -> dict | None:
        body = block.select_one('.tb-single-q-text')
        question_text = self._clean_text(body) if body else ''
        question_image = None
        if self.download_images:
            img = block.select_one('.tb-single-q-body img') or block.select_one('img.tb-single-q-img')
            if img:
                question_image = self.fetch_image(img)
        if not question_text and not question_image:
            return None

        answers = []
        for item in block.select('li.tb-single-opt'):
            text_el = item.select_one('.tb-single-opt-text')
            text = self._clean_text(text_el) if text_el else ''
            answer_image = None
            if self.download_images:
                img = item.select_one('img')
                if img:
                    answer_image = self.fetch_image(img)
            if not text and not answer_image:
                continue
            classes = set(item.get('class', [])) | set(
                c for m in item.select('.tb-single-marker') for c in m.get('class', []))
            answer = {'text': text, 'is_correct': bool(classes & CORRECT_CLASSES)}
            if answer_image:
                answer['image'] = answer_image
            answers.append(answer)

        if len(answers) < 2:
            return None
        question = {'text': question_text, 'answers': answers}
        if question_image:
            question['image'] = question_image
        return question


def main() -> None:
    ap = argparse.ArgumentParser(description='Парсер тестів naurok.com.ua (без правильних відповідей)')
    ap.add_argument('urls', nargs='*', help='Адреси сторінок тестів')
    ap.add_argument('--file', action='append', default=[], help='Локально збережена HTML-сторінка')
    ap.add_argument('--topic-id', type=int, default=cfg.TOPIC_ID)
    ap.add_argument('--class-id', type=int, default=cfg.CLASS_ID)
    ap.add_argument('--subject-id', type=int, default=cfg.SUBJECT_ID)
    ap.add_argument('--no-images', action='store_true', help='Не скачувати картинки')
    ap.add_argument('-o', '--output', default='naurok_questions.json', help="Ім'я файлу в parsers/output/")
    args = ap.parse_args()
    if not args.urls and not args.file:
        ap.error('вкажіть URL тесту або --file збережена_сторінка.html')

    p = NaurokParser(args.topic_id, args.class_id, args.subject_id,
                     not args.no_images and getattr(cfg, 'DOWNLOAD_IMAGES', True), args.output)
    questions: list[dict] = []
    try:
        for path in args.file:
            print(f'Файл: {path}')
            html = Path(path).read_text(encoding='utf-8', errors='replace')
            questions += p.parse_from_string(html, base_url='https://naurok.com.ua/')
            p.sources.append(Path(path).name)
        for i, url in enumerate(args.urls):
            if i:
                time.sleep(cfg.REQUEST_DELAY)
            questions += p.parse(p.fetch_html(url))
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
        print(f'⚠ Без правильної відповіді: {len(questions) - marked} — позначте їх в адмін-панелі після імпорту.')
    print(f'Результат збережено: {p.save_to_json(questions)}')


if __name__ == '__main__':
    main()
