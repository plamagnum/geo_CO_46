<?php
/**
 * Запуск парсерів (PHP / Python) з адмін-панелі
 *
 * Адмін вводить посилання, обирає клас, предмет, тему та ім'я JSON-файлу —
 * ID підставляються в парсер як --topic-id / --class-id / --subject-id.
 * Готовий файл можна одразу імпортувати в тему.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireAdmin();

const PARSER_TIMEOUT = 150;   // секунд
const PARSER_MAX_URLS = 10;
const JSON_EDIT_MAX = 5 * 1024 * 1024;   // байт

/** Дозволені парсери: ключ => [назва, мова, файл, чи приймає кілька посилань, опис] */
const PARSERS = [
    'py_vseosvita' => ['Python · vseosvita.ua', 'python', 'vseosvita_parser.py', true,
        'Тести з vseosvita.ua. Правильних відповідей на сторінці немає — їх позначають в адмінці після імпорту.'],
    'py_naurok'    => ['Python · naurok.com.ua', 'python', 'naurok_parser.py', true,
        'Тести з naurok.com.ua. Правильних відповідей на публічній сторінці немає — їх позначають в адмінці після імпорту.'],
    'py_generic'   => ['Python · універсальний (селектори з config.py)', 'python', 'parser.py', false,
        'Будь-яка сторінка; CSS-селектори задаються у parsers/config.py. Скачує картинки.'],
    'php_generic'  => ['PHP · універсальний (селектори з config.php)', 'php', 'parser.php', false,
        'Будь-яка сторінка; теги та класи задаються у parsers/config.php.'],
];

function parsersDir(): ?string {
    $candidates = array_filter([
        getenv('PARSERS_DIR') ?: null,
        dirname(__DIR__, 2) . '/parsers',
        '/var/www/parsers',
    ]);
    foreach ($candidates as $dir) {
        if (is_dir($dir) && is_file($dir . '/parser.py')) {
            return realpath($dir);
        }
    }
    return null;
}

/** Виконуваний файл інтерпретатора (php-fpm — не CLI, тому PHP_BINARY не підходить) */
function parserBinary(string $lang): ?string {
    $env = getenv($lang === 'php' ? 'PHP_CLI_BIN' : 'PYTHON_BIN');
    $names = $lang === 'php' ? ['php'] : ['python3', 'python'];
    $dirs = ['/usr/local/bin', '/usr/bin', '/bin', '/usr/local/sbin', '/opt/python/bin'];
    if ($env && is_executable($env)) {
        return $env;
    }
    foreach ($names as $name) {
        foreach ($dirs as $dir) {
            if (is_executable("$dir/$name")) {
                return "$dir/$name";
            }
        }
    }
    return null;
}

/** Ім'я файлу: лише літери, цифри, «_» і «-»; розширення .json додається автоматично */
function sanitizeJsonName(string $name): ?string {
    $name = trim($name);
    $name = preg_replace('/\.json$/iu', '', $name);
    $name = preg_replace('/\s+/u', '_', $name);
    if ($name === '' || mb_strlen($name) > 60 || !preg_match('/^[\p{L}\p{N}_-]+$/u', $name)) {
        return null;
    }
    return $name . '.json';
}

/** Посилання має бути http(s) і не вести у внутрішню мережу сервера */
function validateParserUrl(string $url): ?string {
    $p = parse_url($url);
    if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) {
        return 'Некоректне посилання (потрібен http:// або https://): ' . $url;
    }
    $host = trim($p['host'], '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
            $ips[] = $r['ip'] ?? $r['ipv6'] ?? null;
        }
        $ips = array_values(array_filter(array_unique($ips)));
    }
    if (!$ips) {
        return 'Не вдалося визначити адресу сайту: ' . $p['host'] . ' (перевірте DNS/доступ в інтернет з сервера: dig ' . $p['host'] . ')';
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return 'Посилання на внутрішню адресу заборонено: ' . $p['host'];
        }
    }
    return null;
}

