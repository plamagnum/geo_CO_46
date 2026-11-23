/**
 * Спільний рушій ігор.
 *
 * Гра підключається так (src/games/<slug>/game.js):
 *
 *   GameEngine.register('<slug>', {
 *       start(root, engine) { ... }
 *   });
 *
 * Доступно грі:
 *   engine.config.questions        — запитання (без правильних відповідей)
 *   engine.answer(qid, ids)        — Promise {is_correct, correct_ids, selected, correct, answered, total}
 *   engine.hint(type, qid)         — Promise {hide:[ids]} або {votes:{id:%}}
 *   engine.finish()                — Promise {grade, correct, total, score_percent, average_grade, attempts}
 *   engine.renderQuestion(q, opts) — готовий блок запитання з кнопками відповідей
 *   engine.showSummary(root, res, flavor) — фінальний екран з оцінкою і розбором
 *   engine.el(tag, attrs, ...children), engine.toast(text)
 *   engine.plural(n, 'порт', 'порти', 'портів')
 */
(function () {
    'use strict';

    const config = window.GAME_CONFIG || {};
    const games = {};
    const log = {};          // qid -> відповідь сервера (для розбору)
    let started = false;
    let finished = false;

    function el(tag, attrs, ...children) {
        const node = document.createElement(tag);
        Object.entries(attrs || {}).forEach(([k, v]) => {
            if (v === null || v === undefined || v === false) return;
            if (k === 'class') node.className = v;
            else if (k === 'text') node.textContent = v;
            else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2), v);
            else node.setAttribute(k, v === true ? '' : v);
        });
        children.flat().forEach((c) => {
            if (c === null || c === undefined || c === false) return;
            node.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
        });
        return node;
    }

    function toast(text, kind) {
        const t = el('div', { class: 'ge-toast ' + (kind || 'error'), role: 'alert', text });
        document.body.appendChild(t);
        setTimeout(() => t.classList.add('hide'), 3500);
        setTimeout(() => t.remove(), 4000);
    }

    async function post(action, data) {
        const body = new URLSearchParams();
        body.append('action', action);
        body.append('token', config.token);
        body.append('csrf_token', config.csrf);
        Object.entries(data || {}).forEach(([k, v]) => {
            if (Array.isArray(v)) v.forEach((x) => body.append(k + '[]', x));
            else body.append(k, v);
        });
        let res;
        try {
            const r = await fetch(config.endpoint, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            res = await r.json();
        } catch (e) {
            res = { success: false, error: 'Немає зв\'язку з сервером. Перевірте мережу.' };
        }
        if (!res.success) {
            toast(res.error || 'Помилка');
            throw new Error(res.error || 'request failed');
        }
        return res;
    }

    function plural(n, one, few, many) {
        const m = Math.abs(n) % 100;
        if (m >= 11 && m <= 14) return many;
        if (m % 10 === 1) return one;
        if (m % 10 >= 2 && m % 10 <= 4) return few;
        return many;
    }

    const engine = {
        config,
        el,
        toast,
        plural,

        async answer(qid, ids) {
            started = true;
            const res = await post('answer', { question_id: qid, answer_ids: ids });
            log[qid] = res;
            return res;
        },

        hint(type, qid) {
            return post('hint', { question_id: qid, type });
        },

        async finish() {
            const res = await post('finish');
            finished = true;
            return res;
        },

        /**
         * Блок запитання.
         * opts.onAnswer(ids)  — викликається, коли учень відповів
         * opts.letters        — показувати A/B/C/D
         * Повертає {node, reveal(res), hide(ids), showVotes(votes), lock()}
         */
        renderQuestion(q, opts) {
            opts = opts || {};
            const letters = 'ABCDEFGHIJ';
            const chosen = new Set();
            const buttons = {};
            let locked = false;

            const confirmBtn = q.multiple
                ? el('button', { type: 'button', class: 'btn btn-primary ge-confirm', disabled: true, text: opts.confirmText || 'Відповісти' })
                : null;

            const list = el('div', { class: 'ge-answers' + (q.multiple ? ' multi' : '') });
            q.answers.forEach((a, i) => {
                const btn = el('button', { type: 'button', class: 'ge-answer', 'data-id': a.id },
                    opts.letters ? el('span', { class: 'ge-letter', text: letters[i] || '' }) : null,
                    el('span', { class: 'ge-answer-text' },
                        a.text,
                        a.image ? el('img', { src: a.image, alt: '', class: 'ge-a-image', loading: 'lazy' }) : null),
                    el('span', { class: 'ge-vote' })
                );
                btn.addEventListener('click', () => {
                    if (locked) return;
                    if (q.multiple) {
                        chosen.has(a.id) ? chosen.delete(a.id) : chosen.add(a.id);
                        btn.classList.toggle('chosen', chosen.has(a.id));
                        confirmBtn.disabled = chosen.size === 0;
                    } else {
                        chosen.add(a.id);
                        btn.classList.add('chosen');
                        submit();
                    }
                });
                buttons[a.id] = btn;
                list.appendChild(btn);
            });

            function submit() {
                locked = true;
                node.classList.add('locked');
                if (confirmBtn) confirmBtn.disabled = true;
                opts.onAnswer && opts.onAnswer([...chosen]);
            }
            if (confirmBtn) confirmBtn.addEventListener('click', () => { if (!locked && chosen.size) submit(); });

            const node = el('div', { class: 'ge-question' },
                el('h2', { class: 'ge-question-text', text: q.text }),
                q.image ? el('img', { src: q.image, alt: '', class: 'ge-q-image' }) : null,
                q.multiple ? el('p', { class: 'multi-hint', text: '☑ Оберіть усі правильні відповіді (їх кілька)' }) : null,
                list,
                confirmBtn
            );

            return {
                node,
                lock() { locked = true; node.classList.add('locked'); },
                // Відповідь не дійшла до сервера — дозволяємо спробувати ще раз
                unlock() {
                    locked = false;
                    node.classList.remove('locked');
                    if (!q.multiple) {
                        chosen.clear();
                        Object.values(buttons).forEach((b) => b.classList.remove('chosen'));
                    } else if (confirmBtn) {
                        confirmBtn.disabled = chosen.size === 0;
                    }
                },
                reveal(res) {
                    locked = true;
                    node.classList.add('locked', 'revealed');
                    res.correct_ids.forEach((id) => buttons[id] && buttons[id].classList.add('correct'));
                    res.selected.forEach((id) => {
                        if (!res.correct_ids.includes(id) && buttons[id]) buttons[id].classList.add('wrong');
                    });
                },
                hide(ids) {
                    ids.forEach((id) => { if (buttons[id]) { buttons[id].classList.add('hidden'); buttons[id].disabled = true; } });
                },
                showVotes(votes) {
                    Object.entries(votes).forEach(([id, pct]) => {
                        const b = buttons[id];
                        if (!b) return;
                        b.querySelector('.ge-vote').textContent = pct + '%';
                        b.style.setProperty('--vote', pct + '%');
                        b.classList.add('voted');
                    });
                },
            };
        },

        /** Фінальний екран: оцінка, середня, розбір відповідей */
        showSummary(root, res, flavor) {
            flavor = flavor || {};
            const review = el('div', { class: 'ge-review', hidden: true },
                el('h3', { text: 'Розбір відповідей' }),
                config.questions.map((q, i) => {
                    const r = log[q.id];
                    const ok = r && r.is_correct;
                    const byId = Object.fromEntries(q.answers.map((a) => [a.id, a.text]));
                    return el('div', { class: 'ge-review-item ' + (ok ? 'ok' : 'bad') },
                        el('div', { class: 'ge-review-q', text: (i + 1) + '. ' + q.text }),
                        el('div', { class: 'ge-review-a' },
                            ok ? '✅ ' : '❌ ',
                            'Ваша відповідь: ',
                            el('strong', { text: r && r.selected.length ? r.selected.map((id) => byId[id]).join('; ') : 'немає' })),
                        !ok && r ? el('div', { class: 'ge-review-a' }, 'Правильно: ',
                            el('strong', { text: r.correct_ids.map((id) => byId[id]).join('; ') })) : null
                    );
                })
            );

            const word = (g) => (g % 1 !== 0 ? 'бала' : plural(g, 'бал', 'бали', 'балів'));
            const fmt = (g) => String(g).replace('.', ',');

            root.replaceChildren(el('div', { class: 'ge-summary' },
                el('div', { class: 'ge-summary-icon', text: flavor.icon || (res.grade >= 7 ? '🏆' : '💪') }),
                el('h2', { text: flavor.title || 'Гру завершено!' }),
                flavor.text ? el('p', { class: 'ge-summary-text', text: flavor.text }) : null,
                el('div', { class: 'score-circle ge-score ' + (res.grade >= 7 ? 'good' : 'bad') },
                    el('span', { class: 'score-number', text: res.grade }),
                    el('small', { text: word(res.grade) })),
                el('p', { class: 'score-details', text: `Правильних відповідей: ${res.correct} з ${res.total} (${res.score_percent}%)` }),
                res.attempts > 1
                    ? el('p', { class: 'score-average', text: `Спроб: ${res.attempts} · середня оцінка: ${fmt(res.average_grade)} ${word(res.average_grade)}` })
                    : null,
                el('div', { class: 'results-actions' },
                    el('a', { href: config.backUrl, class: 'btn btn-outline', text: '← До списку' }),
                    el('button', { type: 'button', class: 'btn btn-outline', text: '↻ Грати ще раз', onclick: () => location.reload() }),
                    el('button', { type: 'button', class: 'btn btn-primary', text: 'Розбір відповідей',
                        onclick: () => { review.hidden = !review.hidden; } }),
                    el('a', { href: '/tests/results.php', class: 'btn btn-secondary', text: 'Мої результати' })
                ),
                review
            ));
            root.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },

        register(slug, game) {
            games[slug] = game;
            if (slug === config.game) {
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', () => run(game));
                } else {
                    run(game);
                }
            }
        },
    };

    function run(game) {
        const root = document.getElementById('gameRoot');
        if (!root) return;
        root.replaceChildren();
        try {
            game.start(root, engine);
        } catch (e) {
            console.error(e);
            root.textContent = 'Не вдалося запустити гру.';
        }
    }

    // Попередження, якщо учень іде з незавершеної гри
    window.addEventListener('beforeunload', (e) => {
        if (started && !finished) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    window.GameEngine = engine;
})();
