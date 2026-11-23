<?php
/**
 * Картинки до запитань і відповідей.
 * Файли зберігаються у src/uploads/questions/, у БД — лише ім'я файлу.
 */

const IMAGE_DIR       = __DIR__ . '/../uploads/questions';
const IMAGE_URL       = '/uploads/questions/';
const IMAGE_MAX_BYTES = 5 * 1024 * 1024;
const IMAGE_TYPES     = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_GIF  => 'gif',
    IMAGETYPE_WEBP => 'webp',
];

/** Додає колонку image у questions/answers для старих БД. */
function ensureImageColumns(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    foreach (['questions', 'answers'] as $table) {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE 'image'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `image` VARCHAR(255) NULL DEFAULT NULL");
        }
    }
    $done = true;
}

/** Зберігає бінарні дані картинки, повертає ім'я файлу. */
function saveImageBytes(string $bytes): string {
    if ($bytes === '' || strlen($bytes) > IMAGE_MAX_BYTES) {
        throw new RuntimeException('Картинка порожня або більша за 5 МБ.');
    }
    $info = @getimagesizefromstring($bytes);
    if (!$info || !isset(IMAGE_TYPES[$info[2]])) {
        throw new RuntimeException('Непідтримуваний формат картинки (дозволено JPG, PNG, GIF, WEBP).');
    }
    if (!is_dir(IMAGE_DIR) && !@mkdir(IMAGE_DIR, 0775, true)) {
        throw new RuntimeException('Не вдалося створити папку src/uploads/questions.');
    }
    if (!is_writable(IMAGE_DIR)) {
        throw new RuntimeException('Немає прав на запис у src/uploads/questions (виконайте: chmod -R 777 src/uploads).');
    }
    $name = sha1($bytes) . '.' . IMAGE_TYPES[$info[2]];
    $path = IMAGE_DIR . '/' . $name;
    if (!is_file($path) && file_put_contents($path, $bytes) === false) {
        throw new RuntimeException('Не вдалося зберегти картинку.');
    }
    return $name;
}

/** Зберігає картинку з data URI (data:image/png;base64,...). */
function saveImageDataUri(string $dataUri): string {
    if (!preg_match('#^data:image/[a-z0-9.+-]+;base64,(.+)$#is', trim($dataUri), $m)) {
        throw new RuntimeException('Картинка має бути у форматі data:image/...;base64,...');
    }
    $bytes = base64_decode($m[1], true);
    if ($bytes === false) {
        throw new RuntimeException('Пошкоджені base64-дані картинки.');
    }
    return saveImageBytes($bytes);
}

/** Зберігає картинку, завантажену через форму ($_FILES[...]); null — якщо файл не вибрано. */
function saveUploadedImage(?array $file): ?string {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Помилка завантаження картинки.');
    }
    return saveImageBytes((string)file_get_contents($file['tmp_name']));
}

function imageUrl(?string $name): ?string {
    return ($name !== null && $name !== '' && preg_match('/^[a-f0-9]{40}\.(jpg|png|gif|webp)$/', $name))
        ? IMAGE_URL . $name
        : null;
}

/** HTML-тег картинки (або порожній рядок). */
function imageTag(?string $name, string $class = 'q-image'): string {
    $url = imageUrl($name);
    return $url ? '<img src="' . e($url) . '" class="' . e($class) . '" alt="" loading="lazy" draggable="false">' : '';
}
