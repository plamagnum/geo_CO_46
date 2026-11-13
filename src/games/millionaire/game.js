/**
 * Гра «Мільйонер»: драбина запитань від 100 до 1 000 000 ігрових балів.
 * Правильна відповідь піднімає на сходинку вище, помилка — лишає на місці.
 * Підказки «50/50» і «Допомога залу» — по одній на гру (їх видає сервер).
 * Шкільна оцінка рахується лише за правильними відповідями.
 */
GameEngine.register('millionaire', {
    start(root, engine) {
        const el = engine.el;
        const questions = engine.config.questions;
        const N = questions.length;
        const allowed = engine.config.hints || [];
        const money = (n) => n.toLocaleString('uk-UA');

        // Геометрична драбина від 100 до 1 000 000, округлена до «красивих» чисел
        const prizes = (() => {
            const list = [];
            for (let k = 0; k < N; k++) {
                let v = N === 1 ? 1e6 : 100 * Math.pow(1e4, k / (N - 1));
                const mag = Math.pow(10, Math.floor(Math.log10(v)) - 1);
                v = Math.round(v / mag) * mag;
                if (k > 0 && v <= list[k - 1]) v = list[k - 1] + mag;
                list.push(v);
            }
            if (N) list[N - 1] = 1e6;
            return list;
        })();

        const state = { i: 0, level: 0, used: {} };

        /* ---------- Вступ ---------- */
        root.replaceChildren(el('div', { class: 'ge-panel ge-intro mi-intro' },
            el('div', { class: 'ge-intro-icon', text: '💰' }),
            el('h2', { text: 'Хто хоче стати мільйонером?' }),
            el('p', { text: `Тема: «${engine.config.topic}». Попереду ${N} ${engine.plural(N, 'запитання', 'запитання', 'запитань')} — ${N} ${engine.plural(N, 'сходинка', 'сходинки', 'сходинок')} до головного призу в 1 000 000 ігрових балів.` }),
            el('ul', {},
                el('li', { text: '✅ Правильна відповідь — сходинка вгору.' }),
                el('li', { text: '❌ Помилка — лишаєшся на місці, але гра триває.' }),
                allowed.includes('fifty') ? el('li', { text: '½ «50 на 50» прибирає дві неправильні відповіді (один раз за гру).' }) : null,
                allowed.includes('audience') ? el('li', { text: '👥 «Допомога залу» покаже, як голосував клас (один раз за гру).' }) : null,
                el('li', { text: '🎓 Шкільна оцінка залежить лише від кількості правильних відповідей.' })
            ),
            el('div', {}, el('button', { type: 'button', class: 'btn btn-primary btn-lg', text: '▶ Почати гру', onclick: begin }))
        ));

        /* ---------- Ігровий екран ---------- */
        let stage, ladder, hintsBar;
        const rungs = [];

        function begin() {
            ladder = el('ol', { class: 'mi-ladder', reversed: true });
            for (let k = N - 1; k >= 0; k--) {
                const li = el('li', { class: 'mi-rung' + ((k + 1) % 5 === 0 ? ' milestone' : '') },
                    el('span', { class: 'mi-rung-n', text: k + 1 }),
                    el('span', { class: 'mi-rung-prize', text: money(prizes[k]) }));
                rungs[k] = li;
                ladder.appendChild(li);
            }
            hintsBar = el('div', { class: 'mi-hints' });
            stage = el('div', { class: 'mi-stage' });

            root.replaceChildren(el('div', { class: 'mi-layout' },
                el('div', { class: 'mi-main' }, hintsBar, stage),
                el('aside', { class: 'mi-side' }, el('div', { class: 'mi-bank' }), ladder)
            ));
            updateLadder();
            ask();
        }

        function updateLadder() {
            rungs.forEach((r, k) => {
                r.classList.toggle('reached', k < state.level);
                r.classList.toggle('next', k === state.level);
            });
            root.querySelector('.mi-bank').replaceChildren(
                el('small', { text: 'Виграш' }),
                el('strong', { text: state.level ? money(prizes[state.level - 1]) : '0' }));
            const next = rungs[state.level];
            if (next && next.scrollIntoView && ladder.scrollHeight > ladder.clientHeight) {
                ladder.scrollTop = next.offsetTop - ladder.clientHeight / 2;
            }
        }

        function renderHints(q, view, answered) {
            const make = (type, label, title) => {
                if (!allowed.includes(type)) return null;
                const unsupported = q.multiple || (type === 'fifty' && q.answers.length < 3);
                const btn = el('button', {
                    type: 'button',
                    class: 'mi-hint' + (state.used[type] ? ' used' : ''),
                    title: unsupported ? 'Недоступно для цього запитання' : title,
                    disabled: answered || state.used[type] || unsupported,
                    text: label,
                });
                btn.addEventListener('click', async () => {
                    btn.disabled = true;
                    try {
                        const res = await engine.hint(type, q.id);
                        state.used[type] = true;
                        btn.classList.add('used');
                        if (res.hide) view.hide(res.hide);
                        if (res.votes) view.showVotes(res.votes);
                    } catch (e) {
                        btn.disabled = false;
                    }
                });
                return btn;
            };
            hintsBar.replaceChildren(
                make('fifty', '50 : 50', 'Прибрати дві неправильні відповіді'),
                make('audience', '👥 Зал', 'Допомога залу')
            );
        }

        function ask() {
            const q = questions[state.i];
            const feedback = el('div', { class: 'mi-feedback', 'aria-live': 'polite' });
            const view = engine.renderQuestion(q, {
                letters: true,
                confirmText: 'Остаточна відповідь',
                async onAnswer(ids) {
                    renderHints(q, view, true);
                    feedback.replaceChildren(el('p', { class: 'mi-wait', text: '⏳ Остаточна відповідь прийнята…' }));
                    let res;
                    try {
                        // Невелика пауза для інтриги
                        [res] = await Promise.all([engine.answer(q.id, ids), new Promise((r) => setTimeout(r, 1200))]);
                    } catch (e) {
                        feedback.replaceChildren();
                        view.unlock();
                        renderHints(q, view, false);
                        return;
                    }
                    view.reveal(res);
                    if (res.is_correct) {
                        state.level++;
                        updateLadder();
                        rungs[state.level - 1].classList.add('flash');
                    }
                    const last = state.i === N - 1;
                    feedback.replaceChildren(
                        el('div', { class: res.is_correct ? 'mi-good' : 'mi-bad' },
                            res.is_correct
                                ? `Абсолютно правильно! Ваш виграш — ${money(prizes[state.level - 1])}.`
                                : 'На жаль, це неправильна відповідь. Правильну підсвічено зеленим.'),
                        el('button', {
                            type: 'button',
                            class: 'btn ' + (last ? 'btn-success' : 'btn-primary'),
                            text: last ? '🏁 Завершити гру' : 'Наступне запитання →',
                            onclick: (e) => {
                                e.target.disabled = true;
                                if (last) finish();
                                else { state.i++; ask(); }
                            },
                        })
                    );
                    feedback.querySelector('button').focus();
                },
            });

            renderHints(q, view, false);
            stage.replaceChildren(
                el('div', { class: 'mi-qhead' },
                    el('span', { text: `Запитання ${state.i + 1} з ${N}` }),
                    el('span', { class: 'mi-for', text: `на ${money(prizes[state.level] ?? prizes[N - 1])}` })),
                view.node,
                feedback
            );
        }

        async function finish() {
            let res;
            try {
                res = await engine.finish();
            } catch (e) {
                stage.appendChild(el('button', { type: 'button', class: 'btn btn-primary', text: 'Спробувати зберегти ще раз', onclick: finish }));
                return;
            }
            const won = state.level ? prizes[state.level - 1] : 0;
            const flavor = state.level === N
                ? { icon: '💎', title: 'Ви — мільйонер!', text: 'Жодної помилки! Ви виграли головний приз — 1 000 000 ігрових балів.' }
                : { icon: won >= 1e5 ? '🏆' : '💰', title: `Ваш виграш: ${money(won)}`,
                    text: `Ви піднялися на ${state.level} ${engine.plural(state.level, 'сходинку', 'сходинки', 'сходинок')} із ${N}. Переглянь розбір відповідей і спробуй дійти до мільйона!` };
            engine.showSummary(root, res, flavor);
        }
    },
});
