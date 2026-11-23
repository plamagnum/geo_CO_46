/**
 * Гра «Експедиція»: корабель пливе від порту до порту.
 * У кожному порту — запитання. Правильна відповідь — скарб, помилка — шторм.
 * На оцінку впливає лише кількість правильних відповідей.
 */
GameEngine.register('expedition', {
    start(root, engine) {
        const el = engine.el;
        const questions = engine.config.questions;
        const N = questions.length;

        const PORTS = [
            ['Стамбул', 'Туреччина'], ['Афіни', 'Греція'], ['Олександрія', 'Єгипет'], ['Джидда', 'Саудівська Аравія'],
            ['Мумбаї', 'Індія'], ['Коломбо', 'Шрі-Ланка'], ['Сінгапур', 'Сінгапур'], ['Джакарта', 'Індонезія'],
            ['Сідней', 'Австралія'], ['Окленд', 'Нова Зеландія'], ['Токіо', 'Японія'], ['Шанхай', 'Китай'],
            ['Гонолулу', 'США'], ['Ванкувер', 'Канада'], ['Сан-Франциско', 'США'], ['Акапулько', 'Мексика'],
            ['Панама', 'Панама'], ['Ліма', 'Перу'], ['Вальпараїсо', 'Чилі'], ['Буенос-Айрес', 'Аргентина'],
            ['Ріо-де-Жанейро', 'Бразилія'], ['Кейптаун', 'ПАР'], ['Момбаса', 'Кенія'], ['Дакар', 'Сенегал'],
            ['Лісабон', 'Португалія'], ['Барселона', 'Іспанія'], ['Марсель', 'Франція'], ['Лондон', 'Велика Британія'],
            ['Рейк\'явік', 'Ісландія'], ['Нью-Йорк', 'США'], ['Гамбург', 'Німеччина'], ['Осло', 'Норвегія'],
        ];
        const port = (i) => i === 0 ? ['Одеса', 'Україна'] : PORTS[(i - 1) % PORTS.length];

        const MAX_HULL = 3;
        const state = { index: 0, treasures: 0, hull: MAX_HULL };

        // Точки маршруту: 0 — Одеса, 1..N — порти із запитаннями
        const points = Array.from({ length: N + 1 }, (_, i) => ({
            x: 6 + 88 * i / Math.max(N, 1),
            y: 50 + 30 * Math.sin(i * 1.3 + 0.4),
        }));

        /* ---------- Вступ ---------- */
        root.replaceChildren(el('div', { class: 'ge-panel ge-intro' },
            el('div', { class: 'ge-intro-icon', text: '🚢' }),
            el('h2', { text: 'Експедиція «Дослідник»' }),
            el('p', { text: `Капітане! Твоє судно вирушає з Одеси в навколосвітню експедицію. На маршруті ${N} ${engine.plural(N, 'порт', 'порти', 'портів')}, і в кожному місцевий географ перевірить твої знання з теми «${engine.config.topic}».` }),
            el('ul', {},
                el('li', { text: '💎 Правильна відповідь — скриня зі скарбом.' }),
                el('li', { text: '⛈ Помилка — шторм пошкодить корпус, але експедиція продовжиться.' }),
                el('li', { text: '🎓 Оцінка залежить лише від кількості правильних відповідей.' })
            ),
            el('div', {}, el('button', { type: 'button', class: 'btn btn-primary btn-lg', text: '⚓ Підняти якір', onclick: begin }))
        ));

        /* ---------- Ігровий екран ---------- */
        let map, ship, hud, panel;
        const portNodes = [];

        function begin() {
            hud = el('div', { class: 'ex-hud' });

            const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.setAttribute('viewBox', '0 0 100 100');
            svg.setAttribute('preserveAspectRatio', 'none');
            svg.setAttribute('class', 'ex-route');
            const line = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
            line.setAttribute('points', points.map((p) => `${p.x},${p.y}`).join(' '));
            svg.appendChild(line);

            map = el('div', { class: 'ex-map', 'aria-hidden': 'true' },
                svg,
                el('span', { class: 'ex-deco', style: 'left:18%;top:12%', text: '🏝' }),
                el('span', { class: 'ex-deco', style: 'left:55%;top:82%', text: '🐋' }),
                el('span', { class: 'ex-deco', style: 'left:80%;top:8%', text: '🌋' }),
                el('span', { class: 'ex-deco', style: 'left:35%;top:85%', text: '🐬' })
            );
            points.forEach((p, i) => {
                const node = el('span', {
                    class: 'ex-port' + (i === 0 ? ' home' : ''),
                    style: `left:${p.x}%;top:${p.y}%`,
                    title: port(i)[0],
                    text: i === 0 ? '🏠' : '',
                });
                portNodes.push(node);
                map.appendChild(node);
            });
            ship = el('span', { class: 'ex-ship', text: '🚢' });
            map.appendChild(ship);
            placeShip(0, false);

            panel = el('div', { class: 'ge-panel ex-panel' });
            root.replaceChildren(hud, map, panel);
            updateHud();
            sailTo(1);
        }

        function placeShip(i, animate) {
            ship.classList.toggle('sailing', !!animate);
            ship.style.left = points[i].x + '%';
            ship.style.top = points[i].y + '%';
        }

        function updateHud() {
            const hearts = '❤️'.repeat(state.hull) + '🖤'.repeat(MAX_HULL - state.hull);
            hud.replaceChildren(
                el('span', { class: 'ex-hud-item', title: 'Пройдено портів' }, '⚓ ', el('strong', { text: `${state.index} / ${N}` })),
                el('span', { class: 'ex-hud-item', title: 'Скарби' }, '💎 ', el('strong', { text: state.treasures })),
                el('span', { class: 'ex-hud-item', title: 'Корпус судна' }, state.hull > 0 ? hearts : '🛶 на рятувальній шлюпці')
            );
        }

        function sailTo(i) {
            panel.replaceChildren(el('p', { class: 'ex-sailing', text: `⛵ Пливемо до порту ${port(i)[0]}…` }));
            placeShip(i, true);
            setTimeout(() => askAt(i), 1100);
        }

        function askAt(i) {
            const q = questions[i - 1];
            const [city, country] = port(i);
            portNodes[i].classList.add('current');

            const feedback = el('div', { class: 'ex-feedback', 'aria-live': 'polite' });
            const view = engine.renderQuestion(q, {
                async onAnswer(ids) {
                    let res;
                    try {
                        res = await engine.answer(q.id, ids);
                    } catch (e) {
                        view.unlock();
                        return;
                    }
                    view.reveal(res);
                    state.index = i;
                    portNodes[i].classList.remove('current');
                    if (res.is_correct) {
                        state.treasures++;
                        portNodes[i].classList.add('won');
                        portNodes[i].textContent = '💎';
                        feedback.replaceChildren(el('div', { class: 'ex-good' },
                            el('strong', { text: 'Попутний вітер! ' }), `Географ із міста ${city} вручає тобі скриню зі скарбом.`));
                    } else {
                        state.hull = Math.max(0, state.hull - 1);
                        portNodes[i].classList.add('lost');
                        portNodes[i].textContent = '⛈';
                        map.classList.remove('storm');
                        void map.offsetWidth;
                        map.classList.add('storm');
                        feedback.replaceChildren(el('div', { class: 'ex-bad' },
                            el('strong', { text: 'Шторм! ' }),
                            state.hull > 0 ? 'Корпус пошкоджено, але ми тримаємо курс.' : 'Судно розбите — продовжуємо на шлюпці!',
                            ' Правильну відповідь підсвічено зеленим.'));
                    }
                    updateHud();

                    const last = i === N;
                    feedback.appendChild(el('button', {
                        type: 'button',
                        class: 'btn ' + (last ? 'btn-success' : 'btn-primary'),
                        text: last ? '🏁 Повернутися додому' : '⛵ Пливти далі',
                        onclick: (e) => { e.target.disabled = true; last ? finish() : sailTo(i + 1); },
                    }));
                    feedback.querySelector('button').focus();
                },
            });

            panel.replaceChildren(
                el('div', { class: 'ex-port-title' },
                    el('span', { text: `📍 ${city}, ${country}` }),
                    el('small', { text: `Порт ${i} з ${N}` })),
                el('p', { class: 'ex-npc', text: '🧭 Місцевий географ запитує:' }),
                view.node,
                feedback
            );
        }

        async function finish() {
            panel.replaceChildren(el('p', { class: 'ex-sailing', text: '🏠 Повертаємося до Одеси…' }));
            placeShip(0, true);
            let res;
            try {
                res = await engine.finish();
            } catch (e) {
                panel.replaceChildren(el('button', { type: 'button', class: 'btn btn-primary', text: 'Спробувати зберегти ще раз', onclick: finish }));
                return;
            }
            const p = res.score_percent;
            const flavor = p >= 90
                ? { icon: '🏆', title: 'Легендарний капітан!', text: `Трюм повний скарбів: ${res.correct} 💎. Про твою експедицію напишуть у підручниках!` }
                : p >= 60
                    ? { icon: '⚓', title: 'Експедиція успішна!', text: `Ти привіз додому ${res.correct} 💎. Ще кілька плавань — і станеш легендою морів.` }
                    : { icon: '🛟', title: 'Важке плавання…', text: `Штормів було забагато, але ти повернувся з ${res.correct} 💎. Переглянь розбір і спробуй ще раз!` };
            setTimeout(() => engine.showSummary(root, res, flavor), 900);
        }
    },
});