/** Запускає команду без оболонки; повертає [код виходу, вивід] */
function runParserProcess(array $cmd, string $cwd): array {
    $env = [
        'PATH' => '/usr/local/bin:/usr/bin:/bin',
        'HOME' => sys_get_temp_dir(),
        'LANG' => 'C.UTF-8',
        'PYTHONIOENCODING' => 'utf-8',
        'PYTHONUNBUFFERED' => '1',
    ];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    if (!is_resource($proc)) {
        return [-1, 'Не вдалося запустити процес.'];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output = '';
    $deadline = time() + PARSER_TIMEOUT;
    $timedOut = false;
    while (true) {
        $read = [$pipes[1], $pipes[2]];
        $w = $x = null;
        if (stream_select($read, $w, $x, 1) > 0) {
            foreach ($read as $pipe) {
                $output .= (string)fread($pipe, 8192);
            }
        }
        $status = proc_get_status($proc);
        if (!$status['running']) {
            break;
        }
        if (time() > $deadline) {
            proc_terminate($proc, 9);
            $timedOut = true;
            break;
        }
    }
    $output .= (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = $status['running'] ? -1 : $status['exitcode'];
    $closeCode = proc_close($proc);
    if ($code === -1 && !$timedOut) {
        $code = $closeCode;
    }
    if ($timedOut) {
        $output .= "\nПерервано: перевищено ліміт часу (" . PARSER_TIMEOUT . " с).";
        $code = 124;
    }
    return [$code, mb_substr($output, 0, 20000)];
}

$pdo       = getDB();
$csrfToken = generateCsrfToken();
$dir       = parsersDir();
$outDir    = $dir ? $dir . '/output' : null;
$message = $error = $log = '';
$createdFile = null;

// Завантаження готового файлу (для імпорту з браузера)
if (isset($_GET['download']) && $outDir) {
    $name = basename((string)$_GET['download']);
    $path = $outDir . '/' . $name;
    if (sanitizeJsonName($name) === $name && is_file($path)) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        if (!empty($_GET['save'])) {
            header('Content-Disposition: attachment; filename="' . $name . '"');
        }
        readfile($path);
        exit;
    }
    http_response_code(404);
    exit('Файл не знайдено');
}

// Збереження відредагованого JSON (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_file') {
    header('Content-Type: application/json; charset=utf-8');
    $name = basename((string)($_POST['file'] ?? ''));
    $content = (string)($_POST['content'] ?? '');
    $fail = function (string $msg, int $code = 400) {
        http_response_code($code);
        echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    };
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) $fail('Помилка безпеки. Оновіть сторінку.', 403);
    if (!$outDir || sanitizeJsonName($name) !== $name || !is_file($outDir . '/' . $name)) $fail('Файл не знайдено.', 404);
    if (strlen($content) > JSON_EDIT_MAX) $fail('Файл завеликий для редактора.');
    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) $fail('Некоректний JSON: ' . json_last_error_msg());
    if (!is_array($data) || !isset($data['questions']) || !is_array($data['questions'])) {
        $fail('У JSON має бути масив "questions".');
    }
    $tmp = $outDir . '/.' . $name . '.tmp';
    if (file_put_contents($tmp, $content) === false || !rename($tmp, $outDir . '/' . $name)) {
        @unlink($tmp);
        $fail('Не вдалося записати файл (права на parsers/output).', 500);
    }
    echo json_encode(['success' => true, 'questions' => count($data['questions'])], JSON_UNESCAPED_UNICODE);
    exit;
}

$classes  = $pdo->query('SELECT id, name FROM classes ORDER BY CAST(name AS UNSIGNED), name')->fetchAll();
$subjects = $pdo->query('SELECT id, name FROM subjects ORDER BY name')->fetchAll();
$topics   = $pdo->query('SELECT id, name, class_id, subject_id FROM topics ORDER BY name')->fetchAll();

