<?php
/**
 * Сейф паролів учнів.
 *
 * Схема:
 *  - при створенні сейфа генерується пара ключів X25519 (libsodium);
 *  - публічний ключ зберігається у БД відкрито — ним шифруються паролі
 *    (реєстрація, вхід, скидання) без участі адміністратора (sealed box);
 *  - секретний ключ зберігається зашифрованим ключем, виведеним з
 *    майстер-фрази адміністратора (Argon2id). Сама фраза ніде не зберігається;
 *  - у users.password_enc лежить лише шифротекст. Без фрази розшифрувати
 *    його неможливо — навіть маючи повну копію БД і коду.
 */

require_once __DIR__ . '/../config/database.php';

const VAULT_MIN_PHRASE = 10;

function ensurePasswordVault(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS `password_vault` (
        `id` TINYINT PRIMARY KEY,
        `public_key` VARBINARY(64) NOT NULL,
        `enc_secret_key` VARBINARY(255) NOT NULL,
        `salt` VARBINARY(32) NOT NULL,
        `nonce` VARBINARY(32) NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    if (!$pdo->query("SHOW COLUMNS FROM `users` LIKE 'password_enc'")->fetch()) {
        $pdo->exec('ALTER TABLE `users` ADD COLUMN `password_enc` VARBINARY(255) NULL DEFAULT NULL');
    }
    $done = true;
}

function vaultConfig(PDO $pdo): ?array {
    ensurePasswordVault($pdo);
    return $pdo->query('SELECT * FROM password_vault WHERE id = 1')->fetch() ?: null;
}

function vaultIsConfigured(PDO $pdo): bool {
    return vaultConfig($pdo) !== null;
}

/** Ключ шифрування секретного ключа з майстер-фрази (Argon2id). */
function vaultDeriveKey(string $phrase, string $salt): string {
    return sodium_crypto_pwhash(
        SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
        $phrase,
        $salt,
        SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
        SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
        SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
    );
}

/** Шифрує секретний ключ фразою і записує сейф (створення або зміна фрази). */
function vaultStoreKeys(PDO $pdo, string $phrase, string $publicKey, string $secretKey): void {
    $salt  = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $key   = vaultDeriveKey($phrase, $salt);
    $enc   = sodium_crypto_secretbox($secretKey, $nonce, $key);
    sodium_memzero($key);

    $stmt = $pdo->prepare('REPLACE INTO password_vault (id, public_key, enc_secret_key, salt, nonce) VALUES (1, ?, ?, ?, ?)');
    $stmt->execute([$publicKey, $enc, $salt, $nonce]);
}

function vaultCreate(PDO $pdo, string $phrase): void {
    ensurePasswordVault($pdo);
    $keypair = sodium_crypto_box_keypair();
    $secret  = sodium_crypto_box_secretkey($keypair);
    vaultStoreKeys($pdo, $phrase, sodium_crypto_box_publickey($keypair), $secret);
    sodium_memzero($secret);
    sodium_memzero($keypair);
}

/** Повертає пару ключів (для розшифрування) або null, якщо фраза невірна. */
function vaultUnlock(PDO $pdo, string $phrase): ?string {
    $cfg = vaultConfig($pdo);
    if (!$cfg) {
        return null;
    }
    $key    = vaultDeriveKey($phrase, $cfg['salt']);
    $secret = sodium_crypto_secretbox_open($cfg['enc_secret_key'], $cfg['nonce'], $key);
    sodium_memzero($key);
    if ($secret === false) {
        return null;
    }
    return sodium_crypto_box_keypair_from_secretkey_and_publickey($secret, $cfg['public_key']);
}

function vaultChangePhrase(PDO $pdo, string $oldPhrase, string $newPhrase): bool {
    $keypair = vaultUnlock($pdo, $oldPhrase);
    if ($keypair === null) {
        return false;
    }
    $secret = sodium_crypto_box_secretkey($keypair);
    vaultStoreKeys($pdo, $newPhrase, sodium_crypto_box_publickey($keypair), $secret);
    sodium_memzero($secret);
    sodium_memzero($keypair);
    return true;
}

/** Видаляє сейф і всі зашифровані копії (коли фразу забуто). */
function vaultDestroy(PDO $pdo): void {
    ensurePasswordVault($pdo);
    $pdo->exec('DELETE FROM password_vault');
    $pdo->exec('UPDATE users SET password_enc = NULL');
}

/**
 * Зберігає зашифровану копію пароля учня (якщо сейф створено).
 * $onlyIfEmpty — для входу: записати лише якщо копії ще немає.
 */
function vaultStorePassword(PDO $pdo, int $userId, string $password, bool $onlyIfEmpty = false): void {
    try {
        $cfg = vaultConfig($pdo);
        if (!$cfg) {
            return;
        }
        $sql = 'UPDATE users SET password_enc = ? WHERE id = ? AND role = "user"'
             . ($onlyIfEmpty ? ' AND password_enc IS NULL' : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute([sodium_crypto_box_seal($password, $cfg['public_key']), $userId]);
    } catch (Throwable $e) {
        // Сейф — допоміжна функція: його збій не повинен ламати вхід чи реєстрацію
        error_log('Password vault: ' . $e->getMessage());
    }
}

function vaultDecrypt(?string $ciphertext, string $keypair): ?string {
    if ($ciphertext === null || $ciphertext === '') {
        return null;
    }
    $plain = sodium_crypto_box_seal_open($ciphertext, $keypair);
    return $plain === false ? null : $plain;
}

/** Простий пароль для учня: 4 літери + 4 цифри, без схожих символів (l/1, o/0). */
function generateStudentPassword(): string {
    $letters = 'abcdefghjkmnpqrstuvwxyz';
    $pwd = '';
    for ($i = 0; $i < 4; $i++) {
        $pwd .= $letters[random_int(0, strlen($letters) - 1)];
    }
    return $pwd . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}