$form = ['parser' => 'py_vseosvita', 'urls' => '', 'filename' => '', 'class_id' => 0, 'subject_id' => 0,
         'topic_id' => 0, 'no_images' => false, 'overwrite' => false];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Помилка безпеки.';
    } elseif (!$dir) {
        $error = 'Папку з парсерами (parsers/) не знайдено. Задайте змінну середовища PARSERS_DIR.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'delete_file') {
            $name = basename((string)($_POST['file'] ?? ''));
            if (sanitizeJsonName($name) === $name && is_file($outDir . '/' . $name) && unlink($outDir . '/' . $name)) {
                $message = "Файл {$name} видалено.";
            } else {
                $error = 'Не вдалося видалити файл.';
            }
        } elseif ($action === 'run') {
            $form = [
                'parser'    => (string)($_POST['parser'] ?? ''),
                'urls'      => trim((string)($_POST['urls'] ?? '')),
                'filename'  => trim((string)($_POST['filename'] ?? '')),
                'class_id'  => (int)($_POST['class_id'] ?? 0),
                'subject_id' => (int)($_POST['subject_id'] ?? 0),
                'topic_id'  => (int)($_POST['topic_id'] ?? 0),
                'no_images' => !empty($_POST['no_images']),
                'overwrite' => !empty($_POST['overwrite']),
            ];
            $parser = PARSERS[$form['parser']] ?? null;
            $urls = array_values(array_filter(array_map('trim', preg_split('/\R/u', $form['urls']))));
            $file = sanitizeJsonName($form['filename']);

            $topic = null;
            foreach ($topics as $t) {
                if ((int)$t['id'] === $form['topic_id']) {
                    $topic = $t;
                }
            }

            if (!$parser) {
                $error = 'Оберіть парсер.';
            } elseif (!$urls) {
                $error = 'Вставте хоча б одне посилання.';
            } elseif (count($urls) > ($parser[3] ? PARSER_MAX_URLS : 1)) {
                $error = $parser[3] ? 'Забагато посилань (максимум ' . PARSER_MAX_URLS . ').' : 'Цей парсер приймає одне посилання.';
            } elseif (!$file) {
                $error = "Некоректне ім'я файлу: лише літери, цифри, «_» та «-» (до 60 символів).";
            } elseif (!$topic) {
                $error = 'Оберіть тему.';
            } elseif ((int)$topic['class_id'] !== $form['class_id'] || (int)$topic['subject_id'] !== $form['subject_id']) {
                $error = 'Обрана тема не належить до вказаних класу та предмета.';
            } elseif (is_file($outDir . '/' . $file) && !$form['overwrite']) {
                $error = "Файл {$file} уже існує. Змініть ім'я або поставте позначку «Перезаписати».";
            } elseif (!($bin = parserBinary($parser[1]))) {
                $error = $parser[1] === 'python'
                    ? 'На сервері не знайдено Python 3 (потрібні пакети requests і beautifulsoup4). Задайте PYTHON_BIN або встановіть Python.'
                    : 'На сервері не знайдено PHP CLI. Задайте змінну середовища PHP_CLI_BIN.';
            } else {
                foreach ($urls as $u) {
                    if ($err = validateParserUrl($u)) {
                        $error = $err;
                        break;
                    }
                }
            }

            if (!$error) {
                if (!is_dir($outDir) && !@mkdir($outDir, 0775, true)) {
                    $error = 'Не вдалося створити parsers/output.';
                } elseif (!is_writable($outDir)) {
                    $error = 'Немає прав на запис у parsers/output (виконайте: chmod -R 777 parsers/output).';
                }
            }

            if (!$error) {
                $script = $dir . '/' . $parser[2];
                $common = ['--topic-id', (string)$topic['id'], '--class-id', (string)$form['class_id'],
                           '--subject-id', (string)$form['subject_id'], '--output', $file];
                if ($parser[1] === 'php') {
                    $cmd = [$bin, $script, '--url=' . $urls[0], '--topic-id=' . $topic['id'],
                            '--class-id=' . $form['class_id'], '--subject-id=' . $form['subject_id'], '--output=' . $file];
                } else {
                    $cmd = array_merge([$bin, $script], $common, $form['no_images'] ? ['--no-images'] : []);
                    $cmd = $parser[3] ? array_merge($cmd, ['--'], $urls) : array_merge($cmd, ['--url', $urls[0]]);
                }

                set_time_limit(PARSER_TIMEOUT + 30);
                session_write_close(); // не блокувати інші запити, поки працює парсер
                @unlink($outDir . '/' . $file);
                [$code, $log] = runParserProcess($cmd, $dir);

                if ($code === 0 && is_file($outDir . '/' . $file)) {
                    $data = json_decode((string)file_get_contents($outDir . '/' . $file), true);
                    $count = is_array($data['questions'] ?? null) ? count($data['questions']) : 0;
                    if ($count > 0) {
                        $message = "Готово: {$file} — запитань: {$count}.";
                        $createdFile = $file;
                    } else {
                        $error = 'Парсер завершився, але запитань не знайдено. Перевірте посилання та селектори.';
                    }
                } else {
                    $error = 'Парсер завершився з помилкою (код ' . $code . '). Деталі нижче.';
                }
            }
        }
    }
}

// Список готових файлів
$files = [];
if ($outDir && is_dir($outDir)) {
    foreach (glob($outDir . '/*.json') ?: [] as $path) {
        $name = basename($path);
        $data = json_decode((string)file_get_contents($path), true);
        $files[] = [
            'name'  => $name,
            'size'  => filesize($path),
            'time'  => filemtime($path),
            'count' => is_array($data['questions'] ?? null) ? count($data['questions']) : 0,
            'topic' => (int)($data['topic_id'] ?? 0),
        ];
    }
    usort($files, fn($a, $b) => $b['time'] <=> $a['time']);
}
$topicNames = array_column($topics, 'name', 'id');

$pageTitle = 'Парсер';
require_once __DIR__ . '/../includes/header.php';

// Редактор JSON
$editName = isset($_GET['edit']) ? basename((string)$_GET['edit']) : '';
if ($editName !== '') {
    $editPath = $outDir ? $outDir . '/' . $editName : '';
    $tooBig = $editPath && is_file($editPath) && filesize($editPath) > JSON_EDIT_MAX;
    if (sanitizeJsonName($editName) !== $editName || !is_file($editPath) || $tooBig) {
        $error = $tooBig ? 'Файл завеликий для редактора.' : 'Файл не знайдено.';
        $editName = '';
    }
}
?>

<?php if ($editName !== ''): ?>
<div class="admin-header">
    <h1 class="page-title">Редактор: <?= e($editName) ?></h1>
    <a href="/admin/parser.php" class="btn btn-outline">← До списку файлів</a>
</div>
<div class="je-toolbar">
    <button type="button" class="btn btn-primary" id="jeSave">💾 Зберегти (Ctrl+S)</button>
    <button type="button" class="btn btn-outline" id="jeFormat">Форматувати</button>
    <button type="button" class="btn btn-outline" id="jeMinify">Стиснути</button>
    <span id="jeStatus" class="je-status"></span>
</div>
<div class="je" id="je">
    <div class="je-gutter" id="jeGutter" aria-hidden="true"></div>
    <div class="je-body">
        <pre class="je-highlight" id="jeHighlight" aria-hidden="true"></pre>
        <textarea class="je-input" id="jeInput" spellcheck="false" autocapitalize="off" autocomplete="off" wrap="off"><?= e((string)file_get_contents($editPath)) ?></textarea>
    </div>
</div>
<p class="form-hint">Tab — відступ. Підсвічується синтаксис, помилки JSON показуються одразу. Запитання з <code>is_correct: true</code> у відповідях потрапляють у тест.</p>
<script>window.JE_CONFIG = { file: <?= json_encode($editName) ?>, csrf: <?= json_encode($csrfToken) ?> };</script>
<script src="/assets/js/json-editor.js?v=1"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>

<div class="admin-header">
    <h1 class="page-title">Парсер запитань</h1>
    <a href="/admin/index.php" class="btn btn-outline">← Назад</a>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (!$dir): ?>
    <div class="alert alert-error">Папку <code>parsers/</code> не знайдено. Підключіть її та задайте <code>PARSERS_DIR</code>.</div>
<?php endif; ?>

<div class="admin-form-card">
    <h2>Новий парсинг</h2>
    <form method="POST" action="" id="parserForm">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="action" value="run">

        <div class="form-group">
            <label class="form-label" for="parser">Парсер</label>
            <select name="parser" id="parser" class="form-select" required>
                <?php foreach (PARSERS as $key => $p): ?>
                    <option value="<?= e($key) ?>" data-multi="<?= $p[3] ? '1' : '0' ?>" data-images="<?= $p[1] === 'php' ? '0' : '1' ?>"
                            data-desc="<?= e($p[4]) ?>" <?= $form['parser'] === $key ? 'selected' : '' ?>><?= e($p[0]) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted" id="parserDesc"></small>
        </div>

        <div class="form-group">
            <label class="form-label" for="urls" id="urlsLabel">Посилання</label>
            <textarea name="urls" id="urls" class="form-textarea" rows="3" required
                      placeholder="https://vseosvita.ua/test/..."><?= e($form['urls']) ?></textarea>
            <small class="text-muted" id="urlsHint"></small>
        </div>

        <div class="form-group">
            <label class="form-label" for="filename">Ім'я JSON-файлу</label>
            <input type="text" name="filename" id="filename" class="form-input" required maxlength="64"
                   placeholder="geo8_tema1" value="<?= e($form['filename']) ?>">
            <small class="text-muted">Без розширення — <code>.json</code> додається автоматично. Лише літери, цифри, «_» та «-».</small>
        </div>

        <div class="form-group">
            <label class="form-label" for="class_id">Клас</label>
            <select name="class_id" id="class_id" class="form-select" required>
                <option value="">— оберіть —</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $form['class_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label" for="subject_id">Предмет</label>
            <select name="subject_id" id="subject_id" class="form-select" required>
                <option value="">— оберіть —</option>
                <?php foreach ($subjects as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= $form['subject_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label" for="topic_id">Тема</label>
            <select name="topic_id" id="topic_id" class="form-select" required>
                <option value="">— оберіть —</option>
                <?php foreach ($topics as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" data-class="<?= (int)$t['class_id'] ?>" data-subject="<?= (int)$t['subject_id'] ?>"
                            <?= $form['topic_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted" id="topicHint">Показано теми обраного класу та предмета.</small>
        </div>

        <p class="text-muted" id="idsPreview"></p>

        <div class="form-group" id="imagesBox">
            <label><input type="checkbox" name="no_images" value="1" <?= $form['no_images'] ? 'checked' : '' ?>> Не скачувати картинки (швидше)</label>
        </div>
        <div class="form-group">
            <label><input type="checkbox" name="overwrite" value="1" <?= $form['overwrite'] ? 'checked' : '' ?>> Перезаписати файл, якщо він уже існує</label>
        </div>

        <button type="submit" class="btn btn-primary" id="runBtn">▶ Запустити парсер</button>
        <small class="text-muted" id="runNote"></small>
    </form>
</div>

<?php if ($log !== ''): ?>
    <div class="admin-form-card">
        <h2>Вивід парсера</h2>
        <pre class="parser-log"><?= e($log) ?></pre>
        <?php if ($createdFile): ?>
            <button type="button" class="btn btn-success js-import" data-file="<?= e($createdFile) ?>">⬆ Імпортувати в тему</button>
            <div class="import-result"></div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<h2>Готові файли</h2>
<?php if (empty($files)): ?>
    <div class="empty-state"><h3>Файлів ще немає</h3></div>
<?php else: ?>
    <div class="results-table-wrapper">
        <table class="admin-table">
            <thead><tr><th>Файл</th><th>Тема</th><th>Запитань</th><th>Розмір</th><th>Створено</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($files as $f): ?>
                <tr>
                    <td><?= e($f['name']) ?></td>
                    <td><?= e($topicNames[$f['topic']] ?? ('ID ' . $f['topic'])) ?></td>
                    <td><?= (int)$f['count'] ?></td>
                    <td><?= $f['size'] >= 1048576 ? round($f['size'] / 1048576, 1) . ' МБ' : max(1, round($f['size'] / 1024)) . ' КБ' ?></td>
                    <td><?= date('d.m.Y H:i', $f['time']) ?></td>
                    <td class="parser-actions">
                        <button type="button" class="btn btn-sm btn-primary js-import" data-file="<?= e($f['name']) ?>">Імпортувати</button>
                        <a class="btn btn-sm btn-outline" href="?edit=<?= e(rawurlencode($f['name'])) ?>">Редагувати</a>
                        <a class="btn btn-sm btn-outline" href="?download=<?= e(rawurlencode($f['name'])) ?>&amp;save=1">Завантажити</a>
                        <form method="POST" action="" onsubmit="return confirm('Видалити файл?');" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="delete_file">
                            <input type="hidden" name="file" value="<?= e($f['name']) ?>">
                            <button type="submit" class="btn btn-sm btn-outline">Видалити</button>
                        </form>
                        <div class="import-result"></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<script>
(function () {
    var $ = function (id) { return document.getElementById(id); };
    var parser = $('parser'), cls = $('class_id'), subj = $('subject_id'), topic = $('topic_id');

    function syncParser() {
        var o = parser.options[parser.selectedIndex];
        var multi = o.dataset.multi === '1';
        $('parserDesc').textContent = o.dataset.desc;
        $('urlsLabel').textContent = multi ? 'Посилання (по одному в рядок)' : 'Посилання';
        $('urlsHint').textContent = multi ? 'До <?= PARSER_MAX_URLS ?> посилань; запитання з усіх сторінок потраплять в один файл.' : 'Одне посилання.';
        $('imagesBox').style.display = o.dataset.images === '1' ? '' : 'none';
    }

    function syncTopics() {
        var shown = 0;
        Array.prototype.forEach.call(topic.options, function (opt) {
            if (!opt.value) return;
            var ok = (!cls.value || opt.dataset.class === cls.value) && (!subj.value || opt.dataset.subject === subj.value);
            opt.hidden = opt.disabled = !ok;
            if (ok) shown++;
        });
        var cur = topic.options[topic.selectedIndex];
        if (cur && cur.value && cur.disabled) topic.value = '';
        $('topicHint').textContent = cls.value && subj.value && !shown
            ? 'Для цього класу й предмета тем немає — створіть її в розділі «Теми».'
            : 'Показано теми обраного класу та предмета.';
        syncIds();
    }

    function syncIds() {
        $('idsPreview').textContent = topic.value && cls.value && subj.value
            ? 'Підставиться: TOPIC_ID = ' + topic.value + ', CLASS_ID = ' + cls.value + ', SUBJECT_ID = ' + subj.value
            : '';
    }

    parser.addEventListener('change', syncParser);
    cls.addEventListener('change', syncTopics);
    subj.addEventListener('change', syncTopics);
    topic.addEventListener('change', syncIds);
    syncParser();
    syncTopics();

    $('parserForm').addEventListener('submit', function () {
        $('runBtn').disabled = true;
        $('runNote').textContent = ' Парсинг триває, не закривайте сторінку (до ' + Math.round(<?= PARSER_TIMEOUT ?> / 60 * 10) / 10 + ' хв)…';
    });

    document.querySelectorAll('.js-import').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            var box = btn.parentNode.querySelector('.import-result');
            btn.disabled = true;
            box.textContent = 'Імпорт…';
            try {
                var file = await fetch('?download=' + encodeURIComponent(btn.dataset.file), { credentials: 'same-origin' });
                if (!file.ok) throw new Error('Файл не знайдено');
                var res = await fetch('/api/import-questions.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' }, body: await file.text(),
                });
                var r = await res.json();
                if (!r.success) throw new Error(r.error || 'Помилка імпорту');
                box.className = 'import-result alert alert-success';
                box.textContent = '✓ Імпортовано запитань: ' + r.imported
                    + (r.without_correct ? '. Без правильної відповіді: ' + r.without_correct + ' — позначте їх у розділі «Запитання».' : '.')
                    + (r.errors && r.errors.length ? ' Пропущено: ' + r.errors.length + '.' : '');
            } catch (e) {
                box.className = 'import-result alert alert-error';
                box.textContent = e.message;
                btn.disabled = false;
            }
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
